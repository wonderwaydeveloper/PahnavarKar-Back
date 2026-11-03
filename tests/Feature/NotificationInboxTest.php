<?php

namespace Tests\Feature;

use App\Jobs\SendExpoPushNotification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Admin\UserAccountStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_filter_notifications_and_view_unread_count(): void
    {
        $user = User::factory()->create();
        $user->inboxNotifications()->createMany([
            [
                'category' => 'finance',
                'priority' => 'important',
                'title' => 'پرداخت موفق',
                'body' => 'کیف پول شما شارژ شد.',
            ],
            [
                'category' => 'security',
                'priority' => 'important',
                'title' => 'ورود جدید',
                'body' => 'ورود جدیدی به حساب شما انجام شد.',
                'read_at' => now(),
            ],
        ]);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/notifications?category=finance&read=0')
            ->assertOk()
            ->assertJsonPath('data.0.category', 'finance')
            ->assertJsonPath('data.0.priority', 'important')
            ->assertJsonPath('meta.total', 1);

        $this->withToken($token)
            ->getJson('/api/v1/auth/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('read_count', 1);
    }

    public function test_user_can_mark_one_or_all_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $notification = UserNotification::query()->create([
            'user_id' => $user->getKey(),
            'category' => 'account_access',
            'priority' => 'urgent',
            'title' => 'تغییر وضعیت حساب',
            'body' => 'وضعیت حساب شما تغییر کرده است.',
        ]);
        $second = UserNotification::query()->create([
            'user_id' => $user->getKey(),
            'category' => 'service',
            'priority' => 'normal',
            'title' => 'اطلاعیه',
            'body' => 'اطلاعیه سامانه.',
        ]);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/auth/notifications/{$notification->getKey()}/read")
            ->assertOk()
            ->assertJsonPath('notification.id', $notification->getKey());
        $this->assertNotNull($notification->fresh()->read_at);

        $this->withToken($token)
            ->postJson('/api/v1/auth/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('unread_count', 0);
        $this->assertNotNull($second->fresh()->read_at);
    }

    public function test_user_cannot_read_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $notification = $otherUser->inboxNotifications()->create([
            'category' => 'security',
            'priority' => 'important',
            'title' => 'ورود جدید',
            'body' => 'ورود جدیدی به حساب شما انجام شد.',
        ]);

        $this->withToken($owner->createToken('mobile')->plainTextToken)
            ->postJson("/api/v1/auth/notifications/{$notification->getKey()}/read")
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_user_can_soft_delete_and_restore_one_notification_only_within_the_undo_window(): void
    {
        $user = User::factory()->create();
        $notification = $user->inboxNotifications()->create([
            'category' => 'announcement',
            'priority' => 'important',
            'title' => 'اطلاعیه',
            'body' => 'متن اطلاعیه برای آزمون حذف و بازگردانی.',
        ]);
        $token = $user->createToken('mobile')->plainTextToken;

        $deleted = $this->withToken($token)
            ->deleteJson("/api/v1/auth/notifications/{$notification->getKey()}")
            ->assertOk()
            ->assertJsonPath('deleted_count', 1);
        $this->assertSame(
            $notification->fresh()->deleted_at->copy()->addSeconds(30)->toISOString(),
            $deleted->json('undo_expires_at'),
        );
        $undoToken = $deleted->json('undo_token');

        $this->assertDatabaseHas('user_notifications', [
            'id' => $notification->getKey(),
            'deletion_batch_uuid' => $undoToken,
        ]);
        $this->assertNotNull($notification->fresh()->deleted_at);
        $this->assertSame(0, $user->inboxNotifications()->count());

        $this->withToken($token)
            ->postJson('/api/v1/auth/notifications/restore', ['undo_token' => $undoToken])
            ->assertOk()
            ->assertJsonPath('restored_count', 1);
        $this->assertNull($notification->fresh()->deleted_at);

        $secondDeletion = $this->withToken($token)
            ->deleteJson("/api/v1/auth/notifications/{$notification->getKey()}")
            ->assertOk()
            ->assertJsonPath('deleted_count', 1);
        $expiredUndoToken = $secondDeletion->json('undo_token');
        $this->travel(31)->seconds();
        $this->withToken($token)
            ->postJson('/api/v1/auth/notifications/restore', ['undo_token' => $expiredUndoToken])
            ->assertOk()
            ->assertJsonPath('restored_count', 0);
    }

    public function test_user_can_delete_and_restore_all_read_notifications_without_affecting_unread_or_other_users(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $readNotifications = $user->inboxNotifications()->createMany([
            [
                'category' => 'announcement',
                'priority' => 'normal',
                'title' => 'اول',
                'body' => 'اعلان خوانده‌شدهٔ اول.',
                'read_at' => now(),
            ],
            [
                'category' => 'finance',
                'priority' => 'important',
                'title' => 'دوم',
                'body' => 'اعلان خوانده‌شدهٔ دوم.',
                'read_at' => now(),
            ],
        ]);
        $unread = $user->inboxNotifications()->create([
            'category' => 'security',
            'priority' => 'important',
            'title' => 'خوانده‌نشده',
            'body' => 'این اعلان باید باقی بماند.',
        ]);
        $otherUserRead = $otherUser->inboxNotifications()->create([
            'category' => 'service',
            'priority' => 'normal',
            'title' => 'حساب دیگر',
            'body' => 'این اعلان متعلق به کاربر دیگری است.',
            'read_at' => now(),
        ]);
        $token = $user->createToken('mobile')->plainTextToken;

        $deleted = $this->withToken($token)
            ->postJson('/api/v1/auth/notifications/read/delete')
            ->assertOk()
            ->assertJsonPath('deleted_count', 2);
        $this->assertSame(
            $readNotifications[0]->fresh()->deleted_at->copy()->addSeconds(30)->toISOString(),
            $deleted->json('undo_expires_at'),
        );
        $undoToken = $deleted->json('undo_token');

        $this->assertSame(1, $user->inboxNotifications()->count());
        $this->assertSame(1, $user->inboxNotifications()->whereNull('read_at')->count());
        $this->assertSame(1, $otherUser->inboxNotifications()->count());
        foreach ($readNotifications as $notification) {
            $this->assertNotNull($notification->fresh()->deleted_at);
        }

        $this->withToken($token)
            ->postJson('/api/v1/auth/notifications/restore', ['undo_token' => $undoToken])
            ->assertOk()
            ->assertJsonPath('restored_count', 2);
        $this->assertSame(3, $user->inboxNotifications()->count());
        $this->assertNull($otherUserRead->fresh()->deleted_at);

        $this->withToken($token)
            ->postJson('/api/v1/auth/notifications/restore', ['undo_token' => $undoToken])
            ->assertOk()
            ->assertJsonPath('restored_count', 0);
    }

    public function test_user_cannot_delete_or_restore_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $notification = $otherUser->inboxNotifications()->create([
            'category' => 'security',
            'priority' => 'important',
            'title' => 'ورود جدید',
            'body' => 'این اعلان خصوصی است.',
        ]);
        $token = $owner->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/v1/auth/notifications/{$notification->getKey()}")
            ->assertNotFound();

        $this->assertNull($notification->fresh()->deleted_at);
        $undoToken = (string) Str::uuid();
        $notification->forceFill(['deletion_batch_uuid' => $undoToken])->saveOrFail();
        $notification->delete();
        $this->withToken($token)
            ->postJson('/api/v1/auth/notifications/restore', ['undo_token' => $undoToken])
            ->assertOk()
            ->assertJsonPath('restored_count', 0);
        $this->assertSame(0, $otherUser->inboxNotifications()->count());
    }

    public function test_suspension_is_saved_and_push_survives_session_revocation_without_exposing_its_reason(): void
    {
        Queue::fake();
        $target = User::factory()->create();
        $actor = User::factory()->create();
        PushSubscription::query()->create([
            'user_id' => $target->getKey(),
            'push_token' => 'ExponentPushToken[test-device]',
            'platform' => 'android',
        ]);

        app(UserAccountStatusService::class)->setActive(
            $target,
            $actor,
            false,
            'بررسی امنیتی داخلی',
            now()->addDays(2)->toDateTimeString(),
            true,
        );

        $notification = $target->inboxNotifications()->sole();
        $this->assertSame('account_access', $notification->category);
        $this->assertSame('urgent', $notification->priority);
        $this->assertStringNotContainsString('بررسی امنیتی داخلی', $notification->body);
        $this->assertDatabaseMissing('push_subscriptions', ['user_id' => $target->getKey()]);
        Queue::assertPushed(
            SendExpoPushNotification::class,
            fn (SendExpoPushNotification $job): bool => $job->pushTokenSnapshot === 'ExponentPushToken[test-device]'
                && ($job->data['event'] ?? null) === 'account.suspended',
        );
    }
}
