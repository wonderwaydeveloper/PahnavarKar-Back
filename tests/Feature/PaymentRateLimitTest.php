<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\PaymentRequest;
use App\Models\AdminSetting;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewaySettings;
use App\Models\PaymentOrder;
use App\Models\Permission;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PaymentRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        Http::preventStrayRequests();
    }

    public function test_wallet_top_up_rate_limit_blocks_sixth_request_without_creating_payment_attempt(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 500]);
        $this->actingAs($user, 'sanctum');
        $this->mockPaymentGatewayManager(6);

        for ($request = 0; $request < 5; $request++) {
            $this->postJson('/api/v1/auth/wallet/top-ups', ['amount' => 12000], [
                'Idempotency-Key' => (string) Str::uuid(),
            ])->assertCreated();
        }

        $this->assertSame(5, PaymentAttempt::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(500, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());

        $this->setSubscriptionPrice(5000);
        $quote = app(PaymentService::class)->quoteSubscriptionChange($user, 'gold_1_month');
        $expectedQuote = array_intersect_key($quote, array_flip([
            'gross_amount',
            'credit_amount',
            'discount_amount',
            'payable_amount',
            'duration_days',
            'change_type',
            'source_subscription_id',
            'source_plan_key',
        ]));
        $this->postJson('/api/v1/auth/subscription/purchase', [
            'plan_key' => 'gold_1_month',
            'expected_quote' => $expectedQuote,
        ], [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertAccepted();

        $this->assertSame(5, PaymentAttempt::query()
            ->where('user_id', $user->getKey())
            ->whereNull('payment_order_id')
            ->count());
        $this->assertSame(1, PaymentOrder::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(6, PaymentAttempt::query()->where('user_id', $user->getKey())->count());
        $balanceBeforeBlockedRequest = $user->fresh()->wallet_balance;
        $transactionCountBeforeBlockedRequest = Transaction::query()->count();

        $this->postJson('/api/v1/auth/wallet/top-ups', ['amount' => 12000], [
            'Idempotency-Key' => (string) Str::uuid(),
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertSame(5, PaymentAttempt::query()
            ->where('user_id', $user->getKey())
            ->whereNull('payment_order_id')
            ->count());
        $this->assertSame(1, PaymentOrder::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(6, PaymentAttempt::query()->where('user_id', $user->getKey())->count());
        $this->assertSame($balanceBeforeBlockedRequest, $user->fresh()->wallet_balance);
        $this->assertSame($transactionCountBeforeBlockedRequest, Transaction::query()->count());
    }

    public function test_subscription_purchase_rate_limit_blocks_sixth_order_without_financial_side_effects(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 0]);
        $this->actingAs($user, 'sanctum');
        $this->setSubscriptionPrice(5000);
        $quote = app(PaymentService::class)->quoteSubscriptionChange($user, 'gold_1_month');
        $expectedQuote = array_intersect_key($quote, array_flip([
            'gross_amount',
            'credit_amount',
            'discount_amount',
            'payable_amount',
            'duration_days',
            'change_type',
            'source_subscription_id',
            'source_plan_key',
        ]));
        $this->mockPaymentGatewayManager(1);
        $purchasePayload = [
            'plan_key' => 'gold_1_month',
            'expected_quote' => $expectedQuote,
        ];
        $this->postJson('/api/v1/auth/subscription/purchase', $purchasePayload, [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertAccepted();

        for ($request = 0; $request < 4; $request++) {
            $this->postJson('/api/v1/auth/subscription/purchase', [
                'plan_key' => 'invalid-plan',
            ], [
                'Idempotency-Key' => (string) Str::uuid(),
            ])->assertUnprocessable();
        }

        $this->assertSame(1, PaymentOrder::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(1, PaymentAttempt::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, $user->subscriptions()->count());
        $this->assertSame(0, $user->fresh()->wallet_balance);

        $this->postJson('/api/v1/auth/subscription/purchase', $purchasePayload, [
            'Idempotency-Key' => (string) Str::uuid(),
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertSame(1, PaymentOrder::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(1, PaymentAttempt::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, $user->subscriptions()->count());
        $this->assertSame(0, $user->fresh()->wallet_balance);
    }

    public function test_callback_rate_limit_blocks_request_121_without_reverifying_or_duplicating_credit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500]);
        $attempt = $this->createZarinpalAttempt($user, 'AUTH-CALLBACK-RATE-LIMIT');
        $this->configureZarinpalCallback();
        Http::fake([
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 567890],
            ]),
        ]);
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->once();
        });
        $callbackUrl = '/api/v1/payment/callback/zarinpal?Status=OK&Authority=AUTH-CALLBACK-RATE-LIMIT';

        for ($request = 0; $request < 120; $request++) {
            $this->getJson($callbackUrl)->assertOk()->assertJsonPath('payment.status', 'succeeded');
        }

        $this->postJson('/api/v1/webhook/payment/zarinpal', [
            'Status' => 'OK',
            'Authority' => 'AUTH-CALLBACK-RATE-LIMIT',
        ])->assertOk()->assertJsonPath('payment.status', 'succeeded');

        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame(12500, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->count());
        Http::assertSentCount(1);

        $this->getJson($callbackUrl)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '120')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame(12500, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->count());
        Http::assertSentCount(1);
    }

    public function test_webhook_rate_limit_blocks_request_121_without_reverification_or_duplicate_credit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500]);
        $attempt = $this->createZarinpalAttempt($user, 'AUTH-WEBHOOK-RATE-LIMIT');
        $this->configureZarinpalCallback();
        Http::fake([
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 678901],
            ]),
        ]);
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->once();
        });
        $webhookUrl = '/api/v1/webhook/payment/zarinpal';
        $payload = ['Status' => 'OK', 'Authority' => 'AUTH-WEBHOOK-RATE-LIMIT'];

        for ($request = 0; $request < 120; $request++) {
            $this->postJson($webhookUrl, $payload)->assertOk()->assertJsonPath('payment.status', 'succeeded');
        }

        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame(12500, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->count());
        Http::assertSentCount(1);

        $this->postJson($webhookUrl, $payload)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '120')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame(12500, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->count());
        Http::assertSentCount(1);
    }

    public function test_gateway_admin_rate_limit_blocks_update_31_without_changing_settings_or_audit(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('manage payment gateways', 'web'));
        $this->actingAs($admin, 'sanctum');

        $gatewayManager = Mockery::mock(PaymentGatewayManager::class);
        $gatewayManager->shouldReceive('configuredGateways')->times(60)->andReturn(['zarinpal']);
        $gatewayManager->shouldReceive('availableGateways')->times(30)->andReturn(['zarinpal']);
        $this->app->instance(PaymentGatewayManager::class, $gatewayManager);

        $payload = [
            'default_gateway' => 'zarinpal',
            'enabled_gateways' => ['zarinpal'],
            'failover_order' => [],
        ];
        for ($request = 0; $request < 30; $request++) {
            $this->putJson('/api/v1/admin/payment-gateways', $payload)->assertOk();
        }

        $settings = PaymentGatewaySettings::query()->sole();
        $activityCount = Activity::query()->forSubject($settings)->count();
        $this->assertSame('zarinpal', $settings->default_gateway);
        $this->assertSame(['zarinpal'], $settings->enabled_gateways);
        $this->assertSame([], $settings->failover_order);

        $this->putJson('/api/v1/admin/payment-gateways', [
            ...$payload,
            'default_gateway' => 'other-gateway',
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertSame('zarinpal', $settings->fresh()->default_gateway);
        $this->assertSame(['zarinpal'], $settings->fresh()->enabled_gateways);
        $this->assertSame([], $settings->fresh()->failover_order);
        $this->assertSame($activityCount, Activity::query()->forSubject($settings)->count());
    }

    private function mockPaymentGatewayManager(int $initiationCount): void
    {
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('initiate')
            ->times($initiationCount)
            ->andReturnUsing(static fn (PaymentRequest $request): GatewayPaymentResult => new GatewayPaymentResult(
                status: 'pending',
                gatewayReference: 'test-'.Str::uuid(),
                redirectUrl: 'https://payments.example/'.rawurlencode($request->merchantReference),
            ));

        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('purchaseOrder')->times($initiationCount)->andReturn(['test-gateway']);
        $manager->shouldReceive('gateway')->times($initiationCount)->with('test-gateway')->andReturn($gateway);
        $this->app->instance(PaymentGatewayManager::class, $manager);
    }

    private function setSubscriptionPrice(int $price): void
    {
        $plans = config('plans');
        $plans['offers']['gold_1_month']['price'] = $price;
        AdminSetting::query()->create([
            'setting_key' => 'plans',
            'value' => $plans,
        ]);
        Cache::forget('admin-runtime-settings:v2:plans');
    }

    private function createZarinpalAttempt(User $user, string $authority): PaymentAttempt
    {
        return PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'zarinpal',
            'gateway_environment' => 'sandbox',
            'amount' => 12000,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'gateway_reference_id' => $authority,
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
    }

    private function configureZarinpalCallback(): void
    {
        config([
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => (string) Str::uuid(),
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
    }
}
