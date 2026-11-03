<?php

namespace App\Services\Auth;

use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\WithdrawalRequest;

class AccountDeletionGuard
{
    public function hasUnresolvedFinancialOperations(int $userId): bool
    {
        return PaymentOrder::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'processing'])
            ->exists()
            || PaymentAttempt::query()
                ->where('user_id', $userId)
                ->whereIn('status', ['initiating', 'pending', 'initiation_unknown'])
                ->exists()
            || WithdrawalRequest::query()
                ->where('user_id', $userId)
                ->whereIn('status', ['pending', 'approved_pending_payout'])
                ->exists();
    }

    public function blockedMessage(): string
    {
        return 'تا تعیین تکلیف پرداخت‌ها یا برداشت‌های در حال پردازش، امکان حذف حساب وجود ندارد. پس از نهایی‌شدن آن‌ها دوباره تلاش کنید.';
    }
}
