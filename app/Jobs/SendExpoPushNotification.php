<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use UnexpectedValueException;

class SendExpoPushNotification implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $subscription = PushSubscription::query()->find($this->subscriptionId);
        if ($subscription === null) {
            return;
        }

        $request = Http::timeout(10)->acceptJson();
        $accessToken = config('services.expo_push.access_token');
        if (is_string($accessToken) && $accessToken !== '') {
            $request = $request->withToken($accessToken);
        }

        try {
            $response = $request->post('https://exp.host/--/api/v2/push/send', [
                'to' => $subscription->push_token,
                'title' => $this->title,
                'body' => $this->body,
                'data' => $this->data,
                'sound' => 'default',
                'priority' => 'default',
            ])->throw();
        } catch (ConnectionException|RequestException) {
            throw new RuntimeException('Expo Push API request failed.');
        }

        $tickets = $response->json('data');
        $ticket = is_array($tickets) && array_is_list($tickets)
            ? ($tickets[0] ?? null)
            : $tickets;

        if (! is_array($ticket) || ! is_string($ticket['status'] ?? null)) {
            throw new UnexpectedValueException('Expo Push API returned an invalid ticket response.');
        }

        if ($ticket['status'] === 'ok') {
            return;
        }

        $error = $ticket['details']['error'] ?? null;
        if ($error === 'DeviceNotRegistered') {
            $subscription->delete();

            return;
        }

        Log::warning('Expo push notification was rejected.', [
            'subscription_id' => $subscription->id,
            'error' => is_string($error) ? $error : 'UnknownError',
        ]);

        throw new RuntimeException('Expo Push API rejected the notification.');
    }
}
