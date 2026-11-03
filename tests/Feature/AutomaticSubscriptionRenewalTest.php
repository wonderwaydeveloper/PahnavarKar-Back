<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\DiscountCode;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomaticSubscriptionRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00'));
        $this->setOfferPrice('gold_1_month', 1000);
    }

    public function test_automatic_renewal_uses_only_wallet_balance_and_keeps_renewal_enabled(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 5000]);
        $discount = DiscountCode::query()->create([
            'code' => 'MANUALONLY',
            'type' => 'fixed',
            'value' => 500,
            'min_order_amount' => 0,
            'usage_limit' => 1,
            'usage_count' => 0,
            'is_active' => true,
        ]);
        $old = $this->createExpiredSubscription($user);

        Artisan::call('subscriptions:renew-automatically');

        $order = PaymentOrder::query()->where('user_id', $user->getKey())->sole();
        $renewed = Subscription::query()->findOrFail($order->subscription_id);

        $this->assertSame('succeeded', $order->status);
        $this->assertSame(1000, $order->wallet_amount);
        $this->assertSame(0, $order->gateway_amount);
        $this->assertSame(0, $order->discount_amount);
        $this->assertSame(0, $discount->fresh()->usage_count);
        $this->assertSame(4000, $user->fresh()->wallet_balance);
        $this->assertFalse($old->fresh()->auto_renew);
        $this->assertTrue($renewed->auto_renew);
        $this->assertTrue($renewed->starts_at->equalTo(now()));
        $this->assertTrue($renewed->ends_at->equalTo(now()->addDays(30)));
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->getKey(),
            'event' => 'subscription.auto_renewed',
            'body' => 'پلن طلایی یک‌ماهه با استفاده از کیف پول و بر اساس قیمت روز تمدید شد.',
        ]);

        Artisan::call('subscriptions:renew-automatically');
        $this->assertSame(1, PaymentOrder::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(4000, $user->fresh()->wallet_balance);
    }

    public function test_insufficient_balance_disables_auto_renewal_without_partial_charge_and_records_failure(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 999]);
        $subscription = $this->createExpiredSubscription($user);

        Artisan::call('subscriptions:renew-automatically');

        $subscription->refresh();
        $this->assertFalse($subscription->auto_renew);
        $this->assertSame('insufficient_wallet_balance', $subscription->auto_renew_failure_reason);
        $this->assertNotNull($subscription->auto_renew_failed_at);
        $this->assertSame(999, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_automatic_renewal_is_deferred_while_a_manual_gateway_order_is_pending(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 5000]);
        $subscription = $this->createExpiredSubscription($user);
        $order = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'subscription_change_type' => 'purchase',
            'plan_key' => 'gold_1_month',
            'duration_days' => 30,
            'gross_amount' => 1000,
            'wallet_amount' => 0,
            'gateway_amount' => 1000,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'payment_order_id' => $order->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'zarinpal',
            'gateway_environment' => 'sandbox',
            'amount' => 1000,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'gateway_reference_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);

        Artisan::call('subscriptions:renew-automatically');

        $this->assertTrue($subscription->fresh()->auto_renew);
        $this->assertSame(5000, $user->fresh()->wallet_balance);
        $this->assertSame(1, PaymentOrder::query()->where('user_id', $user->getKey())->count());
    }

    public function test_automatic_renewal_pauses_during_suspension_and_resumes_after_expiry(): void
    {
        $user = User::factory()->inactive()->create([
            'wallet_balance' => 5000,
            'suspended_until' => now()->addDay(),
        ]);
        $subscription = $this->createExpiredSubscription($user);

        Artisan::call('subscriptions:renew-automatically');

        $this->assertSame(5000, $user->fresh()->wallet_balance);
        $this->assertTrue($subscription->fresh()->auto_renew);
        $this->assertDatabaseCount('payment_orders', 0);

        $user->forceFill(['suspended_until' => now()->subSecond()])->saveOrFail();
        Artisan::call('accounts:reactivate-expired-suspensions');
        Artisan::call('subscriptions:renew-automatically');

        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame(4000, $user->fresh()->wallet_balance);
        $this->assertSame(1, PaymentOrder::query()->where('user_id', $user->getKey())->count());
    }

    public function test_automatic_renewal_pauses_during_financial_restriction_and_resumes_after_it_is_removed(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 5000,
            'restricted_capabilities' => ['financial'],
        ]);
        $subscription = $this->createExpiredSubscription($user);

        Artisan::call('subscriptions:renew-automatically');

        $this->assertSame(5000, $user->fresh()->wallet_balance);
        $this->assertTrue($subscription->fresh()->auto_renew);
        $this->assertDatabaseCount('payment_orders', 0);

        $user->forceFill(['restricted_capabilities' => null])->saveOrFail();
        Artisan::call('subscriptions:renew-automatically');

        $this->assertSame(4000, $user->fresh()->wallet_balance);
        $this->assertSame(1, PaymentOrder::query()->where('user_id', $user->getKey())->count());
    }

    public function test_user_can_enable_and_disable_auto_renewal_for_their_active_subscription(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $subscription = $user->subscriptions()->create([
            'plan_key' => 'gold_1_month',
            'type' => 'premium',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);

        $this->putJson('/api/v1/auth/subscription/auto-renew', ['enabled' => true])
            ->assertUnauthorized();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/auth/subscription/auto-renew', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('subscription.auto_renew', true)
            ->assertJsonMissingPath('subscription.auto_renew_order_idempotency_key');

        $this->assertTrue(Str::isUuid($subscription->fresh()->auto_renew_order_idempotency_key));

        $this->putJson('/api/v1/auth/subscription/auto-renew', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('subscription.auto_renew', false);
    }

    private function createExpiredSubscription(User $user): Subscription
    {
        return $user->subscriptions()->create([
            'plan_key' => 'gold_1_month',
            'type' => 'premium',
            'status' => 'active',
            'starts_at' => now()->subDays(31),
            'ends_at' => now()->subSecond(),
            'auto_renew' => true,
            'auto_renew_order_idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function setOfferPrice(string $offerKey, int $price): void
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
}
