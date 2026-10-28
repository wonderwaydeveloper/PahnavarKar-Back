<?php

namespace Tests\Feature;

use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_owner_can_read_payment_order_with_only_allowlisted_payment_details(): void
    {
        [$owner, $order, $attempt, $walletTransaction] = $this->createPaymentResources();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/auth/payment-orders/'.$order->getKey())
            ->assertOk()
            ->assertJsonPath('order.id', $order->getKey())
            ->assertJsonPath('order.gross_amount', 1000)
            ->assertJsonPath('payments.0.id', $attempt->getKey())
            ->assertJsonPath('payments.0.gateway', 'test-gateway')
            ->assertJsonPath('wallet_transaction.id', $walletTransaction->getKey())
            ->assertJsonPath('wallet_transaction.amount', 300)
            ->assertJsonStructure([
                'order' => [
                    'id',
                    'reference',
                    'kind',
                    'status',
                    'gross_amount',
                    'discount_amount',
                    'wallet_amount',
                    'gateway_amount',
                    'currency',
                    'expires_at',
                    'completed_at',
                ],
                'payments' => [
                    ['id', 'gateway', 'status', 'redirect_url'],
                ],
                'subscription',
                'transaction',
                'wallet_transaction' => [
                    'id',
                    'type',
                    'status',
                    'amount',
                    'currency',
                    'created_at',
                ],
                'refund_transaction',
            ])
            ->assertJsonMissingPath('order.idempotency_key')
            ->assertJsonMissingPath('payments.0.idempotency_key')
            ->assertJsonMissingPath('payments.0.merchant_reference')
            ->assertJsonMissingPath('payments.0.gateway_reference_id')
            ->assertJsonMissingPath('wallet_transaction.idempotency_key')
            ->assertJsonMissingPath('wallet_transaction.reference_id')
            ->assertJsonMissingPath('wallet_transaction.description');
    }

    public function test_owner_can_read_payment_attempt_without_gateway_or_idempotency_secrets(): void
    {
        [$owner, , $attempt] = $this->createPaymentResources();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/auth/payments/'.$attempt->getKey())
            ->assertOk()
            ->assertJsonPath('payment.id', $attempt->getKey())
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.amount', 700)
            ->assertJsonPath('payment.currency', 'TOMAN')
            ->assertJsonPath('payment.redirect_url', 'https://pay.example/owner-only')
            ->assertJsonStructure([
                'payment' => ['id', 'status', 'amount', 'currency', 'redirect_url', 'expires_at'],
            ])
            ->assertJsonMissingPath('payment.idempotency_key')
            ->assertJsonMissingPath('payment.merchant_reference')
            ->assertJsonMissingPath('payment.gateway_reference_id')
            ->assertJsonMissingPath('payment.failure_code')
            ->assertJsonMissingPath('payment.transaction_id')
            ->assertJsonMissingPath('payment.user_id');
    }

    public function test_guests_and_other_users_cannot_read_payment_orders_or_attempts(): void
    {
        [$owner, $order, $attempt] = $this->createPaymentResources();
        $otherUser = User::factory()->create(['is_active' => true]);
        $orderCount = PaymentOrder::query()->count();
        $attemptCount = PaymentAttempt::query()->count();
        $transactionCount = Transaction::query()->count();

        $this->getJson('/api/v1/auth/payment-orders/'.$order->getKey())
            ->assertUnauthorized();
        $this->getJson('/api/v1/auth/payments/'.$attempt->getKey())
            ->assertUnauthorized();

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/auth/payment-orders/'.$order->getKey())
            ->assertNotFound();
        $this->getJson('/api/v1/auth/payments/'.$attempt->getKey())
            ->assertNotFound();

        $this->assertSame($owner->getKey(), $order->fresh()->user_id);
        $this->assertSame($owner->getKey(), $attempt->fresh()->user_id);
        $this->assertSame($orderCount, PaymentOrder::query()->count());
        $this->assertSame($attemptCount, PaymentAttempt::query()->count());
        $this->assertSame($transactionCount, Transaction::query()->count());
    }

    public function test_pending_subscription_order_is_limited_to_the_owner_and_returns_null_when_absent(): void
    {
        $owner = User::factory()->create(['is_active' => true, 'wallet_balance' => 1000]);
        $otherUser = User::factory()->create(['is_active' => true]);
        $paymentOrderCount = PaymentOrder::query()->count();
        $paymentAttemptCount = PaymentAttempt::query()->count();
        $transactionCount = Transaction::query()->count();

        $this->getJson('/api/v1/auth/subscription/pending-order')
            ->assertUnauthorized();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/auth/subscription/pending-order')
            ->assertOk()
            ->assertExactJson(['order' => null]);

        $order = PaymentOrder::query()->create([
            'user_id' => $owner->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'subscription_change_type' => 'purchase',
            'plan_key' => 'gold_1_month',
            'duration_days' => 30,
            'gross_amount' => 1000,
            'wallet_amount' => 100,
            'gateway_amount' => 900,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'failure_code' => 'provider_result_unknown',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $attempt = PaymentAttempt::query()->create([
            'user_id' => $owner->getKey(),
            'payment_order_id' => $order->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'gateway' => 'test-gateway',
            'gateway_environment' => 'sandbox',
            'amount' => 900,
            'currency' => 'TOMAN',
            'status' => 'initiating_unknown',
            'gateway_reference_id' => 'private-provider-reference',
            'redirect_url' => 'https://pay.example/pending-order',
            'failure_code' => 'private-attempt-failure',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/auth/subscription/pending-order')
            ->assertOk()
            ->assertJsonPath('order.id', $order->getKey())
            ->assertJsonPath('order.status', 'pending')
            ->assertJsonPath('order.failure_code', 'provider_result_unknown')
            ->assertJsonPath('payments.0.id', $attempt->getKey())
            ->assertJsonPath('payments.0.status', 'initiating_unknown')
            ->assertJsonPath('payments.0.redirect_url', 'https://pay.example/pending-order')
            ->assertJsonStructure([
                'order' => [
                    'id',
                    'reference',
                    'kind',
                    'subscription_change_type',
                    'plan_key',
                    'status',
                    'failure_code',
                    'gross_amount',
                    'discount_amount',
                    'plan_credit_amount',
                    'wallet_amount',
                    'gateway_amount',
                    'currency',
                    'expires_at',
                    'completed_at',
                ],
                'payments' => [
                    ['id', 'gateway', 'status', 'redirect_url'],
                ],
            ])
            ->assertJsonMissingPath('order.user_id')
            ->assertJsonMissingPath('order.idempotency_key')
            ->assertJsonMissingPath('payments.0.idempotency_key')
            ->assertJsonMissingPath('payments.0.merchant_reference')
            ->assertJsonMissingPath('payments.0.gateway_reference_id')
            ->assertJsonMissingPath('payments.0.failure_code');

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/auth/subscription/pending-order')
            ->assertOk()
            ->assertExactJson(['order' => null]);

        $this->assertSame($paymentOrderCount + 1, PaymentOrder::query()->count());
        $this->assertSame($paymentAttemptCount + 1, PaymentAttempt::query()->count());
        $this->assertSame($transactionCount, Transaction::query()->count());
        $this->assertSame(1000, $owner->fresh()->wallet_balance);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_pending_subscription_order_returns_latest_pending_or_processing_subscription_only(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $this->createSubscriptionOrder($owner, 'pending', 'gold_1_month');
        $this->createSubscriptionOrder($owner, 'succeeded', 'silver_1_month');
        $processingOrder = $this->createSubscriptionOrder($owner, 'processing', 'gold_3_months');
        $this->createSubscriptionOrder($owner, 'failed', 'silver_3_months');
        $this->createSubscriptionOrder($owner, 'pending', 'gold_6_months', kind: 'wallet_top_up');

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/auth/subscription/pending-order')
            ->assertOk()
            ->assertJsonPath('order.id', $processingOrder->getKey())
            ->assertJsonPath('order.status', 'processing')
            ->assertJsonPath('order.kind', 'subscription')
            ->assertJsonPath('order.plan_key', 'gold_3_months');
    }

    public function test_sensitive_get_rate_limit_is_per_user_and_per_route_without_financial_side_effects(): void
    {
        [$owner, $order, $attempt, $walletTransaction] = $this->createPaymentResources();
        $otherUser = User::factory()->create(['is_active' => true]);
        $paymentOrderCount = PaymentOrder::query()->count();
        $paymentAttemptCount = PaymentAttempt::query()->count();
        $transactionCount = Transaction::query()->count();

        for ($request = 0; $request < 60; $request++) {
            $this->actingAs($owner, 'sanctum')
                ->getJson('/api/v1/auth/payment-orders/'.$order->getKey())
                ->assertOk();
        }

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/auth/payment-orders/'.$order->getKey())
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/auth/payments/'.$attempt->getKey())
            ->assertOk();

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/auth/payment-orders/'.$order->getKey())
            ->assertNotFound();

        $this->assertSame($paymentOrderCount, PaymentOrder::query()->count());
        $this->assertSame($paymentAttemptCount, PaymentAttempt::query()->count());
        $this->assertSame($transactionCount, Transaction::query()->count());
        $this->assertSame(700, $owner->fresh()->wallet_balance);
        $this->assertDatabaseHas('transactions', [
            'id' => $walletTransaction->getKey(),
            'user_id' => $owner->getKey(),
            'amount' => 300,
        ]);
    }

    /**
     * @return array{User, PaymentOrder, PaymentAttempt, Transaction}
     */
    private function createPaymentResources(): array
    {
        $owner = User::factory()->create(['is_active' => true, 'wallet_balance' => 700]);
        $walletTransaction = Transaction::query()->create([
            'user_id' => $owner->getKey(),
            'type' => 'debit',
            'status' => 'succeeded',
            'amount' => 300,
            'balance_after' => 700,
            'currency' => 'TOMAN',
            'reference_id' => 'subscription-order:'.Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'description' => 'private wallet ledger details',
            'expires_at' => now()->addDay(),
        ]);
        $orderReference = (string) Str::uuid();
        $order = PaymentOrder::query()->create([
            'user_id' => $owner->getKey(),
            'order_reference' => $orderReference,
            'kind' => 'subscription',
            'subscription_change_type' => 'purchase',
            'plan_key' => 'gold_1_month',
            'duration_days' => 30,
            'gross_amount' => 1000,
            'wallet_amount' => 300,
            'gateway_amount' => 700,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'wallet_transaction_id' => $walletTransaction->getKey(),
            'expires_at' => now()->addDay(),
        ]);
        $attempt = PaymentAttempt::query()->create([
            'user_id' => $owner->getKey(),
            'payment_order_id' => $order->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'gateway' => 'test-gateway',
            'gateway_environment' => 'sandbox',
            'amount' => 700,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'gateway_reference_id' => 'private-gateway-reference',
            'redirect_url' => 'https://pay.example/owner-only',
            'failure_code' => 'private-failure-detail',
            'expires_at' => now()->addDay(),
        ]);

        return [$owner, $order, $attempt, $walletTransaction];
    }

    private function createSubscriptionOrder(
        User $user,
        string $status,
        string $planKey,
        string $kind = 'subscription',
    ): PaymentOrder {
        return PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => $kind,
            'subscription_change_type' => 'purchase',
            'plan_key' => $planKey,
            'duration_days' => 30,
            'gross_amount' => 1000,
            'wallet_amount' => 0,
            'gateway_amount' => 1000,
            'currency' => 'TOMAN',
            'status' => $status,
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
    }
}
