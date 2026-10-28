<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\WalletOperationConflictException;
use App\Models\Transaction;
use App\Models\PaymentOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    public function recordExternalPurchase(
        User $user,
        PaymentOrder $order,
        string $gateway,
        string $referenceId,
    ): Transaction {
        $this->assertValidAmount((int) $order->gateway_amount);
        if ($gateway === '' || $referenceId === '' || $order->kind !== 'subscription') {
            throw new WalletOperationConflictException('شناسهٔ خرید خارجی معتبر نیست.');
        }

        return DB::transaction(function () use ($user, $order, $gateway, $referenceId): Transaction {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = Transaction::query()
                ->where('gateway', $gateway)
                ->where('reference_id', $referenceId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    $existing->user_id !== $lockedUser->getKey()
                    || $existing->type !== 'subscription'
                    || $existing->amount !== (int) $order->gateway_amount
                ) {
                    throw new WalletOperationConflictException('شناسهٔ مرجع درگاه قبلاً به خرید دیگری اختصاص یافته است.');
                }

                return $existing;
            }

            return $lockedUser->transactions()->create([
                'type' => 'subscription',
                'status' => 'succeeded',
                'amount' => (int) $order->gateway_amount,
                'balance_after' => (int) $lockedUser->wallet_balance,
                'currency' => $order->currency,
                'gateway' => $gateway,
                'reference_id' => $referenceId,
                'idempotency_key' => (string) Str::uuid(),
                'description' => 'Subscription order '.$order->order_reference,
                'expires_at' => now()->addHours(24),
            ]);
        });
    }

    public function creditAfterVerifiedPayment(
        User $user,
        int $amount,
        string $gateway,
        string $referenceId,
        ?string $description = null,
    ): Transaction {
        $this->assertValidAmount($amount);
        if ($gateway === '' || $referenceId === '') {
            throw new WalletOperationConflictException('پرداخت تأییدشده باید شناسهٔ مرجع درگاه داشته باشد.');
        }

        return DB::transaction(function () use ($user, $amount, $gateway, $referenceId, $description): Transaction {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = Transaction::query()
                ->where('gateway', $gateway)
                ->where('reference_id', $referenceId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    $existing->user_id !== $lockedUser->getKey()
                    || $existing->type !== 'deposit'
                    || $existing->amount !== $amount
                ) {
                    throw new WalletOperationConflictException('شناسهٔ مرجع پرداخت قبلاً برای عملیات دیگری ثبت شده است.');
                }

                return $existing;
            }

            $newBalance = $this->addToBalance((int) $lockedUser->wallet_balance, $amount);
            $lockedUser->forceFill(['wallet_balance' => $newBalance])->saveOrFail();

            return $lockedUser->transactions()->create([
                'type' => 'deposit',
                'status' => 'succeeded',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'currency' => config('wallet.currency'),
                'gateway' => $gateway,
                'reference_id' => $referenceId,
                'idempotency_key' => (string) Str::uuid(),
                'description' => $description,
                'expires_at' => now()->addHours(24),
            ]);
        });
    }

    public function debitForPurchase(
        User $user,
        int $amount,
        string $idempotencyKey,
        string $purchaseReference,
        ?string $description = null,
    ): Transaction {
        $this->assertValidAmount($amount);
        if (! Str::isUuid($idempotencyKey) || $purchaseReference === '') {
            throw new WalletOperationConflictException('شناسهٔ یکتای معتبر و مرجع خرید الزامی است.');
        }

        return DB::transaction(function () use (
            $user,
            $amount,
            $idempotencyKey,
            $purchaseReference,
            $description,
        ): Transaction {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = Transaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    $existing->user_id !== $lockedUser->getKey()
                    || $existing->type !== 'debit'
                    || $existing->amount !== $amount
                    || $existing->reference_id !== $purchaseReference
                ) {
                    throw new WalletOperationConflictException('کلید idempotency قبلاً برای عملیات دیگری استفاده شده است.');
                }

                if ($existing->expires_at->isPast()) {
                    throw new WalletOperationConflictException('مهلت تکرار این عملیات به پایان رسیده است.');
                }

                return $existing;
            }

            $balance = (int) $lockedUser->wallet_balance;
            if ($balance < $amount) {
                throw new InsufficientWalletBalanceException('موجودی کیف پول برای این خرید کافی نیست.');
            }

            $newBalance = $balance - $amount;
            $lockedUser->forceFill(['wallet_balance' => $newBalance])->saveOrFail();

            return $lockedUser->transactions()->create([
                'type' => 'debit',
                'status' => 'succeeded',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'currency' => config('wallet.currency'),
                'reference_id' => $purchaseReference,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'expires_at' => now()->addHours(24),
            ]);
        });
    }

    public function refundPurchase(Transaction $debit): Transaction
    {
        if ($debit->type !== 'debit') {
            throw new WalletOperationConflictException('فقط تراکنش خرید کیف پول قابل بازپرداخت است.');
        }

        return DB::transaction(function () use ($debit): Transaction {
            $lockedUser = User::query()
                ->whereKey($debit->user_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedDebit = Transaction::query()
                ->whereKey($debit->getKey())
                ->where('user_id', $lockedUser->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existingRefund = Transaction::query()
                ->where('reverses_transaction_id', $lockedDebit->getKey())
                ->first();
            if ($existingRefund) {
                return $existingRefund;
            }

            if ($lockedDebit->status !== 'succeeded') {
                throw new WalletOperationConflictException('این خرید قبلاً بازپرداخت یا ناموفق شده است.');
            }

            $newBalance = $this->addToBalance((int) $lockedUser->wallet_balance, (int) $lockedDebit->amount);
            $lockedUser->forceFill(['wallet_balance' => $newBalance])->saveOrFail();
            $lockedDebit->forceFill(['status' => 'refunded'])->saveOrFail();

            return $lockedUser->transactions()->create([
                'reverses_transaction_id' => $lockedDebit->getKey(),
                'type' => 'refund',
                'status' => 'succeeded',
                'amount' => $lockedDebit->amount,
                'balance_after' => $newBalance,
                'currency' => $lockedDebit->currency,
                'reference_id' => $lockedDebit->reference_id,
                'idempotency_key' => (string) Str::uuid(),
                'description' => 'Refund for purchase '.$lockedDebit->getKey(),
                'expires_at' => now()->addHours(24),
            ]);
        });
    }

    public function releaseWithdrawalHold(Transaction $hold): Transaction
    {
        if ($hold->type !== 'withdrawal_hold') {
            throw new WalletOperationConflictException('فقط رزرو برداشت قابل آزادسازی است.');
        }

        return DB::transaction(function () use ($hold): Transaction {
            $lockedUser = User::query()->whereKey($hold->user_id)->lockForUpdate()->firstOrFail();
            $lockedHold = Transaction::query()
                ->whereKey($hold->getKey())
                ->where('user_id', $lockedUser->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existingRelease = Transaction::query()
                ->where('reverses_transaction_id', $lockedHold->getKey())
                ->first();
            if ($existingRelease) {
                return $existingRelease;
            }

            if ($lockedHold->status !== 'succeeded') {
                throw new WalletOperationConflictException('رزرو برداشت در وضعیت قابل آزادسازی نیست.');
            }

            $newBalance = $this->addToBalance((int) $lockedUser->wallet_balance, (int) $lockedHold->amount);
            $lockedUser->forceFill(['wallet_balance' => $newBalance])->saveOrFail();
            $lockedHold->forceFill(['status' => 'released'])->saveOrFail();

            return $lockedUser->transactions()->create([
                'reverses_transaction_id' => $lockedHold->getKey(),
                'type' => 'withdrawal_release',
                'status' => 'succeeded',
                'amount' => $lockedHold->amount,
                'balance_after' => $newBalance,
                'currency' => $lockedHold->currency,
                'reference_id' => $lockedHold->reference_id,
                'idempotency_key' => (string) Str::uuid(),
                'description' => 'Rejected withdrawal request released',
                'expires_at' => now()->addHours(24),
            ]);
        });
    }

    private function assertValidAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new WalletOperationConflictException('مبلغ تراکنش باید عدد صحیح مثبت باشد.');
        }
    }

    private function addToBalance(int $balance, int $amount): int
    {
        if ($amount > PHP_INT_MAX - $balance) {
            throw new WalletOperationConflictException('موجودی کیف پول از حداکثر مقدار مجاز بیشتر می‌شود.');
        }

        return $balance + $amount;
    }
}
