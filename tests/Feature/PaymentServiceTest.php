<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\PaymentRequest;
use App\Events\SubscriptionActivated;
use App\Exceptions\PaymentConflictException;
use App\Exceptions\PaymentGatewayRejectedException;
use App\Exceptions\PaymentGatewayRetryableException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\AdminSetting;
use App\Models\DiscountCode;
use App\Models\DiscountUsage;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_top_up_replay_returns_the_original_attempt_and_rejects_changed_amount(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $user = User::factory()->create(['wallet_balance' => 500]);
        $key = (string) Str::uuid();
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->with(Mockery::type(PaymentRequest::class))
            ->andReturn(new GatewayPaymentResult('pending', 'provider-123', 'https://pay.example/123'));
        $this->mockGatewayManager($gateway);
        $payments = $this->app->make(PaymentService::class);

        $attempt = $payments->startWalletTopUp($user, 2500, $key);
        $replayedAttempt = $payments->startWalletTopUp($user, 2500, $key);

        $this->assertSame($attempt->getKey(), $replayedAttempt->getKey());
        $this->assertSame('pending', $attempt->status);
        $this->assertSame(500, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_attempts', 1);

        try {
            $payments->startWalletTopUp($user, 3000, $key);
            $this->fail('An idempotency key must not be reused with a changed amount.');
        } catch (PaymentConflictException) {
            $this->assertDatabaseCount('payment_attempts', 1);
        }
    }

    public function test_wallet_top_up_fails_over_after_a_definitive_gateway_rejection(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $user = User::factory()->create(['wallet_balance' => 500]);
        $key = (string) Str::uuid();
        $primary = Mockery::mock(PaymentGatewayInterface::class);
        $primary->shouldReceive('initiate')
            ->once()
            ->with(Mockery::on(static fn (PaymentRequest $request): bool => $request->idempotencyKey === $key))
            ->andThrow(new PaymentGatewayRejectedException('Payment method rejected.', 'method_rejected'));
        $backup = Mockery::mock(PaymentGatewayInterface::class);
        $backup->shouldReceive('initiate')
            ->once()
            ->with(Mockery::on(static fn (PaymentRequest $request): bool => $request->idempotencyKey === $key))
            ->andReturn(new GatewayPaymentResult('pending', 'backup-reference', 'https://pay.example/backup'));

        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['primary', 'backup']);
        $manager->shouldReceive('gateway')->once()->with('primary')->andReturn($primary);
        $manager->shouldReceive('gateway')->once()->with('backup')->andReturn($backup);
        $this->app->instance(PaymentGatewayManager::class, $manager);

        $attempt = $this->app->make(PaymentService::class)->startWalletTopUp($user, 2500, $key);
        $attempts = PaymentAttempt::query()->orderBy('id')->get();

        $this->assertSame('pending', $attempt->status);
        $this->assertSame('backup', $attempt->gateway);
        $this->assertSame(2, $attempts->count());
        $this->assertSame('failed', $attempts[0]->status);
        $this->assertSame('method_rejected', $attempts[0]->failure_code);
        $this->assertSame('pending', $attempts[1]->status);
        $this->assertSame('backup-reference', $attempts[1]->gateway_reference_id);
        $this->assertNotSame($attempts[0]->merchant_reference, $attempts[1]->merchant_reference);
        $this->assertSame(500, $user->fresh()->wallet_balance);
    }

    public function test_wallet_top_up_fails_over_after_a_confirmed_retryable_gateway_error(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $primary = Mockery::mock(PaymentGatewayInterface::class);
        $primary->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayRetryableException('Provider confirmed the request was not accepted.'));
        $backup = Mockery::mock(PaymentGatewayInterface::class);
        $backup->shouldReceive('initiate')
            ->once()
            ->andReturn(new GatewayPaymentResult('pending', 'backup-reference', 'https://pay.example/backup'));

        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['primary', 'backup']);
        $manager->shouldReceive('gateway')->once()->with('primary')->andReturn($primary);
        $manager->shouldReceive('gateway')->once()->with('backup')->andReturn($backup);
        $this->app->instance(PaymentGatewayManager::class, $manager);

        $attempt = $this->app->make(PaymentService::class)->startWalletTopUp($user, 2500, $key);
        $attempts = PaymentAttempt::query()->orderBy('id')->get();

        $this->assertSame('pending', $attempt->status);
        $this->assertSame('backup', $attempt->gateway);
        $this->assertSame(2, $attempts->count());
        $this->assertSame('failed', $attempts[0]->status);
        $this->assertSame('gateway_unavailable', $attempts[0]->failure_code);
        $this->assertSame('pending', $attempts[1]->status);
    }

    public function test_wallet_top_up_with_all_gateways_failed_is_terminal_and_not_retried(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $user = User::factory()->create(['wallet_balance' => 500]);
        $key = (string) Str::uuid();
        $primary = Mockery::mock(PaymentGatewayInterface::class);
        $primary->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayRejectedException('Payment method rejected.', 'method_rejected'));
        $backup = Mockery::mock(PaymentGatewayInterface::class);
        $backup->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayRetryableException('Provider confirmed the request was not accepted.'));

        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['primary', 'backup']);
        $manager->shouldReceive('gateway')->once()->with('primary')->andReturn($primary);
        $manager->shouldReceive('gateway')->once()->with('backup')->andReturn($backup);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        $payments = $this->app->make(PaymentService::class);

        try {
            $payments->startWalletTopUp($user, 2500, $key);
            $this->fail('A top-up must fail when all configured gateways reject initiation.');
        } catch (PaymentGatewayUnavailableException) {
            $attempts = PaymentAttempt::query()->orderBy('id')->get();
            $this->assertSame(2, $attempts->count());
            $this->assertSame(['failed', 'failed'], $attempts->pluck('status')->all());
            $this->assertSame(['method_rejected', 'gateway_unavailable'], $attempts->pluck('failure_code')->all());
            $this->assertSame(500, $user->fresh()->wallet_balance);
            $this->assertDatabaseCount('transactions', 0);
        }

        try {
            $payments->startWalletTopUp($user, 2500, $key);
            $this->fail('A terminally failed top-up must not be initiated again with the same key.');
        } catch (PaymentConflictException) {
            $this->assertDatabaseCount('payment_attempts', 2);
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    public function test_verified_top_up_callback_credits_once_and_notifies_once_when_replayed(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $user = User::factory()->create(['wallet_balance' => 500]);
        $key = (string) Str::uuid();
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->andReturn(new GatewayPaymentResult('pending', 'provider-123', 'https://pay.example/123'));
        $gateway->shouldReceive('validateCallback')->twice();
        $gateway->shouldReceive('attemptReference')->twice()->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->once()
            ->andReturn(new GatewayPaymentResult('succeeded', 'verified-123'));
        $this->mockGatewayManager($gateway);
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->with(
                    Mockery::type(User::class),
                    Mockery::type('string'),
                    Mockery::type('string'),
                    Mockery::type('array'),
                );
        });
        $payments = $this->app->make(PaymentService::class);
        $attempt = $payments->startWalletTopUp($user, 2500, $key);
        $callback = Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]);

        $processed = $payments->processCallback('test-gateway', $callback);
        $replayed = $payments->processCallback('test-gateway', $callback);

        $this->assertSame($attempt->getKey(), $processed->getKey());
        $this->assertSame('succeeded', $processed->status);
        $this->assertSame($processed->getKey(), $replayed->getKey());
        $this->assertSame(3000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_failed_top_up_verification_does_not_credit_the_wallet(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $user = User::factory()->create(['wallet_balance' => 500]);
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->andReturn(new GatewayPaymentResult('pending', 'provider-123', 'https://pay.example/123'));
        $gateway->shouldReceive('validateCallback')->once();
        $gateway->shouldReceive('attemptReference')->once()->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->once()
            ->andReturn(new GatewayPaymentResult('failed', failureCode: 'payment_declined'));
        $this->mockGatewayManager($gateway);
        $payments = $this->app->make(PaymentService::class);
        $attempt = $payments->startWalletTopUp($user, 2500, (string) Str::uuid());

        $processed = $payments->processCallback('test-gateway', Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]));

        $this->assertSame('failed', $processed->status);
        $this->assertSame('payment_declined', $processed->failure_code);
        $this->assertSame(500, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_unknown_initiation_result_is_persisted_and_not_retried(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $primary = Mockery::mock(PaymentGatewayInterface::class);
        $primary->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayUnavailableException('Provider response was ambiguous.'));
        $backup = Mockery::mock(PaymentGatewayInterface::class);
        $backup->shouldNotReceive('initiate');
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['primary', 'backup']);
        $manager->shouldReceive('gateway')->once()->with('primary')->andReturn($primary);
        $manager->shouldNotReceive('gateway')->with('backup');
        $this->app->instance(PaymentGatewayManager::class, $manager);
        $payments = $this->app->make(PaymentService::class);

        try {
            $payments->startWalletTopUp($user, 2500, $key);
            $this->fail('An ambiguous gateway result must be reported to the caller.');
        } catch (PaymentGatewayUnavailableException) {
            $attempt = PaymentAttempt::query()->sole();
            $this->assertSame('initiation_unknown', $attempt->status);
            $this->assertSame('provider_result_unknown', $attempt->failure_code);
        }

        try {
            $payments->startWalletTopUp($user, 2500, $key);
            $this->fail('An ambiguous attempt must not initiate a potentially duplicate charge.');
        } catch (PaymentConflictException) {
            $this->assertDatabaseCount('payment_attempts', 1);
        }
    }

    public function test_valid_callback_recovers_ambiguous_wallet_top_up_once(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $user = User::factory()->create(['wallet_balance' => 500]);
        $key = (string) Str::uuid();
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayUnavailableException('Provider response was ambiguous.'));
        $gateway->shouldReceive('validateCallback')->twice();
        $gateway->shouldReceive('attemptReference')->twice()->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->once()
            ->andReturn(new GatewayPaymentResult('succeeded', 'verified-after-timeout'));
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['test-gateway']);
        $manager->shouldReceive('gateway')->once()->with('test-gateway')->andReturn($gateway);
        $manager->shouldReceive('gatewayForCallback')->twice()->with('test-gateway')->andReturn($gateway);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->once()->with(
                Mockery::type(User::class),
                Mockery::type('string'),
                Mockery::type('string'),
                Mockery::type('array'),
            );
        });
        $payments = $this->app->make(PaymentService::class);

        try {
            $payments->startWalletTopUp($user, 2500, $key);
            $this->fail('An ambiguous initiation result must be surfaced before callback recovery.');
        } catch (PaymentGatewayUnavailableException) {
            $attempt = PaymentAttempt::query()->sole();
            $this->assertSame('initiation_unknown', $attempt->status);
            $this->assertSame('provider_result_unknown', $attempt->failure_code);
        }

        $callback = Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]);
        $processed = $payments->processCallback('test-gateway', $callback);
        $replayed = $payments->processCallback('test-gateway', $callback);
        $settledAttempt = $attempt->fresh();

        $this->assertSame($attempt->getKey(), $processed->getKey());
        $this->assertSame($processed->getKey(), $replayed->getKey());
        $this->assertSame('succeeded', $settledAttempt->status);
        $this->assertNull($settledAttempt->failure_code);
        $this->assertNotNull($settledAttempt->verified_at);
        $this->assertSame(3000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_failed_callback_finalizes_ambiguous_wallet_top_up_without_credit_or_notification(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $user = User::factory()->create(['wallet_balance' => 500]);
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayUnavailableException('Provider response was ambiguous.'));
        $gateway->shouldReceive('validateCallback')->twice();
        $gateway->shouldReceive('attemptReference')->twice()->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->once()
            ->andReturn(new GatewayPaymentResult('failed', failureCode: 'payment_declined'));
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['test-gateway']);
        $manager->shouldReceive('gateway')->once()->with('test-gateway')->andReturn($gateway);
        $manager->shouldReceive('gatewayForCallback')->twice()->with('test-gateway')->andReturn($gateway);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        $push = $this->mock(ExpoPushService::class);
        $push->shouldNotReceive('sendToUser');
        $payments = $this->app->make(PaymentService::class);

        try {
            $payments->startWalletTopUp($user, 2500, (string) Str::uuid());
            $this->fail('An ambiguous initiation result must be surfaced before callback recovery.');
        } catch (PaymentGatewayUnavailableException) {
            $attempt = PaymentAttempt::query()->sole();
            $this->assertSame('initiation_unknown', $attempt->status);
        }

        $callback = Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]);
        $processed = $payments->processCallback('test-gateway', $callback);

        $this->assertSame('failed', $processed->status);
        $this->assertSame('payment_declined', $processed->failure_code);
        $this->assertSame(500, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('transactions', 0);

        try {
            $payments->processCallback('test-gateway', $callback);
            $this->fail('A failed attempt must not be verified or credited again.');
        } catch (PaymentConflictException) {
            $this->assertSame('failed', $attempt->fresh()->status);
            $this->assertSame(500, $user->fresh()->wallet_balance);
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    public function test_pending_callback_keeps_ambiguous_wallet_top_up_open_until_later_success(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $user = User::factory()->create(['wallet_balance' => 500]);
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayUnavailableException('Provider response was ambiguous.'));
        $gateway->shouldReceive('validateCallback')->times(3);
        $gateway->shouldReceive('attemptReference')->times(3)->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->twice()
            ->andReturn(
                new GatewayPaymentResult('pending'),
                new GatewayPaymentResult('succeeded', 'verified-after-pending'),
            );
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['test-gateway']);
        $manager->shouldReceive('gateway')->once()->with('test-gateway')->andReturn($gateway);
        $manager->shouldReceive('gatewayForCallback')->times(3)->with('test-gateway')->andReturn($gateway);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->once()->with(
                Mockery::type(User::class),
                Mockery::type('string'),
                Mockery::type('string'),
                Mockery::type('array'),
            );
        });
        $payments = $this->app->make(PaymentService::class);

        try {
            $payments->startWalletTopUp($user, 2500, (string) Str::uuid());
            $this->fail('An ambiguous initiation result must be surfaced before callback recovery.');
        } catch (PaymentGatewayUnavailableException) {
            $attempt = PaymentAttempt::query()->sole();
        }

        $callback = Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]);
        $pending = $payments->processCallback('test-gateway', $callback);

        $this->assertSame('initiation_unknown', $pending->status);
        $this->assertSame('provider_result_unknown', $pending->failure_code);
        $this->assertSame(500, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('transactions', 0);

        $processed = $payments->processCallback('test-gateway', $callback);
        $replayed = $payments->processCallback('test-gateway', $callback);

        $this->assertSame('succeeded', $processed->status);
        $this->assertSame($processed->getKey(), $replayed->getKey());
        $this->assertNull($processed->failure_code);
        $this->assertNotNull($processed->verified_at);
        $this->assertSame(3000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_subscription_payment_fails_over_after_a_definitive_gateway_rejection(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        $user = User::factory()->create(['wallet_balance' => 200]);
        $primary = Mockery::mock(PaymentGatewayInterface::class);
        $primary->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayRejectedException('Payment method rejected.', 'method_rejected'));
        $backup = Mockery::mock(PaymentGatewayInterface::class);
        $backup->shouldReceive('initiate')
            ->once()
            ->with(Mockery::on(static fn (PaymentRequest $request): bool => $request->amount === 800))
            ->andReturn(new GatewayPaymentResult('pending', 'backup-subscription', 'https://pay.example/backup'));

        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['primary', 'backup']);
        $manager->shouldReceive('gateway')->once()->with('primary')->andReturn($primary);
        $manager->shouldReceive('gateway')->once()->with('backup')->andReturn($backup);
        $this->app->instance(PaymentGatewayManager::class, $manager);

        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month');
        $order = $payments->purchasePremium($user, 'gold_1_month', null, (string) Str::uuid(), $quote);
        $attempts = $order->attempts()->orderBy('id')->get();

        $this->assertSame('pending', $order->status);
        $this->assertSame(800, $order->gateway_amount);
        $this->assertSame(0, $user->fresh()->wallet_balance);
        $this->assertSame(2, $attempts->count());
        $this->assertSame('failed', $attempts[0]->status);
        $this->assertSame('method_rejected', $attempts[0]->failure_code);
        $this->assertSame('pending', $attempts[1]->status);
        $this->assertSame('backup', $attempts[1]->gateway);
        $this->assertSame('backup-subscription', $attempts[1]->gateway_reference_id);
    }

    public function test_subscription_order_refunds_and_releases_discount_when_all_gateways_fail(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        $user = User::factory()->create(['wallet_balance' => 200]);
        $discount = DiscountCode::query()->create([
            'code' => 'FAILOVER100',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $key = (string) Str::uuid();
        $primary = Mockery::mock(PaymentGatewayInterface::class);
        $primary->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayRejectedException('Payment method rejected.', 'method_rejected'));
        $backup = Mockery::mock(PaymentGatewayInterface::class);
        $backup->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayRetryableException('Provider confirmed the request was not accepted.'));

        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['primary', 'backup']);
        $manager->shouldReceive('gateway')->once()->with('primary')->andReturn($primary);
        $manager->shouldReceive('gateway')->once()->with('backup')->andReturn($backup);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month', $discount->code);

        try {
            $payments->purchasePremium($user, 'gold_1_month', $discount->code, $key, $quote);
            $this->fail('A subscription order must fail when every configured gateway rejects initiation.');
        } catch (PaymentGatewayUnavailableException) {
            $order = PaymentOrder::query()->sole();
            $attempts = $order->attempts()->orderBy('id')->get();

            $this->assertSame('failed', $order->status);
            $this->assertSame('gateway_unavailable', $order->failure_code);
            $this->assertNotNull($order->completed_at);
            $this->assertNotNull($order->refund_transaction_id);
            $this->assertSame(200, $user->fresh()->wallet_balance);
            $this->assertSame(0, $discount->fresh()->usage_count);
            $this->assertSame(2, $attempts->count());
            $this->assertSame(['failed', 'failed'], $attempts->pluck('status')->all());
            $this->assertSame(['method_rejected', 'gateway_unavailable'], $attempts->pluck('failure_code')->all());
            $this->assertDatabaseHas('discount_usages', [
                'payment_order_id' => $order->getKey(),
                'status' => 'released',
            ]);
            $this->assertDatabaseCount('transactions', 2);
            $this->assertDatabaseCount('subscriptions', 0);
        }

        try {
            $payments->purchasePremium($user, 'gold_1_month', $discount->code, $key, $quote);
            $this->fail('A failed order must not create another payment attempt on replay.');
        } catch (PaymentConflictException) {
            $this->assertDatabaseCount('payment_orders', 1);
            $this->assertDatabaseCount('payment_attempts', 2);
            $this->assertDatabaseCount('transactions', 2);
            $this->assertSame(200, $user->fresh()->wallet_balance);
            $this->assertSame(0, $discount->fresh()->usage_count);
        }
    }

    public function test_discount_reservation_rolls_back_when_order_update_fails_and_same_key_can_retry(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        $user = User::factory()->create(['wallet_balance' => 0]);
        $discount = DiscountCode::query()->create([
            'code' => 'ROLLBACK100',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $key = (string) Str::uuid();
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->with(Mockery::on(static fn (PaymentRequest $request): bool => $request->amount === 900))
            ->andReturn(new GatewayPaymentResult('pending', 'retry-reference', 'https://pay.example/retry'));
        $this->mockGatewayManager($gateway);

        $failOrderUpdate = true;
        Event::listen('eloquent.updating: '.PaymentOrder::class, function (PaymentOrder $order) use (&$failOrderUpdate, $key): void {
            if ($order->idempotency_key === $key && $failOrderUpdate) {
                $failOrderUpdate = false;
                throw new RuntimeException('Simulated payment order update failure.');
            }
        });

        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month', $discount->code);

        try {
            $payments->purchasePremium($user, 'gold_1_month', $discount->code, $key, $quote);
            $this->fail('The simulated order update failure must abort checkout.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated payment order update failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertDatabaseCount('discount_usages', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertSame(0, $discount->fresh()->usage_count);
        $this->assertSame(0, $user->fresh()->wallet_balance);

        $order = $payments->purchasePremium($user, 'gold_1_month', $discount->code, $key, $quote);

        $this->assertSame('pending', $order->status);
        $this->assertSame(900, $order->gateway_amount);
        $this->assertSame(1, PaymentOrder::query()->count());
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(1, $discount->fresh()->usage_count);
        $this->assertDatabaseHas('discount_usages', [
            'payment_order_id' => $order->getKey(),
            'discount_code_id' => $discount->getKey(),
            'status' => 'reserved',
        ]);
    }

    public function test_ambiguous_subscription_initiation_does_not_fail_over_or_initiate_again_on_replay(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        $user = User::factory()->create(['wallet_balance' => 200]);
        $key = (string) Str::uuid();
        $primary = Mockery::mock(PaymentGatewayInterface::class);
        $primary->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayUnavailableException('Provider response was ambiguous.'));
        $backup = Mockery::mock(PaymentGatewayInterface::class);
        $backup->shouldNotReceive('initiate');
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->twice()->andReturn(['primary', 'backup']);
        $manager->shouldReceive('gateway')->twice()->with('primary')->andReturn($primary);
        $manager->shouldNotReceive('gateway')->with('backup');
        $this->app->instance(PaymentGatewayManager::class, $manager);

        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month');
        try {
            $payments->purchasePremium($user, 'gold_1_month', null, $key, $quote);
            $this->fail('An ambiguous gateway result must be reported to the caller.');
        } catch (PaymentGatewayUnavailableException) {
            $order = PaymentOrder::query()->sole();
            $attempt = $order->attempts()->sole();
            $this->assertSame('pending', $order->status);
            $this->assertSame('initiation_unknown', $attempt->status);
            $this->assertSame('provider_result_unknown', $attempt->failure_code);
            $this->assertSame(0, $user->fresh()->wallet_balance);
        }

        try {
            $payments->purchasePremium($user, 'gold_1_month', null, $key, $quote);
            $this->fail('An ambiguous attempt must not initiate a potentially duplicate charge.');
        } catch (PaymentGatewayUnavailableException) {
            $this->assertDatabaseCount('payment_orders', 1);
            $this->assertDatabaseCount('payment_attempts', 1);
        }
    }

    public function test_valid_callback_recovers_ambiguous_subscription_payment_once(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        Event::fake([SubscriptionActivated::class]);
        $user = User::factory()->create(['wallet_balance' => 200]);
        $discount = DiscountCode::query()->create([
            'code' => 'RECOVERY100',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $key = (string) Str::uuid();
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->with(Mockery::on(static fn (PaymentRequest $request): bool => $request->amount === 700))
            ->andThrow(new PaymentGatewayUnavailableException('Provider response was ambiguous.'));
        $gateway->shouldReceive('validateCallback')->twice();
        $gateway->shouldReceive('attemptReference')->twice()->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->once()
            ->andReturn(new GatewayPaymentResult('succeeded', 'verified-subscription-after-timeout'));
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['test-gateway']);
        $manager->shouldReceive('gateway')->once()->with('test-gateway')->andReturn($gateway);
        $manager->shouldReceive('gatewayForCallback')->twice()->with('test-gateway')->andReturn($gateway);
        $this->app->instance(PaymentGatewayManager::class, $manager);

        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month', $discount->code);
        try {
            $payments->purchasePremium($user, 'gold_1_month', $discount->code, $key, $quote);
            $this->fail('An ambiguous initiation result must be surfaced before callback recovery.');
        } catch (PaymentGatewayUnavailableException) {
            $order = PaymentOrder::query()->sole();
            $attempt = $order->attempts()->sole();
            $this->assertSame('pending', $order->status);
            $this->assertSame('initiation_unknown', $attempt->status);
            $this->assertSame('provider_result_unknown', $attempt->failure_code);
            $this->assertSame(0, $user->fresh()->wallet_balance);
            $this->assertSame('reserved', $order->discountCode->usages()->sole()->status);
        }

        $callback = Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]);
        $processed = $payments->processCallback('test-gateway', $callback);
        $replayed = $payments->processCallback('test-gateway', $callback);
        $settledOrder = $order->fresh();
        $settledAttempt = $attempt->fresh();

        $this->assertSame($attempt->getKey(), $processed->getKey());
        $this->assertSame($processed->getKey(), $replayed->getKey());
        $this->assertSame('succeeded', $settledAttempt->status);
        $this->assertNull($settledAttempt->failure_code);
        $this->assertNotNull($settledAttempt->verified_at);
        $this->assertSame('succeeded', $settledOrder->status);
        $this->assertSame(700, $settledOrder->gateway_amount);
        $this->assertNotNull($settledOrder->subscription_id);
        $this->assertSame('active', $user->subscriptions()->sole()->status);
        $this->assertSame(0, $user->fresh()->wallet_balance);
        $this->assertSame(1, $discount->fresh()->usage_count);
        $this->assertSame('consumed', $order->discountCode->usages()->sole()->status);
        $this->assertDatabaseCount('payment_orders', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('transactions', 2);
        Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
    }

    public function test_discount_consumption_failure_rolls_back_callback_settlement_and_allows_retry(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        Event::fake([SubscriptionActivated::class]);
        $user = User::factory()->create(['wallet_balance' => 0]);
        $discount = DiscountCode::query()->create([
            'code' => 'CONSUME-ROLLBACK',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayUnavailableException('Provider response was ambiguous.'));
        $gateway->shouldReceive('validateCallback')->twice();
        $gateway->shouldReceive('attemptReference')->twice()->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->twice()
            ->andReturn(new GatewayPaymentResult('succeeded', 'verified-after-consume-failure'));
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['test-gateway']);
        $manager->shouldReceive('gateway')->once()->with('test-gateway')->andReturn($gateway);
        $manager->shouldReceive('gatewayForCallback')->twice()->with('test-gateway')->andReturn($gateway);
        $this->app->instance(PaymentGatewayManager::class, $manager);

        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month', $discount->code);
        try {
            $payments->purchasePremium($user, 'gold_1_month', $discount->code, (string) Str::uuid(), $quote);
            $this->fail('The ambiguous gateway result must be surfaced before callback settlement.');
        } catch (PaymentGatewayUnavailableException) {
            $order = PaymentOrder::query()->sole();
            $attempt = $order->attempts()->sole();
        }

        $usage = $order->discountCode->usages()->sole();
        $this->assertSame('reserved', $usage->status);

        $databaseFailure = 'Simulated discount consumption update failure.';
        $failureInjected = false;
        Event::listen(
            'eloquent.updating: '.DiscountUsage::class,
            function (DiscountUsage $candidate) use (&$failureInjected, $databaseFailure, $usage): void {
                if (
                    ! $failureInjected
                    && $candidate->getKey() === $usage->getKey()
                    && $candidate->status === 'consumed'
                ) {
                    $failureInjected = true;
                    throw new RuntimeException($databaseFailure);
                }
            },
        );
        $callback = Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]);

        try {
            $payments->processCallback('test-gateway', $callback);
            $this->fail('The simulated discount consumption failure must abort payment settlement.');
        } catch (RuntimeException $exception) {
            $this->assertSame($databaseFailure, $exception->getMessage());
        }

        $this->assertTrue($failureInjected, 'The failure hook must target discount consumption.');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->transaction_id);
        $this->assertNull($order->fresh()->subscription_id);
        $this->assertNull($order->fresh()->completed_at);
        $this->assertSame('initiation_unknown', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_id);
        $this->assertNull($attempt->fresh()->verified_at);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, $user->subscriptions()->count());
        $this->assertSame('reserved', $usage->fresh()->status);
        $this->assertSame(1, $discount->fresh()->usage_count);
        Event::assertNotDispatched(SubscriptionActivated::class);

        $processed = $payments->processCallback('test-gateway', $callback);

        $this->assertSame($attempt->getKey(), $processed->getKey());
        $this->assertSame('succeeded', $processed->fresh()->status);
        $this->assertSame('succeeded', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->transaction_id);
        $this->assertNotNull($order->fresh()->subscription_id);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, $user->subscriptions()->count());
        $this->assertSame('consumed', $usage->fresh()->status);
        $this->assertSame($processed->fresh()->transaction_id, $usage->fresh()->transaction_id);
        $this->assertSame($processed->getKey(), $usage->fresh()->payment_attempt_id);
        $this->assertSame(1, $discount->fresh()->usage_count);
        Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
    }

    public function test_failed_callback_refunds_ambiguous_subscription_and_releases_discount_once(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        $user = User::factory()->create(['wallet_balance' => 200]);
        $discount = DiscountCode::query()->create([
            'code' => 'UNKNOWNFAIL100',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayUnavailableException('Provider response was ambiguous.'));
        $gateway->shouldReceive('validateCallback')->twice();
        $gateway->shouldReceive('attemptReference')->twice()->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->once()
            ->andReturn(new GatewayPaymentResult('failed', failureCode: 'payment_declined'));
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['test-gateway']);
        $manager->shouldReceive('gateway')->once()->with('test-gateway')->andReturn($gateway);
        $manager->shouldReceive('gatewayForCallback')->twice()->with('test-gateway')->andReturn($gateway);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month', $discount->code);

        try {
            $payments->purchasePremium($user, 'gold_1_month', $discount->code, (string) Str::uuid(), $quote);
            $this->fail('An ambiguous initiation result must be surfaced before callback recovery.');
        } catch (PaymentGatewayUnavailableException) {
            $order = PaymentOrder::query()->sole();
            $attempt = $order->attempts()->sole();
            $this->assertSame('pending', $order->status);
            $this->assertSame('initiation_unknown', $attempt->status);
            $this->assertSame('reserved', $order->discountCode->usages()->sole()->status);
        }

        $callback = Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]);
        $processed = $payments->processCallback('test-gateway', $callback);
        $failedOrder = $order->fresh();
        $usage = $discount->usages()->sole();

        $this->assertSame('failed', $processed->status);
        $this->assertSame('payment_declined', $processed->failure_code);
        $this->assertSame('failed', $failedOrder->status);
        $this->assertSame('payment_declined', $failedOrder->failure_code);
        $this->assertNotNull($failedOrder->completed_at);
        $this->assertNotNull($failedOrder->refund_transaction_id);
        $this->assertSame(200, $user->fresh()->wallet_balance);
        $this->assertSame(0, $discount->fresh()->usage_count);
        $this->assertSame('released', $usage->status);
        $this->assertSame(2, Transaction::query()->count());
        $this->assertDatabaseCount('subscriptions', 0);

        try {
            $payments->processCallback('test-gateway', $callback);
            $this->fail('A failed subscription payment must not be verified or refunded again.');
        } catch (PaymentConflictException) {
            $this->assertSame('failed', $attempt->fresh()->status);
            $this->assertSame('failed', $order->fresh()->status);
            $this->assertSame(200, $user->fresh()->wallet_balance);
            $this->assertSame(0, $discount->fresh()->usage_count);
            $this->assertSame('released', $usage->fresh()->status);
            $this->assertSame(2, Transaction::query()->count());
            $this->assertDatabaseCount('subscriptions', 0);
        }
    }

    public function test_discount_release_failure_rolls_back_callback_failure_refund_and_allows_retry(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        $user = User::factory()->create(['wallet_balance' => 200]);
        $discount = DiscountCode::query()->create([
            'code' => 'RELEASE-ROLLBACK',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayUnavailableException('Provider response was ambiguous.'));
        $gateway->shouldReceive('validateCallback')->times(3);
        $gateway->shouldReceive('attemptReference')->times(3)->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->twice()
            ->andReturn(new GatewayPaymentResult('failed', failureCode: 'payment_declined'));
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['test-gateway']);
        $manager->shouldReceive('gateway')->once()->with('test-gateway')->andReturn($gateway);
        $manager->shouldReceive('gatewayForCallback')->times(3)->with('test-gateway')->andReturn($gateway);
        $this->app->instance(PaymentGatewayManager::class, $manager);

        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month', $discount->code);
        try {
            $payments->purchasePremium($user, 'gold_1_month', $discount->code, (string) Str::uuid(), $quote);
            $this->fail('The ambiguous gateway result must be surfaced before callback recovery.');
        } catch (PaymentGatewayUnavailableException) {
            $order = PaymentOrder::query()->sole();
            $attempt = $order->attempts()->sole();
        }

        $usage = $order->discountCode->usages()->sole();
        $this->assertSame(1, $discount->fresh()->usage_count);
        $this->assertSame('reserved', $usage->status);

        $databaseFailure = 'Simulated discount release update failure.';
        $failureInjected = false;
        Event::listen(
            'eloquent.updating: '.DiscountUsage::class,
            function (DiscountUsage $candidate) use (&$failureInjected, $databaseFailure, $usage): void {
                if (
                    ! $failureInjected
                    && $candidate->getKey() === $usage->getKey()
                    && $candidate->status === 'released'
                ) {
                    $failureInjected = true;
                    throw new RuntimeException($databaseFailure);
                }
            },
        );
        $callback = Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]);

        try {
            $payments->processCallback('test-gateway', $callback);
            $this->fail('The simulated discount release failure must abort callback finalization.');
        } catch (RuntimeException $exception) {
            $this->assertSame($databaseFailure, $exception->getMessage());
        }

        $this->assertTrue($failureInjected, 'The failure hook must target discount release.');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->refund_transaction_id);
        $this->assertSame('initiation_unknown', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_id);
        $this->assertSame(0, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame('reserved', $usage->fresh()->status);
        $this->assertSame(1, $discount->fresh()->usage_count);
        $this->assertSame(0, $user->subscriptions()->count());

        $failedAttempt = $payments->processCallback('test-gateway', $callback);

        $this->assertSame('failed', $failedAttempt->fresh()->status);
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->refund_transaction_id);
        $this->assertSame(200, $user->fresh()->wallet_balance);
        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame('released', $usage->fresh()->status);
        $this->assertSame(0, $discount->fresh()->usage_count);

        try {
            $payments->processCallback('test-gateway', $callback);
            $this->fail('A failed callback must not repeat refund or reservation release.');
        } catch (PaymentConflictException) {
            $this->assertSame(200, $user->fresh()->wallet_balance);
            $this->assertSame(2, Transaction::query()->count());
            $this->assertSame('released', $usage->fresh()->status);
            $this->assertSame(0, $discount->fresh()->usage_count);
        }
    }

    public function test_pending_callback_keeps_ambiguous_subscription_open_until_later_success(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        Event::fake([SubscriptionActivated::class]);
        $user = User::factory()->create(['wallet_balance' => 200]);
        $discount = DiscountCode::query()->create([
            'code' => 'PENDING-RESERVED',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $key = (string) Str::uuid();
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->andThrow(new PaymentGatewayUnavailableException('Provider response was ambiguous.'));
        $gateway->shouldReceive('validateCallback')->times(3);
        $gateway->shouldReceive('attemptReference')->times(3)->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->twice()
            ->andReturn(
                new GatewayPaymentResult('pending'),
                new GatewayPaymentResult('succeeded', 'verified-subscription-after-pending'),
            );
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->once()->andReturn(['test-gateway']);
        $manager->shouldReceive('gateway')->once()->with('test-gateway')->andReturn($gateway);
        $manager->shouldReceive('gatewayForCallback')->times(3)->with('test-gateway')->andReturn($gateway);
        $this->app->instance(PaymentGatewayManager::class, $manager);

        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month', $discount->code);
        try {
            $payments->purchasePremium($user, 'gold_1_month', $discount->code, $key, $quote);
            $this->fail('An ambiguous initiation result must be surfaced before callback recovery.');
        } catch (PaymentGatewayUnavailableException) {
            $order = PaymentOrder::query()->sole();
            $attempt = $order->attempts()->sole();
        }

        $callback = Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]);
        $pending = $payments->processCallback('test-gateway', $callback);

        $this->assertSame('initiation_unknown', $pending->status);
        $this->assertSame('provider_result_unknown', $pending->failure_code);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertSame(1, $discount->fresh()->usage_count);
        $this->assertSame('reserved', $order->discountCode->usages()->sole()->status);

        $processed = $payments->processCallback('test-gateway', $callback);
        $replayed = $payments->processCallback('test-gateway', $callback);
        $settledOrder = $order->fresh();

        $this->assertSame('succeeded', $processed->status);
        $this->assertSame($processed->getKey(), $replayed->getKey());
        $this->assertNull($processed->failure_code);
        $this->assertSame('succeeded', $settledOrder->status);
        $this->assertNotNull($settledOrder->subscription_id);
        $this->assertSame('active', $user->subscriptions()->sole()->status);
        $this->assertSame(0, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_orders', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertSame(1, $discount->fresh()->usage_count);
        $this->assertSame('consumed', $order->discountCode->usages()->sole()->status);
        Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
    }

    public function test_mixed_subscription_purchase_uses_wallet_then_gateway_and_activates_once_on_callback(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        Event::fake([SubscriptionActivated::class]);
        $user = User::factory()->create(['wallet_balance' => 300]);
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->with(Mockery::on(static fn (PaymentRequest $request): bool => $request->amount === 700))
            ->andReturn(new GatewayPaymentResult('pending', 'provider-subscription', 'https://pay.example/subscription'));
        $gateway->shouldReceive('validateCallback')->twice();
        $gateway->shouldReceive('attemptReference')->twice()->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->once()
            ->andReturn(new GatewayPaymentResult('succeeded', 'verified-subscription'));
        $this->mockGatewayManager($gateway);
        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month');
        $key = (string) Str::uuid();

        $order = $payments->purchasePremium($user, 'gold_1_month', null, $key, $quote);
        $replayedOrder = $payments->purchasePremium($user, 'gold_1_month', null, $key, $quote);

        $this->assertSame($order->getKey(), $replayedOrder->getKey());
        $this->assertSame('pending', $order->status);
        $this->assertSame(1000, $order->gross_amount);
        $this->assertSame(300, $order->wallet_amount);
        $this->assertSame(700, $order->gateway_amount);
        $this->assertSame(0, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_orders', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('subscriptions', 0);

        $attempt = $order->attempts()->sole();
        $callback = Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]);
        $processed = $payments->processCallback('test-gateway', $callback);
        $replayedCallback = $payments->processCallback('test-gateway', $callback);

        $succeededOrder = PaymentOrder::query()->findOrFail($order->getKey());
        $this->assertSame('succeeded', $processed->status);
        $this->assertSame($processed->getKey(), $replayedCallback->getKey());
        $this->assertSame('succeeded', $succeededOrder->status);
        $this->assertNotNull($succeededOrder->subscription_id);
        $this->assertSame(1, $user->subscriptions()->count());
        $this->assertSame('active', $user->subscriptions()->sole()->status);
        $this->assertSame(0, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('transactions', 2);
        Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
    }

    public function test_wallet_only_subscription_purchase_activates_without_gateway_attempt(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        Event::fake([SubscriptionActivated::class]);
        $user = User::factory()->create(['wallet_balance' => 1500]);
        $gatewayManager = Mockery::mock(PaymentGatewayManager::class);
        $gatewayManager->shouldNotReceive('purchaseOrder');
        $this->app->instance(PaymentGatewayManager::class, $gatewayManager);
        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month');

        $order = $payments->purchasePremium(
            $user,
            'gold_1_month',
            null,
            (string) Str::uuid(),
            $quote,
        );

        $this->assertSame('succeeded', $order->status);
        $this->assertSame(1000, $order->wallet_amount);
        $this->assertSame(0, $order->gateway_amount);
        $this->assertSame(500, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('subscriptions', 1);
        Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
    }

    public function test_full_discount_subscription_purchase_activates_once_without_payment_attempt_or_transaction(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        Event::fake([SubscriptionActivated::class]);
        $user = User::factory()->create(['wallet_balance' => 500]);
        $discount = DiscountCode::query()->create([
            'code' => 'FULL100',
            'type' => 'percentage',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 1,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $gatewayManager = Mockery::mock(PaymentGatewayManager::class);
        $gatewayManager->shouldNotReceive('purchaseOrder');
        $gatewayManager->shouldNotReceive('gateway');
        $gatewayManager->shouldNotReceive('gatewayForCallback');
        $this->app->instance(PaymentGatewayManager::class, $gatewayManager);

        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month', $discount->code);
        $key = (string) Str::uuid();
        $order = $payments->purchasePremium($user, 'gold_1_month', $discount->code, $key, $quote);
        $replayedOrder = $payments->purchasePremium($user, 'gold_1_month', $discount->code, $key, $quote);
        $usage = $discount->usages()->sole();

        $this->assertSame(1000, $quote['gross_amount']);
        $this->assertSame(0, $quote['payable_amount']);
        $this->assertSame($order->getKey(), $replayedOrder->getKey());
        $this->assertSame('succeeded', $order->status);
        $this->assertSame(1000, $order->gross_amount);
        $this->assertSame(1000, $order->discount_amount);
        $this->assertSame(0, $order->wallet_amount);
        $this->assertSame(0, $order->gateway_amount);
        $this->assertSame(500, $user->fresh()->wallet_balance);
        $this->assertNotNull($order->subscription_id);
        $this->assertSame('active', $user->subscriptions()->sole()->status);
        $this->assertSame(1, PaymentOrder::query()->count());
        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(1, $user->subscriptions()->count());
        $this->assertSame(1, $discount->fresh()->usage_count);
        $this->assertSame('consumed', $usage->status);
        $this->assertNull($usage->transaction_id);
        $this->assertNull($usage->payment_attempt_id);
        Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
    }

    public function test_subscription_quote_reservation_and_gateway_amount_use_the_same_rounded_discount(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(999);
        $user = User::factory()->create(['wallet_balance' => 0]);
        $discount = DiscountCode::query()->create([
            'code' => 'ROUND15',
            'type' => 'percentage',
            'value' => 15,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->with(Mockery::on(static fn (PaymentRequest $request): bool => $request->amount === 850))
            ->andReturn(new GatewayPaymentResult('pending', 'rounded-order', 'https://pay.example/rounded'));
        $this->mockGatewayManager($gateway);
        $payments = $this->app->make(PaymentService::class);

        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month', $discount->code);
        $order = $payments->purchasePremium(
            $user,
            'gold_1_month',
            $discount->code,
            (string) Str::uuid(),
            $quote,
        );
        $usage = $discount->usages()->sole();

        $this->assertSame(999, $quote['gross_amount']);
        $this->assertSame(149, $quote['discount_amount']);
        $this->assertSame(850, $quote['payable_amount']);
        $this->assertSame($quote['gross_amount'], $order->gross_amount);
        $this->assertSame($quote['discount_amount'], $order->discount_amount);
        $this->assertSame($quote['payable_amount'], $order->gateway_amount);
        $this->assertSame($order->gross_amount, $usage->order_amount);
        $this->assertSame($order->discount_amount, $usage->discount_amount);
        $this->assertSame('reserved', $usage->status);
        $this->assertSame(1, $discount->fresh()->usage_count);
    }

    public function test_failed_subscription_payment_refunds_wallet_and_releases_discount_once(): void
    {
        config(['payment.max_top_up' => 1000000, 'payment.environment' => 'sandbox']);
        $this->configureOfferPrice(1000);
        $user = User::factory()->create(['wallet_balance' => 200]);
        $discount = DiscountCode::query()->create([
            'code' => 'MIXED100',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->once()
            ->with(Mockery::on(static fn (PaymentRequest $request): bool => $request->amount === 700))
            ->andReturn(new GatewayPaymentResult('pending', 'provider-failed', 'https://pay.example/failed'));
        $gateway->shouldReceive('validateCallback')->twice();
        $gateway->shouldReceive('attemptReference')->twice()->andReturnUsing(
            static fn (Request $request): string => (string) $request->input('merchant_reference'),
        );
        $gateway->shouldReceive('verify')
            ->once()
            ->andReturn(new GatewayPaymentResult('failed', failureCode: 'payment_declined'));
        $this->mockGatewayManager($gateway);
        $payments = $this->app->make(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month', $discount->code);
        $order = $payments->purchasePremium(
            $user,
            'gold_1_month',
            $discount->code,
            (string) Str::uuid(),
            $quote,
        );

        $this->assertSame(200, $order->wallet_amount);
        $this->assertSame(700, $order->gateway_amount);
        $this->assertSame(0, $user->fresh()->wallet_balance);
        $this->assertSame(1, $discount->fresh()->usage_count);

        $attempt = $order->attempts()->sole();
        $callback = Request::create('/callback', 'POST', [
            'merchant_reference' => $attempt->merchant_reference,
        ]);
        $processed = $payments->processCallback('test-gateway', $callback);
        $failedOrder = PaymentOrder::query()->findOrFail($order->getKey());

        $this->assertSame('failed', $processed->status);
        $this->assertSame('failed', $failedOrder->status);
        $this->assertSame('payment_declined', $failedOrder->failure_code);
        $this->assertNotNull($failedOrder->refund_transaction_id);
        $this->assertSame(200, $user->fresh()->wallet_balance);
        $this->assertSame(0, $discount->fresh()->usage_count);
        $this->assertDatabaseHas('discount_usages', [
            'payment_order_id' => $order->getKey(),
            'status' => 'released',
        ]);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('transactions', 2);

        try {
            $payments->processCallback('test-gateway', $callback);
            $this->fail('A terminal failed payment callback must not be reprocessed.');
        } catch (PaymentConflictException) {
            $this->assertSame(200, $user->fresh()->wallet_balance);
            $this->assertDatabaseCount('transactions', 2);
        }
    }

    private function configureOfferPrice(int $price): void
    {
        AdminSetting::query()->create([
            'setting_key' => 'plans',
            'value' => ['offers' => ['gold_1_month' => ['price' => $price]]],
        ]);
        Cache::forget('admin-runtime-settings:v2:plans');
    }

    private function mockGatewayManager(PaymentGatewayInterface $gateway): void
    {
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->andReturn(['test-gateway']);
        $manager->shouldReceive('gateway')->with('test-gateway')->andReturn($gateway);
        $manager->shouldReceive('gatewayForCallback')->with('test-gateway')->andReturn($gateway);

        $this->app->instance(PaymentGatewayManager::class, $manager);
    }
}
