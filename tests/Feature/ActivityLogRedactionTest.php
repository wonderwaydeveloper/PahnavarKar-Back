<?php

namespace Tests\Feature;

use App\Events\SubscriptionActivated;
use App\Events\SubscriptionCancelled;
use App\Models\DiscountCode;
use App\Models\Otp;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_model_logs_only_allowlisted_dirty_profile_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Before Name',
            'phone' => '+989121234567',
        ]);

        $user->forceFill([
            'name' => 'After Name',
            'profile_photo_path' => 'private/profile-photo.jpg',
            'phone' => '+989121234568',
            'password' => 'password-secret-marker',
            'two_factor_secret' => 'two-factor-secret-marker',
        ])->saveOrFail();

        $activity = Activity::query()
            ->forSubject($user)
            ->where('event', 'updated')
            ->sole();
        $changes = $activity->attribute_changes->toArray();

        $this->assertSame(['name', 'profile_photo_path'], array_keys($changes['attributes']));
        $this->assertSame(['name', 'profile_photo_path'], array_keys($changes['old']));
        $this->assertSame('After Name', $changes['attributes']['name']);
        $this->assertSame('Before Name', $changes['old']['name']);
        $this->assertStringNotContainsString('+989121234567', json_encode($activity));
        $this->assertStringNotContainsString('+989121234568', json_encode($activity));
        $this->assertStringNotContainsString('password-secret-marker', json_encode($activity));
        $this->assertStringNotContainsString('two-factor-secret-marker', json_encode($activity));

        $activityCount = Activity::query()->forSubject($user)->count();
        $user->saveOrFail();
        $this->assertSame($activityCount, Activity::query()->forSubject($user)->count());
    }

    public function test_phone_change_audit_uses_only_a_marker_and_is_caused_by_the_user(): void
    {
        $user = User::factory()->create([
            'phone' => '+989121234567',
            'phone_verified_at' => now(),
        ]);
        $oldPhone = $user->phone;
        $newPhone = '+989121234568';
        $code = '482619';
        Otp::query()->create([
            'phone' => $newPhone,
            'code_hash' => Hash::make($code),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/profile/phone', [
                'phone' => $newPhone,
                'code' => $code,
            ])
            ->assertOk();

        $this->assertSame($newPhone, $user->fresh()->phone);
        $activity = Activity::query()
            ->forSubject($user)
            ->where('event', 'phone_changed')
            ->sole();

        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame(['phone' => 'changed'], $activity->properties->toArray());
        $this->assertTrue($activity->attribute_changes->isEmpty());
        $serialized = json_encode($activity);
        $this->assertStringNotContainsString($user->phone, $serialized);
        $this->assertStringNotContainsString($newPhone, $serialized);
        $this->assertStringNotContainsString($code, $serialized);
        $this->assertSame(
            0,
            Activity::query()->forSubject($user)->where('event', 'updated')->count(),
        );
    }

    public function test_financial_model_logs_contain_only_their_declared_allowlists(): void
    {
        $user = User::factory()->create();
        $expiresAt = now()->addHour();
        $idempotencyKey = (string) Str::uuid();
        $transaction = $user->transactions()->create([
            'type' => 'credit',
            'status' => 'succeeded',
            'amount' => 5000,
            'balance_after' => 5000,
            'currency' => 'TOMAN',
            'gateway' => 'zarinpal',
            'reference_id' => 'gateway-reference-secret',
            'idempotency_key' => $idempotencyKey,
            'description' => 'internal transaction description',
            'expires_at' => $expiresAt,
        ]);
        $discountCode = DiscountCode::query()->create([
            'code' => 'SAFE-DISCOUNT',
            'type' => 'fixed',
            'value' => 1000,
            'min_order_amount' => 3000,
            'max_discount' => 1000,
            'usage_limit' => 20,
            'usage_count' => 1,
            'user_id' => $user->getKey(),
            'starts_at' => now(),
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);
        $paymentOrder = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'subscription_change_type' => 'purchase',
            'plan_key' => 'gold_1_month',
            'duration_days' => 30,
            'discount_code_id' => $discountCode->getKey(),
            'discount_amount' => 1000,
            'plan_credit_amount' => 0,
            'gross_amount' => 5000,
            'wallet_amount' => 0,
            'gateway_amount' => 5000,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'failure_code' => null,
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => $expiresAt,
        ]);
        $paymentAttempt = PaymentAttempt::query()->create([
            'user_id' => $user->getKey(),
            'payment_order_id' => $paymentOrder->getKey(),
            'merchant_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'gateway' => 'zarinpal',
            'gateway_environment' => 'sandbox',
            'amount' => 5000,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'gateway_reference_id' => 'provider-reference-secret',
            'redirect_url' => 'https://gateway.example/checkout?token=redirect-secret',
            'failure_code' => null,
            'expires_at' => $expiresAt,
        ]);
        $subscription = Subscription::query()->create([
            'user_id' => $user->getKey(),
            'plan_key' => 'gold_1_month',
            'type' => 'premium',
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => $expiresAt,
        ]);
        $withdrawal = WithdrawalRequest::query()->create([
            'user_id' => $user->getKey(),
            'request_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 2000,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'review_note' => 'private review note',
            'payout_reference' => 'payout-reference-secret',
            'idempotency_expires_at' => $expiresAt,
        ]);

        $expectedKeys = [
            Transaction::class => ['type', 'status', 'amount', 'balance_after', 'currency'],
            DiscountCode::class => [
                'code', 'type', 'value', 'min_order_amount', 'max_discount', 'usage_limit',
                'usage_count', 'user_id', 'starts_at', 'expires_at', 'is_active',
            ],
            PaymentOrder::class => [
                'kind', 'subscription_change_type', 'plan_key', 'duration_days', 'discount_amount',
                'plan_credit_amount', 'gross_amount', 'wallet_amount', 'gateway_amount', 'status',
                'failure_code', 'subscription_id',
            ],
            PaymentAttempt::class => [
                'gateway', 'amount', 'currency', 'status', 'failure_code', 'transaction_id',
            ],
            Subscription::class => [
                'plan_key', 'type', 'status', 'starts_at', 'ends_at', 'cancelled_at',
            ],
            WithdrawalRequest::class => ['amount', 'currency', 'status', 'reviewed_by'],
        ];
        $activities = collect();
        foreach ([
            $transaction,
            $discountCode,
            $paymentOrder,
            $paymentAttempt,
            $subscription,
            $withdrawal,
        ] as $model) {
            $activity = Activity::query()->forSubject($model)->where('event', 'created')->sole();
            $this->assertSame($expectedKeys[$model::class], array_keys(
                $activity->attribute_changes->get('attributes'),
            ));
            $activities->push($activity);
        }

        $serializedLogs = json_encode($activities);
        foreach ([
            $idempotencyKey,
            'gateway-reference-secret',
            'provider-reference-secret',
            'redirect-secret',
            'payout-reference-secret',
            'private review note',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serializedLogs);
        }
    }

    public function test_subscription_lifecycle_events_log_only_plan_metadata(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::query()->create([
            'user_id' => $user->getKey(),
            'plan_key' => 'gold_1_month',
            'type' => 'premium',
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        event(new SubscriptionActivated($subscription));
        event(new SubscriptionCancelled($subscription));

        $activities = Activity::query()
            ->forSubject($subscription)
            ->whereIn('event', ['subscription_activated', 'subscription_cancellation_scheduled'])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $activities, $activities->map(fn (Activity $activity): string => $activity->description)->implode('; '));
        foreach ($activities as $activity) {
            $this->assertSame([
                'plan_key' => 'gold_1_month',
                'type' => 'premium',
            ], $activity->properties->toArray());
            $this->assertNull($activity->causer_id);
            $this->assertTrue($activity->attribute_changes->isEmpty());
        }
    }

    public function test_model_activity_is_rolled_back_with_the_subject_transaction(): void
    {
        $user = User::factory()->create();

        try {
            DB::transaction(function () use ($user): void {
                $user->transactions()->create([
                    'type' => 'credit',
                    'status' => 'succeeded',
                    'amount' => 5000,
                    'balance_after' => 5000,
                    'currency' => 'TOMAN',
                    'idempotency_key' => (string) Str::uuid(),
                    'expires_at' => now()->addHour(),
                ]);

                throw new RuntimeException('Force transaction rollback.');
            });
            $this->fail('The transaction should have thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force transaction rollback.', $exception->getMessage());
        }

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(
            0,
            Activity::query()->where('subject_type', Transaction::class)->count(),
        );
    }
}
