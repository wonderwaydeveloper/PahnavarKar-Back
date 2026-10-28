<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\WalletOperationConflictException;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_payment_credit_is_idempotent_and_keeps_a_ledger_snapshot(): void
    {
        $user = User::factory()->create(['wallet_balance' => 1000]);
        $wallet = app(WalletService::class);

        $credit = $wallet->creditAfterVerifiedPayment($user, 250, 'zarinpal', 'gateway-ref-1');
        $replayedCredit = $wallet->creditAfterVerifiedPayment($user, 250, 'zarinpal', 'gateway-ref-1');

        $this->assertSame($credit->getKey(), $replayedCredit->getKey());
        $this->assertSame(1250, $user->fresh()->wallet_balance);
        $this->assertSame(1250, $credit->balance_after);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_verified_payment_reference_cannot_be_reused_for_a_different_credit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 1000]);
        $wallet = app(WalletService::class);
        $wallet->creditAfterVerifiedPayment($user, 250, 'zarinpal', 'gateway-ref-1');

        try {
            $wallet->creditAfterVerifiedPayment($user, 300, 'zarinpal', 'gateway-ref-1');
            $this->fail('A gateway reference must not credit a different amount.');
        } catch (WalletOperationConflictException) {
            $this->assertSame(1250, $user->fresh()->wallet_balance);
            $this->assertDatabaseCount('transactions', 1);
        }
    }

    public function test_purchase_debit_is_idempotent_and_rejects_changed_replay_inputs(): void
    {
        $user = User::factory()->create(['wallet_balance' => 1000]);
        $wallet = app(WalletService::class);
        $key = (string) Str::uuid();

        $debit = $wallet->debitForPurchase($user, 400, $key, 'order-1');
        $replayedDebit = $wallet->debitForPurchase($user, 400, $key, 'order-1');

        $this->assertSame($debit->getKey(), $replayedDebit->getKey());
        $this->assertSame(600, $user->fresh()->wallet_balance);
        $this->assertSame(600, $debit->balance_after);
        $this->assertDatabaseCount('transactions', 1);

        try {
            $wallet->debitForPurchase($user, 300, $key, 'order-1');
            $this->fail('An idempotency key must not be reused with a changed amount.');
        } catch (WalletOperationConflictException) {
            $this->assertSame(600, $user->fresh()->wallet_balance);
            $this->assertDatabaseCount('transactions', 1);
        }
    }

    public function test_purchase_debit_does_not_change_the_wallet_when_funds_are_insufficient(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);

        try {
            app(WalletService::class)->debitForPurchase($user, 400, (string) Str::uuid(), 'order-1');
            $this->fail('A debit larger than the balance must fail.');
        } catch (InsufficientWalletBalanceException) {
            $this->assertSame(300, $user->fresh()->wallet_balance);
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    public function test_purchase_refund_restores_balance_once_and_links_the_reversal(): void
    {
        $user = User::factory()->create(['wallet_balance' => 1000]);
        $wallet = app(WalletService::class);
        $debit = $wallet->debitForPurchase($user, 400, (string) Str::uuid(), 'order-1');

        $refund = $wallet->refundPurchase($debit);
        $replayedRefund = $wallet->refundPurchase($debit);

        $this->assertSame($refund->getKey(), $replayedRefund->getKey());
        $this->assertSame(1000, $user->fresh()->wallet_balance);
        $this->assertSame('refunded', $debit->fresh()->status);
        $this->assertSame($debit->getKey(), $refund->reverses_transaction_id);
        $this->assertSame(1000, $refund->balance_after);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_withdrawal_hold_release_restores_balance_only_once(): void
    {
        $user = User::factory()->create(['wallet_balance' => 600]);
        $hold = $user->transactions()->create([
            'type' => 'withdrawal_hold',
            'status' => 'succeeded',
            'amount' => 400,
            'balance_after' => 600,
            'currency' => 'TOMAN',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $wallet = app(WalletService::class);

        $release = $wallet->releaseWithdrawalHold($hold);
        $replayedRelease = $wallet->releaseWithdrawalHold($hold);

        $this->assertSame($release->getKey(), $replayedRelease->getKey());
        $this->assertSame(1000, $user->fresh()->wallet_balance);
        $this->assertSame('released', $hold->fresh()->status);
        $this->assertSame($hold->getKey(), $release->reverses_transaction_id);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_external_subscription_purchase_is_idempotent_by_gateway_reference(): void
    {
        $user = User::factory()->create(['wallet_balance' => 900]);
        $order = PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'gross_amount' => 700,
            'wallet_amount' => 0,
            'gateway_amount' => 700,
            'currency' => 'TOMAN',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        $wallet = app(WalletService::class);

        $purchase = $wallet->recordExternalPurchase($user, $order, 'zarinpal', 'gateway-ref-2');
        $replayedPurchase = $wallet->recordExternalPurchase($user, $order, 'zarinpal', 'gateway-ref-2');

        $this->assertSame($purchase->getKey(), $replayedPurchase->getKey());
        $this->assertSame(900, $user->fresh()->wallet_balance);
        $this->assertSame(700, $purchase->amount);
        $this->assertSame(900, $purchase->balance_after);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_credit_overflow_fails_without_changing_balance_or_ledger(): void
    {
        $user = User::factory()->create(['wallet_balance' => PHP_INT_MAX]);

        try {
            app(WalletService::class)->creditAfterVerifiedPayment($user, 1, 'zarinpal', 'gateway-ref-3');
            $this->fail('A credit exceeding the integer limit must fail.');
        } catch (WalletOperationConflictException) {
            $this->assertSame(PHP_INT_MAX, $user->fresh()->wallet_balance);
            $this->assertDatabaseCount('transactions', 0);
        }
    }
}
