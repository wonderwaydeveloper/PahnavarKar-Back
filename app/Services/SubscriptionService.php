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

class SubscriptionService
{
    public function __construct(private readonly AdminRuntimeSettings $runtimeSettings) {}

    public function activatePremium(PaymentOrder $order, ?Transaction $transaction): Subscription
    {
        $subscription = DB::transaction(function () use ($order, $transaction): Subscription {
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
                ])->saveOrFail();
            } elseif ($lockedOrder->subscription_change_type === 'scheduled') {
                if ($activeSubscription && $activeSubscription->getKey() !== $sourceSubscription?->getKey()) {
                    throw new SubscriptionConflictException('اشتراک فعلی برای تغییر زمان‌بندی‌شده تغییر کرده است.');
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

            $subscription->forceFill(['cancelled_at' => now()])->saveOrFail();
            $cancellationScheduled = true;

            return $subscription;
        });

        if ($subscription && $cancellationScheduled) {
            SubscriptionCancelled::dispatch($subscription);
        }

        return $subscription;
    }

    public function getActiveSubscription(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->active()
            ->latest('id')
            ->first();
    }
}
