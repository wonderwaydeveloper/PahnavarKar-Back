<?php

namespace Tests\Feature;

use App\Events\SubscriptionActivated;
use App\Models\DiscountCode;
use App\Models\DiscountUsage;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class ReconcileZarinpalPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.gateways.zarinpal.credentials_configured' => true,
            'payment.gateways.zarinpal.merchant_ids.sandbox' => (string) Str::uuid(),
            'payment.gateways.zarinpal.merchant_ids.production' => (string) Str::uuid(),
            'payment.gateways.zarinpal.api_urls.sandbox' => 'https://sandbox.example/api/payment',
            'payment.gateways.zarinpal.api_urls.production' => 'https://production.example/api/payment',
        ]);
        Http::preventStrayRequests();
    }

    public function test_unverified_authority_is_matched_verified_and_credited_once(): void
    {
        $merchantId = config('payment.gateways.zarinpal.merchant_ids.sandbox');
        $user = User::factory()->create(['wallet_balance' => 500]);
        $attempt = $this->createAttempt($user, 'AUTH-UNVERIFIED');
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->once();
        });
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => [['authority' => 'AUTH-UNVERIFIED']],
                ],
            ]),
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 123456],
            ]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 1 local attempt(s); verified 1 payment(s).')
            ->assertExitCode(0);

        $transaction = Transaction::query()->sole();
        $this->assertSame(2500, $user->fresh()->wallet_balance);
        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame($transaction->getKey(), $attempt->fresh()->transaction_id);
        $this->assertNotNull($attempt->fresh()->verified_at);
        $this->assertNotNull($attempt->fresh()->last_reconciled_at);
        $this->assertSame('deposit', $transaction->type);
        $this->assertSame('zarinpal', $transaction->gateway);
        $this->assertSame('123456', $transaction->reference_id);
        $this->assertSame(2000, $transaction->amount);
        $this->assertSame(2500, $transaction->balance_after);

        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/unVerified.json'
            && $request->data() === ['merchant_id' => $merchantId]);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/verify.json'
            && $request->data() === [
                'merchant_id' => $merchantId,
                'amount' => 2000,
                'authority' => 'AUTH-UNVERIFIED',
            ]);
        Http::assertSentCount(2);

        $this->artisan('payments:reconcile-zarinpal')->assertExitCode(0);
        $this->assertSame(2500, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('transactions', 1);
        Http::assertSentCount(2);
    }

    public function test_reconciliation_keeps_sandbox_and_production_authorities_and_credentials_isolated(): void
    {
        $sandboxMerchantId = config('payment.gateways.zarinpal.merchant_ids.sandbox');
        $productionMerchantId = config('payment.gateways.zarinpal.merchant_ids.production');
        $sandboxUser = User::factory()->create(['wallet_balance' => 100]);
        $productionUser = User::factory()->create(['wallet_balance' => 200]);
        $sandboxAttempt = $this->createAttempt($sandboxUser, 'AUTH-SANDBOX', environment: 'sandbox');
        $productionAttempt = $this->createAttempt($productionUser, 'AUTH-PRODUCTION', environment: 'production');
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->twice();
        });
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => [
                        ['authority' => 'AUTH-SANDBOX'],
                        ['authority' => 'AUTH-PRODUCTION'],
                    ],
                ],
            ]),
            'https://production.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => [
                        ['authority' => 'AUTH-PRODUCTION'],
                        ['authority' => 'AUTH-SANDBOX'],
                    ],
                ],
            ]),
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 111111],
            ]),
            'https://production.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 222222],
            ]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 2 local attempt(s); verified 2 payment(s).')
            ->assertExitCode(0);

        $this->assertSame('succeeded', $sandboxAttempt->fresh()->status);
        $this->assertSame('succeeded', $productionAttempt->fresh()->status);
        $this->assertSame('sandbox', $sandboxAttempt->fresh()->gateway_environment);
        $this->assertSame('production', $productionAttempt->fresh()->gateway_environment);
        $this->assertSame(2100, $sandboxUser->fresh()->wallet_balance);
        $this->assertSame(2200, $productionUser->fresh()->wallet_balance);
        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(
            '111111',
            Transaction::query()->where('user_id', $sandboxUser->getKey())->sole()->reference_id,
        );
        $this->assertSame(
            '222222',
            Transaction::query()->where('user_id', $productionUser->getKey())->sole()->reference_id,
        );

        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/unVerified.json'
            && $request->data() === ['merchant_id' => $sandboxMerchantId]);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://production.example/api/payment/unVerified.json'
            && $request->data() === ['merchant_id' => $productionMerchantId]);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/verify.json'
            && $request->data() === [
                'merchant_id' => $sandboxMerchantId,
                'amount' => 2000,
                'authority' => 'AUTH-SANDBOX',
            ]);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://production.example/api/payment/verify.json'
            && $request->data() === [
                'merchant_id' => $productionMerchantId,
                'amount' => 2000,
                'authority' => 'AUTH-PRODUCTION',
            ]);
        Http::assertSentCount(4);
    }

    public function test_authority_list_from_other_environment_cannot_match_or_verify_local_attempt(): void
    {
        $sandboxMerchantId = config('payment.gateways.zarinpal.merchant_ids.sandbox');
        $productionMerchantId = config('payment.gateways.zarinpal.merchant_ids.production');
        $sandboxUser = User::factory()->create(['wallet_balance' => 100]);
        $productionUser = User::factory()->create(['wallet_balance' => 200]);
        $sandboxAttempt = $this->createAttempt($sandboxUser, 'AUTH-ONLY-SANDBOX', environment: 'sandbox');
        $productionAttempt = $this->createAttempt($productionUser, 'AUTH-ONLY-PRODUCTION', environment: 'production');
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => [['authority' => 'AUTH-ONLY-PRODUCTION']],
                ],
            ]),
            'https://production.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => [['authority' => 'AUTH-ONLY-SANDBOX']],
                ],
            ]),
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'IN_BANK'],
            ]),
            'https://production.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'IN_BANK'],
            ]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 0 local attempt(s); verified 0 payment(s).')
            ->assertExitCode(0);

        foreach ([$sandboxAttempt, $productionAttempt] as $attempt) {
            $this->assertSame('pending', $attempt->fresh()->status);
            $this->assertNotNull($attempt->fresh()->last_reconciled_at);
            $this->assertNull($attempt->fresh()->transaction_id);
        }
        $this->assertSame(100, $sandboxUser->fresh()->wallet_balance);
        $this->assertSame(200, $productionUser->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(4);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/inquiry.json'
            && $request->data() === [
                'merchant_id' => $sandboxMerchantId,
                'authority' => 'AUTH-ONLY-SANDBOX',
            ]);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://production.example/api/payment/inquiry.json'
            && $request->data() === [
                'merchant_id' => $productionMerchantId,
                'authority' => 'AUTH-ONLY-PRODUCTION',
            ]);
        Http::assertNotSent(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/verify.json'));
    }

    public function test_reconciliation_processes_at_most_one_hundred_unverified_attempts_per_environment_per_run(): void
    {
        $sandboxMerchantId = config('payment.gateways.zarinpal.merchant_ids.sandbox');
        $productionMerchantId = config('payment.gateways.zarinpal.merchant_ids.production');
        $sandboxUser = User::factory()->create(['wallet_balance' => 0]);
        $productionUser = User::factory()->create(['wallet_balance' => 0]);
        $sandboxAttempts = collect(range(1, 105))
            ->map(fn (int $number): PaymentAttempt => $this->createAttempt(
                $sandboxUser,
                sprintf('AUTH-SANDBOX-BATCH-%04d', $number),
                environment: 'sandbox',
            ));
        $productionAttempts = collect(range(1, 105))
            ->map(fn (int $number): PaymentAttempt => $this->createAttempt(
                $productionUser,
                sprintf('AUTH-PRODUCTION-BATCH-%04d', $number),
                environment: 'production',
            ));
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->times(210);
        });
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => $sandboxAttempts
                        ->map(static fn (PaymentAttempt $attempt): array => [
                            'authority' => $attempt->gateway_reference_id,
                        ])
                        ->all(),
                ],
            ]),
            'https://production.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => $productionAttempts
                        ->map(static fn (PaymentAttempt $attempt): array => [
                            'authority' => $attempt->gateway_reference_id,
                        ])
                        ->all(),
                ],
            ]),
            'https://sandbox.example/api/payment/verify.json' => static function (ClientRequest $request) use ($sandboxMerchantId) {
                if ($request->data()['merchant_id'] !== $sandboxMerchantId) {
                    throw new \RuntimeException('Verification used the wrong sandbox merchant ID.');
                }

                return Http::response([
                    'data' => ['code' => 100, 'ref_id' => 'SANDBOX-'.$request->data()['authority']],
                ]);
            },
            'https://production.example/api/payment/verify.json' => static function (ClientRequest $request) use ($productionMerchantId) {
                if ($request->data()['merchant_id'] !== $productionMerchantId) {
                    throw new \RuntimeException('Verification used the wrong production merchant ID.');
                }

                return Http::response([
                    'data' => ['code' => 100, 'ref_id' => 'PRODUCTION-'.$request->data()['authority']],
                ]);
            },
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'IN_BANK'],
            ]),
            'https://production.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'IN_BANK'],
            ]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 200 local attempt(s); verified 200 payment(s).')
            ->assertExitCode(0);

        $this->assertSame(200, Transaction::query()->count());
        $this->assertSame(200000, $sandboxUser->fresh()->wallet_balance);
        $this->assertSame(200000, $productionUser->fresh()->wallet_balance);
        $this->assertSame(100, $sandboxAttempts->filter(
            static fn (PaymentAttempt $attempt): bool => $attempt->fresh()->status === 'succeeded',
        )->count());
        $this->assertSame(100, $productionAttempts->filter(
            static fn (PaymentAttempt $attempt): bool => $attempt->fresh()->status === 'succeeded',
        )->count());
        $this->assertSame(5, $sandboxAttempts->filter(
            static fn (PaymentAttempt $attempt): bool => $attempt->fresh()->status === 'pending',
        )->count());
        $this->assertSame(5, $productionAttempts->filter(
            static fn (PaymentAttempt $attempt): bool => $attempt->fresh()->status === 'pending',
        )->count());
        Http::assertSentCount(202);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/unVerified.json'
            && $request->data() === ['merchant_id' => $sandboxMerchantId]);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://production.example/api/payment/unVerified.json'
            && $request->data() === ['merchant_id' => $productionMerchantId]);
        Http::assertNotSent(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/inquiry.json'));

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 10 local attempt(s); verified 10 payment(s).')
            ->assertExitCode(0);

        $this->assertSame(210, Transaction::query()->count());
        $this->assertSame(210000, $sandboxUser->fresh()->wallet_balance);
        $this->assertSame(210000, $productionUser->fresh()->wallet_balance);
        $this->assertSame(210, PaymentAttempt::query()->where('status', 'succeeded')->count());
        Http::assertSentCount(214);
        Http::assertNotSent(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/inquiry.json'));
    }

    public function test_unverified_processing_and_inquiry_share_the_one_hundred_attempt_budget(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $unverifiedAttempts = collect(range(1, 50))
            ->map(fn (int $number): PaymentAttempt => $this->createAttempt(
                $user,
                sprintf('AUTH-UNVERIFIED-%03d', $number),
            ));
        $inquiryAttempts = collect(range(1, 100))
            ->map(fn (int $number): PaymentAttempt => $this->createAttempt(
                $user,
                sprintf('AUTH-INQUIRY-%03d', $number),
            ));
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => $unverifiedAttempts
                        ->map(static fn (PaymentAttempt $attempt): array => [
                            'authority' => $attempt->gateway_reference_id,
                        ])
                        ->all(),
                ],
            ]),
            'https://sandbox.example/api/payment/verify.json' => static fn (ClientRequest $request) => Http::response([
                'data' => [
                    'code' => 100,
                    'ref_id' => 'REF-'.$request->data()['authority'],
                ],
            ]),
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'IN_BANK'],
            ]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 50 local attempt(s); verified 50 payment(s).')
            ->assertExitCode(0);

        $this->assertSame(50, $unverifiedAttempts->filter(
            static fn (PaymentAttempt $attempt): bool => $attempt->fresh()->status === 'succeeded',
        )->count());
        $this->assertSame(50, $inquiryAttempts->filter(
            static fn (PaymentAttempt $attempt): bool => $attempt->fresh()->last_reconciled_at !== null,
        )->count());
        $this->assertSame(50, $inquiryAttempts->filter(
            static fn (PaymentAttempt $attempt): bool => $attempt->fresh()->last_reconciled_at === null,
        )->count());
        $this->assertSame(50, Transaction::query()->count());
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        Http::assertSentCount(101);
        $this->assertSame(
            50,
            Http::recorded(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/verify.json'))->count(),
        );
        $this->assertSame(
            50,
            Http::recorded(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/inquiry.json'))->count(),
        );
    }

    public function test_inquiry_paid_status_is_verified_before_payment_is_credited(): void
    {
        $merchantId = config('payment.gateways.zarinpal.merchant_ids.sandbox');
        $user = User::factory()->create(['wallet_balance' => 100]);
        $attempt = $this->createAttempt($user, 'AUTH-PAID');
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->once();
        });
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => ['code' => 100, 'authorities' => []],
            ]),
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'PAID'],
            ]),
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 101, 'ref_id' => 789012],
            ]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 0 local attempt(s); verified 1 payment(s).')
            ->assertExitCode(0);

        $transaction = Transaction::query()->sole();
        $this->assertSame(2100, $user->fresh()->wallet_balance);
        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame('789012', $transaction->reference_id);
        $this->assertSame($transaction->getKey(), $attempt->fresh()->transaction_id);
        $this->assertNotNull($attempt->fresh()->last_reconciled_at);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/inquiry.json'
            && $request->data() === [
                'merchant_id' => $merchantId,
                'authority' => 'AUTH-PAID',
            ]);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/verify.json'
            && $request->data()['authority'] === 'AUTH-PAID');
        Http::assertSentCount(3);
    }

    public function test_in_bank_attempt_stays_pending_and_is_not_inquired_again_before_thirty_minutes(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);
        $attempt = $this->createAttempt($user, 'AUTH-IN-BANK');
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->once();
        });
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => ['code' => 100, 'authorities' => []],
            ]),
            'https://sandbox.example/api/payment/inquiry.json' => Http::sequence()
                ->push(['data' => ['code' => 100, 'status' => 'IN_BANK']])
                ->push(['data' => ['code' => 100, 'status' => 'PAID']]),
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 654321],
            ]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 0 local attempt(s); verified 0 payment(s).')
            ->assertExitCode(0);

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertNotNull($attempt->fresh()->last_reconciled_at);
        $firstCheckedAt = $attempt->fresh()->last_reconciled_at;
        Http::assertSentCount(2);

        $this->artisan('payments:reconcile-zarinpal')->assertExitCode(0);
        Http::assertSentCount(3);
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertEquals($firstCheckedAt, $attempt->fresh()->last_reconciled_at);

        $this->travel(31)->minutes();
        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 0 local attempt(s); verified 1 payment(s).')
            ->assertExitCode(0);

        $transaction = Transaction::query()->sole();
        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame($transaction->getKey(), $attempt->fresh()->transaction_id);
        $this->assertNotEquals($firstCheckedAt, $attempt->fresh()->last_reconciled_at);
        $this->assertSame(2300, $user->fresh()->wallet_balance);
        $this->assertSame('654321', $transaction->reference_id);
        Http::assertSentCount(6);
        $this->assertSame(
            2,
            Http::recorded(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/inquiry.json'))->count(),
        );
        $this->assertSame(
            1,
            Http::recorded(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/verify.json'))->count(),
        );
    }

    public function test_unverified_authority_with_nonfinal_verification_is_retried_after_thirty_minutes(): void
    {
        $merchantId = config('payment.gateways.zarinpal.merchant_ids.sandbox');
        $user = User::factory()->create(['wallet_balance' => 300]);
        $attempt = $this->createAttempt($user, 'AUTH-RETRY-VERIFY');
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUser')->once();
        });
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => [['authority' => 'AUTH-RETRY-VERIFY']],
                ],
            ]),
            'https://sandbox.example/api/payment/verify.json' => Http::sequence()
                ->push(['data' => ['code' => 102]])
                ->push(['data' => ['code' => 100, 'ref_id' => 987654]]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Could not verify Zarinpal attempt '.$attempt->getKey().'.')
            ->expectsOutput('Matched 1 local attempt(s); verified 0 payment(s).')
            ->assertExitCode(1);

        $firstCheckedAt = $attempt->fresh()->last_reconciled_at;
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertNotNull($firstCheckedAt);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(2);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 0 local attempt(s); verified 0 payment(s).')
            ->assertExitCode(0);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertEquals($firstCheckedAt, $attempt->fresh()->last_reconciled_at);
        Http::assertSentCount(3);

        $this->travel(31)->minutes();
        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 1 local attempt(s); verified 1 payment(s).')
            ->assertExitCode(0);

        $transaction = Transaction::query()->sole();
        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame($transaction->getKey(), $attempt->fresh()->transaction_id);
        $this->assertNotEquals($firstCheckedAt, $attempt->fresh()->last_reconciled_at);
        $this->assertSame(2300, $user->fresh()->wallet_balance);
        $this->assertSame('987654', $transaction->reference_id);
        Http::assertSentCount(5);
        $this->assertSame(
            2,
            Http::recorded(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/verify.json'))->count(),
        );
        Http::assertNotSent(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/inquiry.json'));
        Http::assertSent(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/verify.json')
            && $request->data() === [
                'merchant_id' => $merchantId,
                'amount' => 2000,
                'authority' => 'AUTH-RETRY-VERIFY',
            ]);
    }

    public function test_failed_or_reversed_inquiry_requires_review_without_crediting_attempt(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);
        $failedAttempt = $this->createAttempt($user, 'AUTH-FAILED');
        $reversedAttempt = $this->createAttempt($user, 'AUTH-REVERSED');
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => ['code' => 100, 'authorities' => []],
            ]),
            'https://sandbox.example/api/payment/inquiry.json' => Http::sequence()
                ->push(['data' => ['code' => 100, 'status' => 'FAILED']])
                ->push(['data' => ['code' => 100, 'status' => 'REVERSED']]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Attempt '.$failedAttempt->getKey().' requires review (FAILED).')
            ->expectsOutput('Attempt '.$reversedAttempt->getKey().' requires review (REVERSED).')
            ->assertExitCode(1);

        $this->assertSame('pending', $failedAttempt->fresh()->status);
        $this->assertSame('pending', $reversedAttempt->fresh()->status);
        $this->assertNotNull($failedAttempt->fresh()->last_reconciled_at);
        $this->assertNotNull($reversedAttempt->fresh()->last_reconciled_at);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(3);
    }

    public function test_unmatched_initiation_without_authority_fails_for_manual_reconciliation_without_http_requests(): void
    {
        $user = User::factory()->create();
        $attempt = $this->createAttempt($user, null, 'initiation_unknown');
        Http::fake();

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('1 Zarinpal initiation(s) have no known authority and require manual reconciliation.')
            ->expectsOutput('No Zarinpal attempts with a known authority require reconciliation.')
            ->assertExitCode(1);

        $this->assertSame('initiation_unknown', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->gateway_reference_id);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertNothingSent();
    }

    public function test_provider_error_leaves_pending_attempt_unchanged_and_returns_failure(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);
        $attempt = $this->createAttempt($user, 'AUTH-UNAVAILABLE');
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'errors' => ['code' => 500],
            ], 503),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Could not query Zarinpal sandbox unverified payments.')
            ->assertExitCode(1);

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->last_reconciled_at);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(1);
    }

    public function test_malformed_unverified_authority_list_leaves_attempt_untouched(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);
        $attempt = $this->createAttempt($user, 'AUTH-MALFORMED-LIST');
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => [['unexpected_key' => 'AUTH-MALFORMED-LIST']],
                ],
            ]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Could not query Zarinpal sandbox unverified payments.')
            ->assertExitCode(1);

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->last_reconciled_at);
        $this->assertNull($attempt->fresh()->transaction_id);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(1);
        Http::assertNotSent(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/verify.json')
            || str_ends_with($request->url(), '/inquiry.json'));
    }

    public function test_inquiry_connection_failure_leaves_attempt_eligible_for_retry_without_credit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);
        $attempt = $this->createAttempt($user, 'AUTH-INQUIRY-UNAVAILABLE');
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => ['code' => 100, 'authorities' => []],
            ]),
            'https://sandbox.example/api/payment/inquiry.json' => Http::failedConnection(),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Could not inquire Zarinpal attempt '.$attempt->getKey().'.')
            ->assertExitCode(1);

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->last_reconciled_at);
        $this->assertNull($attempt->fresh()->transaction_id);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(2);
        Http::assertNotSent(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/verify.json'));
    }

    public function test_paid_inquiry_with_failed_verification_does_not_credit_and_is_backed_off(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);
        $attempt = $this->createAttempt($user, 'AUTH-VERIFY-UNAVAILABLE');
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => ['code' => 100, 'authorities' => []],
            ]),
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'PAID'],
            ]),
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'errors' => ['code' => 500],
            ], 502),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Could not verify Zarinpal attempt '.$attempt->getKey().'.')
            ->expectsOutput('Matched 0 local attempt(s); verified 0 payment(s).')
            ->assertExitCode(1);

        $checkedAt = $attempt->fresh()->last_reconciled_at;
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertNotNull($checkedAt);
        $this->assertNull($attempt->fresh()->transaction_id);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(3);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 0 local attempt(s); verified 0 payment(s).')
            ->assertExitCode(0);

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertEquals($checkedAt, $attempt->fresh()->last_reconciled_at);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(4);
        $this->assertSame(
            1,
            Http::recorded(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/inquiry.json'))->count(),
        );
        $this->assertSame(
            1,
            Http::recorded(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/verify.json'))->count(),
        );
    }

    public function test_wallet_ledger_write_failure_rolls_back_reconciliation_settlement(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);
        $attempt = $this->createAttempt($user, 'AUTH-LEDGER-FAILURE');
        $this->mock(ExpoPushService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendToUser');
        });
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => [['authority' => 'AUTH-LEDGER-FAILURE']],
                ],
            ]),
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 345678],
            ]),
        ]);

        $databaseFailure = 'Simulated wallet ledger write failure.';
        $failureInjected = false;
        DB::connection()->beforeExecuting(static function (string $query) use (
            &$failureInjected,
            $databaseFailure,
        ): void {
            $normalizedQuery = strtolower(ltrim($query));
            if (
                ! $failureInjected
                && str_starts_with($normalizedQuery, 'insert')
                && str_contains($normalizedQuery, 'transactions')
            ) {
                $failureInjected = true;
                throw new \RuntimeException($databaseFailure);
            }
        });

        try {
            $this->artisan('payments:reconcile-zarinpal');
            $this->fail('The reconciliation should surface the database write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($databaseFailure, $exception->getMessage());
        }

        $this->assertTrue($failureInjected, 'The failure hook must target the ledger insert.');
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_id);
        $this->assertNull($attempt->fresh()->verified_at);
        $this->assertNotNull($attempt->fresh()->last_reconciled_at);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(2);
    }

    public function test_subscription_write_failure_rolls_back_order_ledger_attempt_and_subscription_settlement(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);
        $discount = DiscountCode::query()->create([
            'code' => 'RECONCILE-RESERVED',
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => 2,
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
            'gross_amount' => 2100,
            'wallet_amount' => 0,
            'gateway_amount' => 2000,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $attempt = PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'payment_order_id' => $order->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'gateway' => 'zarinpal',
            'gateway_environment' => 'sandbox',
            'amount' => 2000,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'gateway_reference_id' => 'AUTH-SUBSCRIPTION-ROLLBACK',
            'expires_at' => now()->addDay(),
        ]);
        $usage = DiscountUsage::query()->create([
            'discount_code_id' => $discount->getKey(),
            'user_id' => $user->getKey(),
            'payment_order_id' => $order->getKey(),
            'order_amount' => 2100,
            'discount_amount' => 100,
            'status' => 'reserved',
        ]);
        Event::fake([SubscriptionActivated::class]);
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => [['authority' => 'AUTH-SUBSCRIPTION-ROLLBACK']],
                ],
            ]),
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 456789],
            ]),
        ]);

        $databaseFailure = 'Simulated subscription insert failure.';
        $failureInjected = false;
        DB::connection()->beforeExecuting(static function (string $query) use (
            &$failureInjected,
            $databaseFailure,
        ): void {
            $normalizedQuery = strtolower(ltrim($query));
            if (
                ! $failureInjected
                && str_starts_with($normalizedQuery, 'insert')
                && str_contains($normalizedQuery, 'subscriptions')
            ) {
                $failureInjected = true;
                throw new \RuntimeException($databaseFailure);
            }
        });

        try {
            $this->artisan('payments:reconcile-zarinpal');
            $this->fail('The reconciliation should surface the subscription insert failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($databaseFailure, $exception->getMessage());
        }

        $this->assertTrue($failureInjected, 'The failure hook must target subscription insertion.');
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->transaction_id);
        $this->assertNull($attempt->fresh()->verified_at);
        $this->assertNotNull($attempt->fresh()->last_reconciled_at);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->transaction_id);
        $this->assertNull($order->fresh()->subscription_id);
        $this->assertNull($order->fresh()->completed_at);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame('reserved', $usage->fresh()->status);
        $this->assertSame(1, $discount->fresh()->usage_count);
        Event::assertNotDispatched(SubscriptionActivated::class);
        Http::assertSentCount(2);
    }

    public function test_provider_authority_without_local_match_is_ignored(): void
    {
        $merchantId = config('payment.gateways.zarinpal.merchant_ids.sandbox');
        $user = User::factory()->create(['wallet_balance' => 300]);
        $attempt = $this->createAttempt($user, 'AUTH-LOCAL-ONLY');
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => [['authority' => 'AUTH-UNKNOWN-LOCAL']],
                ],
            ]),
            'https://sandbox.example/api/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'IN_BANK'],
            ]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Matched 0 local attempt(s); verified 0 payment(s).')
            ->assertExitCode(0);

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertNotNull($attempt->fresh()->last_reconciled_at);
        Http::assertSentCount(2);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.example/api/payment/inquiry.json'
            && $request->data() === [
                'merchant_id' => $merchantId,
                'authority' => 'AUTH-LOCAL-ONLY',
            ]);
        Http::assertNotSent(static fn (ClientRequest $request): bool => str_ends_with($request->url(), '/verify.json'));
    }

    public function test_nonfinal_verification_response_is_reported_for_retry_without_credit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);
        $attempt = $this->createAttempt($user, 'AUTH-VERIFY-UNKNOWN');
        Http::fake([
            'https://sandbox.example/api/payment/unVerified.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'authorities' => [['authority' => 'AUTH-VERIFY-UNKNOWN']],
                ],
            ]),
            'https://sandbox.example/api/payment/verify.json' => Http::response([
                'data' => ['code' => 102],
            ]),
        ]);

        $this->artisan('payments:reconcile-zarinpal')
            ->expectsOutput('Could not verify Zarinpal attempt '.$attempt->getKey().'.')
            ->expectsOutput('Matched 1 local attempt(s); verified 0 payment(s).')
            ->assertExitCode(1);

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->last_reconciled_at);
        $this->assertNull($attempt->fresh()->transaction_id);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::query()->count());
        Http::assertSentCount(2);
    }

    private function createAttempt(
        User $user,
        ?string $authority,
        string $status = 'pending',
        string $environment = 'sandbox',
    ): PaymentAttempt {
        return PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'gateway' => 'zarinpal',
            'gateway_environment' => $environment,
            'amount' => 2000,
            'currency' => 'TOMAN',
            'status' => $status,
            'gateway_reference_id' => $authority,
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
    }
}
