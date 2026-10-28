<?php

namespace App\Listeners;

use App\Events\SubscriptionActivated;
use App\Services\Notifications\ExpoPushService;

class SendSubscriptionActivatedPushNotification
{
    public function __construct(private readonly ExpoPushService $pushNotifications) {}

    public function handle(SubscriptionActivated $event): void
    {
        $event->subscription->loadMissing('user');
        $planName = $event->subscription->plan_key;

        $this->pushNotifications->sendToUser(
            $event->subscription->user,
            'اشتراک شما فعال شد',
            "پرداخت تأیید شد و پلن {$planName} برای حساب شما فعال شد.",
            [
                'event' => 'subscription.activated',
                'subscription_id' => $event->subscription->getKey(),
            ],
        );
    }
}
