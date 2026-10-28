<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class WithdrawalReviewLifecycleTest extends TestCase
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

    public function test_admin_review_routes_require_manage_wallet_permission(): void
    {
        $user = User::factory()->create(['wallet_balance' => 200000]);
        $withdrawal = $this->createWithdrawal($user);
        $unauthorized = User::factory()->create(['is_active' => true]);
        $this->actingAs($unauthorized, 'sanctum');

        $this->getJson('/api/v1/admin/wallet/withdrawal-requests')->assertForbidden();
        $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$withdrawal->getKey()}/approve")
            ->assertForbidden();
        $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$withdrawal->getKey()}/reject")
            ->assertForbidden();

        $this->assertSame('pending', $withdrawal->fresh()->status);
        $this->assertNull($withdrawal->fresh()->reviewed_by);
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());
    }

    public function test_user_history_and_admin_queue_return_only_their_expected_requests(): void
    {
        $firstUser = User::factory()->create(['wallet_balance' => 300000]);
        $secondUser = User::factory()->create(['wallet_balance' => 300000]);
        $firstPending = $this->createWithdrawal($firstUser);
        $firstApproved = $this->createWithdrawal($firstUser);
        $secondRejected = $this->createWithdrawal($secondUser);
        $reviewer = $this->createWalletAdmin();

        app(WithdrawalRequestService::class)->review(
            (int) $firstApproved->getKey(),
            $reviewer,
            'approve',
        );
        app(WithdrawalRequestService::class)->review(
            (int) $secondRejected->getKey(),
            $reviewer,
            'reject',
        );

        $this->actingAs($firstUser, 'sanctum')
            ->getJson('/api/v1/auth/wallet/withdrawal-requests')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstApproved->getKey())
            ->assertJsonPath('data.1.id', $firstPending->getKey())
            ->assertJsonMissing(['id' => $secondRejected->getKey()]);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/v1/admin/wallet/withdrawal-requests')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstPending->getKey())
            ->assertJsonPath('data.1.id', $firstApproved->getKey())
            ->assertJsonMissing(['id' => $secondRejected->getKey()]);
    }

    public function test_approval_and_rejection_transitions_have_no_duplicate_financial_effects(): void
    {
        $user = User::factory()->create(['wallet_balance' => 400000]);
        $withdrawal = $this->createWithdrawal($user);
        $hold = $withdrawal->holdTransaction;
        $reviewer = $this->createWalletAdmin();
        $this->actingAs($reviewer, 'sanctum');

        $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$withdrawal->getKey()}/approve")
            ->assertOk()
            ->assertJsonPath('withdrawal_request.status', 'approved_pending_payout');

        $approved = $withdrawal->fresh();
        $this->assertSame($reviewer->getKey(), $approved->reviewed_by);
        $this->assertNull($approved->paid_at);
        $this->assertNull($approved->payout_reference);
        $this->assertSame(300000, $user->fresh()->wallet_balance);
        $this->assertSame('succeeded', $hold->fresh()->status);
        $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());

        $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$withdrawal->getKey()}/approve")
            ->assertConflict()
            ->assertJsonPath('code', 'withdrawal_request_conflict');

        $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$withdrawal->getKey()}/reject")
            ->assertOk()
            ->assertJsonPath('withdrawal_request.status', 'rejected');

        $release = Transaction::query()
            ->where('reverses_transaction_id', $hold->getKey())
            ->sole();
        $this->assertSame('released', $hold->fresh()->status);
        $this->assertSame(100000, $release->amount);
        $this->assertSame('withdrawal_release', $release->type);
        $this->assertSame(400000, $release->balance_after);
        $this->assertSame(400000, $user->fresh()->wallet_balance);
        $this->assertSame(2, Transaction::query()->where('user_id', $user->getKey())->count());

        $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$withdrawal->getKey()}/reject")
            ->assertConflict()
            ->assertJsonPath('code', 'withdrawal_request_conflict');
        $this->postJson("/api/v1/admin/wallet/withdrawal-requests/{$withdrawal->getKey()}/approve")
            ->assertConflict()
            ->assertJsonPath('code', 'withdrawal_request_conflict');

        $this->assertSame(400000, $user->fresh()->wallet_balance);
        $this->assertSame(2, Transaction::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(1, Transaction::query()
            ->where('reverses_transaction_id', $hold->getKey())
            ->count());
    }

    public function test_rejection_failure_rolls_back_review_hold_release_balance_and_audit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 200000]);
        $withdrawal = $this->createWithdrawal($user);
        $hold = $withdrawal->holdTransaction;
        $reviewer = $this->createWalletAdmin();
        $activityCount = Activity::query()->forSubject($withdrawal)->count();

        DB::statement(
            "CREATE TRIGGER fail_withdrawal_release_insert BEFORE INSERT ON transactions
             WHEN NEW.type = 'withdrawal_release'
             BEGIN SELECT RAISE(ABORT, 'injected withdrawal release failure'); END",
        );

        try {
            app(WithdrawalRequestService::class)->review(
                (int) $withdrawal->getKey(),
                $reviewer,
                'reject',
                'Injected rollback',
            );
            $this->fail('The injected withdrawal release failure was not raised.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('injected withdrawal release failure', $exception->getMessage());
        }

        $this->assertSame('pending', $withdrawal->fresh()->status);
        $this->assertNull($withdrawal->fresh()->reviewed_by);
        $this->assertNull($withdrawal->fresh()->reviewed_at);
        $this->assertSame('succeeded', $hold->fresh()->status);
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(0, Transaction::query()
            ->where('reverses_transaction_id', $hold->getKey())
            ->count());
        $this->assertSame($activityCount, Activity::query()->forSubject($withdrawal)->count());
    }

    private function createWalletAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('manage wallet', 'web'));

        return $admin;
    }

    private function createWithdrawal(User $user): WithdrawalRequest
    {
        return app(WithdrawalRequestService::class)->create(
            $user,
            100000,
            (string) Str::uuid(),
        );
    }
}
