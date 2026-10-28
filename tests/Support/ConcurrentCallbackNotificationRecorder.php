<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use RuntimeException;

class ConcurrentCallbackNotificationRecorder extends ExpoPushService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        $path = (string) getenv('PAYMENT_CALLBACK_NOTIFICATION_LOG');
        $event = (string) ($data['event'] ?? '');
        if ($path === '' || ! in_array($event, ['payment.succeeded', 'subscription.activated'], true)) {
            throw new RuntimeException('Unexpected callback notification recording request.');
        }

        $record = json_encode([
            'event' => $event,
            'user_id' => $user->getKey(),
            'data' => $data,
        ], JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $record.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to record callback notification.');
        }

        return 1;
    }
}
