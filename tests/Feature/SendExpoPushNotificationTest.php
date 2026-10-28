<?php

namespace Tests\Feature;

use App\Jobs\SendExpoPushNotification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

class SendExpoPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_the_expected_authenticated_request_and_accepts_a_ticket_list(): void
    {
        $accessToken = 'expo-access-secret';
        config(['services.expo_push.access_token' => $accessToken]);
        $pushSubscription = $this->pushSubscription('ExponentPushToken[test-device]');
        $data = ['event' => 'subscription.expiring', 'subscription_id' => 42];
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [['status' => 'ok', 'id' => 'ticket-123']],
            ]),
        ]);
        $job = new SendExpoPushNotification(
            (int) $pushSubscription->getKey(),
            'Subscription ending',
            'Your subscription expires soon.',
            $data,
        );

        $job->handle();

        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://exp.host/--/api/v2/push/send'
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Authorization', 'Bearer '.$accessToken)
            && $request->data() === [
                'to' => 'ExponentPushToken[test-device]',
                'title' => 'Subscription ending',
                'body' => 'Your subscription expires soon.',
                'data' => $data,
                'sound' => 'default',
                'priority' => 'default',
            ]);
        Http::assertSentCount(1);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertTrue($job->afterCommit);
    }

    public function test_job_sends_without_bearer_auth_when_no_expo_access_token_is_configured(): void
    {
        config(['services.expo_push.access_token' => null]);
        $pushSubscription = $this->pushSubscription('ExponentPushToken[public-project]');
        Http::fake([
            '*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-456']]),
        ]);

        (new SendExpoPushNotification(
            (int) $pushSubscription->getKey(),
            'Title',
            'Body',
        ))->handle();

        Http::assertSent(static fn (ClientRequest $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function test_missing_push_subscription_is_a_no_op_without_contacting_expo(): void
    {
        Http::fake();

        (new SendExpoPushNotification(999, 'Title', 'Body'))->handle();

        Http::assertNothingSent();
    }

    public function test_device_not_registered_response_deletes_only_that_push_subscription(): void
    {
        $pushSubscription = $this->pushSubscription('ExponentPushToken[invalid-device]');
        $otherSubscription = $this->pushSubscription('ExponentPushToken[other-device]');
        Http::fake([
            '*' => Http::response([
                'data' => [[
                    'status' => 'error',
                    'message' => 'The recipient device is not registered.',
                    'details' => ['error' => 'DeviceNotRegistered'],
                ]],
            ]),
        ]);

        (new SendExpoPushNotification(
            (int) $pushSubscription->getKey(),
            'Title',
            'Body',
        ))->handle();

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $pushSubscription->getKey()]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $otherSubscription->getKey()]);
        Http::assertSentCount(1);
    }

    public function test_other_expo_ticket_rejections_are_logged_without_tokens_and_are_retryable(): void
    {
        $accessToken = 'private-expo-access-token';
        $pushToken = 'ExponentPushToken[private-device-token]';
        config(['services.expo_push.access_token' => $accessToken]);
        $pushSubscription = $this->pushSubscription($pushToken);
        Http::fake([
            '*' => Http::response([
                'data' => [[
                    'status' => 'error',
                    'message' => 'Expo rejected the message.',
                    'details' => ['error' => 'MessageRateExceeded'],
                ]],
            ]),
        ]);
        Log::spy();

        try {
            (new SendExpoPushNotification(
                (int) $pushSubscription->getKey(),
                'Title',
                'Body',
            ))->handle();
            $this->fail('A retryable Expo ticket rejection must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Expo Push API rejected the notification.', $exception->getMessage());
        }

        $this->assertDatabaseHas('push_subscriptions', ['id' => $pushSubscription->getKey()]);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($pushToken, $accessToken, $pushSubscription): bool {
                $this->assertSame('Expo push notification was rejected.', $message);
                $this->assertSame($pushSubscription->getKey(), $context['subscription_id']);
                $this->assertSame('MessageRateExceeded', $context['error']);
                $this->assertStringNotContainsString($pushToken, json_encode($context));
                $this->assertStringNotContainsString($accessToken, json_encode($context));

                return true;
            });
        Http::assertSentCount(1);
    }

    public function test_http_failure_throws_for_queue_retry_and_exposes_three_attempts_with_two_retry_delays(): void
    {
        $pushSubscription = $this->pushSubscription('ExponentPushToken[retry-device]');
        Http::fake([
            '*' => Http::response(['error' => 'temporary failure'], 503),
        ]);
        Log::spy();
        $job = new SendExpoPushNotification(
            (int) $pushSubscription->getKey(),
            'Title',
            'Body',
        );

        try {
            $job->handle();
            $this->fail('An unsuccessful HTTP response must throw for queue retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Expo Push API request failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120], $job->backoff);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $pushSubscription->getKey()]);
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        Http::assertSentCount(1);
    }

    public function test_invalid_expo_ticket_shapes_throw_without_removing_the_subscription(): void
    {
        $pushSubscription = $this->pushSubscription('ExponentPushToken[malformed-response]');
        Http::fake([
            '*' => Http::response(['data' => []]),
        ]);

        $this->expectException(UnexpectedValueException::class);
        try {
            (new SendExpoPushNotification(
                (int) $pushSubscription->getKey(),
                'Title',
                'Body',
            ))->handle();
        } finally {
            $this->assertDatabaseHas('push_subscriptions', ['id' => $pushSubscription->getKey()]);
            Http::assertSentCount(1);
        }
    }

    private function pushSubscription(string $pushToken): PushSubscription
    {
        return PushSubscription::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'push_token' => $pushToken,
            'platform' => 'ios',
        ]);
    }
}
