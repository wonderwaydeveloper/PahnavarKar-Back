<?php

namespace Tests\Feature;

use App\Events\SubscriptionActivated;
use App\Jobs\SendExpoPushNotification;
use App\Models\PushSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_activation_dispatches_an_opted_in_push_notification(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        PushSubscription::query()->create([
            'user_id' => $user->getKey(),
            'push_token' => 'ExponentPushToken[test-device]',
            'platform' => 'ios',
        ]);
        $subscription = Subscription::query()->create([
            'user_id' => $user->getKey(),
            'plan_key' => 'bronze',
            'type' => 'premium',
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        SubscriptionActivated::dispatch($subscription);

        Queue::assertPushed(SendExpoPushNotification::class, fn (SendExpoPushNotification $job): bool => $job->subscriptionId === PushSubscription::query()->value('id')
            && ($job->data['event'] ?? null) === 'subscription.activated',
        );
    }

    public function test_expiring_subscription_reminder_is_sent_only_once(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $pushSubscription = PushSubscription::query()->create([
            'user_id' => $user->getKey(),
            'push_token' => 'ExponentPushToken[test-device]',
            'platform' => 'ios',
        ]);
        $subscription = Subscription::query()->create([
            'user_id' => $user->getKey(),
            'plan_key' => 'bronze',
            'type' => 'premium',
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(2),
        ]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();
        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Queue::assertPushed(
            SendExpoPushNotification::class,
            fn (SendExpoPushNotification $job): bool => $job->subscriptionId === $pushSubscription->getKey()
                && ($job->data['event'] ?? null) === 'subscription.expiring',
        );
        Queue::assertPushed(SendExpoPushNotification::class, 1);
        $this->assertNotNull($subscription->fresh()->push_expiry_notified_at);
    }

    public function test_expiry_reminder_selects_only_active_started_unexpired_subscriptions_within_three_days(): void
    {
        Queue::fake();
        $now = Carbon::parse('2026-10-27 12:00:00');
        $this->travelTo($now);

        $eligibleAtBoundary = $this->subscription([
            'ends_at' => $now->copy()->addDays(3),
        ]);
        $this->pushSubscriptionFor($eligibleAtBoundary->user);

        $startsAtBoundary = $this->subscription([
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays(1),
        ]);
        $this->pushSubscriptionFor($startsAtBoundary->user);

        $trial = $this->subscription([
            'type' => 'trial',
            'ends_at' => $now->copy()->addDay(),
        ]);
        $this->pushSubscriptionFor($trial->user);

        $outsideWindow = $this->subscription([
            'ends_at' => $now->copy()->addDays(3)->addSecond(),
        ]);
        $this->pushSubscriptionFor($outsideWindow->user);

        $notStarted = $this->subscription([
            'starts_at' => $now->copy()->addSecond(),
            'ends_at' => $now->copy()->addDays(2),
        ]);
        $this->pushSubscriptionFor($notStarted->user);

        $expiredAtBoundary = $this->subscription([
            'ends_at' => $now,
        ]);
        $this->pushSubscriptionFor($expiredAtBoundary->user);

        $inactive = $this->subscription([
            'status' => 'cancelled',
            'ends_at' => $now->copy()->addDay(),
        ]);
        $this->pushSubscriptionFor($inactive->user);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Queue::assertPushed(SendExpoPushNotification::class, 2);
        $this->assertNotNull($eligibleAtBoundary->fresh()->push_expiry_notified_at);
        $this->assertNotNull($startsAtBoundary->fresh()->push_expiry_notified_at);
        foreach ([$trial, $outsideWindow, $notStarted, $expiredAtBoundary, $inactive] as $subscription) {
            $this->assertNull($subscription->fresh()->push_expiry_notified_at);
        }
    }

    public function test_subscription_without_push_devices_remains_eligible_for_a_later_run(): void
    {
        Queue::fake();
        $subscription = $this->subscription(['ends_at' => now()->addDays(2)]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        $this->assertNull($subscription->fresh()->push_expiry_notified_at);
        Queue::assertNothingPushed();

        $pushSubscription = $this->pushSubscriptionFor($subscription->user);
        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Queue::assertPushed(
            SendExpoPushNotification::class,
            fn (SendExpoPushNotification $job): bool => $job->subscriptionId === $pushSubscription->getKey()
                && ($job->data['event'] ?? null) === 'subscription.expiring',
        );
        $this->assertNotNull($subscription->fresh()->push_expiry_notified_at);
    }

    public function test_dispatch_failure_leaves_the_reminder_retryable(): void
    {
        $subscription = $this->subscription(['ends_at' => now()->addDays(2)]);
        $this->pushSubscriptionFor($subscription->user);
        $pushNotifications = Mockery::mock(ExpoPushService::class);
        $pushNotifications->shouldReceive('sendToUser')
            ->once()
            ->andThrow(new RuntimeException('Queue unavailable'));
        $this->app->instance(ExpoPushService::class, $pushNotifications);

        try {
            $this->artisan('subscriptions:notify-expiring');
            $this->fail('Queue dispatch failure should be surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue unavailable', $exception->getMessage());
        }

        $this->assertNull($subscription->fresh()->push_expiry_notified_at);

        Queue::fake();
        $this->app->instance(ExpoPushService::class, new ExpoPushService);
        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Queue::assertPushed(SendExpoPushNotification::class, 1);
        $this->assertNotNull($subscription->fresh()->push_expiry_notified_at);
    }

    public function test_expiry_reminder_is_queued_once_for_each_device_and_not_repeated(): void
    {
        Queue::fake();
        $subscription = $this->subscription(['ends_at' => now()->addDays(2)]);
        $firstDevice = $this->pushSubscriptionFor($subscription->user, 'ExponentPushToken[first]');
        $secondDevice = $this->pushSubscriptionFor($subscription->user, 'ExponentPushToken[second]');

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();
        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Queue::assertPushed(
            SendExpoPushNotification::class,
            fn (SendExpoPushNotification $job): bool => in_array(
                $job->subscriptionId,
                [$firstDevice->getKey(), $secondDevice->getKey()],
                true,
            )
                && ($job->data['event'] ?? null) === 'subscription.expiring',
            2,
        );
        Queue::assertPushed(SendExpoPushNotification::class, 2);
    }

    public function test_expiring_subscription_command_is_scheduled_daily_with_overlap_protection(): void
    {
        $this->artisan('schedule:list');
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => str_contains($event->command ?? '', 'subscriptions:notify-expiring'),
        );

        $this->assertNotNull($event);
        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(1440, $event->expiresAt);
    }

    private function subscription(array $attributes = []): Subscription
    {
        $user = User::factory()->create();

        return Subscription::query()->create([
            'user_id' => $user->getKey(),
            'plan_key' => 'bronze',
            'type' => 'premium',
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(2),
            ...$attributes,
        ]);
    }

    private function pushSubscriptionFor(User $user, ?string $token = null): PushSubscription
    {
        return PushSubscription::query()->create([
            'user_id' => $user->getKey(),
            'push_token' => $token ?? "ExponentPushToken[user-{$user->getKey()}]",
            'platform' => 'ios',
        ]);
    }
}
