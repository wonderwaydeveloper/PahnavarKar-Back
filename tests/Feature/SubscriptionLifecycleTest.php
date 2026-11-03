<?php

namespace Tests\Feature;

use App\Events\SubscriptionActivated;
use App\Events\SubscriptionCancelled;
use App\Exceptions\SubscriptionConflictException;
use App\Jobs\SendExpoPushNotification;
use App\Models\AdminSetting;
use App\Models\DiscountCode;
use App\Models\PaymentOrder;
use App\Models\PushSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Payments\PaymentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_quote_keeps_selected_duration_and_credits_remaining_source_value(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        $this->configureOfferPrice('silver_3_months', 9000);
        $this->configureOfferPrice('silver_6_months', 18000);
        $user = User::factory()->create(['wallet_balance' => 10000]);
        $source = $this->createActiveSubscription($user, 'bronze_1_month', 300, 30);
        $source->forceFill([
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'auto_renew' => true,
            'auto_renew_order_idempotency_key' => (string) Str::uuid(),
        ])->save();
        $payments = app(PaymentService::class);

        $quote = $payments->quoteSubscriptionChange($user, 'silver_3_months');

        $this->assertSame('upgrade', $quote['change_type']);
        $this->assertSame($source->getKey(), $quote['source_subscription_id']);
        $this->assertSame(90, $quote['duration_days']);
        $this->assertSame(250, $quote['credit_amount']);
        $this->assertSame(8750, $quote['gross_amount']);
        $this->assertSame(8750, $quote['payable_amount']);

        $sixMonthQuote = $payments->quoteSubscriptionChange($user, 'silver_6_months');
        $this->assertSame(180, $sixMonthQuote['duration_days']);
        $this->assertSame(18000, $sixMonthQuote['gross_amount'] + $sixMonthQuote['credit_amount']);
        $this->assertTrue(Carbon::parse($sixMonthQuote['ends_at'])->equalTo(now()->addDays(180)));

        $order = $payments->purchasePremium(
            $user,
            'silver_3_months',
            null,
            (string) Str::uuid(),
            $quote,
        );

        $this->assertSame('succeeded', $order->status);
        $this->assertSame('upgrade', $order->subscription_change_type);
        $this->assertSame($source->getKey(), $order->source_subscription_id);
        $this->assertSame(8750, $order->wallet_amount);
        $this->assertSame(0, $order->gateway_amount);
        $this->assertSame(1250, $user->fresh()->wallet_balance);
        $this->assertSame('replaced', $source->fresh()->status);
        $this->assertFalse($source->fresh()->auto_renew);
        $this->assertNotNull($source->fresh()->cancelled_at);

        $upgradedSubscription = Subscription::query()->findOrFail($order->subscription_id);
        $this->assertSame('silver_3_months', $upgradedSubscription->plan_key);
        $this->assertSame('active', $upgradedSubscription->status);
        $this->assertTrue($upgradedSubscription->starts_at->equalTo(now()));
        $this->assertTrue($upgradedSubscription->ends_at->equalTo(now()->addDays(90)));
        $this->assertDatabaseCount('subscriptions', 2);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/subscription')
            ->assertOk()
            ->assertJsonCount(2, 'subscriptions')
            ->assertJsonPath('subscriptions.0.status', 'active')
            ->assertJsonPath('subscriptions.1.status', 'replaced')
            ->assertJsonPath('subscriptions.1.replaced_by.id', $upgradedSubscription->getKey())
            ->assertJsonPath('subscriptions.1.replaced_by.plan_key', 'silver_3_months')
            ->assertJsonPath('subscriptions.1.replaced_by.replaced_at', $upgradedSubscription->created_at->toISOString());
    }

    public function test_upgrade_discount_is_applied_after_remaining_plan_credit_before_payment_split(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        $this->configureOfferPrice('silver_3_months', 9000);
        $user = User::factory()->create(['wallet_balance' => 500]);
        $source = $this->createActiveSubscription($user, 'bronze_1_month', 300, 30);
        $source->forceFill([
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ])->save();
        $discount = DiscountCode::query()->create([
            'code' => 'UPGRADE10',
            'type' => 'percentage',
            'value' => 10,
            'min_order_amount' => 0,
            'usage_limit' => 1,
            'usage_count' => 0,
            'is_active' => true,
        ]);

        $quote = app(PaymentService::class)
            ->quoteSubscriptionChange($user, 'silver_3_months', $discount->code);

        $this->assertSame('upgrade', $quote['change_type']);
        $this->assertSame(250, $quote['credit_amount']);
        $this->assertSame(8750, $quote['gross_amount']);
        $this->assertSame(875, $quote['discount_amount']);
        $this->assertSame(7875, $quote['payable_amount']);
        $this->assertSame(500, $quote['wallet_amount']);
        $this->assertSame(7375, $quote['gateway_amount']);
        $this->assertSame(0, $discount->fresh()->usage_count);
    }

    public function test_lower_tier_purchase_is_scheduled_after_the_active_subscription_and_blocks_another_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        $this->configureOfferPrice('gold_1_month', 5000);
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
        $this->assertFalse($source->fresh()->auto_renew);
        $this->assertSame(500, $user->fresh()->wallet_balance);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/auth/subscription/auto-renew', ['enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');
        $this->assertFalse($source->fresh()->auto_renew);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.plan_key', 'gold_1_month')
            ->assertJsonPath('subscription.auto_renew', false)
            ->assertJsonPath('scheduled_subscription.plan_key', 'silver_1_month')
            ->assertJsonPath('scheduled_subscription.is_active', false)
            ->assertJsonCount(2, 'subscriptions')
            ->assertJsonPath('subscriptions.0.plan_key', 'silver_1_month')
            ->assertJsonPath('subscriptions.0.reservation_created_at', $scheduled->created_at->toISOString())
            ->assertJsonPath('subscriptions.1.plan_key', 'gold_1_month');

        try {
            $payments->quoteSubscriptionChange($user, 'gold_1_month');
            $this->fail('Another subscription purchase must be rejected while a plan is reserved.');
        } catch (SubscriptionConflictException) {
            $this->assertDatabaseCount('subscriptions', 2);
            $this->assertSame(500, $user->fresh()->wallet_balance);
        }
    }

    public function test_scheduled_subscription_cancellation_refunds_wallet_payment_and_releases_discount_for_revalidation(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        $this->configureOfferPrice('gold_1_month', 5000);
        $this->configureOfferPrice('silver_1_month', 1000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 1500]);
        $source = $this->createActiveSubscription($user, 'gold_1_month', 5000, 30);
        $discount = DiscountCode::query()->create([
            'code' => 'RESERVE10',
            'type' => 'percentage',
            'value' => 10,
            'min_order_amount' => 0,
            'usage_limit' => 1,
            'usage_count' => 0,
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $payments = app(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'silver_1_month', $discount->code);
        $order = $payments->purchasePremium(
            $user,
            'silver_1_month',
            $discount->code,
            (string) Str::uuid(),
            $quote,
        );
        $scheduled = Subscription::query()->findOrFail($order->subscription_id);
        $pushSubscription = PushSubscription::query()->create([
            'user_id' => $user->getKey(),
            'push_token' => 'ExponentPushToken[scheduled-cancel]',
            'platform' => 'ios',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/subscription')
            ->assertOk()
            ->assertJsonPath('scheduled_subscription.refundable_amount', 900);

        $this->deleteJson('/api/v1/auth/subscription/scheduled')
            ->assertOk()
            ->assertJsonPath('subscription.id', $scheduled->getKey())
            ->assertJsonPath('subscription.status', 'cancelled')
            ->assertJsonPath('subscription.refund_amount', 900)
            ->assertJsonPath('refund_amount', 900);

        $walletDebit = Transaction::query()->findOrFail($order->wallet_transaction_id);
        $refund = Transaction::query()->findOrFail($order->fresh()->refund_transaction_id);
        $this->assertSame('refunded', $walletDebit->status);
        $this->assertSame($walletDebit->getKey(), $refund->reverses_transaction_id);
        $this->assertSame(900, $refund->amount);
        $this->assertSame(
            'وجه پرداخت‌شده بابت پلن رزروشدهٔ لغوشده به کیف پول بازگردانده شد.',
            $refund->description,
        );
        $this->assertSame(1500, $user->fresh()->wallet_balance);
        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame('refunded', $discount->usages()->where('payment_order_id', $order->getKey())->value('status'));
        $this->assertSame(0, $discount->fresh()->usage_count);

        $replayedResponse = $this->deleteJson('/api/v1/auth/subscription/scheduled')
            ->assertOk()
            ->assertJsonPath('subscription.id', $scheduled->getKey())
            ->assertJsonPath('refund_amount', 900);
        $this->assertSame(1500, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->where('type', 'refund')->count());
        $this->assertNotNull($replayedResponse->json('subscription.refund_amount'));
        $notification = UserNotification::query()
            ->where('event', 'subscription.scheduled_cancelled')
            ->sole();
        $this->assertSame('subscription.scheduled_cancelled', $notification->event);
        $this->assertSame('subscription', $notification->category);
        $this->assertSame('important', $notification->priority);
        $this->assertSame('پلن رزروشده لغو شد', $notification->title);
        $this->assertSame($order->getKey(), $notification->data['payment_order_id']);
        $this->assertSame(900, $notification->data['refund_amount']);
        $this->assertSame(
            'پلن نقره‌ای یک‌ماهه لغو شد. ۹۰۰ تومان به کیف پول شما بازگشت. تمدید خودکار پلن فعلی فعال نشده است. '
                .'سهمیهٔ استفاده از کد تخفیف قبلی آزاد شد؛ استفادهٔ دوباره فقط در صورت معتبر بودن کد هنگام خرید بعدی ممکن است.',
            $notification->body,
        );
        Queue::assertPushed(
            SendExpoPushNotification::class,
            fn (SendExpoPushNotification $job): bool => $job->subscriptionId === $pushSubscription->getKey()
                && ($job->data['event'] ?? null) === 'subscription.scheduled_cancelled'
                && ($job->data['notification_id'] ?? null) === $notification->getKey(),
        );
        Queue::assertPushed(SendExpoPushNotification::class, 1);

        $reusedQuote = $payments->quoteSubscriptionChange($user, 'silver_1_month', $discount->code);
        $this->assertSame(100, $reusedQuote['discount_amount']);
        $discount->forceFill(['is_active' => false])->saveOrFail();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/subscription/quote', [
                'plan_key' => 'silver_1_month',
                'discount_code' => $discount->code,
            ])
            ->assertUnprocessable();
        $this->assertSame(0, $discount->fresh()->usage_count);
    }

    public function test_cancelling_a_fully_discounted_scheduled_plan_does_not_claim_that_a_refund_was_paid(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 0]);
        $order = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'subscription_change_type' => 'scheduled',
            'plan_key' => 'silver_1_month',
            'duration_days' => 30,
            'gross_amount' => 1000,
            'discount_amount' => 1000,
            'wallet_amount' => 0,
            'gateway_amount' => 0,
            'currency' => 'TOMAN',
            'status' => 'succeeded',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
            'effective_starts_at' => now()->addDays(30),
            'effective_ends_at' => now()->addDays(60),
            'completed_at' => now(),
        ]);
        $scheduled = $user->subscriptions()->create([
            'payment_order_id' => $order->getKey(),
            'plan_key' => 'silver_1_month',
            'type' => 'premium',
            'status' => 'active',
            'starts_at' => now()->addDays(30),
            'ends_at' => now()->addDays(60),
        ]);
        $order->forceFill(['subscription_id' => $scheduled->getKey()])->saveOrFail();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/subscription/scheduled')
            ->assertOk()
            ->assertJsonPath('refund_amount', 0);

        $notification = UserNotification::query()
            ->where('event', 'subscription.scheduled_cancelled')
            ->sole();
        $this->assertSame(
            'پلن نقره‌ای یک‌ماهه لغو شد. چون مبلغی بابت این پلن پرداخت نشده بود، بازپرداختی به کیف پول انجام نشد. '
                .'تمدید خودکار پلن فعلی فعال نشده است.',
            $notification->body,
        );
        $this->assertStringNotContainsString('کد تخفیف', $notification->body);
        $this->assertSame(0, Transaction::query()->where('type', 'refund')->count());
    }

    public function test_scheduled_subscription_cancellation_refunds_wallet_and_gateway_shares_atomically(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 300]);
        $orderReference = (string) Str::uuid();
        $order = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => $orderReference,
            'kind' => 'subscription',
            'subscription_change_type' => 'scheduled',
            'plan_key' => 'silver_1_month',
            'duration_days' => 30,
            'gross_amount' => 1000,
            'wallet_amount' => 300,
            'gateway_amount' => 700,
            'currency' => 'TOMAN',
            'status' => 'succeeded',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
            'effective_starts_at' => now()->addDays(30),
            'effective_ends_at' => now()->addDays(60),
            'completed_at' => now(),
        ]);
        $wallet = app(WalletService::class);
        $walletDebit = $wallet->debitForPurchase(
            $user,
            300,
            (string) Str::uuid(),
            $orderReference,
        );
        $gatewayPayment = $wallet->recordExternalPurchase($user, $order, 'zarinpal', 'gateway-subscription-1');
        $order->forceFill([
            'wallet_transaction_id' => $walletDebit->getKey(),
            'transaction_id' => $gatewayPayment->getKey(),
        ])->saveOrFail();
        $scheduled = $user->subscriptions()->create([
            'payment_order_id' => $order->getKey(),
            'transaction_id' => $gatewayPayment->getKey(),
            'plan_key' => 'silver_1_month',
            'type' => 'premium',
            'status' => 'active',
            'starts_at' => now()->addDays(30),
            'ends_at' => now()->addDays(60),
        ]);
        $order->forceFill(['subscription_id' => $scheduled->getKey()])->saveOrFail();

        $gatewayPayment->forceFill(['amount' => 699])->saveOrFail();
        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/subscription/scheduled')
            ->assertConflict();
        $this->assertSame(0, $user->fresh()->wallet_balance);
        $this->assertSame('succeeded', $walletDebit->fresh()->status);
        $this->assertSame('succeeded', $gatewayPayment->fresh()->status);
        $this->assertSame(0, Transaction::query()->where('type', 'refund')->count());
        $this->assertSame('active', $scheduled->fresh()->status);

        $gatewayPayment->forceFill(['amount' => 700])->saveOrFail();
        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/subscription/scheduled')
            ->assertOk()
            ->assertJsonPath('refund_amount', 1000)
            ->assertJsonPath('subscription.status', 'cancelled');

        $walletRefund = Transaction::query()
            ->where('reverses_transaction_id', $walletDebit->getKey())
            ->firstOrFail();
        $gatewayRefund = Transaction::query()
            ->where('reverses_transaction_id', $gatewayPayment->getKey())
            ->firstOrFail();
        $this->assertSame(300, $walletRefund->amount);
        $this->assertSame(700, $gatewayRefund->amount);
        $this->assertSame(
            'وجه پرداخت‌شده بابت پلن رزروشدهٔ لغوشده به کیف پول بازگردانده شد.',
            $gatewayRefund->description,
        );
        $this->assertSame('refunded', $walletDebit->fresh()->status);
        $this->assertSame('refunded', $gatewayPayment->fresh()->status);
        $this->assertSame(1000, $user->fresh()->wallet_balance);
        $this->assertSame($gatewayRefund->getKey(), $order->fresh()->refund_transaction_id);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/payment-orders/'.$order->getKey())
            ->assertOk()
            ->assertJsonPath('order.refunded_amount', 1000)
            ->assertJsonCount(2, 'refund_transactions');
    }

    public function test_scheduled_subscription_cannot_be_cancelled_after_its_start_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        $this->configureOfferPrice('gold_1_month', 5000);
        $this->configureOfferPrice('silver_1_month', 1000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 1500]);
        $this->createActiveSubscription($user, 'gold_1_month', 5000, 30);
        $payments = app(PaymentService::class);
        $quote = $payments->quoteSubscriptionChange($user, 'silver_1_month');
        $order = $payments->purchasePremium(
            $user,
            'silver_1_month',
            null,
            (string) Str::uuid(),
            $quote,
        );
        $scheduled = Subscription::query()->findOrFail($order->subscription_id);

        $this->travelTo($scheduled->starts_at);
        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/subscription/scheduled')
            ->assertConflict();

        $this->assertSame('active', $scheduled->fresh()->status);
        $this->assertNull($scheduled->fresh()->cancelled_at);
        $this->assertSame(500, $user->fresh()->wallet_balance);
        $this->assertNull($order->fresh()->refund_transaction_id);
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

    public function test_quote_includes_wallet_and_gateway_split_and_rejects_changed_balance(): void
    {
        $this->configureOfferPrice('gold_1_month', 1000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 300]);

        $quoteResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/subscription/quote', ['plan_key' => 'gold_1_month'])
            ->assertOk()
            ->assertJsonPath('quote.gross_amount', 1000)
            ->assertJsonPath('quote.payable_amount', 1000)
            ->assertJsonPath('quote.wallet_amount', 300)
            ->assertJsonPath('quote.gateway_amount', 700);
        $expectedQuote = $this->expectedQuote($quoteResponse->json('quote'));

        $user->forceFill(['wallet_balance' => 200])->saveOrFail();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'expected_quote' => $expectedQuote,
            ])
            ->assertConflict();

        $this->postJson('/api/v1/auth/subscription/quote', ['plan_key' => 'gold_1_month'])
            ->assertOk()
            ->assertJsonPath('quote.wallet_amount', 200)
            ->assertJsonPath('quote.gateway_amount', 800);

        $this->assertSame(200, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_quote_supports_wallet_combined_and_gateway_payment_modes(): void
    {
        $this->configureOfferPrice('gold_1_month', 1000);
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 300]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/subscription/quote', [
                'plan_key' => 'gold_1_month',
                'payment_method' => 'gateway',
            ])
            ->assertOk()
            ->assertJsonPath('quote.payment_method', 'gateway')
            ->assertJsonPath('quote.wallet_amount', 0)
            ->assertJsonPath('quote.gateway_amount', 1000);

        $this->postJson('/api/v1/auth/subscription/quote', [
            'plan_key' => 'gold_1_month',
            'payment_method' => 'combined',
        ])
            ->assertOk()
            ->assertJsonPath('quote.payment_method', 'combined')
            ->assertJsonPath('quote.wallet_amount', 300)
            ->assertJsonPath('quote.gateway_amount', 700);

        $this->postJson('/api/v1/auth/subscription/quote', [
            'plan_key' => 'gold_1_month',
            'payment_method' => 'wallet',
        ])->assertConflict();

        $user->forceFill(['wallet_balance' => 1200])->saveOrFail();
        $this->postJson('/api/v1/auth/subscription/quote', [
            'plan_key' => 'gold_1_month',
            'payment_method' => 'combined',
        ])
            ->assertOk()
            ->assertJsonPath('quote.payment_method', 'combined')
            ->assertJsonPath('quote.wallet_amount', 1000)
            ->assertJsonPath('quote.gateway_amount', 0);

        $walletQuoteResponse = $this->postJson('/api/v1/auth/subscription/quote', [
            'plan_key' => 'gold_1_month',
            'payment_method' => 'wallet',
        ])
            ->assertOk()
            ->assertJsonPath('quote.payment_method', 'wallet')
            ->assertJsonPath('quote.wallet_amount', 1000)
            ->assertJsonPath('quote.gateway_amount', 0);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'gold_1_month',
                'payment_method' => 'wallet',
                'expected_quote' => $this->expectedQuote($walletQuoteResponse->json('quote')),
            ])
            ->assertCreated()
            ->assertJsonPath('order.wallet_amount', 1000)
            ->assertJsonPath('order.gateway_amount', 0);
        $this->assertSame(200, $user->fresh()->wallet_balance);
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
                'wallet_amount',
                'gateway_amount',
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
            'wallet_amount',
            'gateway_amount',
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
