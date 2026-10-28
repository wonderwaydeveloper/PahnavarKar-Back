<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class WithdrawalRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'wallet.withdrawals.enabled' => true,
        ]);
        Cache::store('array')->flush();
        Cache::forget('admin-runtime-settings:v2:wallet_withdrawals');
    }

    public function test_user_withdrawal_rate_limit_blocks_sixth_request_without_financial_side_effects(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'wallet_balance' => 1000000,
        ]);
        $this->actingAs($user, 'sanctum');

        for ($request = 0; $request < 5; $request++) {
            $this->postJson('/api/v1/auth/wallet/withdrawal-requests', [
                'amount' => 100000,
                'idempotency_key' => (string) Str::uuid(),
            ])->assertCreated();
        }

        $this->assertSame(500000, $user->fresh()->wallet_balance);
        $this->assertSame(5, WithdrawalRequest::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(5, Transaction::query()->where('user_id', $user->getKey())->count());

        $this->postJson('/api/v1/auth/wallet/withdrawal-requests', [
            'amount' => 100000,
            'idempotency_key' => (string) Str::uuid(),
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertSame(500000, $user->fresh()->wallet_balance);
        $this->assertSame(5, WithdrawalRequest::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(5, Transaction::query()->where('user_id', $user->getKey())->count());
    }

    public function test_admin_withdrawal_queue_limit_is_separate_from_review_actions(): void
    {
        $admin = $this->createWalletAdmin();
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $withdrawal = $this->createPendingWithdrawal($user);
        $this->actingAs($admin, 'sanctum');

        for ($request = 0; $request < 30; $request++) {
            $this->getJson('/api/v1/admin/wallet/withdrawal-requests')->assertOk();
        }

        $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$withdrawal->getKey()}/approve")
            ->assertOk()
            ->assertJsonPath('withdrawal_request.status', 'approved_pending_payout');

        $this->getJson('/api/v1/admin/wallet/withdrawal-requests')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertSame('approved_pending_payout', $withdrawal->fresh()->status);
    }

    public function test_admin_approval_rate_limit_blocks_31st_action_without_reviewing_request(): void
    {
        $admin = $this->createWalletAdmin();
        $user = User::factory()->create(['wallet_balance' => 1000000]);
        $withdrawals = collect(range(1, 31))
            ->map(fn (): WithdrawalRequest => $this->createPendingWithdrawal($user));
        $this->actingAs($admin, 'sanctum');

        foreach ($withdrawals->take(30) as $withdrawal) {
            $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$withdrawal->getKey()}/approve")
                ->assertOk()
                ->assertJsonPath('withdrawal_request.status', 'approved_pending_payout');
        }

        $lastWithdrawal = $withdrawals->last();
        $transactionCount = Transaction::query()->count();
        $activityCount = Activity::query()->forSubject($lastWithdrawal)->count();
        $balance = $user->fresh()->wallet_balance;

        $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$lastWithdrawal->getKey()}/approve")
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertSame('pending', $lastWithdrawal->fresh()->status);
        $this->assertNull($lastWithdrawal->fresh()->reviewed_by);
        $this->assertSame($balance, $user->fresh()->wallet_balance);
        $this->assertSame($transactionCount, Transaction::query()->count());
        $this->assertSame($activityCount, Activity::query()->forSubject($lastWithdrawal)->count());

        $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$lastWithdrawal->getKey()}/reject")
            ->assertOk()
            ->assertJsonPath('withdrawal_request.status', 'rejected');
    }

    public function test_admin_rejection_rate_limit_blocks_31st_action_without_releasing_its_hold(): void
    {
        $admin = $this->createWalletAdmin();
        $user = User::factory()->create(['wallet_balance' => 1000000]);
        $withdrawals = collect(range(1, 31))
            ->map(fn (): WithdrawalRequest => $this->createPendingWithdrawal($user));
        $this->actingAs($admin, 'sanctum');

        foreach ($withdrawals->take(30) as $withdrawal) {
            $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$withdrawal->getKey()}/reject", [
                'note' => 'Reviewed',
            ])
                ->assertOk()
                ->assertJsonPath('withdrawal_request.status', 'rejected');
        }

        $lastWithdrawal = $withdrawals->last();
        $transactionCount = Transaction::query()->count();
        $activityCount = Activity::query()->forSubject($lastWithdrawal)->count();
        $balance = $user->fresh()->wallet_balance;

        $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$lastWithdrawal->getKey()}/reject", [
            'note' => 'Should not be applied',
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertSame('pending', $lastWithdrawal->fresh()->status);
        $this->assertNull($lastWithdrawal->fresh()->reviewed_by);
        $this->assertSame($balance, $user->fresh()->wallet_balance);
        $this->assertSame($transactionCount, Transaction::query()->count());
        $this->assertSame($activityCount, Activity::query()->forSubject($lastWithdrawal)->count());
    }

    private function createWalletAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('manage wallet', 'web'));

        return $admin;
    }

    private function createPendingWithdrawal(User $user): WithdrawalRequest
    {
        return app(WithdrawalRequestService::class)->create(
            $user,
            1000,
            (string) Str::uuid(),
        );
    }
}
