<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialHistoryDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_history_endpoints_filter_all_records_by_tehran_calendar_days(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00', 'UTC'));
        $user = User::factory()->create(['is_active' => true]);
        $inside = Carbon::parse('2026-11-01 21:00:00', 'UTC');
        $before = Carbon::parse('2026-11-01 20:00:00', 'UTC');
        $after = Carbon::parse('2026-11-02 20:30:00', 'UTC');

        $transactions = collect([$inside, $before, $after])->map(
            fn (Carbon $createdAt): Transaction => $this->createTransaction($user, $createdAt),
        );
        $orders = collect([$inside, $before, $after])->map(
            fn (Carbon $createdAt): PaymentOrder => $this->createOrder($user, $createdAt),
        );
        $withdrawals = collect([$inside, $before, $after])->map(
            fn (Carbon $createdAt): WithdrawalRequest => $this->createWithdrawal($user, $createdAt),
        );

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/wallet/transactions?from=2026-11-02&to=2026-11-02')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $transactions[0]->getKey());

        $this->getJson('/api/v1/auth/payment-orders?from=2026-11-02&to=2026-11-02')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $orders[0]->getKey());

        $this->getJson('/api/v1/auth/wallet/withdrawal-requests?from=2026-11-02&to=2026-11-02')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $withdrawals[0]->getKey());
    }

    public function test_financial_history_date_bounds_are_optional_but_validated_when_provided(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->createTransaction($user, Carbon::parse('2026-10-01 12:00:00', 'UTC'));
        $this->createTransaction($user, Carbon::parse('2026-11-01 12:00:00', 'UTC'));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/wallet/transactions')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/auth/wallet/transactions?from=2026-11-01')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/auth/wallet/transactions?from=2026-11-02&to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/v1/auth/wallet/transactions?from=2026-02-30')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
    }

    private function createTransaction(User $user, Carbon $createdAt): Transaction
    {
        $transaction = Transaction::query()->create([
            'user_id' => $user->getKey(),
            'type' => 'deposit',
            'status' => 'succeeded',
            'amount' => 1000,
            'balance_after' => 1000,
            'currency' => 'TOMAN',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $transaction->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $transaction;
    }

    private function createOrder(User $user, Carbon $createdAt): PaymentOrder
    {
        $order = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'plan_key' => 'gold_1_month',
            'gross_amount' => 1000,
            'currency' => 'TOMAN',
            'status' => 'succeeded',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $order;
    }

    private function createWithdrawal(User $user, Carbon $createdAt): WithdrawalRequest
    {
        $withdrawal = WithdrawalRequest::query()->create([
            'user_id' => $user->getKey(),
            'request_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 1000,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_expires_at' => now()->addDay(),
        ]);
        $withdrawal->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $withdrawal;
    }
}
