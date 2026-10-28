<?php

namespace Tests\Feature;

use App\Events\SubscriptionActivated;
use App\Models\User;
use App\Services\Admin\AdminRuntimeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionRuntimeSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_public_catalog_and_current_subscription_use_runtime_prices_and_features(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $subscription = $user->subscriptions()->create([
            'plan_key' => 'silver_1_month',
            'type' => 'premium',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);
        $plans = $this->normalizedPlanSettings();
        $plans['tiers']['silver']['features']['base-salary'] = true;
        $plans['tiers']['silver']['features']['group-calculation'] = true;
        $plans['offers']['gold_1_month']['price'] = 25000;
        $plans['offers']['bronze_1_month']['price'] = 0;
        $this->savePlanSettings($plans);

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'gold_1_month',
                'tier' => 'gold',
                'duration' => '1_month',
                'duration_days' => 30,
                'price' => 25000,
                'is_available' => true,
            ])
            ->assertJsonFragment([
                'key' => 'bronze_1_month',
                'tier' => 'bronze',
                'duration' => '1_month',
                'duration_days' => 30,
                'price' => 0,
                'is_available' => false,
            ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.id', $subscription->getKey())
            ->assertJsonPath('plan.key', 'silver')
            ->assertJsonPath('plan.features.base-salary', true)
            ->assertJsonPath('plan.features.group-calculation', true)
            ->assertJsonPath('plan.features.yearly-info', false)
            ->assertJsonPath('plan.is_unlimited', false);
    }

    public function test_subscription_quote_and_purchase_recalculate_runtime_price_on_the_server(): void
    {
        $plans = $this->normalizedPlanSettings();
        $plans['offers']['gold_1_month']['price'] = 1000;
        $this->savePlanSettings($plans);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 5000]);
        Event::fake([SubscriptionActivated::class]);

        $oldQuoteResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/subscription/quote', [
                'plan_key' => 'gold_1_month',
                'price' => 1,
                'amount' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('quote.gross_amount', 1000)
            ->assertJsonPath('quote.payable_amount', 1000);
        $oldQuote = $this->expectedQuote($oldQuoteResponse->json('quote'));

        $plans['offers']['gold_1_month']['price'] = 2500;
        $this->savePlanSettings($plans);
        $this->postJson('/api/v1/auth/subscription/quote', [
            'plan_key' => 'gold_1_month',
        ])
            ->assertOk()
            ->assertJsonPath('quote.gross_amount', 2500)
            ->assertJsonPath('quote.payable_amount', 2500);

        $idempotencyKey = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'expected_quote' => $oldQuote,
            ])
            ->assertConflict();

        $forgedQuote = [...$oldQuote, 'gross_amount' => 1, 'payable_amount' => 1];
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'expected_quote' => $forgedQuote,
            ])
            ->assertConflict();

        $this->assertSame(5000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('subscriptions', 0);

        $currentQuoteResponse = $this->postJson('/api/v1/auth/subscription/quote', [
            'plan_key' => 'gold_1_month',
        ])->assertOk();
        $purchaseResponse = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'expected_quote' => $this->expectedQuote($currentQuoteResponse->json('quote')),
                'price' => 1,
                'amount' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('order.gross_amount', 2500)
            ->assertJsonPath('order.wallet_amount', 2500)
            ->assertJsonPath('order.gateway_amount', 0)
            ->assertJsonPath('subscription.plan_key', 'gold_1_month');

        $this->assertSame(2500, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_orders', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('subscriptions', 1);
        Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
        $this->assertSame(2500, $purchaseResponse->json('order.gross_amount'));
    }

    public function test_zero_price_offer_is_unavailable_and_cannot_be_quoted_or_purchased(): void
    {
        $plans = $this->normalizedPlanSettings();
        $plans['offers']['gold_1_month']['price'] = 0;
        $this->savePlanSettings($plans);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 5000]);
        Event::fake([SubscriptionActivated::class]);

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'gold_1_month',
                'tier' => 'gold',
                'duration' => '1_month',
                'duration_days' => 30,
                'price' => 0,
                'is_available' => false,
            ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/subscription/quote', ['plan_key' => 'gold_1_month'])
            ->assertConflict();

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'expected_quote' => [
                    'gross_amount' => 0,
                    'payable_amount' => 0,
                    'credit_amount' => 0,
                    'discount_amount' => 0,
                    'duration_days' => 30,
                    'change_type' => 'purchase',
                    'source_subscription_id' => null,
                    'source_plan_key' => null,
                ],
            ])
            ->assertConflict();

        $this->assertSame(5000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        Event::assertNotDispatched(SubscriptionActivated::class);
    }

    /**
     * @return array{tiers: array<string, array{features: array<string, bool>}>, offers: array<string, array{price: int}>}
     */
    private function normalizedPlanSettings(): array
    {
        $config = config('plans');
        $tiers = [];
        foreach ($config['tiers'] as $tierKey => $tier) {
            $tiers[$tierKey] = ['features' => $tier['features']];
        }

        $offers = [];
        foreach ($config['offers'] as $offerKey => $offer) {
            $offers[$offerKey] = ['price' => $offer['price']];
        }

        return ['tiers' => $tiers, 'offers' => $offers];
    }

    /**
     * @param  array{tiers: array<string, array{features: array<string, bool>}>, offers: array<string, array{price: int}>}  $plans
     */
    private function savePlanSettings(array $plans): void
    {
        $user = User::factory()->create(['is_active' => true]);
        app(AdminRuntimeSettings::class)->save('plans', $plans, $user);
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
}
