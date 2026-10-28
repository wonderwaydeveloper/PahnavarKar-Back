<?php

namespace Tests\Feature\MySql;

use App\Models\AdminSetting;
use App\Models\DiscountCode;
use App\Models\DiscountUsage;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\Support\ConcurrentPaymentGateway;
use Tests\TestCase;
use Throwable;

class PaymentCallbackConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.gateways.concurrency-test.class' => ConcurrentPaymentGateway::class,
            'payment.gateways.concurrency-test.credentials_configured' => true,
            'payment.gateways.concurrency-test.concurrent_verification' => true,
        ]);
    }

    public function test_two_valid_concurrent_callbacks_settle_one_top_up_and_notify_once(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $user = User::factory()->create(['wallet_balance' => 1000]);
        $attempt = PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'concurrency-test',
            'gateway_environment' => 'sandbox',
            'amount' => 700,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $gatewayReference = (string) Str::uuid();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-callback-concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory);

        try {
            $responses = $this->runConcurrentCallbacks($attempt, $gatewayReference, $directory);

            $this->assertSame([200, 200], collect($responses)->pluck('status_code')->sort()->values()->all());
            $this->assertSame(
                ['succeeded', 'succeeded'],
                collect($responses)->map(static fn (array $response): mixed => $response['payment']['status'])
                    ->sort()
                    ->values()
                    ->all(),
            );
            $this->assertSame(
                [(int) $attempt->getKey()],
                collect($responses)->pluck('payment.id')->unique()->values()->all(),
            );
            $this->assertSame(1700, $user->fresh()->wallet_balance);
            $this->assertSame(1, PaymentAttempt::query()->whereKey($attempt->getKey())->count());
            $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());

            $transaction = Transaction::query()->where('user_id', $user->getKey())->sole();
            $this->assertSame('deposit', $transaction->type);
            $this->assertSame('concurrency-test', $transaction->gateway);
            $this->assertSame($gatewayReference, $transaction->reference_id);
            $this->assertSame(700, $transaction->amount);
            $this->assertSame(1700, $transaction->balance_after);
            $this->assertSame($transaction->getKey(), $attempt->fresh()->transaction_id);
            $this->assertSame('succeeded', $attempt->fresh()->status);
            $this->assertNotNull($attempt->fresh()->verified_at);

            $notifications = array_map(
                static fn (string $record): array => json_decode($record, true, flags: JSON_THROW_ON_ERROR),
                file($directory.DIRECTORY_SEPARATOR.'notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
            $this->assertCount(1, $notifications);
            $this->assertSame('payment.succeeded', $notifications[0]['event']);
            $this->assertSame($user->getKey(), $notifications[0]['user_id']);

            foreach ([3, 4] as $worker) {
                $replay = $this->runCallbackReplay($attempt, $gatewayReference, $directory, $worker);

                $this->assertSame(200, $replay['status_code']);
                $this->assertSame($attempt->getKey(), $replay['payment']['id']);
                $this->assertSame('succeeded', $replay['payment']['status']);
                $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'verified-'.$worker);
            }

            $this->assertSame(1700, $user->fresh()->wallet_balance);
            $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());
            $this->assertCount(
                1,
                file($directory.DIRECTORY_SEPARATOR.'notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
        } finally {
            $this->removeBarrierFiles($directory);
        }
    }

    public function test_conflicting_top_up_callbacks_preserve_the_first_terminal_result(): void
    {
        $this->assertIsolatedMysqlDatabase();

        foreach (['succeeded-first', 'failed-first'] as $firstOutcome) {
            $user = User::factory()->create(['wallet_balance' => 1000]);
            $attempt = PaymentAttempt::query()->create([
                'user_id' => $user->getKey(),
                'merchant_reference' => (string) Str::uuid(),
                'gateway' => 'concurrency-test',
                'gateway_environment' => 'sandbox',
                'amount' => 700,
                'currency' => 'TOMAN',
                'status' => 'pending',
                'idempotency_key' => (string) Str::uuid(),
                'expires_at' => now()->addDay(),
            ]);
            $gatewayReference = (string) Str::uuid();
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-callback-conflict-'.bin2hex(random_bytes(12));
            mkdir($directory);

            try {
                $outcomes = $firstOutcome === 'succeeded-first'
                    ? ['succeeded', 'failed']
                    : ['failed', 'succeeded'];
                $responses = $this->runConcurrentCallbacks(
                    $attempt,
                    $gatewayReference,
                    $directory,
                    $outcomes,
                    ordered: true,
                );
                $firstStatus = $outcomes[0] === 'succeeded' ? 'succeeded' : 'failed';
                $secondStatus = $outcomes[1] === 'succeeded' ? 'succeeded' : 'failed';

                $this->assertSame(200, $responses[0]['status_code']);
                $this->assertSame($firstStatus, $responses[0]['payment']['status']);
                if ($firstOutcome === 'succeeded-first') {
                    $this->assertSame(200, $responses[1]['status_code']);
                    $this->assertSame('succeeded', $responses[1]['payment']['status']);
                    $this->assertSame(1700, $user->fresh()->wallet_balance);
                    $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());
                    $transaction = Transaction::query()->where('user_id', $user->getKey())->sole();
                    $this->assertSame($gatewayReference, $transaction->reference_id);
                    $this->assertSame(1700, $transaction->balance_after);
                    $this->assertCount(
                        1,
                        file($directory.DIRECTORY_SEPARATOR.'notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
                    );
                } else {
                    $this->assertSame(409, $responses[1]['status_code']);
                    $this->assertArrayNotHasKey('payment', $responses[1]);
                    $this->assertSame(1000, $user->fresh()->wallet_balance);
                    $this->assertSame(0, Transaction::query()->where('user_id', $user->getKey())->count());
                    $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'notifications.log');
                }

                $this->assertSame($firstStatus, $attempt->fresh()->status);
                $this->assertSame(1, PaymentAttempt::query()->whereKey($attempt->getKey())->count());
                $this->assertNotSame($firstStatus, $secondStatus);
            } finally {
                $this->removeBarrierFiles($directory);
            }
        }
    }

    public function test_two_valid_concurrent_subscription_callbacks_activate_once_and_consume_discount_once(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $user = User::factory()->create(['wallet_balance' => 1000]);
        $discount = DiscountCode::query()->create([
            'code' => 'CONCURRENT100',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 5,
            'usage_count' => 1,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $order = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'subscription_change_type' => 'purchase',
            'plan_key' => 'gold_1_month',
            'duration_days' => 30,
            'discount_code_id' => $discount->getKey(),
            'discount_amount' => 100,
            'gross_amount' => 1000,
            'wallet_amount' => 0,
            'gateway_amount' => 900,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $attempt = PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'payment_order_id' => $order->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'concurrency-test',
            'gateway_environment' => 'sandbox',
            'amount' => 900,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $usage = DiscountUsage::query()->create([
            'discount_code_id' => $discount->getKey(),
            'user_id' => $user->getKey(),
            'payment_order_id' => $order->getKey(),
            'order_amount' => 1000,
            'discount_amount' => 100,
            'status' => 'reserved',
        ]);
        $gatewayReference = (string) Str::uuid();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-callback-concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory);

        try {
            $responses = $this->runConcurrentCallbacks($attempt, $gatewayReference, $directory);

            $this->assertSame([302, 302], collect($responses)->pluck('status_code')->sort()->values()->all());
            foreach ($responses as $response) {
                $location = parse_url((string) $response['location']);
                parse_str($location['query'] ?? '', $query);
                $this->assertSame((string) $order->getKey(), $query['orderId'] ?? null);
                $this->assertSame('succeeded', $query['status'] ?? null);
            }

            $this->assertSame('succeeded', $order->fresh()->status);
            $this->assertSame('succeeded', $attempt->fresh()->status);
            $this->assertSame($order->getKey(), $attempt->fresh()->payment_order_id);
            $this->assertSame(1000, $user->fresh()->wallet_balance);
            $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());

            $transaction = Transaction::query()->where('user_id', $user->getKey())->sole();
            $this->assertSame('subscription', $transaction->type);
            $this->assertSame('concurrency-test', $transaction->gateway);
            $this->assertSame($gatewayReference, $transaction->reference_id);
            $this->assertSame(900, $transaction->amount);
            $this->assertSame(1000, $transaction->balance_after);
            $this->assertSame($transaction->getKey(), $attempt->fresh()->transaction_id);
            $this->assertSame($transaction->getKey(), $order->fresh()->transaction_id);

            $subscription = Subscription::query()->where('payment_order_id', $order->getKey())->sole();
            $this->assertSame($user->getKey(), $subscription->user_id);
            $this->assertSame($transaction->getKey(), $subscription->transaction_id);
            $this->assertSame('active', $subscription->status);
            $this->assertSame('gold_1_month', $subscription->plan_key);

            $this->assertSame(1, $discount->fresh()->usage_count);
            $this->assertSame('consumed', $usage->fresh()->status);
            $this->assertSame($attempt->getKey(), $usage->fresh()->payment_attempt_id);
            $this->assertSame($transaction->getKey(), $usage->fresh()->transaction_id);

            $notifications = array_map(
                static fn (string $record): array => json_decode($record, true, flags: JSON_THROW_ON_ERROR),
                file($directory.DIRECTORY_SEPARATOR.'notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
            $this->assertCount(1, $notifications);
            $this->assertSame('subscription.activated', $notifications[0]['event']);
            $this->assertSame($user->getKey(), $notifications[0]['user_id']);
            $this->assertSame($subscription->getKey(), $notifications[0]['data']['subscription_id']);

            foreach ([3, 4] as $worker) {
                $replay = $this->runCallbackReplay($attempt, $gatewayReference, $directory, $worker);
                $location = parse_url((string) $replay['location']);
                parse_str($location['query'] ?? '', $query);

                $this->assertSame(302, $replay['status_code']);
                $this->assertSame((string) $order->getKey(), $query['orderId'] ?? null);
                $this->assertSame('succeeded', $query['status'] ?? null);
                $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'verified-'.$worker);
            }

            $this->assertSame(1000, $user->fresh()->wallet_balance);
            $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());
            $this->assertSame(1, Subscription::query()->where('payment_order_id', $order->getKey())->count());
            $this->assertSame(1, $discount->fresh()->usage_count);
            $this->assertSame('consumed', $usage->fresh()->status);
            $this->assertCount(
                1,
                file($directory.DIRECTORY_SEPARATOR.'notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
        } finally {
            $this->removeBarrierFiles($directory);
        }
    }

    public function test_conflicting_subscription_callbacks_preserve_the_first_terminal_result(): void
    {
        $this->assertIsolatedMysqlDatabase();
        $this->configureOfferPrice(1000);

        foreach (['succeeded-first', 'failed-first'] as $firstOutcome) {
            $user = User::factory()->create(['wallet_balance' => 0]);
            $discount = DiscountCode::query()->create([
                'code' => 'CONFLICT'.strtoupper(Str::random(8)),
                'type' => 'fixed',
                'value' => 100,
                'min_order_amount' => 0,
                'usage_limit' => 5,
                'usage_count' => 1,
                'expires_at' => now()->addDay(),
                'is_active' => true,
            ]);
            $order = PaymentOrder::query()->create([
                'user_id' => $user->getKey(),
                'order_reference' => (string) Str::uuid(),
                'kind' => 'subscription',
                'subscription_change_type' => 'purchase',
                'plan_key' => 'gold_1_month',
                'duration_days' => 30,
                'discount_code_id' => $discount->getKey(),
                'discount_amount' => 100,
                'gross_amount' => 1000,
                'wallet_amount' => 200,
                'gateway_amount' => 700,
                'currency' => 'TOMAN',
                'status' => 'pending',
                'idempotency_key' => (string) Str::uuid(),
                'expires_at' => now()->addDay(),
            ]);
            $walletDebit = Transaction::query()->create([
                'user_id' => $user->getKey(),
                'type' => 'debit',
                'status' => 'succeeded',
                'amount' => 200,
                'balance_after' => 0,
                'currency' => 'TOMAN',
                'idempotency_key' => (string) Str::uuid(),
                'reference_id' => 'subscription-order:'.$order->order_reference,
                'description' => 'Concurrent subscription wallet contribution',
                'expires_at' => now()->addDay(),
            ]);
            $order->forceFill(['wallet_transaction_id' => $walletDebit->getKey()])->saveOrFail();
            $attempt = PaymentAttempt::query()->create([
                'user_id' => $user->getKey(),
                'payment_order_id' => $order->getKey(),
                'merchant_reference' => (string) Str::uuid(),
                'gateway' => 'concurrency-test',
                'gateway_environment' => 'sandbox',
                'amount' => 700,
                'currency' => 'TOMAN',
                'status' => 'pending',
                'idempotency_key' => (string) Str::uuid(),
                'expires_at' => now()->addDay(),
            ]);
            $usage = DiscountUsage::query()->create([
                'discount_code_id' => $discount->getKey(),
                'user_id' => $user->getKey(),
                'payment_order_id' => $order->getKey(),
                'order_amount' => 1000,
                'discount_amount' => 100,
                'status' => 'reserved',
            ]);
            $gatewayReference = (string) Str::uuid();
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-callback-conflict-'.bin2hex(random_bytes(12));
            mkdir($directory);

            try {
                $outcomes = $firstOutcome === 'succeeded-first'
                    ? ['succeeded', 'failed']
                    : ['failed', 'succeeded'];
                $responses = $this->runConcurrentCallbacks(
                    $attempt,
                    $gatewayReference,
                    $directory,
                    $outcomes,
                    ordered: true,
                );
                $firstStatus = $outcomes[0] === 'succeeded' ? 'succeeded' : 'failed';

                $this->assertSame(302, $responses[0]['status_code']);
                $location = parse_url((string) $responses[0]['location']);
                parse_str($location['query'] ?? '', $firstQuery);
                $this->assertSame($firstStatus, $firstQuery['status'] ?? null);

                if ($firstOutcome === 'succeeded-first') {
                    $this->assertSame(302, $responses[1]['status_code']);
                    $secondLocation = parse_url((string) $responses[1]['location']);
                    parse_str($secondLocation['query'] ?? '', $secondQuery);
                    $this->assertSame('succeeded', $secondQuery['status'] ?? null);
                    $this->assertSame('succeeded', $order->fresh()->status);
                    $this->assertSame('succeeded', $attempt->fresh()->status);
                    $this->assertSame(0, $user->fresh()->wallet_balance);
                    $this->assertSame(2, Transaction::query()->where('user_id', $user->getKey())->count());
                    $this->assertSame(
                        ['debit', 'subscription'],
                        Transaction::query()
                            ->where('user_id', $user->getKey())
                            ->orderBy('id')
                            ->pluck('type')
                            ->all(),
                    );
                    $this->assertSame(1, Subscription::query()->where('payment_order_id', $order->getKey())->count());
                    $this->assertSame(1, $discount->fresh()->usage_count);
                    $this->assertSame('consumed', $usage->fresh()->status);
                    $this->assertCount(
                        1,
                        file($directory.DIRECTORY_SEPARATOR.'notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
                    );
                } else {
                    $this->assertSame(409, $responses[1]['status_code']);
                    $this->assertSame('failed', $order->fresh()->status);
                    $this->assertSame('failed', $attempt->fresh()->status);
                    $this->assertSame('concurrent_payment_declined', $attempt->fresh()->failure_code);
                    $this->assertSame(200, $user->fresh()->wallet_balance);
                    $this->assertSame(2, Transaction::query()->where('user_id', $user->getKey())->count());
                    $this->assertSame(
                        ['debit', 'refund'],
                        Transaction::query()
                            ->where('user_id', $user->getKey())
                            ->orderBy('id')
                            ->pluck('type')
                            ->all(),
                    );
                    $this->assertSame(0, Subscription::query()->where('payment_order_id', $order->getKey())->count());
                    $this->assertSame(0, $discount->fresh()->usage_count);
                    $this->assertSame('released', $usage->fresh()->status);
                    $this->assertNotNull($order->fresh()->refund_transaction_id);
                    $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'notifications.log');
                }

                $this->assertSame(2, Transaction::query()->where('user_id', $user->getKey())->count());
                $this->assertSame(1, PaymentAttempt::query()->whereKey($attempt->getKey())->count());
            } finally {
                $this->removeBarrierFiles($directory);
            }
        }
    }

    public function test_callback_can_settle_top_up_while_gateway_initiation_is_in_flight(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $user = User::factory()->create(['wallet_balance' => 1000]);
        $idempotencyKey = (string) Str::uuid();
        $gatewayReference = (string) Str::uuid();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-initiation-concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory);

        try {
            $initiator = $this->startInitiationWorker('top-up', $user, $idempotencyKey, $directory, amount: 700);
            $this->waitForInitiationWorker($directory, $initiator);
            $attempt = PaymentAttempt::query()->where('idempotency_key', $idempotencyKey)->sole();

            $this->assertSame('initiating', $attempt->status);

            $callback = $this->runCallbackReplay($attempt, $gatewayReference, $directory, 1);
            $this->assertSame(200, $callback['status_code']);
            $this->assertSame('succeeded', $callback['payment']['status']);
            $this->assertSame('succeeded', $attempt->fresh()->status);
            $this->assertSame(1700, $user->fresh()->wallet_balance);
            $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());

            touch($directory.DIRECTORY_SEPARATOR.'initiation-release');
            $initiator->wait();
            $this->assertSame(0, $initiator->getExitCode(), $initiator->getErrorOutput());
            $startResult = json_decode(
                (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'initiation-result.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $this->assertSame($attempt->getKey(), $startResult['payment_id']);
            $this->assertSame('succeeded', $startResult['status']);
            $this->assertNull($startResult['failure_code']);
            $this->assertSame('succeeded', $attempt->fresh()->status);
            $transaction = Transaction::query()->where('user_id', $user->getKey())->sole();
            $this->assertSame('deposit', $transaction->type);
            $this->assertSame('concurrency-test', $transaction->gateway);
            $this->assertSame($gatewayReference, $transaction->reference_id);
            $this->assertSame(700, $transaction->amount);
            $this->assertSame(1700, $transaction->balance_after);
            $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());
            $this->assertCount(
                1,
                file($directory.DIRECTORY_SEPARATOR.'notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
        } finally {
            if (isset($initiator) && $initiator->isRunning()) {
                $initiator->stop(1);
            }
            $this->removeBarrierFiles($directory);
        }
    }

    public function test_callback_can_settle_subscription_while_gateway_initiation_is_in_flight(): void
    {
        $this->assertIsolatedMysqlDatabase();
        $this->configureOfferPrice(1000);

        $user = User::factory()->create(['wallet_balance' => 200]);
        $idempotencyKey = (string) Str::uuid();
        $gatewayReference = (string) Str::uuid();
        $payments = app(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month');
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-initiation-concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory);

        try {
            $initiator = $this->startInitiationWorker(
                'subscription',
                $user,
                $idempotencyKey,
                $directory,
                quote: $quote,
            );
            $this->waitForInitiationWorker($directory, $initiator);
            $order = PaymentOrder::query()
                ->where('user_id', $user->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->sole();
            $attempt = $order->attempts()->sole();

            $this->assertSame('pending', $order->status);
            $this->assertSame('initiating', $attempt->status);
            $this->assertSame(0, $user->fresh()->wallet_balance);
            $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());

            $callback = $this->runCallbackReplay($attempt, $gatewayReference, $directory, 1);
            $this->assertSame(302, $callback['status_code']);
            $this->assertSame('succeeded', $attempt->fresh()->status);
            $this->assertSame('succeeded', $order->fresh()->status);
            $this->assertSame(1, Subscription::query()->where('payment_order_id', $order->getKey())->count());

            touch($directory.DIRECTORY_SEPARATOR.'initiation-release');
            $initiator->wait();
            $this->assertSame(0, $initiator->getExitCode(), $initiator->getErrorOutput());
            $startResult = json_decode(
                (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'initiation-result.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $this->assertSame($order->getKey(), $startResult['payment_id']);
            $this->assertSame('succeeded', $startResult['status']);
            $this->assertNull($startResult['failure_code']);
            $this->assertSame('succeeded', $startResult['attempt_status']);
            $this->assertSame('succeeded', $order->fresh()->status);
            $this->assertSame('active', Subscription::query()->where('payment_order_id', $order->getKey())->sole()->status);
            $this->assertSame(0, $user->fresh()->wallet_balance);
            $transactions = Transaction::query()->where('user_id', $user->getKey())->orderBy('id')->get();
            $this->assertSame(['debit', 'subscription'], $transactions->pluck('type')->all());
            $this->assertSame([200, 800], $transactions->pluck('amount')->all());
            $this->assertCount(
                1,
                file($directory.DIRECTORY_SEPARATOR.'notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
        } finally {
            if (isset($initiator) && $initiator->isRunning()) {
                $initiator->stop(1);
            }
            $this->removeBarrierFiles($directory);
        }
    }

    public function test_late_gateway_failures_cannot_overwrite_a_settled_top_up(): void
    {
        $this->assertIsolatedMysqlDatabase();

        foreach (['rejected', 'retryable', 'ambiguous'] as $lateFailure) {
            $user = User::factory()->create(['wallet_balance' => 1000]);
            $idempotencyKey = (string) Str::uuid();
            $gatewayReference = (string) Str::uuid();
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-initiation-concurrency-'.bin2hex(random_bytes(12));
            mkdir($directory);

            try {
                $initiator = $this->startInitiationWorker(
                    'top-up',
                    $user,
                    $idempotencyKey,
                    $directory,
                    amount: 700,
                    lateFailure: $lateFailure,
                );
                $this->waitForInitiationWorker($directory, $initiator);
                $attempt = PaymentAttempt::query()->where('idempotency_key', $idempotencyKey)->sole();

                $this->assertSame('initiating', $attempt->status);
                $callback = $this->runCallbackReplay($attempt, $gatewayReference, $directory, 1);
                $this->assertSame(200, $callback['status_code']);
                $this->assertSame('succeeded', $attempt->fresh()->status);
                $this->assertSame(1700, $user->fresh()->wallet_balance);

                touch($directory.DIRECTORY_SEPARATOR.'initiation-release');
                $initiator->wait();
                $this->assertSame(0, $initiator->getExitCode(), $initiator->getErrorOutput());
                $startResult = json_decode(
                    (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'initiation-result.json'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                $this->assertSame($attempt->getKey(), $startResult['payment_id']);
                $this->assertSame('succeeded', $startResult['status']);
                $this->assertNull($startResult['failure_code']);
                $this->assertSame('succeeded', $attempt->fresh()->status);
                $transaction = Transaction::query()->where('user_id', $user->getKey())->sole();
                $this->assertSame('deposit', $transaction->type);
                $this->assertSame($gatewayReference, $transaction->reference_id);
                $this->assertSame(700, $transaction->amount);
                $this->assertSame(1700, $transaction->balance_after);
                $this->assertCount(
                    1,
                    file($directory.DIRECTORY_SEPARATOR.'notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
                );
            } finally {
                if (isset($initiator) && $initiator->isRunning()) {
                    $initiator->stop(1);
                }
                $this->removeBarrierFiles($directory);
            }
        }
    }

    public function test_late_gateway_failures_cannot_overwrite_a_settled_subscription(): void
    {
        $this->assertIsolatedMysqlDatabase();
        $this->configureOfferPrice(1000);

        foreach (['rejected', 'retryable', 'ambiguous'] as $lateFailure) {
            $user = User::factory()->create(['wallet_balance' => 200]);
            $idempotencyKey = (string) Str::uuid();
            $gatewayReference = (string) Str::uuid();
            $payments = app(PaymentService::class);
            $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month');
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-initiation-concurrency-'.bin2hex(random_bytes(12));
            mkdir($directory);

            try {
                $initiator = $this->startInitiationWorker(
                    'subscription',
                    $user,
                    $idempotencyKey,
                    $directory,
                    quote: $quote,
                    lateFailure: $lateFailure,
                );
                $this->waitForInitiationWorker($directory, $initiator);
                $order = PaymentOrder::query()
                    ->where('user_id', $user->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->sole();
                $attempt = $order->attempts()->sole();

                $this->assertSame('pending', $order->status);
                $this->assertSame('initiating', $attempt->status);
                $this->assertSame(0, $user->fresh()->wallet_balance);

                $callback = $this->runCallbackReplay($attempt, $gatewayReference, $directory, 1);
                $this->assertSame(302, $callback['status_code']);
                $this->assertSame('succeeded', $order->fresh()->status);
                $this->assertSame('succeeded', $attempt->fresh()->status);
                $this->assertSame(1, Subscription::query()->where('payment_order_id', $order->getKey())->count());

                touch($directory.DIRECTORY_SEPARATOR.'initiation-release');
                $initiator->wait();
                $this->assertSame(0, $initiator->getExitCode(), $initiator->getErrorOutput());
                $startResult = json_decode(
                    (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'initiation-result.json'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                $this->assertSame($order->getKey(), $startResult['payment_id']);
                $this->assertSame('succeeded', $startResult['status']);
                $this->assertNull($startResult['failure_code']);
                $this->assertSame('succeeded', $startResult['attempt_status']);
                $this->assertSame('succeeded', $order->fresh()->status);
                $this->assertSame('succeeded', $attempt->fresh()->status);
                $this->assertSame('active', Subscription::query()->where('payment_order_id', $order->getKey())->sole()->status);
                $this->assertSame(0, $user->fresh()->wallet_balance);
                $transactions = Transaction::query()->where('user_id', $user->getKey())->orderBy('id')->get();
                $this->assertSame(['debit', 'subscription'], $transactions->pluck('type')->all());
                $this->assertSame([200, 800], $transactions->pluck('amount')->all());
                $this->assertCount(
                    1,
                    file($directory.DIRECTORY_SEPARATOR.'notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
                );
            } finally {
                if (isset($initiator) && $initiator->isRunning()) {
                    $initiator->stop(1);
                }
                $this->removeBarrierFiles($directory);
            }
        }
    }

    public function test_invalid_callback_does_not_change_payment_or_wallet_state(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $user = User::factory()->create(['wallet_balance' => 1000]);
        $attempt = PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'concurrency-test',
            'gateway_environment' => 'sandbox',
            'amount' => 700,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->postJson('/api/v1/payment/callback/concurrency-test', [
            'merchant_reference' => $attempt->merchant_reference,
            'callback_proof' => 'invalid-test-proof',
        ]);

        $response->assertServiceUnavailable();
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(1000, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->where('user_id', $user->getKey())->count());
    }

    public function test_webhook_returns_json_and_replay_does_not_settle_or_notify_twice(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $user = User::factory()->create(['wallet_balance' => 1000]);
        $attempt = PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'concurrency-test',
            'gateway_environment' => 'sandbox',
            'amount' => 700,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $gatewayReference = (string) Str::uuid();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-callback-concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory);

        try {
            $firstResponse = $this->runCallbackReplay($attempt, $gatewayReference, $directory, 1, 'webhook');
            $this->assertSame(200, $firstResponse['status_code']);
            $this->assertStringStartsWith('application/json', $firstResponse['content_type']);
            $this->assertArrayNotHasKey('location', $firstResponse);
            $this->assertSame($attempt->getKey(), $firstResponse['payment']['id']);
            $this->assertSame('succeeded', $firstResponse['payment']['status']);
            $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'verified-1');

            $replayResponse = $this->runCallbackReplay($attempt, $gatewayReference, $directory, 2, 'webhook');
            $this->assertSame(200, $replayResponse['status_code']);
            $this->assertStringStartsWith('application/json', $replayResponse['content_type']);
            $this->assertArrayNotHasKey('location', $replayResponse);
            $this->assertSame($attempt->getKey(), $replayResponse['payment']['id']);
            $this->assertSame('succeeded', $replayResponse['payment']['status']);
            $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'verified-2');

            $transaction = Transaction::query()->where('user_id', $user->getKey())->sole();
            $this->assertSame(1700, $user->fresh()->wallet_balance);
            $this->assertSame('deposit', $transaction->type);
            $this->assertSame($gatewayReference, $transaction->reference_id);
            $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());
            $this->assertSame($transaction->getKey(), $attempt->fresh()->transaction_id);

            $notifications = array_map(
                static fn (string $record): array => json_decode($record, true, flags: JSON_THROW_ON_ERROR),
                file($directory.DIRECTORY_SEPARATOR.'notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
            $this->assertCount(1, $notifications);
            $this->assertSame('payment.succeeded', $notifications[0]['event']);
        } finally {
            $this->removeBarrierFiles($directory);
        }
    }

    public function test_webhook_rejects_invalid_proof_mismatched_amount_and_unsupported_method_without_side_effects(): void
    {
        $this->assertIsolatedMysqlDatabase();
        config(['payment.gateways.concurrency-test.concurrent_verification' => false]);
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendToUser');
        });

        $user = User::factory()->create(['wallet_balance' => 1000]);
        $order = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'subscription_change_type' => 'purchase',
            'plan_key' => 'gold_1_month',
            'duration_days' => 30,
            'gross_amount' => 1000,
            'wallet_amount' => 0,
            'gateway_amount' => 900,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $attempt = PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'payment_order_id' => $order->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'concurrency-test',
            'gateway_environment' => 'sandbox',
            'amount' => 899,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $payload = [
            'merchant_reference' => $attempt->merchant_reference,
            'callback_proof' => 'isolated-test-proof',
        ];

        $this->postJson('/api/v1/webhook/payment/concurrency-test', [
            ...$payload,
            'callback_proof' => 'invalid-test-proof',
        ])->assertServiceUnavailable();

        $this->postJson('/api/v1/webhook/payment/concurrency-test', $payload)
            ->assertConflict()
            ->assertJsonStructure(['message']);

        $this->getJson('/api/v1/webhook/payment/concurrency-test?'.http_build_query($payload))
            ->assertMethodNotAllowed();

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_id);
        $this->assertSame(1000, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(0, Subscription::query()->where('user_id', $user->getKey())->count());
    }

    public function test_subscription_callback_rejects_mismatched_attempt_amount_or_currency_without_side_effects(): void
    {
        $this->assertIsolatedMysqlDatabase();
        config(['payment.gateways.concurrency-test.concurrent_verification' => false]);
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendToUser');
        });

        $user = User::factory()->create(['wallet_balance' => 1000]);
        $discount = DiscountCode::query()->create([
            'code' => 'MISMATCH100',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 5,
            'usage_count' => 2,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $fixtures = [];

        foreach ([
            'amount' => ['amount' => 899, 'currency' => 'TOMAN'],
            'currency' => ['amount' => 900, 'currency' => 'RIAL'],
        ] as $mismatch => $attemptValues) {
            $order = PaymentOrder::query()->create([
                'user_id' => $user->getKey(),
                'order_reference' => (string) Str::uuid(),
                'kind' => 'subscription',
                'subscription_change_type' => 'purchase',
                'plan_key' => 'gold_1_month',
                'duration_days' => 30,
                'discount_code_id' => $discount->getKey(),
                'discount_amount' => 100,
                'gross_amount' => 1000,
                'wallet_amount' => 0,
                'gateway_amount' => 900,
                'currency' => 'TOMAN',
                'status' => 'pending',
                'idempotency_key' => (string) Str::uuid(),
                'expires_at' => now()->addDay(),
            ]);
            $attempt = PaymentAttempt::query()->create([
                'user_id' => $user->getKey(),
                'payment_order_id' => $order->getKey(),
                'merchant_reference' => (string) Str::uuid(),
                'gateway' => 'concurrency-test',
                'gateway_environment' => 'sandbox',
                'amount' => $attemptValues['amount'],
                'currency' => $attemptValues['currency'],
                'status' => 'pending',
                'idempotency_key' => (string) Str::uuid(),
                'expires_at' => now()->addDay(),
            ]);
            $usage = DiscountUsage::query()->create([
                'discount_code_id' => $discount->getKey(),
                'user_id' => $user->getKey(),
                'payment_order_id' => $order->getKey(),
                'order_amount' => 1000,
                'discount_amount' => 100,
                'status' => 'reserved',
            ]);

            $fixtures[$mismatch] = compact('order', 'attempt', 'usage');
        }

        foreach ($fixtures as $mismatch => $fixture) {
            $response = $this->postJson('/api/v1/payment/callback/concurrency-test', [
                'merchant_reference' => $fixture['attempt']->merchant_reference,
                'callback_proof' => 'isolated-test-proof',
            ]);

            $response->assertConflict()->assertJsonStructure(['message']);
            $this->assertSame('pending', $fixture['attempt']->fresh()->status, $mismatch);
            $this->assertSame('pending', $fixture['order']->fresh()->status, $mismatch);
            $this->assertSame('reserved', $fixture['usage']->fresh()->status, $mismatch);
            $this->assertNull($fixture['attempt']->fresh()->transaction_id, $mismatch);
            $this->assertSame(1000, $user->fresh()->wallet_balance, $mismatch);
            $this->assertSame(2, $discount->fresh()->usage_count, $mismatch);
            $this->assertSame(0, Transaction::query()->where('user_id', $user->getKey())->count(), $mismatch);
            $this->assertSame(0, Subscription::query()->where('user_id', $user->getKey())->count(), $mismatch);
        }
    }

    public function test_callback_rejects_unknown_and_other_gateway_attempt_references_without_side_effects(): void
    {
        $this->assertIsolatedMysqlDatabase();
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendToUser');
        });

        $user = User::factory()->create(['wallet_balance' => 1000]);
        $otherGatewayAttempt = PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'other-gateway',
            'gateway_environment' => 'sandbox',
            'amount' => 700,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);

        foreach ([(string) Str::uuid(), $otherGatewayAttempt->merchant_reference] as $reference) {
            $response = $this->postJson('/api/v1/payment/callback/concurrency-test', [
                'merchant_reference' => $reference,
                'callback_proof' => 'isolated-test-proof',
            ]);

            $response->assertNotFound();
            $this->assertSame('pending', $otherGatewayAttempt->fresh()->status);
            $this->assertSame(1000, $user->fresh()->wallet_balance);
            $this->assertSame(0, Transaction::query()->where('user_id', $user->getKey())->count());
            $this->assertSame(0, Subscription::query()->where('user_id', $user->getKey())->count());
        }
    }

    private function assertIsolatedMysqlDatabase(): void
    {
        if (env('PAYMENT_CALLBACK_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set PAYMENT_CALLBACK_MYSQL_CONCURRENCY=1 to run against a disposable MySQL test database.');
        }

        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('mysql', config('database.connections.mysql.driver'));

        $database = (string) config('database.connections.mysql.database');
        $this->assertStringStartsWith('pahnavarkar_payment_callback_test_', $database);

        foreach (['users', 'payment_attempts', 'transactions'] as $table) {
            $engine = DB::selectOne(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$database, $table],
            )?->ENGINE;
            $this->assertSame('InnoDB', $engine);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function runConcurrentCallbacks(
        PaymentAttempt $attempt,
        string $gatewayReference,
        string $directory,
        ?array $outcomes = null,
        bool $ordered = false,
    ): array {
        $processes = [];
        $outcomes ??= ['succeeded', 'succeeded'];

        try {
            foreach ([1, 2] as $worker) {
                $processes[$worker] = new Process(
                    [PHP_BINARY, base_path('tests/Support/payment-callback-worker.php'), (string) $worker],
                    base_path(),
                    [
                        'APP_ENV' => 'testing',
                        'PAYMENT_CALLBACK_CONCURRENCY_DIR' => $directory,
                        'PAYMENT_CALLBACK_CONCURRENCY_WORKER' => (string) $worker,
                        'PAYMENT_CALLBACK_CONCURRENCY_MERCHANT_REFERENCE' => (string) $attempt->merchant_reference,
                        'PAYMENT_CALLBACK_CONCURRENCY_GATEWAY_REFERENCE' => $gatewayReference,
                        'PAYMENT_CALLBACK_NOTIFICATION_LOG' => $directory.DIRECTORY_SEPARATOR.'notifications.log',
                        'PAYMENT_CALLBACK_ROUTE' => 'callback',
                        'PAYMENT_CALLBACK_METHOD' => 'POST',
                        'PAYMENT_CALLBACK_CONCURRENT_VERIFICATION' => '1',
                        'PAYMENT_CALLBACK_CONCURRENCY_ORDERED_VERIFICATION' => $ordered ? '1' : '0',
                        'PAYMENT_CALLBACK_CONCURRENCY_RESULT' => $outcomes[$worker - 1],
                        'DB_HOST' => (string) config('database.connections.mysql.host'),
                        'DB_PORT' => (string) config('database.connections.mysql.port'),
                        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
                        'DB_USERNAME' => (string) config('database.connections.mysql.username'),
                        'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
                    ],
                    null,
                    null,
                    30,
                );
                $processes[$worker]->start();
            }

            $this->waitForWorkers($directory, $processes);
            touch($directory.DIRECTORY_SEPARATOR.'release');

            if ($ordered) {
                $this->waitForVerifiedWorkers($directory, $processes);
                foreach ([1, 2] as $worker) {
                    touch($directory.DIRECTORY_SEPARATOR.'verify-release-'.$worker);
                    $processes[$worker]->wait();
                    $this->assertSame(0, $processes[$worker]->getExitCode(), $processes[$worker]->getErrorOutput());
                }
            } else {
                foreach ($processes as $process) {
                    $process->wait();
                    $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                }
            }

            return [
                json_decode(
                    (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
                json_decode(
                    (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-2'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
            ];
        } catch (Throwable $exception) {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            throw $exception;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runCallbackReplay(
        PaymentAttempt $attempt,
        string $gatewayReference,
        string $directory,
        int $worker,
        string $route = 'callback',
        string $method = 'POST',
    ): array {
        if (! is_file($directory.DIRECTORY_SEPARATOR.'release')) {
            touch($directory.DIRECTORY_SEPARATOR.'release');
        }

        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/payment-callback-worker.php'), (string) $worker],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'PAYMENT_CALLBACK_CONCURRENCY_DIR' => $directory,
                'PAYMENT_CALLBACK_CONCURRENCY_WORKER' => (string) $worker,
                'PAYMENT_CALLBACK_CONCURRENCY_MERCHANT_REFERENCE' => (string) $attempt->merchant_reference,
                'PAYMENT_CALLBACK_CONCURRENCY_GATEWAY_REFERENCE' => $gatewayReference,
                'PAYMENT_CALLBACK_NOTIFICATION_LOG' => $directory.DIRECTORY_SEPARATOR.'notifications.log',
                'PAYMENT_CALLBACK_ROUTE' => $route,
                'PAYMENT_CALLBACK_METHOD' => $method,
                'PAYMENT_CALLBACK_CONCURRENT_VERIFICATION' => '0',
                'DB_HOST' => (string) config('database.connections.mysql.host'),
                'DB_PORT' => (string) config('database.connections.mysql.port'),
                'DB_DATABASE' => (string) config('database.connections.mysql.database'),
                'DB_USERNAME' => (string) config('database.connections.mysql.username'),
                'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
            ],
            null,
            null,
            30,
        );

        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode(
            (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-'.$worker),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, int|string|null>|null  $quote
     */
    private function startInitiationWorker(
        string $paymentType,
        User $user,
        string $idempotencyKey,
        string $directory,
        ?int $amount = null,
        ?array $quote = null,
        string $lateFailure = '',
    ): Process {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/payment-initiation-worker.php')],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'PAYMENT_CALLBACK_CONCURRENCY_DIR' => $directory,
                'PAYMENT_CALLBACK_CONCURRENT_INITIATION' => '1',
                'PAYMENT_CALLBACK_CONCURRENT_INITIATION_FAILURE' => $lateFailure,
                'PAYMENT_CALLBACK_CONCURRENCY_USER_ID' => (string) $user->getKey(),
                'PAYMENT_CALLBACK_CONCURRENCY_IDEMPOTENCY_KEY' => $idempotencyKey,
                'PAYMENT_CALLBACK_CONCURRENCY_PAYMENT_TYPE' => $paymentType,
                'PAYMENT_CALLBACK_CONCURRENCY_AMOUNT' => (string) ($amount ?? 0),
                'PAYMENT_CALLBACK_CONCURRENCY_GATEWAY_REFERENCE' => (string) Str::uuid(),
                'PAYMENT_CALLBACK_CONCURRENCY_QUOTE' => json_encode($quote ?? [], JSON_THROW_ON_ERROR),
                'DB_HOST' => (string) config('database.connections.mysql.host'),
                'DB_PORT' => (string) config('database.connections.mysql.port'),
                'DB_DATABASE' => (string) config('database.connections.mysql.database'),
                'DB_USERNAME' => (string) config('database.connections.mysql.username'),
                'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
            ],
            null,
            null,
            45,
        );
        $process->start();

        return $process;
    }

    private function waitForInitiationWorker(string $directory, Process $process): void
    {
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            if (is_file($directory.DIRECTORY_SEPARATOR.'initiation-entered')) {
                return;
            }

            if (! $process->isRunning()) {
                $this->fail($process->getErrorOutput());
            }

            usleep(10000);
        }

        $this->fail('Timed out waiting for the payment initiation worker to enter the gateway.');
    }

    private function configureOfferPrice(int $price): void
    {
        AdminSetting::query()->updateOrCreate(
            ['setting_key' => 'plans'],
            ['value' => ['offers' => ['gold_1_month' => ['price' => $price]]]],
        );
        Cache::forget('admin-runtime-settings:v2:plans');
    }

    /**
     * @param  array<int, Process>  $processes
     */
    private function waitForWorkers(string $directory, array $processes): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            if (
                is_file($directory.DIRECTORY_SEPARATOR.'ready-1')
                && is_file($directory.DIRECTORY_SEPARATOR.'ready-2')
            ) {
                return;
            }

            foreach ($processes as $process) {
                if (! $process->isRunning() && $process->getExitCode() !== 0) {
                    $this->fail($process->getErrorOutput());
                }
            }

            usleep(10000);
        }

        $this->fail('Timed out waiting for concurrent callback workers to become ready.');
    }

    /**
     * @param  array<int, Process>  $processes
     */
    private function waitForVerifiedWorkers(string $directory, array $processes): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            if (
                is_file($directory.DIRECTORY_SEPARATOR.'verified-1')
                && is_file($directory.DIRECTORY_SEPARATOR.'verified-2')
            ) {
                return;
            }

            foreach ($processes as $process) {
                if (! $process->isRunning() && $process->getExitCode() !== 0) {
                    $this->fail($process->getErrorOutput());
                }
            }

            usleep(10000);
        }

        $this->fail('Timed out waiting for both callback workers to verify.');
    }

    private function removeBarrierFiles(string $directory): void
    {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($directory);
    }
}
