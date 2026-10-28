<?php

namespace Tests\Feature;

use App\Data\Payments\PaymentRequest;
use App\Exceptions\PaymentConflictException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\DiscountCode;
use App\Models\DiscountUsage;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\ZarinpalGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class ZarinpalGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_initiation_uses_sandbox_credentials_and_builds_an_encoded_redirect_url(): void
    {
        $merchantId = (string) Str::uuid();
        config([
            'payment.gateways.zarinpal.merchant_ids.sandbox' => $merchantId,
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
            'payment.gateways.zarinpal.start_urls.sandbox' => 'https://sandbox.example/pg/StartPay',
        ]);
        Http::fake([
            '*' => Http::response(['data' => ['code' => 100, 'authority' => 'AUTH/123']], 200),
        ]);

        $result = app(ZarinpalGateway::class)->initiate(new PaymentRequest(
            amount: 12000,
            currency: 'TOMAN',
            reference: 'attempt:order-ref',
            merchantReference: 'order-ref',
            callbackUrl: 'https://app.example/api/v1/payment/callback/zarinpal',
            idempotencyKey: (string) Str::uuid(),
            environment: 'sandbox',
        ));

        $this->assertSame('pending', $result->status);
        $this->assertSame('AUTH/123', $result->gatewayReference);
        $this->assertSame('https://sandbox.example/pg/StartPay/AUTH%2F123', $result->redirectUrl);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/request.json'
            && $request->data()['merchant_id'] === $merchantId
            && $request->data()['amount'] === 12000
            && $request->data()['currency'] === 'IRT'
            && $request->data()['callback_url'] === 'https://app.example/api/v1/payment/callback/zarinpal'
            && $request->data()['metadata']['auto_verify'] === false);
    }

    public function test_successful_initiation_response_without_authority_is_ambiguous_and_not_retried(): void
    {
        $merchantId = (string) Str::uuid();
        config([
            'payment.default' => 'zarinpal',
            'payment.environment' => 'sandbox',
            'payment.max_top_up' => 1000000,
            'payment.gateways.zarinpal.enabled' => true,
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => $merchantId,
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
            'payment.gateways.zarinpal.start_urls.sandbox' => 'https://sandbox.example/pg/StartPay',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/request.json' => Http::response([
                'data' => ['code' => 100],
            ]),
        ]);
        $user = User::factory()->create();
        $idempotencyKey = (string) Str::uuid();
        $payments = app(PaymentService::class);

        try {
            $payments->startWalletTopUp($user, 12000, $idempotencyKey);
            $this->fail('A successful provider code without its authority must remain ambiguous.');
        } catch (PaymentGatewayUnavailableException) {
            $attempt = PaymentAttempt::query()->sole();
            $this->assertSame('initiation_unknown', $attempt->status);
            $this->assertSame('provider_result_unknown', $attempt->failure_code);
            $this->assertSame(0, Transaction::query()->count());
        }

        try {
            $payments->startWalletTopUp($user, 12000, $idempotencyKey);
            $this->fail('An ambiguous initiation must not start a duplicate charge.');
        } catch (PaymentConflictException) {
            $this->assertDatabaseCount('payment_attempts', 1);
        }

        Http::assertSentCount(1);
    }

    public function test_initiation_connection_failure_is_ambiguous_and_blocks_replay(): void
    {
        config([
            'payment.default' => 'zarinpal',
            'payment.environment' => 'sandbox',
            'payment.max_top_up' => 1000000,
            'payment.gateways.zarinpal.enabled' => true,
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => (string) Str::uuid(),
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
            'payment.gateways.zarinpal.start_urls.sandbox' => 'https://sandbox.example/pg/StartPay',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/request.json' => Http::failedConnection(),
        ]);
        $user = User::factory()->create();
        $idempotencyKey = (string) Str::uuid();
        $payments = app(PaymentService::class);

        try {
            $payments->startWalletTopUp($user, 12000, $idempotencyKey);
            $this->fail('A connection failure must be reported as an unknown initiation result.');
        } catch (PaymentGatewayUnavailableException) {
            $attempt = PaymentAttempt::query()->sole();
            $this->assertSame('initiation_unknown', $attempt->status);
            $this->assertSame('provider_result_unknown', $attempt->failure_code);
            $this->assertSame(0, Transaction::query()->count());
        }

        try {
            $payments->startWalletTopUp($user, 12000, $idempotencyKey);
            $this->fail('An ambiguous initiation must not start a duplicate charge.');
        } catch (PaymentConflictException) {
            $this->assertDatabaseCount('payment_attempts', 1);
        }

        Http::assertSentCount(1);
    }

    public function test_verification_sends_the_persisted_amount_and_accepts_provider_success_codes(): void
    {
        $merchantId = (string) Str::uuid();
        config([
            'payment.gateways.zarinpal.merchant_ids.sandbox' => $merchantId,
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            '*' => Http::response(['data' => ['code' => 101, 'ref_id' => 987654]], 200),
        ]);
        $attempt = $this->createAttempt('AUTH-123');

        $result = app(ZarinpalGateway::class)->verify($attempt, Request::create(
            '/callback?Status=OK&Authority=AUTH-123',
            'GET',
        ));

        $this->assertSame('succeeded', $result->status);
        $this->assertSame('987654', $result->gatewayReference);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/verify.json'
            && $request->data()['merchant_id'] === $merchantId
            && $request->data()['amount'] === 12000
            && $request->data()['authority'] === 'AUTH-123');
    }

    public function test_customer_cancellation_is_failed_only_after_provider_confirms_failure(): void
    {
        $merchantId = (string) Str::uuid();
        config([
            'payment.gateways.zarinpal.merchant_ids.sandbox' => $merchantId,
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'FAILED'],
            ]),
        ]);
        $attempt = $this->createAttempt('AUTH-123');

        $result = app(ZarinpalGateway::class)->verify($attempt, Request::create(
            '/callback?Status=NOK&Authority=AUTH-123',
            'GET',
        ));

        $this->assertSame('failed', $result->status);
        $this->assertSame('inquiry_failed', $result->failureCode);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/inquiry.json'
            && $request->data() === [
                'merchant_id' => $merchantId,
                'authority' => 'AUTH-123',
            ]);
        Http::assertSentCount(1);
    }

    public function test_confirmed_reversal_is_terminal_without_treating_inquiry_as_payment_verification(): void
    {
        config([
            'payment.gateways.zarinpal.merchant_ids.sandbox' => (string) Str::uuid(),
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'REVERSED'],
            ]),
        ]);
        $attempt = $this->createAttempt('AUTH-123');

        $result = app(ZarinpalGateway::class)->verify($attempt, Request::create(
            '/callback?Status=NOK&Authority=AUTH-123',
            'GET',
        ));

        $this->assertSame('failed', $result->status);
        $this->assertSame('inquiry_reversed', $result->failureCode);
        Http::assertSentCount(1);
    }

    public function test_nok_callback_remains_pending_when_provider_reports_in_bank(): void
    {
        $attempt = $this->createAttempt('AUTH-123');
        config([
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => (string) Str::uuid(),
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'IN_BANK'],
            ]),
        ]);

        $response = $this->get('/api/v1/payment/callback/zarinpal?Status=NOK&Authority=AUTH-123');

        $response->assertOk()->assertJsonPath('payment.status', 'pending');
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(0, $attempt->user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(1);
    }

    public function test_nok_callback_that_provider_reports_as_paid_uses_server_verification(): void
    {
        $merchantId = (string) Str::uuid();
        $user = User::factory()->create(['wallet_balance' => 500]);
        $attempt = $this->createAttempt('AUTH-123', $user);
        config([
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => $merchantId,
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'PAID'],
            ]),
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 101, 'ref_id' => 987654],
            ]),
        ]);
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->once();
        });

        $response = $this->get('/api/v1/payment/callback/zarinpal?Status=NOK&Authority=AUTH-123');

        $response->assertOk()->assertJsonPath('payment.status', 'succeeded');
        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame(12500, $user->fresh()->wallet_balance);
        $this->assertSame('987654', Transaction::query()->sole()->reference_id);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/verify.json'
            && $request->data() === [
                'merchant_id' => $merchantId,
                'amount' => 12000,
                'authority' => 'AUTH-123',
            ]);
        Http::assertSentCount(2);
    }

    public function test_forged_ok_callback_claim_cannot_settle_without_provider_success(): void
    {
        $merchantId = (string) Str::uuid();
        $user = User::factory()->create(['wallet_balance' => 500]);
        $attempt = $this->createAttempt('AUTH-FORGED-OK', $user);
        config([
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => $merchantId,
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 102],
            ]),
        ]);

        $response = $this->get('/api/v1/payment/callback/zarinpal?Status=OK&Authority=AUTH-FORGED-OK');

        $response->assertServiceUnavailable();
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_id);
        $this->assertNull($attempt->fresh()->verified_at);
        $this->assertSame(500, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/verify.json'
            && $request->data() === [
                'merchant_id' => $merchantId,
                'amount' => 12000,
                'authority' => 'AUTH-FORGED-OK',
            ]);
        Http::assertSentCount(1);
    }

    public function test_successful_callback_replay_does_not_repeat_verification_or_credit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500]);
        $attempt = $this->createAttempt('AUTH-REPLAY', $user);
        config([
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => (string) Str::uuid(),
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 987654],
            ]),
        ]);
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->once();
        });

        $callbackUrl = '/api/v1/payment/callback/zarinpal?Status=OK&Authority=AUTH-REPLAY';
        $firstResponse = $this->get($callbackUrl);
        $replayResponse = $this->get($callbackUrl);

        $firstResponse->assertOk()->assertJsonPath('payment.status', 'succeeded');
        $replayResponse->assertOk()->assertJsonPath('payment.status', 'succeeded');
        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->verified_at);
        $this->assertSame(12500, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame('987654', Transaction::query()->sole()->reference_id);
        Http::assertSentCount(1);
    }

    public function test_nok_callback_does_not_refund_subscription_wallet_or_release_discount_while_in_bank(): void
    {
        $merchantId = (string) Str::uuid();
        $user = User::factory()->create(['wallet_balance' => 0]);
        $discount = DiscountCode::query()->create([
            'code' => 'PENDING'.strtoupper(Str::random(8)),
            'type' => 'fixed',
            'value' => 100,
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
        $debit = Transaction::query()->create([
            'user_id' => $user->getKey(),
            'type' => 'debit',
            'status' => 'succeeded',
            'amount' => 200,
            'balance_after' => 0,
            'currency' => 'TOMAN',
            'idempotency_key' => (string) Str::uuid(),
            'reference_id' => 'subscription-order:'.$order->order_reference,
            'description' => 'Subscription wallet contribution',
            'expires_at' => now()->addDay(),
        ]);
        $order->forceFill(['wallet_transaction_id' => $debit->getKey()])->saveOrFail();
        $attempt = PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'payment_order_id' => $order->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'zarinpal',
            'gateway_environment' => 'sandbox',
            'amount' => 700,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'gateway_reference_id' => 'AUTH-SUBSCRIPTION',
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
        config([
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => $merchantId,
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'IN_BANK'],
            ]),
        ]);

        $response = $this->get('/api/v1/payment/callback/zarinpal?Status=NOK&Authority=AUTH-SUBSCRIPTION');

        $response->assertRedirect();
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $user->fresh()->wallet_balance);
        $this->assertSame(['debit'], Transaction::query()->where('user_id', $user->getKey())->pluck('type')->all());
        $this->assertSame(1, $discount->fresh()->usage_count);
        $this->assertSame('reserved', $usage->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_verification_rejects_authority_mismatch_before_contacting_provider(): void
    {
        Http::fake();
        $attempt = $this->createAttempt('AUTH-123');

        $this->expectException(PaymentGatewayUnavailableException::class);
        try {
            app(ZarinpalGateway::class)->verify($attempt, Request::create(
                '/callback?Status=OK&Authority=OTHER',
                'GET',
            ));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_unknown_verification_code_remains_pending_for_reconciliation(): void
    {
        config([
            'payment.gateways.zarinpal.merchant_ids.sandbox' => (string) Str::uuid(),
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            '*' => Http::response(['data' => ['code' => 102]], 200),
        ]);
        $attempt = $this->createAttempt('AUTH-123');

        $this->expectException(PaymentGatewayUnavailableException::class);
        app(ZarinpalGateway::class)->verify($attempt, Request::create(
            '/callback?Status=OK&Authority=AUTH-123',
            'GET',
        ));
    }

    public function test_http_errors_and_incomplete_verification_responses_keep_callback_attempt_pending(): void
    {
        $merchantId = (string) Str::uuid();
        config([
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => $merchantId,
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        $responses = Http::sequence()
            ->push(['errors' => ['code' => 'temporary_failure']], 502)
            ->push([], 200)
            ->push(['data' => ['code' => 101]], 200)
            ->push(['data' => ['code' => 101, 'ref_id' => []]], 200);
        Http::fake([
            'https://sandbox.example/api/payment/verify.json' => $responses,
        ]);

        foreach (['HTTP-ERROR', 'MISSING-DATA', 'MISSING-REFERENCE', 'INVALID-REFERENCE'] as $index => $authority) {
            $attempt = $this->createAttempt($authority);
            $response = $this->get('/api/v1/payment/callback/zarinpal?'.http_build_query([
                'Status' => 'OK',
                'Authority' => $authority,
            ]));

            $response->assertServiceUnavailable();
            $this->assertSame('pending', $attempt->fresh()->status);
            $this->assertSame(0, $attempt->user->fresh()->wallet_balance);
            $this->assertSame(0, Transaction::query()->count());
            Http::assertSentCount($index + 1);
        }
    }

    public function test_verification_connection_failure_keeps_callback_attempt_pending(): void
    {
        config([
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => (string) Str::uuid(),
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/verify.json' => Http::failedConnection(),
        ]);
        $attempt = $this->createAttempt('AUTH-TIMEOUT');

        $response = $this->get('/api/v1/payment/callback/zarinpal?Status=OK&Authority=AUTH-TIMEOUT');

        $response->assertServiceUnavailable();
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(0, $attempt->user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(1);
    }

    public function test_inquiry_connection_failure_does_not_finalize_nok_callback(): void
    {
        config([
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => (string) Str::uuid(),
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/inquiry.json' => Http::failedConnection(),
        ]);
        $attempt = $this->createAttempt('AUTH-INQUIRY-TIMEOUT');

        $response = $this->get('/api/v1/payment/callback/zarinpal?Status=NOK&Authority=AUTH-INQUIRY-TIMEOUT');

        $response->assertServiceUnavailable();
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(0, $attempt->user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(1);
    }

    public function test_documented_terminal_verify_codes_fail_but_ambiguous_codes_remain_unresolved(): void
    {
        config([
            'payment.gateways.zarinpal.merchant_ids.sandbox' => (string) Str::uuid(),
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        $attempt = $this->createAttempt('AUTH-123');
        $codes = [-51, -54, -55, -50, -52, -53, 102];
        $responses = Http::sequence();
        foreach ($codes as $code) {
            $responses->push(['data' => ['code' => $code]]);
        }
        Http::fake([
            'https://sandbox.example/api/payment/verify.json' => $responses,
        ]);

        foreach ([-51, -54, -55] as $code) {
            $result = app(ZarinpalGateway::class)->verify($attempt, Request::create(
                '/callback?Status=OK&Authority=AUTH-123',
                'GET',
            ));

            $this->assertSame('failed', $result->status);
            $this->assertSame('verification_code_'.$code, $result->failureCode);
        }

        foreach ([-50, -52, -53, 102] as $code) {
            try {
                app(ZarinpalGateway::class)->verify($attempt, Request::create(
                    '/callback?Status=OK&Authority=AUTH-123',
                    'GET',
                ));
                $this->fail("Verification code {$code} must remain unresolved.");
            } catch (PaymentGatewayUnavailableException) {
                $this->assertSame('pending', $attempt->status);
            }
        }
    }

    public function test_callback_rejects_missing_or_undocumented_fields_without_contacting_provider(): void
    {
        $attempt = $this->createAttempt('AUTH-123');
        config(['payment.gateways.zarinpal.credentials_configured' => true]);
        Http::preventStrayRequests();

        foreach ([
            '/api/v1/payment/callback/zarinpal?Status=UNKNOWN&Authority=AUTH-123',
            '/api/v1/payment/callback/zarinpal?Status=OK',
        ] as $callbackUrl) {
            $response = $this->get($callbackUrl);

            $response->assertServiceUnavailable();
        }

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertNothingSent();
    }

    public function test_nok_callback_with_local_merchant_reference_is_rejected_before_provider_inquiry(): void
    {
        $attempt = $this->createAttempt('AUTH-123');
        config([
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => (string) Str::uuid(),
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
        ]);
        Http::fake([
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'FAILED'],
            ]),
        ]);

        $response = $this->get('/api/v1/payment/callback/zarinpal?'.http_build_query([
            'Status' => 'NOK',
            'Authority' => $attempt->merchant_reference,
        ]));

        $response->assertServiceUnavailable();
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertNothingSent();
    }

    private function createAttempt(string $authority, ?User $user = null): PaymentAttempt
    {
        $user ??= User::factory()->create();

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
}
