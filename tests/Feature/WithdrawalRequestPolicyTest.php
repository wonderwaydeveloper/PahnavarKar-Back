<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class WithdrawalRequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdrawals_are_disabled_by_default(): void
    {
        config(['wallet.withdrawals.enabled' => false]);
        Cache::forget('admin-runtime-settings:v2:wallet_withdrawals');

        $user = User::factory()->create([
            'is_active' => true,
            'wallet_balance' => 500000,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/wallet/withdrawal-requests', [
                'amount' => 100000,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable();

        $this->assertSame(500000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('withdrawal_requests', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_daily_cap_counts_pending_approved_and_paid_but_not_other_days_or_rejected(): void
    {
        config(['wallet.withdrawals.enabled' => true]);
        Cache::forget('admin-runtime-settings:v2:wallet_withdrawals');

        $user = User::factory()->create([
            'is_active' => true,
            'wallet_balance' => 1000000,
        ]);

        $today = now()->setTime(12, 0);
        $this->createRequest($user, 100000, 'pending', $today);
        $this->createRequest($user, 100000, 'approved_pending_payout', $today);
        $this->createRequest($user, 200000, 'paid', $today);
        $this->createRequest($user, 100000, 'paid', $today->copy()->addDay());
        $this->createRequest($user, 250000, 'pending', $today->copy()->subDay());
        $this->createRequest($user, 500000, 'rejected', $today);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/wallet/withdrawal-requests', [
                'amount' => 100000,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $this->postJson('/api/v1/auth/wallet/withdrawal-requests', [
            'amount' => 1,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable();

        $this->assertSame(900000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('withdrawal_requests', 7);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_withdrawal_amount_limits_and_insufficient_balance_do_not_change_financial_state(): void
    {
        config([
            'wallet.withdrawals.enabled' => true,
            'wallet.withdrawals.max_per_request' => 100000,
        ]);
        Cache::forget('admin-runtime-settings:v2:wallet_withdrawals');

        $user = User::factory()->create([
            'is_active' => true,
            'wallet_balance' => 999,
        ]);
        $this->actingAs($user, 'sanctum');

        foreach ([0, 100001] as $amount) {
            $this->postJson('/api/v1/auth/wallet/withdrawal-requests', [
                'amount' => $amount,
                'idempotency_key' => (string) Str::uuid(),
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/wallet/withdrawal-requests', [
            'amount' => 1000,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable();

        $this->assertSame(999, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('withdrawal_requests', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_withdrawal_idempotency_replays_once_and_is_scoped_to_the_user(): void
    {
        config(['wallet.withdrawals.enabled' => true]);
        Cache::forget('admin-runtime-settings:v2:wallet_withdrawals');

        $user = User::factory()->create([
            'is_active' => true,
            'wallet_balance' => 300000,
        ]);
        $otherUser = User::factory()->create([
            'is_active' => true,
            'wallet_balance' => 300000,
        ]);
        $key = (string) Str::uuid();
        $payload = ['amount' => 100000, 'idempotency_key' => $key];

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/wallet/withdrawal-requests', $payload)
            ->assertCreated()
            ->assertJsonMissingPath('withdrawal_request.idempotency_key');
        $firstId = $first->json('withdrawal_request.id');

        $this->postJson('/api/v1/auth/wallet/withdrawal-requests', $payload)
            ->assertOk()
            ->assertJsonPath('withdrawal_request.id', $firstId);

        $this->postJson('/api/v1/auth/wallet/withdrawal-requests', [
            ...$payload,
            'amount' => 90000,
        ])->assertConflict()
            ->assertJsonPath('code', 'withdrawal_request_conflict');

        $this->actingAs($otherUser, 'sanctum')
            ->postJson('/api/v1/auth/wallet/withdrawal-requests', $payload)
            ->assertCreated()
            ->assertJsonMissingPath('withdrawal_request.idempotency_key');

        $this->assertSame(200000, $user->fresh()->wallet_balance);
        $this->assertSame(200000, $otherUser->fresh()->wallet_balance);
        $this->assertDatabaseCount('withdrawal_requests', 2);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame(1, WithdrawalRequest::query()->where('user_id', $otherUser->getKey())->count());
    }

    public function test_failed_withdrawal_request_insert_rolls_back_balance_and_hold_transaction(): void
    {
        config(['wallet.withdrawals.enabled' => true]);
        Cache::forget('admin-runtime-settings:v2:wallet_withdrawals');

        $user = User::factory()->create(['wallet_balance' => 200000]);
        DB::statement(
            "CREATE TRIGGER fail_withdrawal_request_insert BEFORE INSERT ON withdrawal_requests
             BEGIN SELECT RAISE(ABORT, 'injected withdrawal insert failure'); END",
        );

        try {
            app(WithdrawalRequestService::class)->create(
                $user,
                100000,
                (string) Str::uuid(),
            );
            $this->fail('The injected withdrawal request insert failure was not raised.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('injected withdrawal insert failure', $exception->getMessage());
        }

        $this->assertSame(200000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('withdrawal_requests', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0, Activity::query()->where('log_name', 'withdrawals')->count());
    }

    private function createRequest(User $user, int $amount, string $status, Carbon $createdAt): WithdrawalRequest
    {
        $withdrawal = $user->withdrawalRequests()->create([
            'request_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'amount' => $amount,
            'currency' => 'TOMAN',
            'status' => $status,
            'idempotency_expires_at' => now()->addDay(),
        ]);

        $withdrawal->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $withdrawal;
    }
}
