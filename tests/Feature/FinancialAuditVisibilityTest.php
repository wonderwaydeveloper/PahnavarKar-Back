<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FinancialAuditVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_wallet_summary_api_returns_only_the_authenticated_users_balance_and_currency_metadata(): void
    {
        Config::set('wallet.currency', 'TEST-CURRENCY');
        Config::set('wallet.unit', 'test-unit');
        $user = User::factory()->create([
            'is_active' => true,
            'wallet_balance' => 12345,
        ]);
        User::factory()->create([
            'is_active' => true,
            'wallet_balance' => 98765,
        ]);
        $transaction = $this->createTransaction($user);
        $balanceBefore = $user->fresh()->wallet_balance;
        $transactionCount = Transaction::query()->count();

        $this->getJson('/api/v1/auth/wallet')
            ->assertUnauthorized();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/wallet')
            ->assertOk()
            ->assertExactJson([
                'wallet' => [
                    'balance' => 12345,
                    'currency' => 'TEST-CURRENCY',
                    'unit' => 'test-unit',
                ],
            ]);

        $this->assertSame($balanceBefore, $user->fresh()->wallet_balance);
        $this->assertSame($transactionCount, Transaction::query()->count());
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->getKey(),
            'user_id' => $user->getKey(),
            'amount' => 2500,
        ]);
    }

    public function test_wallet_summary_get_rate_limit_is_per_route_and_has_no_financial_side_effects(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 12345]);
        $transaction = $this->createTransaction($user);
        $transactionCount = Transaction::query()->count();
        $balanceBefore = $user->fresh()->wallet_balance;

        for ($request = 0; $request < 60; $request++) {
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/auth/wallet')
                ->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/wallet')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/wallet/transactions')
            ->assertOk();

        $this->assertSame($balanceBefore, $user->fresh()->wallet_balance);
        $this->assertSame($transactionCount, Transaction::query()->count());
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->getKey(),
            'user_id' => $user->getKey(),
            'amount' => 2500,
        ]);
    }

    public function test_regular_user_wallet_history_does_not_expose_balance_snapshot(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->createTransaction($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/wallet/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.amount', 2500)
            ->assertJsonMissingPath('data.0.balance_after');
    }

    public function test_wallet_history_is_user_scoped_newest_first_and_paginated_with_allowlisted_fields(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['is_active' => true]);
        $ownedTransactions = [];

        for ($index = 1; $index <= 5; $index++) {
            $ownedTransactions[] = $this->createTransaction($user, [
                'amount' => $index * 100,
                'description' => 'Owned transaction '.$index,
                'reference_id' => 'private-reference-'.$index,
            ]);

            if ($index === 2 || $index === 4) {
                $this->createTransaction($otherUser, [
                    'amount' => 9000 + $index,
                    'description' => 'Other user transaction',
                ]);
            }
        }

        $transactionCount = Transaction::query()->count();
        $balanceBefore = $user->fresh()->wallet_balance;

        $this->getJson('/api/v1/auth/wallet/transactions')
            ->assertUnauthorized();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/wallet/transactions?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('data.0.id', $ownedTransactions[2]->getKey())
            ->assertJsonPath('data.1.id', $ownedTransactions[1]->getKey())
            ->assertJsonPath('data.0.amount', 300)
            ->assertJsonPath('data.1.amount', 200)
            ->assertJsonMissingPath('data.0.balance_after')
            ->assertJsonMissingPath('data.0.idempotency_key')
            ->assertJsonMissingPath('data.0.user_id')
            ->assertJsonMissingPath('data.0.reference_id')
            ->assertJsonMissingPath('data.0.expires_at');

        foreach ($response->json('data') as $transaction) {
            $this->assertSame(
                ['id', 'type', 'status', 'amount', 'currency', 'description', 'created_at'],
                array_keys($transaction),
            );
        }

        $this->assertSame($transactionCount, Transaction::query()->count());
        $this->assertSame($balanceBefore, $user->fresh()->wallet_balance);
    }

    public function test_wallet_history_rejects_per_page_outside_the_supported_range_without_side_effects(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 5000]);
        $transaction = $this->createTransaction($user);
        $transactionCount = Transaction::query()->count();
        $balanceBefore = $user->fresh()->wallet_balance;

        $this->actingAs($user, 'sanctum');
        foreach ([0, 101, -1] as $perPage) {
            $this->getJson('/api/v1/auth/wallet/transactions?per_page='.$perPage)
                ->assertUnprocessable();
        }

        $this->assertSame($transactionCount, Transaction::query()->count());
        $this->assertSame($balanceBefore, $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_wallet_history_accepts_minimum_and_maximum_page_sizes(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->createTransaction($user);
        $this->createTransaction($user, ['amount' => 3000]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/wallet/transactions?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/auth/wallet/transactions?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonCount(2, 'data');
    }

    public function test_wallet_history_get_rate_limit_is_per_route_and_has_no_financial_side_effects(): void
    {
        $user = User::factory()->create(['is_active' => true, 'wallet_balance' => 4321]);
        $transaction = $this->createTransaction($user);
        $transactionCount = Transaction::query()->count();
        $balanceBefore = $user->fresh()->wallet_balance;

        for ($request = 0; $request < 60; $request++) {
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/auth/wallet/transactions')
                ->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/wallet/transactions')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/wallet')
            ->assertOk();

        $this->assertSame($balanceBefore, $user->fresh()->wallet_balance);
        $this->assertSame($transactionCount, Transaction::query()->count());
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->getKey(),
            'user_id' => $user->getKey(),
            'amount' => 2500,
        ]);
    }

    public function test_balance_snapshot_is_available_only_to_transaction_auditors(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $transaction = $this->createTransaction($owner);
        $regularUser = User::factory()->create(['is_active' => true]);

        $this->actingAs($regularUser, 'sanctum')
            ->getJson('/api/v1/admin/transactions')
            ->assertForbidden();

        $auditor = User::factory()->create(['is_active' => true]);
        $auditor->givePermissionTo(Permission::findOrCreate('view transactions', 'web'));

        $this->actingAs($auditor, 'sanctum')
            ->getJson('/api/v1/admin/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.balance_after', 15000)
            ->assertJsonPath('data.0.id', $transaction->getKey());
    }

    public function test_web_financial_audit_page_requires_permission_and_shows_the_snapshot_to_auditors(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $transaction = $this->createTransaction($owner);
        $regularUser = User::factory()->create(['is_active' => true]);

        $this->actingAs($regularUser, 'web')
            ->get('/admin/transactions')
            ->assertForbidden();

        $auditor = User::factory()->create(['is_active' => true]);
        $auditor->givePermissionTo(Permission::findOrCreate('view transactions', 'web'));

        $this->actingAs($auditor, 'web')
            ->get('/admin/transactions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Transactions/Index')
                ->where('transactions.data.0.balanceAfter', 15000)
                ->where('transactions.data.0.id', $transaction->getKey()),
            );
    }

    private function createTransaction(User $user, array $attributes = []): Transaction
    {
        return $user->transactions()->create(array_merge([
            'type' => 'deposit',
            'status' => 'succeeded',
            'amount' => 2500,
            'balance_after' => 15000,
            'currency' => 'TOMAN',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ], $attributes));
    }
}
