<?php

namespace App\Services;

use App\Events\SubscriptionCancelled;
use App\Exceptions\SubscriptionConflictException;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\AdminRuntimeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionService
{
    public function __construct(
        private readonly AdminRuntimeSettings $runtimeSettings,
        private readonly WalletService $wallet,
        private readonly DiscountService $discounts,
    ) {}

    public function activatePremium(
        PaymentOrder $order,
        ?Transaction $transaction,
        bool $autoRenew = false,
    ): Subscription {
        $subscription = DB::transaction(function () use ($order, $transaction, $autoRenew): Subscription {
            $lockedOrder = PaymentOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            if ($lockedOrder->status === 'succeeded' && $lockedOrder->subscription_id !== null) {
                return Subscription::query()->findOrFail($lockedOrder->subscription_id);
            }
            if ($lockedOrder->kind !== 'subscription' || $lockedOrder->status !== 'processing') {
                throw new SubscriptionConflictException('این سفارش در وضعیت فعال‌سازی اشتراک نیست.');
            }

            $durationDays = (int) $lockedOrder->duration_days;
            if ($durationDays < 1) {
                throw new SubscriptionConflictException('پلن اشتراک سفارش معتبر نیست.');
            }

            $lockedUser = User::query()->whereKey($lockedOrder->user_id)->lockForUpdate()->firstOrFail();
            $sourceSubscription = null;
            if ($lockedOrder->source_subscription_id !== null) {
                $sourceSubscription = Subscription::query()
                    ->where('user_id', $lockedUser->getKey())
                    ->lockForUpdate()
                    ->find($lockedOrder->source_subscription_id);
                if (! $sourceSubscription) {
                    throw new SubscriptionConflictException('اشتراک مبدأ برای این تغییر پیدا نشد.');
                }
            }

            $activeSubscription = $lockedUser->subscriptions()->active()->latest('id')->lockForUpdate()->first();
            if ($lockedOrder->subscription_change_type === 'purchase' && $activeSubscription) {
                throw new SubscriptionConflictException('کاربر در حال حاضر اشتراک فعالی دارد.');
            }
            if ($lockedOrder->subscription_change_type === 'upgrade') {
                if (
                    ! $sourceSubscription
                    || ($activeSubscription && $activeSubscription->getKey() !== $sourceSubscription->getKey())
                    || ($activeSubscription && ! $sourceSubscription->isActive())
                ) {
                    throw new SubscriptionConflictException('اشتراک فعلی برای ارتقای پلن تغییر کرده است.');
                }
                $sourceSubscription->forceFill([
                    'status' => 'replaced',
                    'cancelled_at' => now(),
                    'auto_renew' => false,
                ])->saveOrFail();
            } elseif ($lockedOrder->subscription_change_type === 'scheduled') {
                if ($activeSubscription && $activeSubscription->getKey() !== $sourceSubscription?->getKey()) {
                    throw new SubscriptionConflictException('اشتراک فعلی برای تغییر زمان‌بندی‌شده تغییر کرده است.');
                }
                if ($sourceSubscription?->auto_renew) {
                    $sourceSubscription->forceFill(['auto_renew' => false])->saveOrFail();
                }
            } elseif ($lockedOrder->subscription_change_type !== 'purchase') {
                throw new SubscriptionConflictException('نوع تغییر اشتراک سفارش معتبر نیست.');
            }

            $now = now();
            $startsAt = $lockedOrder->effective_starts_at ?? $now;
            $endsAt = $lockedOrder->effective_ends_at ?? $now->copy()->addDays($durationDays);
            if (
                $lockedOrder->subscription_change_type === 'upgrade'
                && $sourceSubscription
                && $sourceSubscription->ends_at->lessThanOrEqualTo($now)
            ) {
                $startsAt = $now->copy();
                $endsAt = $startsAt->copy()->addDays($durationDays);
            }
            if ($lockedOrder->subscription_change_type === 'scheduled' && $startsAt->lessThan($now)) {
                $startsAt = $now->copy();
                $endsAt = $startsAt->copy()->addDays($durationDays);
            }
            $subscription = $lockedUser->subscriptions()->create([
                'payment_order_id' => $lockedOrder->getKey(),
                'transaction_id' => $transaction?->getKey(),
                'plan_key' => $lockedOrder->plan_key,
                'type' => 'premium',
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'auto_renew' => $autoRenew,
                'auto_renew_order_idempotency_key' => $autoRenew ? (string) Str::uuid() : null,
            ]);

            $lockedOrder->forceFill([
                'subscription_id' => $subscription->getKey(),
                'transaction_id' => $transaction?->getKey() ?? $lockedOrder->wallet_transaction_id,
                'status' => 'succeeded',
                'completed_at' => now(),
            ])->saveOrFail();

            return $subscription;
        });

        return $subscription;
    }

    public function cancel(User $user): ?Subscription
    {
        $cancellationScheduled = false;
        $subscription = DB::transaction(function () use ($user, &$cancellationScheduled): ?Subscription {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $subscription = $lockedUser->subscriptions()
                ->active()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                return null;
            }

            if ($subscription->cancelled_at !== null) {
                return $subscription;
            }

            $subscription->forceFill([
                'cancelled_at' => now(),
                'auto_renew' => false,
            ])->saveOrFail();
            $cancellationScheduled = true;

            return $subscription;
        });

        if ($subscription && $cancellationScheduled) {
            SubscriptionCancelled::dispatch($subscription);
        }

        return $subscription;
    }

    /**
     * @return array{subscription: Subscription, refund_amount: int, notification_required: bool}
     */
    public function cancelScheduled(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $subscription = $lockedUser->subscriptions()
                ->where('status', 'active')
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                $previouslyCancelled = $lockedUser->subscriptions()
                    ->where('status', 'cancelled')
                    ->whereHas('paymentOrder', fn ($query) => $query
                        ->where('kind', 'subscription')
                        ->where('subscription_change_type', 'scheduled')
                        ->where('status', 'succeeded'))
                    ->with('paymentOrder')
                    ->latest('cancelled_at')
                    ->first();

                if ($previouslyCancelled) {
                    return [
                        'subscription' => $previouslyCancelled,
                        'refund_amount' => (int) $previouslyCancelled->paymentOrder->wallet_amount
                            + (int) $previouslyCancelled->paymentOrder->gateway_amount,
                        'notification_required' => false,
                    ];
                }

                throw new SubscriptionConflictException('پلن رزروشدهٔ قابل لغو برای این حساب پیدا نشد.');
            }

            $order = PaymentOrder::query()
                ->where('user_id', $lockedUser->getKey())
                ->whereKey($subscription->payment_order_id)
                ->lockForUpdate()
                ->first();
            if (
                ! $order
                || $order->kind !== 'subscription'
                || $order->subscription_change_type !== 'scheduled'
                || $order->status !== 'succeeded'
                || $order->subscription_id !== $subscription->getKey()
            ) {
                throw new SubscriptionConflictException('سفارش پرداخت‌شدهٔ این پلن رزروشده قابل تأیید نیست.');
            }

            $refundAmount = (int) $order->wallet_amount + (int) $order->gateway_amount;
            if ($refundAmount !== (int) $order->gross_amount - (int) $order->discount_amount) {
                throw new SubscriptionConflictException('مبلغ پرداخت سفارش با مبلغ قابل بازپرداخت مطابقت ندارد.');
            }

            $refundTransactions = [];
            if ($order->wallet_amount > 0) {
                $walletDebit = Transaction::query()
                    ->whereKey($order->wallet_transaction_id)
                    ->where('user_id', $lockedUser->getKey())
                    ->lockForUpdate()
                    ->first();
                if (
                    ! $walletDebit
                    || $walletDebit->type !== 'debit'
                    || $walletDebit->status !== 'succeeded'
                    || $walletDebit->amount !== $order->wallet_amount
                    || $walletDebit->currency !== $order->currency
                ) {
                    throw new SubscriptionConflictException('تراکنش کیف پول سفارش برای بازپرداخت معتبر نیست.');
                }

                $refundTransactions[] = $this->wallet->refundPurchase(
                    $walletDebit,
                    'وجه پرداخت‌شده بابت پلن رزروشدهٔ لغوشده به کیف پول بازگردانده شد.',
                );
            }

            if ($order->gateway_amount > 0) {
                $gatewayPayment = Transaction::query()
                    ->whereKey($order->transaction_id)
                    ->where('user_id', $lockedUser->getKey())
                    ->lockForUpdate()
                    ->first();
                if (
                    ! $gatewayPayment
                    || $gatewayPayment->type !== 'subscription'
                    || $gatewayPayment->status !== 'succeeded'
                    || $gatewayPayment->amount !== $order->gateway_amount
                    || $gatewayPayment->currency !== $order->currency
                ) {
                    throw new SubscriptionConflictException('تراکنش درگاه سفارش برای بازپرداخت معتبر نیست.');
                }

                $refundTransactions[] = $this->wallet->refundSubscriptionPaymentToWallet(
                    $gatewayPayment,
                    'وجه پرداخت‌شده بابت پلن رزروشدهٔ لغوشده به کیف پول بازگردانده شد.',
                );
            }

            if ($refundAmount > 0 && $refundTransactions === []) {
                throw new SubscriptionConflictException('تراکنش‌های پرداخت برای بازپرداخت سفارش پیدا نشدند.');
            }

            $this->discounts->refundConsumedReservation($order);
            $subscription->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'auto_renew' => false,
            ])->saveOrFail();

            if ($refundTransactions !== []) {
                $order->forceFill([
                    'refund_transaction_id' => $refundTransactions[array_key_last($refundTransactions)]->getKey(),
                ])->saveOrFail();
            }

            return [
                'subscription' => $subscription->fresh()->load('paymentOrder'),
                'refund_amount' => $refundAmount,
                'notification_required' => true,
            ];
        }, 3);
    }

    public function getActiveSubscription(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->active()
            ->latest('id')
            ->first();
    }
}
