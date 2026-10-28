<?php

namespace Tests\Feature;

use App\Exceptions\DiscountNotValidException;
use App\Models\DiscountCode;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiscountServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_endpoint_returns_preview_without_reserving_or_consuming_usage(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 1234]);
        $code = $this->createCode([
            'code' => 'SPRING25',
            'type' => 'percentage',
            'value' => 25,
            'max_discount' => 150,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/discounts/quote', [
                'code' => ' spring25 ',
                'order_amount' => 1000,
            ])
            ->assertOk()
            ->assertJsonPath('discount.code', 'SPRING25')
            ->assertJsonPath('discount.type', 'percentage')
            ->assertJsonPath('discount.discount_amount', 150)
            ->assertJsonPath('discount.final_amount', 850);

        $this->assertSame(0, $code->fresh()->usage_count);
        $this->assertDatabaseCount('discount_usages', 0);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertSame(1234, $user->fresh()->wallet_balance);
    }

    public function test_quote_endpoint_requires_authentication_and_valid_input(): void
    {
        $this->postJson('/api/v1/auth/discounts/quote', [
            'code' => 'SAVE',
            'order_amount' => 100,
        ])->assertUnauthorized();

        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/discounts/quote', [
                'code' => 'SAVE',
                'order_amount' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_amount');
    }

    public function test_quote_endpoint_rejects_an_inactive_account_without_creating_usage(): void
    {
        $user = User::factory()->create(['is_active' => false, 'wallet_balance' => 1234]);
        $code = $this->createCode(['code' => 'DISABLEDUSER']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/discounts/quote', [
                'code' => $code->code,
                'order_amount' => 1000,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');

        $this->assertSame(0, $code->fresh()->usage_count);
        $this->assertDatabaseCount('discount_usages', 0);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertSame(1234, $user->fresh()->wallet_balance);
    }

    public function test_quote_endpoint_returns_validation_errors_for_malformed_inputs(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 1234]);
        $this->actingAs($user, 'sanctum');

        $invalidInputs = [
            [['order_amount' => 1000], ['code']],
            [['code' => '', 'order_amount' => 1000], ['code']],
            [['code' => str_repeat('A', 65), 'order_amount' => 1000], ['code']],
            [['code' => 'SAVE'], ['order_amount']],
            [['code' => 'SAVE', 'order_amount' => 'not-an-integer'], ['order_amount']],
            [['code' => 'SAVE', 'order_amount' => 0], ['order_amount']],
            [['code' => 'SAVE', 'order_amount' => -1], ['order_amount']],
            [['code' => 'SAVE', 'order_amount' => 1.5], ['order_amount']],
        ];

        foreach ($invalidInputs as [$payload, $fields]) {
            $this->postJson('/api/v1/auth/discounts/quote', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($fields);
        }

        $this->assertDatabaseCount('discount_usages', 0);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertSame(1234, $user->fresh()->wallet_balance);
    }

    public function test_quote_endpoint_returns_a_stable_error_for_unknown_or_ineligible_codes_without_side_effects(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 1234]);
        $otherUser = User::factory()->create(['is_active' => true]);
        $inactiveCode = $this->createCode([
            'code' => 'INACTIVEAPI',
            'is_active' => false,
        ]);
        $privateCode = $this->createCode([
            'code' => 'PRIVATEAPI',
            'user_id' => $otherUser->getKey(),
        ]);

        foreach (['UNKNOWNAPI', $inactiveCode->code, $privateCode->code] as $code) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/auth/discounts/quote', [
                    'code' => $code,
                    'order_amount' => 1000,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'discount_not_valid')
                ->assertJsonStructure(['message', 'code']);
        }

        $this->assertSame(0, $inactiveCode->fresh()->usage_count);
        $this->assertSame(0, $privateCode->fresh()->usage_count);
        $this->assertDatabaseCount('discount_usages', 0);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertSame(1234, $user->fresh()->wallet_balance);
    }

    public function test_quote_endpoint_is_rate_limited_without_reserving_usage(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 1234]);
        $code = $this->createCode(['code' => 'RATELIMITQUOTE']);
        $payload = ['code' => $code->code, 'order_amount' => 1000];

        for ($request = 0; $request < 10; $request++) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/auth/discounts/quote', $payload)
                ->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/discounts/quote', $payload)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertSame(0, $code->fresh()->usage_count);
        $this->assertDatabaseCount('discount_usages', 0);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertSame(1234, $user->fresh()->wallet_balance);
    }

    public function test_percentage_discount_uses_floor_rounding_for_fractional_toman(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode([
            'code' => 'FLOOR15',
            'type' => 'percentage',
            'value' => 15,
        ]);

        $quote = app(DiscountService::class)->quote($user, $code->code, 199);

        $this->assertSame(29, $quote['discount_amount']);
        $this->assertSame(170, $quote['final_amount']);
    }

    public function test_fixed_discount_larger_than_order_is_capped_at_order_amount(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode([
            'code' => 'OVERCAP',
            'type' => 'fixed',
            'value' => 2000,
        ]);
        $order = $this->createOrder($user, $code);

        $quote = app(DiscountService::class)->quote($user, $code->code, 1000);
        $reservation = app(DiscountService::class)->reserveForOrder($user, $code->code, 1000, $order);

        $this->assertSame(1000, $quote['discount_amount']);
        $this->assertSame(0, $quote['final_amount']);
        $this->assertSame($quote['discount_amount'], $reservation['discount_amount']);
        $this->assertSame($quote['final_amount'], $reservation['final_amount']);
        $this->assertDatabaseHas('discount_usages', [
            'payment_order_id' => $order->getKey(),
            'order_amount' => 1000,
            'discount_amount' => 1000,
            'status' => 'reserved',
        ]);
    }

    public function test_percentage_discount_at_maximum_integer_order_amount_does_not_overflow(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode([
            'code' => 'MAXINT100',
            'type' => 'percentage',
            'value' => 100,
        ]);

        $quote = app(DiscountService::class)->quote($user, $code->code, PHP_INT_MAX);

        $this->assertSame(PHP_INT_MAX, $quote['discount_amount']);
        $this->assertSame(0, $quote['final_amount']);
    }

    public function test_quote_rejects_inactive_expired_under_minimum_and_other_users_codes(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['is_active' => true]);
        $inactive = $this->createCode(['code' => 'INACTIVE', 'is_active' => false]);
        $expired = $this->createCode(['code' => 'EXPIRED', 'expires_at' => now()->subMinute()]);
        $minimum = $this->createCode(['code' => 'MINIMUM', 'min_order_amount' => 500]);
        $private = $this->createCode(['code' => 'PRIVATE', 'user_id' => $otherUser->getKey()]);
        $discounts = app(DiscountService::class);

        foreach ([$inactive, $expired, $minimum, $private] as $code) {
            try {
                $discounts->quote($user, $code->code, 100);
                $this->fail("Code {$code->code} should not be quoteable for this user and amount.");
            } catch (DiscountNotValidException) {
                $this->assertSame(0, $code->fresh()->usage_count);
            }
        }

        $this->assertDatabaseCount('discount_usages', 0);
    }

    public function test_reservation_increments_capacity_and_prevents_duplicate_user_reservations(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode([
            'code' => 'ONCE',
            'type' => 'fixed',
            'value' => 100,
            'usage_limit' => 5,
        ]);
        $order = $this->createOrder($user, $code);
        $discounts = app(DiscountService::class);

        $reservation = $discounts->reserveForOrder($user, ' once ', 1000, $order);

        $this->assertSame((int) $code->getKey(), $reservation['discount_code_id']);
        $this->assertSame(100, $reservation['discount_amount']);
        $this->assertSame(900, $reservation['final_amount']);
        $this->assertSame(1, $code->fresh()->usage_count);
        $this->assertDatabaseHas('discount_usages', [
            'payment_order_id' => $order->getKey(),
            'status' => 'reserved',
            'discount_amount' => 100,
        ]);

        $secondOrder = $this->createOrder($user, $code);
        try {
            $discounts->reserveForOrder($user, $code->code, 1000, $secondOrder);
            $this->fail('A user must not reserve the same code twice.');
        } catch (DiscountNotValidException) {
            $this->assertSame(1, $code->fresh()->usage_count);
            $this->assertDatabaseCount('discount_usages', 1);
        }
    }

    public function test_quote_rejects_a_code_that_has_reached_its_usage_limit(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode([
            'code' => 'FULL',
            'usage_limit' => 1,
            'usage_count' => 1,
        ]);

        try {
            app(DiscountService::class)->quote($user, $code->code, 1000);
            $this->fail('A code at its usage limit must not be available.');
        } catch (DiscountNotValidException) {
            $this->assertSame(1, $code->fresh()->usage_count);
            $this->assertDatabaseCount('discount_usages', 0);
        }
    }

    public function test_releasing_a_reservation_decrements_usage_count_only_once(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode(['code' => 'RELEASE', 'type' => 'fixed', 'value' => 100]);
        $order = $this->createOrder($user, $code);
        $discounts = app(DiscountService::class);
        $discounts->reserveForOrder($user, $code->code, 1000, $order);

        $discounts->releaseReservation($order);
        $discounts->releaseReservation($order);

        $this->assertSame(0, $code->fresh()->usage_count);
        $this->assertDatabaseHas('discount_usages', [
            'payment_order_id' => $order->getKey(),
            'status' => 'released',
        ]);
    }

    public function test_consuming_a_successful_order_reservation_is_idempotent(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode(['code' => 'CONSUME', 'type' => 'fixed', 'value' => 100]);
        $transaction = $this->createTransaction($user);
        $order = $this->createOrder($user, $code, [
            'status' => 'succeeded',
            'discount_amount' => 100,
            'transaction_id' => $transaction->getKey(),
        ]);
        $attempt = $this->createSuccessfulAttempt($user, $order, $transaction);
        $discounts = app(DiscountService::class);
        $discounts->reserveForOrder($user, $code->code, 1000, $order);

        $discounts->consumeReservation($order, $attempt, $transaction);
        $discounts->consumeReservation($order, $attempt, $transaction);

        $this->assertSame(1, $code->fresh()->usage_count);
        $this->assertDatabaseHas('discount_usages', [
            'payment_order_id' => $order->getKey(),
            'status' => 'consumed',
            'payment_attempt_id' => $attempt->getKey(),
            'transaction_id' => $transaction->getKey(),
        ]);
    }

    public function test_consuming_a_reservation_rejects_an_unrelated_transaction_from_the_same_user(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode(['code' => 'UNRELATEDTX', 'type' => 'fixed', 'value' => 100]);
        $order = $this->createOrder($user, $code, [
            'status' => 'succeeded',
            'discount_amount' => 100,
        ]);
        $unrelatedTransaction = $this->createTransaction($user);
        $discounts = app(DiscountService::class);
        $discounts->reserveForOrder($user, $code->code, 1000, $order);

        try {
            $discounts->consumeReservation($order, transaction: $unrelatedTransaction);
            $this->fail('A successful transaction not linked to the order must not consume its discount.');
        } catch (DiscountNotValidException) {
            $this->assertDatabaseHas('discount_usages', [
                'payment_order_id' => $order->getKey(),
                'status' => 'reserved',
                'transaction_id' => null,
            ]);
            $this->assertSame(1, $code->fresh()->usage_count);
        }
    }

    public function test_consuming_a_paid_order_reservation_requires_its_transaction(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode(['code' => 'MISSINGTX', 'type' => 'fixed', 'value' => 100]);
        $transaction = $this->createTransaction($user);
        $order = $this->createOrder($user, $code, [
            'status' => 'succeeded',
            'discount_amount' => 100,
            'transaction_id' => $transaction->getKey(),
        ]);
        $discounts = app(DiscountService::class);
        $discounts->reserveForOrder($user, $code->code, 1000, $order);

        try {
            $discounts->consumeReservation($order);
            $this->fail('A paid order must provide its linked transaction when consuming a discount.');
        } catch (DiscountNotValidException) {
            $this->assertDatabaseHas('discount_usages', [
                'payment_order_id' => $order->getKey(),
                'status' => 'reserved',
                'transaction_id' => null,
            ]);
            $this->assertSame(1, $code->fresh()->usage_count);
        }
    }

    public function test_consuming_a_reservation_rejects_an_attempt_from_another_order(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode(['code' => 'UNRELATEDATTEMPT', 'type' => 'fixed', 'value' => 100]);
        $transaction = $this->createTransaction($user);
        $order = $this->createOrder($user, $code, [
            'status' => 'succeeded',
            'discount_amount' => 100,
            'transaction_id' => $transaction->getKey(),
        ]);
        $otherOrder = $this->createOrder($user, $code);
        $attempt = $this->createSuccessfulAttempt($user, $otherOrder, $transaction);
        $discounts = app(DiscountService::class);
        $discounts->reserveForOrder($user, $code->code, 1000, $order);

        try {
            $discounts->consumeReservation($order, $attempt, $transaction);
            $this->fail('An attempt belonging to another order must not consume this reservation.');
        } catch (DiscountNotValidException) {
            $this->assertDatabaseHas('discount_usages', [
                'payment_order_id' => $order->getKey(),
                'status' => 'reserved',
                'payment_attempt_id' => null,
                'transaction_id' => null,
            ]);
            $this->assertSame(1, $code->fresh()->usage_count);
        }
    }

    public function test_consuming_a_reservation_rejects_an_attempt_linked_to_another_transaction(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode(['code' => 'ATTEMPTTX', 'type' => 'fixed', 'value' => 100]);
        $transaction = $this->createTransaction($user);
        $otherTransaction = $this->createTransaction($user);
        $order = $this->createOrder($user, $code, [
            'status' => 'succeeded',
            'discount_amount' => 100,
            'transaction_id' => $transaction->getKey(),
        ]);
        $attempt = $this->createSuccessfulAttempt($user, $order, $otherTransaction);
        $discounts = app(DiscountService::class);
        $discounts->reserveForOrder($user, $code->code, 1000, $order);

        try {
            $discounts->consumeReservation($order, $attempt, $transaction);
            $this->fail('An attempt linked to a different transaction must not consume this reservation.');
        } catch (DiscountNotValidException) {
            $this->assertDatabaseHas('discount_usages', [
                'payment_order_id' => $order->getKey(),
                'status' => 'reserved',
                'payment_attempt_id' => null,
                'transaction_id' => null,
            ]);
            $this->assertSame(1, $code->fresh()->usage_count);
        }
    }

    public function test_pending_order_cannot_consume_its_discount_reservation(): void
    {
        $user = User::factory()->create();
        $code = $this->createCode(['code' => 'PENDING', 'type' => 'fixed', 'value' => 100]);
        $order = $this->createOrder($user, $code, ['discount_amount' => 100]);
        $discounts = app(DiscountService::class);
        $discounts->reserveForOrder($user, $code->code, 1000, $order);

        try {
            $discounts->consumeReservation($order);
            $this->fail('A pending payment order must not consume a reservation.');
        } catch (DiscountNotValidException) {
            $this->assertDatabaseHas('discount_usages', [
                'payment_order_id' => $order->getKey(),
                'status' => 'reserved',
            ]);
            $this->assertSame(1, $code->fresh()->usage_count);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCode(array $attributes = []): DiscountCode
    {
        return DiscountCode::query()->create(array_merge([
            'code' => 'SAVE',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'max_discount' => null,
            'usage_limit' => null,
            'usage_count' => 0,
            'user_id' => null,
            'starts_at' => null,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOrder(User $user, DiscountCode $code, array $attributes = []): PaymentOrder
    {
        return PaymentOrder::query()->create(array_merge([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'discount_code_id' => $code->getKey(),
            'discount_amount' => 0,
            'gross_amount' => 1000,
            'wallet_amount' => 0,
            'gateway_amount' => 900,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ], $attributes));
    }

    private function createTransaction(User $user): Transaction
    {
        return $user->transactions()->create([
            'type' => 'subscription',
            'status' => 'succeeded',
            'amount' => 900,
            'balance_after' => $user->wallet_balance,
            'currency' => 'TOMAN',
            'gateway' => 'test-gateway',
            'reference_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
    }

    private function createSuccessfulAttempt(
        User $user,
        PaymentOrder $order,
        Transaction $transaction,
    ): PaymentAttempt {
        return PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'payment_order_id' => $order->getKey(),
            'transaction_id' => $transaction->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'gateway' => 'test-gateway',
            'gateway_environment' => 'sandbox',
            'amount' => $order->gateway_amount,
            'currency' => $order->currency,
            'status' => 'succeeded',
            'expires_at' => now()->addDay(),
            'verified_at' => now(),
        ]);
    }
}
