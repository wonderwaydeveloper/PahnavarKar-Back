<?php

namespace Tests\Feature;

use App\Exceptions\DiscountNotValidException;
use App\Models\DiscountCode;
use App\Models\DiscountUsage;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DiscountService;
use App\Services\Payments\PaymentService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpireStaleSubscriptionOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00'));
    }

    public function test_expired_order_is_refunded_only_after_all_gateway_attempts_are_confirmed_failed(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $discount = DiscountCode::query()->create([
            'code' => 'EXPIRED20',
            'type' => 'fixed',
            'value' => 200,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 1,
            'is_active' => true,
        ]);
        $failedOrder = $this->createOrder($user, walletAmount: 200, discount: $discount);
        $this->createAttempt($user, $failedOrder, 'failed');

        $pendingUser = User::factory()->create(['wallet_balance' => 0]);
        $pendingOrder = $this->createOrder($pendingUser, walletAmount: 0);
        $primaryAttempt = $this->createAttempt($pendingUser, $pendingOrder, 'pending');
        $backupAttempt = $this->createAttempt($pendingUser, $pendingOrder, 'pending');
        app(PaymentService::class)
            ->failConfirmedGatewayAttempt((int) $primaryAttempt->getKey(), 'inquiry_failed');

        $this->travel(2)->days();
        $this->artisan('payments:expire-stale-subscription-orders')
            ->expectsOutput('Expired 1 confirmed failed subscription order(s).')
            ->assertExitCode(0);

        $this->assertSame('failed', $failedOrder->fresh()->status);
        $this->assertSame('checkout_expired', $failedOrder->fresh()->failure_code);
        $this->assertSame(200, $user->fresh()->wallet_balance);
        $this->assertSame('released', $discount->usages()->sole()->status);
        $this->assertSame(0, $discount->fresh()->usage_count);
        $this->assertSame(2, Transaction::query()->where('user_id', $user->getKey())->count());

        $this->assertSame('pending', $pendingOrder->fresh()->status);
        $this->assertSame(
            ['failed', 'pending'],
            $pendingOrder->attempts()->orderBy('id')->pluck('status')->all(),
        );
        $this->assertSame('pending', $backupAttempt->fresh()->status);
        $this->assertSame(0, $pendingUser->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->where('user_id', $pendingUser->getKey())->count());
    }

    public function test_expired_order_cleanup_is_scheduled_every_ten_minutes_with_overlap_protection(): void
    {
        $this->artisan('schedule:list');
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event) => str_contains($event->command ?? '', 'payments:expire-stale-subscription-orders'),
        );

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(1440, $event->expiresAt);
    }

    public function test_ambiguous_expired_order_keeps_discount_capacity_reserved(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $discount = DiscountCode::query()->create([
            'code' => 'UNKNOWN20',
            'type' => 'fixed',
            'value' => 200,
            'min_order_amount' => 0,
            'usage_limit' => 1,
            'usage_count' => 1,
            'is_active' => true,
        ]);
        $order = $this->createOrder($user, walletAmount: 0, discount: $discount);
        $attempt = $this->createAttempt($user, $order, 'initiation_unknown');
        $attempt->forceFill(['gateway_reference_id' => null])->saveOrFail();

        $this->travel(2)->days();
        $this->artisan('payments:expire-stale-subscription-orders')
            ->expectsOutput('Expired 0 confirmed failed subscription order(s).')
            ->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('initiation_unknown', $attempt->fresh()->status);
        $this->assertSame('reserved', $discount->usages()->sole()->status);
        $this->assertSame(1, $discount->fresh()->usage_count);

        try {
            app(DiscountService::class)->quote(
                User::factory()->create(),
                $discount->code,
                1000,
            );
            $this->fail('The unresolved reservation must continue to consume the final discount capacity.');
        } catch (DiscountNotValidException $exception) {
            $this->assertSame('ظرفیت استفاده از این کد تخفیف تکمیل شده است.', $exception->getMessage());
        }
    }

    private function createOrder(User $user, int $walletAmount, ?DiscountCode $discount = null): PaymentOrder
    {
        $reference = (string) Str::uuid();
        $order = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => $reference,
            'kind' => 'subscription',
            'subscription_change_type' => 'purchase',
            'plan_key' => 'gold_1_month',
            'duration_days' => 30,
            'discount_code_id' => $discount?->getKey(),
            'discount_amount' => $discount ? 200 : 0,
            'gross_amount' => 1000,
            'wallet_amount' => $walletAmount,
            'gateway_amount' => 800 - $walletAmount,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->subMinute(),
        ]);

        if ($walletAmount > 0) {
            $debit = $user->transactions()->create([
                'type' => 'debit',
                'status' => 'succeeded',
                'amount' => $walletAmount,
                'balance_after' => (int) $user->wallet_balance,
                'currency' => 'TOMAN',
                'reference_id' => $reference,
                'idempotency_key' => (string) Str::uuid(),
                'expires_at' => now()->addDay(),
            ]);
            $order->forceFill(['wallet_transaction_id' => $debit->getKey()])->saveOrFail();
        }

        if ($discount) {
            DiscountUsage::query()->create([
                'discount_code_id' => $discount->getKey(),
                'user_id' => $user->getKey(),
                'payment_order_id' => $order->getKey(),
                'order_amount' => 1000,
                'discount_amount' => 200,
                'status' => 'reserved',
            ]);
        }

        return $order;
    }

    private function createAttempt(User $user, PaymentOrder $order, string $status): PaymentAttempt
    {
        return PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'payment_order_id' => $order->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'zarinpal',
            'gateway_environment' => 'sandbox',
            'amount' => $order->gateway_amount,
            'currency' => 'TOMAN',
            'status' => $status,
            'gateway_reference_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->subMinute(),
        ]);
    }
}
