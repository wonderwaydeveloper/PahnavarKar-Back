<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotifyExpiringSubscriptions extends Command
{
    protected $signature = 'subscriptions:notify-expiring';

    protected $description = 'Notify opted-in devices about subscriptions ending within three days.';

    public function handle(ExpoPushService $pushNotifications): int
    {
        Subscription::query()
            ->where('type', 'premium')
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addDays(3))
            ->whereNull('push_expiry_notified_at')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($subscriptions) use ($pushNotifications): void {
                foreach ($subscriptions as $subscription) {
                    $this->notifySubscription((int) $subscription->id, $pushNotifications);
                }
            });

        return self::SUCCESS;
    }

    private function notifySubscription(int $subscriptionId, ExpoPushService $pushNotifications): void
    {
        $notifiedAt = now();

        try {
            DB::transaction(function () use ($subscriptionId, $pushNotifications, $notifiedAt): void {
                $subscription = Subscription::query()->lockForUpdate()->find($subscriptionId);
                if (
                    ! $subscription
                    || $subscription->type !== 'premium'
                    || $subscription->status !== 'active'
                    || $subscription->starts_at->isFuture()
                    || $subscription->ends_at->lte(now())
                    || $subscription->ends_at->gt(now()->addDays(3))
                    || $subscription->push_expiry_notified_at !== null
                ) {
                    return;
                }

                $queuedCount = $pushNotifications->sendToUser(
                    $subscription->user,
                    'اشتراک شما رو به پایان است',
                    'اشتراک فعال شما طی سه روز آینده به پایان می‌رسد. برای بررسی پلن خود به حساب‌تان مراجعه کنید.',
                    [
                        'event' => 'subscription.expiring',
                        'subscription_id' => $subscription->getKey(),
                    ],
                );

                if ($queuedCount > 0) {
                    $subscription->forceFill(['push_expiry_notified_at' => $notifiedAt])->saveOrFail();
                }
            });
        } catch (Throwable $exception) {
            Log::error('Could not enqueue subscription expiry reminders.', [
                'subscription_id' => $subscriptionId,
                'exception' => $exception,
            ]);
            Subscription::query()
                ->whereKey($subscriptionId)
                ->where('push_expiry_notified_at', $notifiedAt)
                ->update(['push_expiry_notified_at' => null]);

            throw $exception;
        }
    }
}
