<?php

namespace App\Services\Notifications;

use App\Jobs\SendExpoPushNotification;
use App\Models\User;
use App\Models\UserNotification;

class ExpoPushService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(
        User $user,
        string $title,
        string $body,
        array $data = [],
        bool $snapshotPushTokens = false,
    ): int {
        $notification = $this->createInboxNotification($user, $title, $body, $data);
        $subscriptions = $user->pushSubscriptions()->get(['id', 'push_token']);

        foreach ($subscriptions as $subscription) {
            $this->dispatchPush(
                (int) $subscription->getKey(),
                $title,
                $body,
                [...$data, 'notification_id' => $notification->getKey()],
                $snapshotPushTokens ? $subscription->push_token : null,
            );
        }

        return $subscriptions->count();
    }

    public function sendForInboxNotification(UserNotification $notification): int
    {
        $subscriptions = $notification->user->pushSubscriptions()->get(['id']);
        foreach ($subscriptions as $subscription) {
            $this->dispatchPush(
                (int) $subscription->getKey(),
                $notification->title,
                $notification->body,
                [
                    'event' => $notification->event,
                    'notification_id' => $notification->getKey(),
                    ...($notification->data ?? []),
                ],
            );
        }

        return $subscriptions->count();
    }

    public function sendWelcomeNotification(User $user): void
    {
        $title = 'به پهناورکار خوش آمدید';
        $body = 'حساب شما با موفقیت ساخته شد. برای شروع، ابزارهای محاسباتی و بخش‌های برنامه را ببینید. اطلاعات و تنظیمات امنیتی حساب از بخش «حساب کاربری» در دسترس است.';
        $data = ['event' => 'account.welcome'];
        $notification = $this->createInboxNotification($user, $title, $body, $data);
        if (! $notification->wasRecentlyCreated) {
            return;
        }

        foreach ($user->pushSubscriptions()->get(['id']) as $subscription) {
            $this->dispatchPush(
                (int) $subscription->getKey(),
                $title,
                $body,
                [...$data, 'notification_id' => $notification->getKey()],
            );
        }
    }

    public function sendNewDeviceAlert(User $user, int $currentDeviceId): void
    {
        $title = 'ورود جدید به حساب';
        $body = 'ورود به حساب شما از یک دستگاه جدید انجام شد. اگر این کار را انجام نداده‌اید، نشست‌های خود را بررسی کنید.';
        $data = ['event' => 'security.new_device'];
        $notification = $this->createInboxNotification($user, $title, $body, $data);

        $user->pushSubscriptions()
            ->whereNotNull('user_device_id')
            ->where('user_device_id', '!=', $currentDeviceId)
            ->pluck('id')
            ->each(static function (int $subscriptionId) use ($title, $body, $data, $notification): void {
                SendExpoPushNotification::dispatch(
                    $subscriptionId,
                    $title,
                    $body,
                    [...$data, 'notification_id' => $notification->getKey()],
                );
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatchPush(
        int $subscriptionId,
        string $title,
        string $body,
        array $data,
        ?string $pushTokenSnapshot = null,
    ): void {
        SendExpoPushNotification::dispatch(
            $subscriptionId,
            $title,
            $body,
            $data,
            $pushTokenSnapshot,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createInboxNotification(User $user, string $title, string $body, array $data): UserNotification
    {
        $event = is_string($data['event'] ?? null) ? $data['event'] : null;
        [$category, $priority] = match (true) {
            $event === 'account.welcome' => ['account_access', 'normal'],
            $event === 'account.suspended' => ['account_access', 'urgent'],
            in_array($event, ['account.restricted', 'account.reactivated', 'account.restriction_expired'], true) => ['account_access', 'important'],
            str_starts_with($event ?? '', 'security.') => ['security', 'important'],
            str_starts_with($event ?? '', 'payment.') || str_starts_with($event ?? '', 'wallet.') => ['finance', 'important'],
            str_starts_with($event ?? '', 'subscription.') => [
                'subscription',
                in_array($event, [
                    'subscription.expiring',
                    'subscription.auto_renewal_failed',
                    'subscription.scheduled_cancelled',
                ], true)
                    ? 'important'
                    : 'normal',
            ],
            str_starts_with($event ?? '', 'announcement.') => ['announcement', 'normal'],
            default => ['service', 'normal'],
        };
        $deduplicationKey = match ($event) {
            'account.welcome' => 'account.welcome:'.(int) $user->getKey(),
            'subscription.expiring' => isset($data['subscription_id'])
                ? 'subscription.expiring:'.(int) $data['subscription_id']
                : null,
            default => null,
        };
        $attributes = [
            'category' => $category,
            'priority' => $priority,
            'title' => $title,
            'body' => $body,
            'event' => $event,
            'data' => $data,
        ];

        if ($deduplicationKey === null) {
            return $user->inboxNotifications()->create($attributes);
        }

        return UserNotification::withTrashed()->firstOrCreate(
            ['deduplication_key' => $deduplicationKey],
            ['user_id' => $user->getKey(), ...$attributes],
        );
    }
}
