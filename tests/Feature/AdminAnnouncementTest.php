<?php

namespace Tests\Feature;

use App\Jobs\DeliverOfficialAnnouncement;
use App\Jobs\SendExpoPushNotification;
use App\Models\OfficialAnnouncement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\ExpoPushService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_management_permission_is_seeded_for_owner_and_admin_only(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Role::findByName('Owner', 'web')->hasPermissionTo('manage notifications'));
        $this->assertTrue(Role::findByName('Admin', 'web')->hasPermissionTo('manage notifications'));
        $this->assertFalse(Role::findByName('User', 'web')->hasPermissionTo('manage notifications'));
    }

    public function test_only_users_with_notification_permission_can_open_and_publish_announcements(): void
    {
        $unauthorized = User::factory()->create();
        $this->actingAs($unauthorized, 'web')->get(route('admin.announcements.index'))->assertForbidden();
        $this->actingAs($unauthorized, 'web')
            ->post(route('admin.announcements.store'), $this->announcementPayload())
            ->assertForbidden();

        Queue::fake();
        $manager = $this->notificationManager();
        $recipient = User::factory()->create();

        $this->actingAs($manager, 'web')
            ->from(route('admin.announcements.index'))
            ->post(route('admin.announcements.store'), $this->announcementPayload())
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHas('status');

        $announcement = OfficialAnnouncement::query()->sole();
        $this->assertSame($manager->getKey(), $announcement->published_by);
        $this->assertSame(3, $announcement->recipient_count);
        $this->assertFalse($announcement->send_push);
        $this->assertSame('queued', $announcement->delivery_status);
        Queue::assertPushed(DeliverOfficialAnnouncement::class, fn (DeliverOfficialAnnouncement $job): bool => $job->announcementId === $announcement->getKey());
        $this->assertSame(0, $recipient->inboxNotifications()->count());
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => $announcement->getMorphClass(),
            'subject_id' => $announcement->getKey(),
            'causer_id' => $manager->getKey(),
            'event' => 'official_announcement_published',
        ]);
    }

    public function test_announcement_delivery_creates_persistent_notifications_for_the_published_audience_once(): void
    {
        Queue::fake();
        $publisher = $this->notificationManager();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $announcement = $this->publish($publisher, true);
        $lateUser = User::factory()->create();
        $first->pushSubscriptions()->create([
            'push_token' => 'ExponentPushToken[announcement-device]',
            'platform' => 'android',
        ]);

        $job = new DeliverOfficialAnnouncement((int) $announcement->getKey());
        $service = app(ExpoPushService::class);
        $job->handle($service);
        $job->handle($service);

        $this->assertSame(3, $announcement->fresh()->recipient_count);
        $this->assertSame('delivered', $announcement->fresh()->delivery_status);
        $this->assertNotNull($announcement->fresh()->delivered_at);
        $this->assertSame(1, $first->inboxNotifications()->count());
        $this->assertSame(1, $second->inboxNotifications()->count());
        $this->assertSame(1, $publisher->inboxNotifications()->count());
        $this->assertSame(0, $lateUser->inboxNotifications()->count());
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $first->getKey(),
            'category' => 'announcement',
            'priority' => 'important',
            'event' => 'announcement.published',
            'deduplication_key' => "announcement:{$announcement->id}:user:{$first->id}",
        ]);
        Queue::assertPushed(
            SendExpoPushNotification::class,
            fn (SendExpoPushNotification $push): bool => ($push->data['event'] ?? null) === 'announcement.published'
                && ($push->data['notification_id'] ?? null) === $first->inboxNotifications()->value('id'),
        );
        Queue::assertPushed(SendExpoPushNotification::class, 1);
    }

    public function test_retried_announcement_delivery_does_not_restore_a_notification_deleted_by_the_user(): void
    {
        Queue::fake();
        $publisher = $this->notificationManager();
        $recipient = User::factory()->create();
        $announcement = $this->publish($publisher);
        $job = new DeliverOfficialAnnouncement((int) $announcement->getKey());
        $service = app(ExpoPushService::class);

        $job->handle($service);
        $notification = UserNotification::query()
            ->where('user_id', $recipient->getKey())
            ->where('event', 'announcement.published')
            ->firstOrFail();
        $notification->delete();

        $job->handle($service);

        $this->assertSame(0, $recipient->inboxNotifications()->count());
        $this->assertNotNull($notification->fresh()->deleted_at);
        $this->assertDatabaseCount('user_notifications', 2);
    }

    public function test_announcement_validation_rejects_invalid_priority_and_short_content(): void
    {
        $manager = $this->notificationManager();
        $this->actingAs($manager, 'web')
            ->from(route('admin.announcements.index'))
            ->post(route('admin.announcements.store'), [
                'title' => 'OK',
                'body' => 'Short',
                'priority' => 'critical',
                'send_push' => 'yes',
            ])
            ->assertSessionHasErrors(['title', 'body', 'priority', 'send_push']);

        $this->assertDatabaseCount('official_announcements', 0);
    }

    public function test_admin_can_retry_failed_delivery_but_cannot_duplicate_a_successful_delivery(): void
    {
        Queue::fake();
        $manager = $this->notificationManager();
        $announcement = $this->publish($manager);
        $announcement->forceFill(['delivery_status' => 'failed'])->saveOrFail();

        $this->actingAs($manager, 'web')
            ->post(route('admin.announcements.retry', $announcement))
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHas('status', 'تحویل اطلاعیه دوباره در صف قرار گرفت.');

        $this->assertSame('queued', $announcement->fresh()->delivery_status);
        Queue::assertPushed(DeliverOfficialAnnouncement::class, 1);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $announcement->getKey(),
            'causer_id' => $manager->getKey(),
            'event' => 'official_announcement_delivery_retried',
        ]);

        $announcement->forceFill(['delivery_status' => 'delivered'])->saveOrFail();
        $this->actingAs($manager, 'web')
            ->post(route('admin.announcements.retry', $announcement))
            ->assertSessionHas('status', 'فقط اطلاعیه‌های ناموفق قابل تلاش دوباره هستند.');

        Queue::assertPushed(DeliverOfficialAnnouncement::class, 1);
    }

    private function notificationManager(): User
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::findOrCreate('manage notifications', 'web'));

        return $manager;
    }

    private function publish(User $publisher, bool $sendPush = false): OfficialAnnouncement
    {
        return OfficialAnnouncement::query()->create([
            'title' => 'اطلاعیه مهم سامانه',
            'body' => 'این پیام برای آزمایش تحویل پایدار صندوق ثبت شده است.',
            'priority' => 'important',
            'send_push' => $sendPush,
            'published_by' => $publisher->getKey(),
            'recipient_max_user_id' => User::query()->max('id'),
            'recipient_count' => User::query()->count(),
            'delivery_status' => 'queued',
            'published_at' => now(),
        ]);
    }

    private function announcementPayload(): array
    {
        return [
            'title' => 'اطلاعیه رسمی سامانه',
            'body' => 'این پیام برای همه کاربران سامانه منتشر می‌شود.',
            'priority' => 'normal',
        ];
    }
}
