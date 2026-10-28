<?php

namespace Tests\Feature;

use App\Events\SubscriptionActivated;
use App\Events\SubscriptionCancelled;
use App\Exceptions\SubscriptionConflictException;
use App\Models\AdminSetting;
use App\Models\DiscountCode;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_quote_credits_remaining_value_and_activation_replaces_source_subscription(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        $this->configureOfferPrice('gold_3_months', 9000);
        $user = User::factory()->create(['wallet_balance' => 3000]);
        $source = $this->createActiveSubscription($user, 'silver_1_month', 300, 30);
        $source->forceFill([
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ])->save();
        $payments = app(PaymentService::class);

        $quote = $payments->quoteSubscriptionChange($user, 'gold_3_months');

        $this->assertSame('upgrade', $quote['change_type']);
        $this->assertSame($source->getKey(), $quote['source_subscription_id']);
        $this->assertSame(25, $quote['duration_days']);
        $this->assertSame(250, $quote['credit_amount']);
        $this->assertSame(2250, $quote['gross_amount']);
        $this->assertSame(2250, $quote['payable_amount']);

        $order = $payments->purchasePremium(
            $user,
            'gold_3_months',
            null,
            (string) Str::uuid(),
            $quote,
        );

        $this->assertSame('succeeded', $order->status);
        $this->assertSame('upgrade', $order->subscription_change_type);
        $this->assertSame($source->getKey(), $order->source_subscription_id);
        $this->assertSame(2250, $order->wallet_amount);
        $this->assertSame(0, $order->gateway_amount);
        $this->assertSame(750, $user->fresh()->wallet_balance);
        $this->assertSame('replaced', $source->fresh()->status);
        $this->assertNotNull($source->fresh()->cancelled_at);

        $upgradedSubscription = Subscription::query()->findOrFail($order->subscription_id);
        $this->assertSame('gold_3_months', $upgradedSubscription->plan_key);
        $this->assertSame('active', $upgradedSubscription->status);
        $this->assertTrue($upgradedSubscription->starts_at->equalTo(now()));
        $this->assertTrue($upgradedSubscription->ends_at->equalTo($source->ends_at));
        $this->assertDatabaseCount('subscriptions', 2);
    }

    public function test_lower_tier_purchase_is_scheduled_after_the_active_subscription_and_blocks_another_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        $this->configureOfferPrice('silver_1_month', 1000);
        $this->configureOfferPrice('silver_3_months', 3000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 1500]);
        $source = $this->createActiveSubscription($user, 'gold_1_month', 500, 30);
        $source->forceFill([
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ])->save();
        $payments = app(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'silver_1_month');

        $this->assertSame('scheduled', $quote['change_type']);
        $this->assertSame($source->getKey(), $quote['source_subscription_id']);
        $this->assertSame(1000, $quote['gross_amount']);
        $this->assertSame(1000, $quote['payable_amount']);
        $this->assertTrue(Carbon::parse($quote['starts_at'])->equalTo($source->ends_at));

        $order = $payments->purchasePremium(
            $user,
            'silver_1_month',
            null,
            (string) Str::uuid(),
            $quote,
        );

        $scheduled = Subscription::query()->findOrFail($order->subscription_id);
        $this->assertSame('succeeded', $order->status);
        $this->assertSame('scheduled', $order->subscription_change_type);
        $this->assertSame('active', $scheduled->status);
        $this->assertTrue($scheduled->starts_at->equalTo($source->ends_at));
        $this->assertTrue($scheduled->ends_at->equalTo($source->ends_at->copy()->addDays(30)));
        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame(500, $user->fresh()->wallet_balance);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.plan_key', 'gold_1_month')
            ->assertJsonPath('scheduled_subscription.plan_key', 'silver_1_month')
            ->assertJsonPath('scheduled_subscription.is_active', false);

        try {
            $payments->quoteSubscriptionChange($user, 'silver_3_months');
            $this->fail('A second scheduled subscription must be rejected.');
        } catch (SubscriptionConflictException) {
            $this->assertDatabaseCount('subscriptions', 2);
            $this->assertSame(500, $user->fresh()->wallet_balance);
        }
    }

    public function test_subscription_cancel_endpoint_is_authenticated_and_idempotent(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        Event::fake([SubscriptionCancelled::class]);
        $user = User::factory()->create(['is_active' => true]);
        $subscription = $this->createActiveSubscription($user, 'gold_1_month', 1000, 30);

        $this->deleteJson('/api/v1/auth/subscription')->assertUnauthorized();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.id', $subscription->getKey())
            ->assertJsonPath('subscription.cancellation_scheduled', true)
            ->assertJsonPath('subscription.status', 'active');

        $cancelledAt = $subscription->fresh()->cancelled_at;
        $this->assertNotNull($cancelledAt);
        $this->assertTrue($subscription->fresh()->isActive());

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.id', $subscription->getKey())
            ->assertJsonPath('subscription.cancellation_scheduled', true);

        $this->assertTrue($subscription->fresh()->cancelled_at->equalTo($cancelledAt));
        Event::assertDispatchedTimes(SubscriptionCancelled::class, 1);
    }

    public function test_subscription_quote_and_purchase_api_are_authenticated_and_return_no_idempotency_key(): void
    {
        $this->configureOfferPrice('gold_1_month', 1000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 1200]);

        $this->postJson('/api/v1/auth/subscription/quote', [
            'plan_key' => 'gold_1_month',
        ])->assertUnauthorized();

        $quoteResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/subscription/quote', [
                'plan_key' => 'gold_1_month',
            ])
            ->assertOk()
            ->assertJsonPath('quote.plan_key', 'gold_1_month')
            ->assertJsonPath('quote.gross_amount', 1000)
            ->assertJsonPath('quote.payable_amount', 1000);

        $quote = $this->expectedQuote($quoteResponse->json('quote'));
        $idempotencyKey = (string) Str::uuid();

        $purchaseResponse = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'expected_quote' => $this->expectedQuote($quote),
            ])
            ->assertCreated()
            ->assertJsonPath('order.status', 'succeeded')
            ->assertJsonPath('order.wallet_amount', 1000)
            ->assertJsonPath('order.gateway_amount', 0)
            ->assertJsonPath('subscription.plan_key', 'gold_1_month')
            ->assertJsonMissing(['idempotency_key' => $idempotencyKey]);

        $orderId = $purchaseResponse->json('order.id');
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'expected_quote' => $quote,
            ])
            ->assertCreated()
            ->assertJsonPath('order.id', $orderId);

        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_3_months',
                'expected_quote' => $quote,
            ])
            ->assertConflict();

        $this->assertSame(200, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_orders', 1);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_purchase_rejects_a_stale_quote_without_reserving_money_or_creating_an_order(): void
    {
        $this->configureOfferPrice('gold_1_month', 1000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 1200]);
        $payments = app(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'gold_1_month');
        $quote['gross_amount'] = 900;
        $quote['payable_amount'] = 900;

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'expected_quote' => $this->expectedQuote($quote),
            ]);
        $response->assertConflict();

        $this->assertSame(1200, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_discounted_subscription_purchase_api_reserves_once_and_replays_idempotently(): void
    {
        $this->configureOfferPrice('gold_1_month', 1000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 2000]);
        $discount = DiscountCode::query()->create([
            'code' => 'API15OFF',
            'type' => 'percentage',
            'value' => 15,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $quoteResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/subscription/quote', [
                'plan_key' => 'gold_1_month',
                'discount_code' => $discount->code,
            ])
            ->assertOk()
            ->assertJsonPath('quote.gross_amount', 1000)
            ->assertJsonPath('quote.discount_amount', 150)
            ->assertJsonPath('quote.payable_amount', 850);
        $expectedQuote = $this->expectedQuote($quoteResponse->json('quote'));
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'plan_key' => 'gold_1_month',
            'discount_code' => $discount->code,
            'expected_quote' => $expectedQuote,
        ];

        $purchaseResponse = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/auth/subscription/purchase', $payload)
            ->assertCreated()
            ->assertJsonPath('order.status', 'succeeded')
            ->assertJsonPath('order.gross_amount', 1000)
            ->assertJsonPath('order.discount_amount', 150)
            ->assertJsonPath('order.wallet_amount', 850)
            ->assertJsonPath('order.gateway_amount', 0)
            ->assertJsonPath('subscription.plan_key', 'gold_1_month')
            ->assertJsonMissing(['idempotency_key' => $idempotencyKey]);

        $orderId = $purchaseResponse->json('order.id');
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/auth/subscription/purchase', $payload)
            ->assertCreated()
            ->assertJsonPath('order.id', $orderId);

        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/auth/subscription/purchase', [
                ...$payload,
                'discount_code' => 'OTHER-CODE',
            ])
            ->assertConflict();

        $this->assertSame(1150, $user->fresh()->wallet_balance);
        $this->assertSame(1, $discount->fresh()->usage_count);
        $this->assertDatabaseCount('payment_orders', 1);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseHas('discount_usages', [
            'payment_order_id' => $orderId,
            'discount_code_id' => $discount->getKey(),
            'order_amount' => 1000,
            'discount_amount' => 150,
            'status' => 'consumed',
        ]);
    }

    public function test_subscription_purchase_idempotency_is_isolated_between_users(): void
    {
        $this->configureOfferPrice('gold_1_month', 1000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 2000]);
        $otherUser = User::factory()->create(['is_active' => true, 'wallet_balance' => 3000]);
        $discount = DiscountCode::query()->create([
            'code' => 'SHARED15',
            'type' => 'percentage',
            'value' => 15,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        Event::fake([SubscriptionActivated::class]);

        $firstQuote = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/subscription/quote', [
                'plan_key' => 'gold_1_month',
                'discount_code' => $discount->code,
            ])
            ->assertOk()
            ->json('quote');
        $otherQuote = $this->actingAs($otherUser, 'sanctum')
            ->postJson('/api/v1/auth/subscription/quote', [
                'plan_key' => 'gold_1_month',
                'discount_code' => $discount->code,
            ])
            ->assertOk()
            ->json('quote');
        $idempotencyKey = (string) Str::uuid();
        $purchasePayload = static fn (array $quote): array => [
            'plan_key' => 'gold_1_month',
            'discount_code' => $discount->code,
            'expected_quote' => array_intersect_key($quote, array_flip([
                'gross_amount',
                'credit_amount',
                'discount_amount',
                'payable_amount',
                'duration_days',
                'change_type',
                'source_subscription_id',
                'source_plan_key',
            ])),
        ];

        $firstPurchase = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/auth/subscription/purchase', $purchasePayload($firstQuote))
            ->assertCreated()
            ->assertJsonPath('order.wallet_amount', 850)
            ->assertJsonMissing(['idempotency_key' => $idempotencyKey]);
        $firstOrderId = $firstPurchase->json('order.id');

        $otherPurchase = $this->actingAs($otherUser, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/auth/subscription/purchase', $purchasePayload($otherQuote))
            ->assertCreated()
            ->assertJsonPath('order.wallet_amount', 850)
            ->assertJsonMissing(['idempotency_key' => $idempotencyKey]);
        $otherOrderId = $otherPurchase->json('order.id');

        $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/auth/subscription/purchase', $purchasePayload($firstQuote))
            ->assertCreated()
            ->assertJsonPath('order.id', $firstOrderId);
        $this->actingAs($otherUser, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/auth/subscription/purchase', $purchasePayload($otherQuote))
            ->assertCreated()
            ->assertJsonPath('order.id', $otherOrderId);

        $this->assertNotSame($firstOrderId, $otherOrderId);
        $this->assertDatabaseHas('payment_orders', [
            'id' => $firstOrderId,
            'user_id' => $user->getKey(),
            'idempotency_key' => $idempotencyKey,
        ]);
        $this->assertDatabaseHas('payment_orders', [
            'id' => $otherOrderId,
            'user_id' => $otherUser->getKey(),
            'idempotency_key' => $idempotencyKey,
        ]);
        $this->assertSame(1, PaymentOrder::query()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->count());
        $this->assertSame(1, PaymentOrder::query()
            ->where('user_id', $otherUser->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->count());
        $firstOrderReference = PaymentOrder::query()->findOrFail($firstOrderId)->order_reference;
        $otherOrderReference = PaymentOrder::query()->findOrFail($otherOrderId)->order_reference;
        $this->assertNotSame($firstOrderReference, $otherOrderReference);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->getKey(),
            'reference_id' => 'subscription-order:'.$firstOrderReference,
            'idempotency_key' => $firstOrderReference,
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $otherUser->getKey(),
            'reference_id' => 'subscription-order:'.$otherOrderReference,
            'idempotency_key' => $otherOrderReference,
        ]);
        $this->assertSame(1150, $user->fresh()->wallet_balance);
        $this->assertSame(2150, $otherUser->fresh()->wallet_balance);
        $this->assertSame(2, $discount->fresh()->usage_count);
        $this->assertDatabaseCount('payment_orders', 2);
        $this->assertDatabaseCount('discount_usages', 2);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertDatabaseHas('discount_usages', [
            'payment_order_id' => $firstOrderId,
            'status' => 'consumed',
        ]);
        $this->assertDatabaseHas('discount_usages', [
            'payment_order_id' => $otherOrderId,
            'status' => 'consumed',
        ]);
        Event::assertDispatchedTimes(SubscriptionActivated::class, 2);
    }

    public function test_discounted_purchase_api_rejects_invalid_discount_or_changed_quote_without_side_effects(): void
    {
        $this->configureOfferPrice('gold_1_month', 1000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 2000]);
        $discount = DiscountCode::query()->create([
            'code' => 'APIVALID',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $validQuoteResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/subscription/quote', [
                'plan_key' => 'gold_1_month',
                'discount_code' => $discount->code,
            ])
            ->assertOk();
        $expectedQuote = $this->expectedQuote($validQuoteResponse->json('quote'));

        $this->postJson('/api/v1/auth/subscription/purchase', [
            'plan_key' => 'gold_1_month',
            'discount_code' => $discount->code,
            'expected_quote' => $expectedQuote,
        ])->assertUnprocessable();

        $malformedQuote = $expectedQuote;
        unset($malformedQuote['credit_amount']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'discount_code' => $discount->code,
                'expected_quote' => $malformedQuote,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_quote.credit_amount');

        $invalidQuoteCases = [
            [
                [...$expectedQuote, 'gross_amount' => 'not-an-integer'],
                'expected_quote.gross_amount',
            ],
            [
                [...$expectedQuote, 'duration_days' => 0],
                'expected_quote.duration_days',
            ],
            [
                [...$expectedQuote, 'change_type' => 'invalid'],
                'expected_quote.change_type',
            ],
        ];

        foreach ($invalidQuoteCases as [$invalidQuote, $field]) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/auth/subscription/purchase', [
                    'plan_key' => 'gold_1_month',
                    'discount_code' => $discount->code,
                    'expected_quote' => $invalidQuote,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        Cache::store('array')->flush();

        $quoteWithUnexpectedField = [...$expectedQuote, 'client_price' => 1];
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'discount_code' => $discount->code,
                'expected_quote' => $quoteWithUnexpectedField,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_quote');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'discount_code' => 'DOES-NOT-EXIST',
                'expected_quote' => $expectedQuote,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'discount_not_valid');

        $changedQuote = [...$expectedQuote, 'payable_amount' => 1];
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'discount_code' => $discount->code,
                'expected_quote' => $changedQuote,
            ])
            ->assertConflict();

        $this->assertSame(2000, $user->fresh()->wallet_balance);
        $this->assertSame(0, $discount->fresh()->usage_count);
        $this->assertDatabaseCount('discount_usages', 0);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_inactive_account_cannot_purchase_subscription_or_reserve_discount(): void
    {
        $this->configureOfferPrice('gold_1_month', 1000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 2000]);
        $discount = DiscountCode::query()->create([
            'code' => 'INACTIVEAPI',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 2,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $quote = app(PaymentService::class)->quoteSubscriptionChange($user, 'gold_1_month', $discount->code);
        $user->forceFill(['is_active' => false])->save();
        $user->createToken('test-token');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'discount_code' => $discount->code,
                'expected_quote' => $this->expectedQuote($quote),
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');

        $this->assertSame(2000, $user->fresh()->wallet_balance);
        $this->assertSame(0, $discount->fresh()->usage_count);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('discount_usages', 0);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_invalid_plan_and_malformed_idempotency_key_are_rejected_without_side_effects(): void
    {
        $this->configureOfferPrice('gold_1_month', 1000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 2000]);
        $quote = app(PaymentService::class)->quoteSubscriptionChange($user, 'gold_1_month');
        $payload = [
            'plan_key' => 'gold_1_month',
            'expected_quote' => $this->expectedQuote($quote),
        ];

        $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                ...$payload,
                'plan_key' => 'unknown_plan',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_key');

        $this->withHeader('Idempotency-Key', 'not-a-uuid')
            ->postJson('/api/v1/auth/subscription/purchase', $payload)
            ->assertConflict()
            ->assertJsonStructure(['message']);

        $this->assertSame(2000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    private function configureOfferPrice(string $offerKey, int $price): void
    {
        $setting = AdminSetting::query()->where('setting_key', 'plans')->first();
        $value = $setting?->value ?? [];
        $value['offers'][$offerKey]['price'] = $price;
        AdminSetting::query()->updateOrCreate(
            ['setting_key' => 'plans'],
            ['value' => $value],
        );
        Cache::forget('admin-runtime-settings:v2:plans');
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function expectedQuote(array $quote): array
    {
        return array_intersect_key($quote, array_flip([
            'gross_amount',
            'credit_amount',
            'discount_amount',
            'payable_amount',
            'duration_days',
            'change_type',
            'source_subscription_id',
            'source_plan_key',
        ]));
    }

    private function createActiveSubscription(User $user, string $planKey, int $sourceValue, int $durationDays): Subscription
    {
        $sourceOrder = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'subscription_change_type' => 'purchase',
            'plan_key' => $planKey,
            'duration_days' => $durationDays,
            'gross_amount' => $sourceValue,
            'wallet_amount' => $sourceValue,
            'gateway_amount' => 0,
            'currency' => 'TOMAN',
            'status' => 'succeeded',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);

        return $user->subscriptions()->create([
            'payment_order_id' => $sourceOrder->getKey(),
            'plan_key' => $planKey,
            'type' => 'premium',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays($durationDays - 1),
        ]);
    }
}
