<?php

namespace App\Services\Notifications;

use App\Jobs\SendExpoPushNotification;
use App\Models\User;

class ExpoPushService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        $subscriptionIds = $user->pushSubscriptions()->pluck('id');

        foreach ($subscriptionIds as $subscriptionId) {
            SendExpoPushNotification::dispatch((int) $subscriptionId, $title, $body, $data);
        }

        return $subscriptionIds->count();
    }

    public function sendNewDeviceAlert(User $user, int $currentDeviceId): void
    {
        $user->pushSubscriptions()
            ->whereNotNull('user_device_id')
            ->where('user_device_id', '!=', $currentDeviceId)
            ->pluck('id')
            ->each(static function (int $subscriptionId): void {
                SendExpoPushNotification::dispatch(
                    $subscriptionId,
                    'ورود جدید به حساب',
                    'ورود به حساب شما از یک دستگاه جدید انجام شد. اگر این کار را انجام نداده‌اید، نشست‌های خود را بررسی کنید.',
                    ['event' => 'security.new_device'],
                );
            });
    }
}
