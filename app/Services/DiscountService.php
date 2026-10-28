<?php

namespace App\Services;

use App\Exceptions\DiscountNotValidException;
use App\Models\DiscountCode;
use App\Models\DiscountUsage;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DiscountService
{
    /**
     * @return array{code: string, type: string, discount_amount: int, final_amount: int}
     */
    public function quote(User $user, string $code, int $orderAmount): array
    {
        if ($orderAmount < 1) {
            throw new DiscountNotValidException('مبلغ سفارش باید بیشتر از صفر باشد.');
        }

        $discount = DiscountCode::query()
            ->currentlyValid()
            ->where('code', Str::upper(trim($code)))
            ->first();

        if (! $discount) {
            throw new DiscountNotValidException('کد تخفیف معتبر یا فعال نیست.');
        }

        $this->assertEligible($user, $discount, $orderAmount);
        $discountAmount = $this->calculateDiscount($discount, $orderAmount);
        if ($discountAmount < 1) {
            throw new DiscountNotValidException('مبلغ سفارش برای استفاده از این کد تخفیف کافی نیست.');
        }

        return [
            'code' => $discount->code,
            'type' => $discount->type,
            'discount_amount' => $discountAmount,
            'final_amount' => $orderAmount - $discountAmount,
        ];
    }

    /**
     * @return array{discount_code_id: int, discount_amount: int, final_amount: int}
     */
    public function reserveForOrder(User $user, string $code, int $orderAmount, PaymentOrder $order): array
    {
        return DB::transaction(function () use ($user, $code, $orderAmount, $order): array {
            $discount = DiscountCode::query()
                ->currentlyValid()
                ->where('code', Str::upper(trim($code)))
                ->lockForUpdate()
                ->first();
            if (! $discount) {
                throw new DiscountNotValidException('کد تخفیف معتبر یا فعال نیست.');
            }

            $this->assertEligible($user, $discount, $orderAmount);
            $discountAmount = $this->calculateDiscount($discount, $orderAmount);
            if ($discountAmount < 1) {
                throw new DiscountNotValidException('مبلغ سفارش برای استفاده از این کد تخفیف کافی نیست.');
            }

            DiscountUsage::query()->create([
                'discount_code_id' => $discount->getKey(),
                'user_id' => $user->getKey(),
                'payment_order_id' => $order->getKey(),
                'order_amount' => $orderAmount,
                'discount_amount' => $discountAmount,
                'status' => 'reserved',
            ]);
            $discount->increment('usage_count');

            return [
                'discount_code_id' => (int) $discount->getKey(),
                'discount_amount' => $discountAmount,
                'final_amount' => $orderAmount - $discountAmount,
            ];
        });
    }

    public function consumeReservation(
        PaymentOrder $order,
        ?PaymentAttempt $attempt = null,
        ?Transaction $transaction = null,
    ): void {
        DB::transaction(function () use ($order, $attempt, $transaction): void {
            $lockedOrder = PaymentOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            if ($lockedOrder->status !== 'succeeded') {
                throw new DiscountNotValidException('ثبت مصرف تخفیف فقط پس از تکمیل سفارش مجاز است.');
            }

            $lockedAttempt = $attempt
                ? PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey())
                : null;
            if ($lockedAttempt && (
                $lockedAttempt->status !== 'succeeded'
                || $lockedAttempt->payment_order_id !== $lockedOrder->getKey()
                || $lockedAttempt->user_id !== $lockedOrder->user_id
                || $transaction === null
                || $lockedAttempt->transaction_id !== $transaction->getKey()
            )) {
                throw new DiscountNotValidException('تلاش پرداخت با سفارش تکمیل‌شده مطابقت ندارد.');
            }

            $lockedTransaction = $transaction
                ? Transaction::query()->lockForUpdate()->findOrFail($transaction->getKey())
                : null;
            if ($lockedOrder->transaction_id !== null && $lockedTransaction === null) {
                throw new DiscountNotValidException('تراکنش سفارش برای ثبت مصرف تخفیف الزامی است.');
            }
            if ($lockedTransaction && (
                $lockedTransaction->status !== 'succeeded'
                || $lockedTransaction->user_id !== $lockedOrder->user_id
                || $lockedOrder->transaction_id !== $lockedTransaction->getKey()
            )) {
                throw new DiscountNotValidException('تراکنش با سفارش تکمیل‌شده مطابقت ندارد.');
            }

            $usage = DiscountUsage::query()
                ->where('payment_order_id', $lockedOrder->getKey())
                ->lockForUpdate()
                ->first();
            if (! $usage) {
                if ($lockedOrder->discount_code_id === null) {
                    return;
                }
                throw new DiscountNotValidException('رزرو کد تخفیف سفارش پیدا نشد.');
            }
            if ($usage->status === 'consumed') {
                return;
            }
            if ($usage->status !== 'reserved') {
                throw new DiscountNotValidException('رزرو کد تخفیف برای این خرید معتبر نیست.');
            }
            if (
                $usage->discount_code_id !== $lockedOrder->discount_code_id
                || $usage->discount_amount !== $lockedOrder->discount_amount
                || $usage->order_amount !== $lockedOrder->gross_amount
            ) {
                throw new DiscountNotValidException('مقادیر رزرو تخفیف با سفارش نهایی مطابقت ندارد.');
            }

            $usage->forceFill([
                'status' => 'consumed',
                'payment_attempt_id' => $lockedAttempt?->getKey(),
                'transaction_id' => $lockedTransaction?->getKey(),
            ])->saveOrFail();
        });
    }

    public function releaseReservation(PaymentOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $usage = DiscountUsage::query()
                ->where('payment_order_id', $order->getKey())
                ->lockForUpdate()
                ->first();
            if (! $usage || $usage->status !== 'reserved') {
                return;
            }

            $usage->forceFill(['status' => 'released'])->saveOrFail();
            DiscountCode::query()
                ->whereKey($usage->discount_code_id)
                ->where('usage_count', '>', 0)
                ->decrement('usage_count');
        });
    }

    private function assertEligible(User $user, DiscountCode $discount, int $orderAmount): void
    {
        if ($discount->user_id !== null && $discount->user_id !== $user->getKey()) {
            throw new DiscountNotValidException('این کد تخفیف برای این حساب کاربری در دسترس نیست.');
        }
        if ($orderAmount < $discount->min_order_amount) {
            throw new DiscountNotValidException('مبلغ سفارش به حداقل مبلغ لازم برای تخفیف نمی‌رسد.');
        }
        if ($discount->usage_limit !== null && $discount->usage_count >= $discount->usage_limit) {
            throw new DiscountNotValidException('ظرفیت استفاده از این کد تخفیف تکمیل شده است.');
        }
        if ($discount->usages()
            ->where('user_id', $user->getKey())
            ->whereIn('status', ['reserved', 'consumed'])
            ->exists()) {
            throw new DiscountNotValidException('این کد تخفیف قبلاً توسط این حساب استفاده یا رزرو شده است.');
        }
    }

    private function calculateDiscount(DiscountCode $discount, int $orderAmount): int
    {
        $amount = $discount->type === 'percentage'
            ? intdiv($orderAmount, 100) * $discount->value
                + intdiv(($orderAmount % 100) * $discount->value, 100)
            : $discount->value;

        if ($discount->max_discount !== null) {
            $amount = min($amount, $discount->max_discount);
        }

        return min($amount, $orderAmount);
    }
}
