<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminUserAccountStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_deactivates_user_and_revokes_all_active_access_and_records_the_reason(): void
    {
        $manager = $this->manager();
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        $device = $user->devices()->create([
            'device_id' => fake()->uuid(),
            'platform' => 'ios',
            'is_active' => true,
        ]);
        $pushSubscription = $user->pushSubscriptions()->create([
            'user_device_id' => $device->getKey(),
            'push_token' => 'ExpoPushToken[account-status-test]',
            'platform' => 'ios',
        ]);
        DB::table('sessions')->insert([
            'id' => 'account-status-session',
            'user_id' => $user->getKey(),
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($manager, 'web')
            ->from('/admin/users')
            ->patch(route('admin.users.status', $user), [
                'is_active' => false,
                'reason' => 'Policy violation',
            ])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertSame('Policy violation', $user->account_status_reason);
        $this->assertSame($manager->getKey(), $user->account_status_changed_by);
        $this->assertNotNull($user->account_status_changed_at);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->getKey()]);
        $this->assertFalse($device->fresh()->is_active);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $pushSubscription->getKey()]);
        $this->assertDatabaseMissing('sessions', ['id' => 'account-status-session']);

        $activity = Activity::query()->where('event', 'account_suspended')->sole();
        $this->assertSame($user->getKey(), $activity->subject_id);
        $this->assertSame($manager->getKey(), $activity->causer_id);
        $this->assertSame('Policy violation', $activity->properties->get('reason'));
        $this->assertStringNotContainsString($token, (string) $activity->properties);
    }

    public function test_manager_can_reactivate_user_but_revoked_token_stays_invalid(): void
    {
        $manager = $this->manager();
        $user = User::factory()->create();
        $oldToken = $user->createToken('mobile')->plainTextToken;

        $this->actingAs($manager, 'web')
            ->from('/admin/users')
            ->patch(route('admin.users.status', $user), [
                'is_active' => false,
                'reason' => 'Policy violation',
            ])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('status');

        Auth::forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        Auth::forgetGuards();
        $this->actingAs($manager, 'web')
            ->from('/admin/users')
            ->patch(route('admin.users.status', $user), ['is_active' => true])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue($user->is_active);
        $this->assertNull($user->account_status_reason);
        $this->assertSame($manager->getKey(), $user->account_status_changed_by);
        $this->assertNotNull($user->account_status_changed_at);

        Auth::forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $freshToken = $user->createToken('new-device')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($freshToken)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $user->getKey(),
            'causer_id' => $manager->getKey(),
            'event' => 'account_reactivated',
        ]);
    }

    public function test_user_without_manage_users_permission_cannot_change_account_status(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user, 'web')
            ->patch(route('admin.users.status', $target), [
                'is_active' => false,
                'reason' => 'Policy violation',
            ])
            ->assertForbidden();

        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseMissing('activity_log', ['event' => 'account_suspended']);
    }

    public function test_account_cannot_be_deactivated_without_a_valid_reason_or_by_its_own_user(): void
    {
        $manager = $this->manager();
        $target = User::factory()->create();

        $this->actingAs($manager, 'web')
            ->patch(route('admin.users.status', $target), [
                'is_active' => false,
                'reason' => 'No',
            ])
            ->assertSessionHasErrors('reason');
        $this->assertTrue($target->fresh()->is_active);

        $this->actingAs($manager, 'web')
            ->patch(route('admin.users.status', $target), [
                'is_active' => false,
                'reason' => '     ',
            ])
            ->assertSessionHasErrors('reason');
        $this->assertTrue($target->fresh()->is_active);

        $this->actingAs($manager, 'web')
            ->patch(route('admin.users.status', $target), [
                'is_active' => false,
                'reason' => '  abc  ',
            ])
            ->assertSessionHasErrors('reason');
        $this->assertTrue($target->fresh()->is_active);

        $this->actingAs($manager, 'web')
            ->patch(route('admin.users.status', $manager), [
                'is_active' => false,
                'reason' => 'Policy violation',
            ])
            ->assertSessionHasErrors('is_active');
        $this->assertTrue($manager->fresh()->is_active);
    }

    public function test_only_manage_roles_actor_can_deactivate_an_admin_account(): void
    {
        $this->seedRoles();
        $manager = $this->manager();
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($manager, 'web')
            ->patch(route('admin.users.status', $admin), [
                'is_active' => false,
                'reason' => 'Policy violation',
            ])
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_last_active_owner_cannot_be_deactivated(): void
    {
        $this->seedRoles();
        $owner = User::factory()->create();
        $owner->assignRole('Owner');
        $manager = $this->manager(['manage users', 'manage roles']);

        $this->actingAs($manager, 'web')
            ->patch(route('admin.users.status', $owner), [
                'is_active' => false,
                'reason' => 'Policy violation',
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_inactive_web_user_is_logged_out_and_redirected_to_admin_login(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user, 'web')
            ->get('/admin/profile')
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('phone');

        $this->assertGuest('web');
    }

    public function test_inactive_api_user_receives_forbidden_response_and_all_tokens_are_revoked(): void
    {
        $user = User::factory()->inactive()->create();
        $token = $user->createToken('stale-device')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->getKey()]);
    }

    private function manager(array $permissions = ['manage users']): User
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(collect($permissions)
            ->map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'))
            ->all());

        return $manager;
    }

    private function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }
}
