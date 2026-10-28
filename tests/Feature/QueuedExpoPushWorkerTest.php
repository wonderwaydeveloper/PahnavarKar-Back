<?php

namespace Tests\Feature;

use App\Jobs\SendExpoPushNotification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class QueuedExpoPushWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_not_enqueued_until_the_transaction_commits_then_worker_reads_committed_data(): void
    {
        $this->configureIsolatedDatabaseQueue();
        $pushToken = 'ExponentPushToken[committed-device]';
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [['status' => 'ok', 'id' => 'committed-ticket']],
            ]),
        ]);

        DB::transaction(function () use ($pushToken): void {
            $pushSubscription = $this->pushSubscription($pushToken);
            SendExpoPushNotification::dispatch(
                (int) $pushSubscription->getKey(),
                'Committed title',
                'Committed body',
            )->onQueue('push');

            $this->assertSame(0, DB::table('jobs')->count());
            $this->assertDatabaseHas('push_subscriptions', [
                'id' => $pushSubscription->getKey(),
                'push_token' => $pushToken,
            ]);
        });

        $this->assertSame(1, DB::table('jobs')->count());
        $this->workOnce('push', 3);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        Http::assertSent(static fn (ClientRequest $request): bool => $request->data()['to'] === $pushToken);
    }

    public function test_job_is_not_enqueued_when_the_transaction_rolls_back(): void
    {
        $this->configureIsolatedDatabaseQueue();
        $pushToken = 'ExponentPushToken[rolled-back-device]';
        Http::fake();

        try {
            DB::transaction(function () use ($pushToken): void {
                $pushSubscription = $this->pushSubscription($pushToken);
                SendExpoPushNotification::dispatch(
                    (int) $pushSubscription->getKey(),
                    'Rolled back title',
                    'Rolled back body',
                )->onQueue('push');

                $this->assertSame(0, DB::table('jobs')->count());

                throw new RuntimeException('Force transaction rollback.');
            });
            $this->fail('The transaction must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force transaction rollback.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertDatabaseMissing('push_subscriptions', ['push_token' => $pushToken]);
        Http::assertNothingSent();
    }

    public function test_database_worker_sends_encrypted_push_job_to_expo_and_removes_it_after_success(): void
    {
        $this->configureIsolatedDatabaseQueue();
        $pushToken = 'ExponentPushToken[worker-success-device]';
        $pushSubscription = $this->pushSubscription($pushToken);
        $data = ['event' => 'subscription.expiring', 'subscription_id' => 81];
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [['status' => 'ok', 'id' => 'worker-ticket']],
            ]),
        ]);

        SendExpoPushNotification::dispatch(
            (int) $pushSubscription->getKey(),
            'Subscription ending',
            'Your subscription expires soon.',
            $data,
        )->onQueue('push');

        $queued = DB::table('jobs')->sole();
        $this->assertSame('push', $queued->queue);
        $payload = json_decode($queued->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(SendExpoPushNotification::class, $payload['data']['command']);
        $this->assertStringNotContainsString($pushToken, $queued->payload);

        $this->workOnce('push', 3);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertDatabaseHas('push_subscriptions', ['id' => $pushSubscription->getKey()]);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://exp.host/--/api/v2/push/send'
            && $request->data() === [
                'to' => $pushToken,
                'title' => 'Subscription ending',
                'body' => 'Your subscription expires soon.',
                'data' => $data,
                'sound' => 'default',
                'priority' => 'default',
            ]);
        Http::assertSentCount(1);
    }

    public function test_database_worker_retries_http_failure_and_records_no_response_secrets_in_failed_job(): void
    {
        $this->configureIsolatedDatabaseQueue();
        $pushToken = 'ExponentPushToken[private-worker-device]';
        $accessToken = 'private-expo-access-secret';
        $pushSubscription = $this->pushSubscription($pushToken);
        config(['services.expo_push.access_token' => $accessToken]);
        $providerResponse = "Expo rejected {$pushToken}; Authorization: Bearer {$accessToken}";
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'error' => $providerResponse,
            ], 503),
        ]);

        SendExpoPushNotification::dispatch(
            (int) $pushSubscription->getKey(),
            'Private title',
            'Private body',
            ['event' => 'test.private', 'marker' => 'private-payload-marker'],
        )->onQueue('push');

        $queued = DB::table('jobs')->sole();
        foreach ([$pushToken, $accessToken, 'Private title', 'Private body', 'private-payload-marker'] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $queued->payload);
        }

        $this->workOnce('push', 3);
        $this->assertSame(1, (int) DB::table('jobs')->sole()->attempts);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->makeQueuedJobAvailableNow();
        $this->workOnce('push', 3);
        $this->assertSame(2, (int) DB::table('jobs')->sole()->attempts);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->makeQueuedJobAvailableNow();
        $this->workOnce('push', 3);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertDatabaseHas('push_subscriptions', ['id' => $pushSubscription->getKey()]);

        $failedJob = DB::table('failed_jobs')->sole();
        $this->assertSame('database', $failedJob->connection);
        $this->assertSame('push', $failedJob->queue);
        foreach ([$pushToken, $accessToken, $providerResponse] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $failedJob->payload);
            $this->assertStringNotContainsString($sensitiveValue, $failedJob->exception);
        }
        Http::assertSentCount(3);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->hasHeader(
            'Authorization',
            'Bearer '.$accessToken,
        ));
    }

    private function configureIsolatedDatabaseQueue(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.connection' => null,
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.queue' => 'default',
            'queue.connections.database.retry_after' => 90,
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.database' => 'sqlite',
            'queue.failed.table' => 'failed_jobs',
        ]);
    }

    private function pushSubscription(string $pushToken): PushSubscription
    {
        return PushSubscription::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'push_token' => $pushToken,
            'platform' => 'ios',
        ]);
    }

    private function workOnce(string $queue, int $tries): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => $queue,
            '--once' => true,
            '--sleep' => 0,
            '--tries' => $tries,
            '--timeout' => 20,
        ])->assertSuccessful();
    }

    private function makeQueuedJobAvailableNow(): void
    {
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
    }
}
