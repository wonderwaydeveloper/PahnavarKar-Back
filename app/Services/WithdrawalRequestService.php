<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\WithdrawalRequestConflictException;
use App\Exceptions\WithdrawalRequestException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Admin\AdminRuntimeSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WithdrawalRequestService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly AdminRuntimeSettings $runtimeSettings,
    ) {}

    public function create(
        User $user,
        int $amount,
        string $idempotencyKey,
        string $payoutMethod = 'iban',
        ?string $cardNumber = null,
    ): WithdrawalRequest {
        $settings = $this->runtimeSettings->walletWithdrawals();
        if (! $settings['enabled']) {
            throw new WithdrawalRequestException('درخواست برداشت در حال حاضر غیرفعال است.');
        }
        if (
            ($settings['processing_mode'] ?? 'manual') === 'automatic'
            && ! ($settings['automatic_mode_available'] ?? false)
        ) {
            throw new WithdrawalRequestException('پرداخت خودکار برداشت هنوز پیکربندی و فعال نشده است.');
        }
        if ($amount < 1 || $amount > (int) $settings['max_per_request']) {
            throw new WithdrawalRequestException('مبلغ درخواست از سقف هر برداشت بیشتر است.');
        }
        if (! Str::isUuid($idempotencyKey)) {
            throw new WithdrawalRequestException('کلید idempotency باید UUID معتبر باشد.');
        }
        if (! in_array($payoutMethod, ['iban', 'card'], true)) {
            throw new WithdrawalRequestException('روش مقصد برداشت معتبر نیست.');
        }

        $dayStart = now()->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();

        return DB::transaction(function () use (
            $user,
            $amount,
            $idempotencyKey,
            $payoutMethod,
            $cardNumber,
            $settings,
            $dayStart,
            $dayEnd,
        ): WithdrawalRequest {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $bankAccountIban = $payoutMethod === 'iban' ? $lockedUser->bank_account_iban : null;
            if ($payoutMethod === 'iban' && (! is_string($bankAccountIban) || $bankAccountIban === '')) {
                throw new WithdrawalRequestException('برای ثبت برداشت، ابتدا شماره شبای خود را ثبت کنید.');
            }
            if ($payoutMethod === 'card' && (! is_string($cardNumber) || preg_match('/^[0-9]{16}$/', $cardNumber) !== 1)) {
                throw new WithdrawalRequestException('برای ثبت برداشت، شماره کارت معتبر وارد کنید.');
            }
            $existing = WithdrawalRequest::query()
                ->where('user_id', $lockedUser->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    $existing->amount !== $amount
                    || ($existing->payout_method ?? 'iban') !== $payoutMethod
                    || ($payoutMethod === 'iban' && $existing->bank_account_iban !== $bankAccountIban)
                    || ($payoutMethod === 'card' && $existing->bank_account_card_number !== $cardNumber)
                ) {
                    throw new WithdrawalRequestConflictException('کلید idempotency قبلاً برای درخواست دیگری استفاده شده است.');
                }
                if ($existing->idempotency_expires_at->isPast()) {
                    throw new WithdrawalRequestConflictException('مهلت تکرار این درخواست به پایان رسیده است.');
                }

                return $existing;
            }

            $dailyTotal = WithdrawalRequest::query()
                ->where('user_id', $lockedUser->getKey())
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->whereIn('status', ['pending', 'approved_pending_payout', 'paid'])
                ->sum('amount');

            if ($dailyTotal + $amount > (int) $settings['max_per_day']) {
                throw new WithdrawalRequestException('مجموع درخواست‌های برداشت امروز از سقف روزانه بیشتر می‌شود.');
            }

            $balance = (int) $lockedUser->wallet_balance;
            if ($balance < $amount) {
                throw new InsufficientWalletBalanceException('موجودی آزاد کیف پول برای این درخواست کافی نیست.');
            }

            $reference = (string) Str::uuid();
            $newBalance = $balance - $amount;
            $lockedUser->forceFill(['wallet_balance' => $newBalance])->saveOrFail();

            $hold = $lockedUser->transactions()->create([
                'type' => 'withdrawal_hold',
                'status' => 'succeeded',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'currency' => config('wallet.currency'),
                'reference_id' => $reference,
                'idempotency_key' => (string) Str::uuid(),
                'description' => 'مبلغ درخواست برداشت تا زمان بررسی از موجودی قابل‌استفاده رزرو شد.',
                'expires_at' => now()->addHours(24),
            ]);

            return WithdrawalRequest::query()->create([
                'user_id' => $lockedUser->getKey(),
                'hold_transaction_id' => $hold->getKey(),
                'request_reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'amount' => $amount,
                'currency' => config('wallet.currency'),
                'bank_account_iban' => $bankAccountIban,
                'bank_account_card_number' => $payoutMethod === 'card' ? $cardNumber : null,
                'payout_method' => $payoutMethod,
                'payout_mode' => $settings['processing_mode'] ?? 'manual',
                'status' => 'pending',
                'idempotency_expires_at' => now()->addHours(24),
            ]);
        });
    }

    public function review(
        int $requestId,
        User $reviewer,
        string $decision,
        ?string $note = null,
    ): WithdrawalRequest {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new WithdrawalRequestConflictException('تصمیم بررسی درخواست معتبر نیست.');
        }

        return DB::transaction(function () use ($requestId, $reviewer, $decision, $note): WithdrawalRequest {
            $withdrawal = WithdrawalRequest::query()->lockForUpdate()->findOrFail($requestId);
            $canApprove = $decision === 'approve' && $withdrawal->status === 'pending';
            $canReject = $decision === 'reject'
                && in_array($withdrawal->status, ['pending', 'approved_pending_payout'], true);
            if (! $canApprove && ! $canReject) {
                throw new WithdrawalRequestConflictException('این درخواست قبلاً بررسی شده است.');
            }

            $withdrawal->forceFill([
                'status' => $canApprove ? 'approved_pending_payout' : 'rejected',
                'reviewed_by' => $reviewer->getKey(),
                'review_note' => $note,
                'reviewed_at' => now(),
            ])->saveOrFail();

            if ($decision === 'reject') {
                $this->releaseHold($withdrawal);
            }

            return $withdrawal->fresh();
        });
    }

    public function markPaid(int $requestId, User $reviewer, string $payoutReference): WithdrawalRequest
    {
        return DB::transaction(function () use ($requestId, $reviewer, $payoutReference): WithdrawalRequest {
            $withdrawal = WithdrawalRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ($withdrawal->status !== 'approved_pending_payout') {
                throw new WithdrawalRequestConflictException('فقط درخواست تأییدشده و پرداخت‌نشده قابل نهایی‌سازی است.');
            }

            $withdrawal->forceFill([
                'status' => 'paid',
                'paid_by' => $reviewer->getKey(),
                'payout_reference' => $payoutReference,
                'paid_at' => now(),
            ])->saveOrFail();

            return $withdrawal->fresh();
        });
    }

    public function userRequests(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $user->withdrawalRequests()->latest('id')->paginate($perPage);
    }

    public function reviewQueue(int $perPage = 20): LengthAwarePaginator
    {
        return WithdrawalRequest::query()
            ->with('user:id,name,phone')
            ->whereIn('status', ['pending', 'approved_pending_payout'])
            ->oldest('id')
            ->paginate($perPage);
    }

    private function releaseHold(WithdrawalRequest $withdrawal): Transaction
    {
        $hold = $withdrawal->holdTransaction;
        if (! $hold) {
            throw new WithdrawalRequestConflictException('رزرو موجودی این درخواست پیدا نشد؛ آزادسازی خودکار انجام نشد.');
        }

        return $this->walletService->releaseWithdrawalHold($hold);
    }
}
