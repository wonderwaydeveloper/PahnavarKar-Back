<?php

namespace App\Listeners;

use App\Events\SubscriptionActivated;
use App\Services\Notifications\ExpoPushService;
use App\Services\SubscriptionPlanName;

class SendSubscriptionActivatedPushNotification
{
    public function __construct(
        private readonly ExpoPushService $pushNotifications,
        private readonly SubscriptionPlanName $planNames,
    ) {}

    public function handle(SubscriptionActivated $event): void
    {
        $event->subscription->loadMissing('user');
        $planName = $this->planNames->forKey($event->subscription->plan_key);
        $startsAt = $event->subscription->starts_at;
        $isScheduled = $startsAt->isFuture();
        $notificationEvent = $isScheduled ? 'subscription.scheduled' : 'subscription.activated';
        $title = $isScheduled ? 'پلن شما رزرو شد' : 'اشتراک شما فعال شد';
        $body = $isScheduled
            ? "پرداخت تأیید شد و پلن {$planName} پس از پایان اشتراک فعلی شما فعال خواهد شد."
            : "پرداخت تأیید شد و پلن {$planName} برای حساب شما فعال شد.";

        $this->pushNotifications->sendToUser(
            $event->subscription->user,
            $title,
            $body,
            [
                'event' => $notificationEvent,
                'subscription_id' => $event->subscription->getKey(),
                'plan_name' => $planName,
                ...($isScheduled ? ['scheduled_start_at' => $startsAt->toISOString()] : []),
            ],
        );
    }
}
