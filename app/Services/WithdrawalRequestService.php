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

    public function create(User $user, int $amount, string $idempotencyKey): WithdrawalRequest
    {
        $settings = $this->runtimeSettings->walletWithdrawals();
        if (! $settings['enabled']) {
            throw new WithdrawalRequestException('درخواست برداشت در حال حاضر غیرفعال است.');
        }
        if ($amount < 1 || $amount > (int) $settings['max_per_request']) {
            throw new WithdrawalRequestException('مبلغ درخواست از سقف هر برداشت بیشتر است.');
        }
        if (! Str::isUuid($idempotencyKey)) {
            throw new WithdrawalRequestException('کلید idempotency باید UUID معتبر باشد.');
        }

        $dayStart = now()->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();

        return DB::transaction(function () use ($user, $amount, $idempotencyKey, $settings, $dayStart, $dayEnd): WithdrawalRequest {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = WithdrawalRequest::query()
                ->where('user_id', $lockedUser->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->amount !== $amount) {
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
                'description' => 'Withdrawal request hold',
                'expires_at' => now()->addHours(24),
            ]);

            return WithdrawalRequest::query()->create([
                'user_id' => $lockedUser->getKey(),
                'hold_transaction_id' => $hold->getKey(),
                'request_reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'amount' => $amount,
                'currency' => config('wallet.currency'),
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
