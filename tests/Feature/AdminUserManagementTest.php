<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_page_requires_manage_users_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_users_page_requires_a_web_session(): void
    {
        $this->get('/admin/users')
            ->assertRedirect(route('admin.login'));
    }

    public function test_only_owner_with_manage_roles_receives_role_management_options(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $owner = $this->owner();
        $owner->givePermissionTo('manage users');

        $this->actingAs($owner, 'web')
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Users/Index')
                ->where('canManageRoles', true)
                ->has('assignableRoles', 2)
                ->where('assignableRoles.0.key', 'Admin')
                ->where('assignableRoles.1.key', 'User'),
            );

        $manager = $this->manager();
        $this->actingAs($manager, 'web')
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Users/Index')
                ->where('canManageRoles', false)
                ->has('assignableRoles', 0),
            );
    }

    public function test_users_page_searches_name_and_phone_and_does_not_expose_sensitive_fields(): void
    {
        $manager = $this->manager();
        $nameMatch = User::factory()->create([
            'name' => 'Searchable Customer',
            'phone' => '+989123456789',
        ]);
        User::factory()->create([
            'name' => 'Different Customer',
            'phone' => '+989987654321',
        ]);

        $this->actingAs($manager, 'web')
            ->get('/admin/users?search=%20Searchable%20')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Users/Index')
                ->where('filters.search', 'Searchable')
                ->where('users.total', 1)
                ->where('users.data.0.id', $nameMatch->getKey())
                ->where('users.data.0.phone', '+989123456789')
                ->missing('users.data.0.password')
                ->missing('users.data.0.wallet_balance')
                ->missing('users.data.0.remember_token'),
            );

        $this->actingAs($manager, 'web')
            ->get('/admin/users?search=987654321')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Users/Index')
                ->where('users.total', 1)
                ->where('users.data.0.phone', '+989987654321'),
            );
    }

    public function test_users_page_filters_status_and_preserves_filters_in_pagination(): void
    {
        $manager = $this->manager();
        User::factory()->count(23)->create(['name' => 'Customer']);
        User::factory()->inactive()->count(22)->create(['name' => 'Customer']);
        $manager->forceFill(['name' => 'Customer Manager'])->saveOrFail();

        $this->actingAs($manager, 'web')
            ->get('/admin/users?status=inactive&search=Customer&page=2')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Users/Index')
                ->where('filters.status', 'inactive')
                ->where('filters.search', 'Customer')
                ->where('users.total', 22)
                ->where('users.currentPage', 2)
                ->where('users.lastPage', 2)
                ->has('users.data', 2)
                ->where('users.data.0.isActive', false)
                ->where('users.data.1.isActive', false),
            );

        User::factory()->count(25)->create();
        $this->actingAs($manager, 'web')
            ->get('/admin/users?status=active&page=2')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Users/Index')
                ->where('filters.status', 'active')
                ->where('users.currentPage', 2)
                ->where('users.lastPage', 3)
                ->where('users.total', 49)
                ->has('users.data', 20)
                ->where('users.links.0.url', fn ($url) => str_contains($url, 'status=active')),
            );
    }

    public function test_users_page_rejects_invalid_filters_and_page_numbers(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'web')
            ->get('/admin/users?status=all')
            ->assertSessionHasErrors('status');

        $this->actingAs($manager, 'web')
            ->get('/admin/users?page=0')
            ->assertSessionHasErrors('page');
    }

    public function test_owner_can_sync_roles_clear_direct_permissions_and_audit_the_access_change(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $owner = $this->owner();
        $user = User::factory()->create();
        $user->assignRole('User');
        $directPermission = Permission::findOrCreate('manage wallet', 'web');
        $user->givePermissionTo($directPermission);
        $adminRole = Role::query()->where('name', 'Admin')->where('guard_name', 'web')->firstOrFail();

        $this->actingAs($owner, 'web')
            ->from('/admin/users')
            ->patch(route('admin.users.access', $user), [
                'role_ids' => [$adminRole->getKey()],
            ])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue($user->hasRole('Admin'));
        $this->assertFalse($user->hasRole('User'));
        $this->assertSame([], $user->getDirectPermissions()->pluck('name')->all());
        $this->assertTrue($user->can('manage wallet'));

        $activity = Activity::query()->where('event', 'user_access_updated')->sole();
        $this->assertSame($user->getKey(), $activity->subject_id);
        $this->assertSame($owner->getKey(), $activity->causer_id);
        $this->assertSame(['User'], $activity->properties->get('before')['roles']);
        $this->assertSame(['manage wallet'], $activity->properties->get('before')['permissions']);
        $this->assertSame(['Admin'], $activity->properties->get('roles'));
        $this->assertSame([], $activity->properties->get('direct_permissions'));
    }

    public function test_non_owner_cannot_change_user_roles_even_with_manage_roles_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->assignRole('Admin');
        $actor->givePermissionTo('manage roles');
        $target = User::factory()->create();
        $role = Role::query()->where('name', 'Admin')->where('guard_name', 'web')->firstOrFail();

        $this->actingAs($actor, 'web')
            ->patch(route('admin.users.access', $target), ['role_ids' => [$role->getKey()]])
            ->assertForbidden();

        $this->assertFalse($target->fresh()->hasRole('Admin'));
        $this->assertDatabaseMissing('activity_log', ['event' => 'user_access_updated']);
    }

    public function test_access_update_rejects_owner_target_direct_permission_ids_and_invalid_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $owner = $this->owner();
        $target = User::factory()->create();
        $target->assignRole('User');
        $ownerTarget = User::factory()->create();
        $ownerTarget->assignRole('Owner');
        $userRole = Role::query()->where('name', 'User')->where('guard_name', 'web')->firstOrFail();
        $ownerRole = Role::query()->where('name', 'Owner')->where('guard_name', 'web')->firstOrFail();
        $apiRole = Role::query()->create([
            'name' => 'ApiOnly',
            'guard_name' => 'api',
        ]);
        $permission = Permission::findOrCreate('manage wallet', 'web');

        $this->actingAs($owner, 'web')
            ->patch(route('admin.users.access', $target), [
                'role_ids' => [$userRole->getKey()],
                'permission_ids' => [$permission->getKey()],
            ])
            ->assertSessionHasErrors('permission_ids');

        $this->actingAs($owner, 'web')
            ->patch(route('admin.users.access', $target), ['role_ids' => [$ownerRole->getKey()]])
            ->assertSessionHasErrors('access');

        $this->actingAs($owner, 'web')
            ->patch(route('admin.users.access', $target), ['role_ids' => [$apiRole->getKey()]])
            ->assertSessionHasErrors('access');

        $this->actingAs($owner, 'web')
            ->patch(route('admin.users.access', $target), ['role_ids' => [$userRole->getKey(), $userRole->getKey()]])
            ->assertSessionHasErrors('role_ids.1');

        $this->actingAs($owner, 'web')
            ->patch(route('admin.users.access', $ownerTarget), ['role_ids' => [$userRole->getKey()]])
            ->assertSessionHasErrors('access');

        $this->assertTrue($target->fresh()->hasRole('User'));
        $this->assertTrue($ownerTarget->fresh()->hasRole('Owner'));
        $this->assertDatabaseMissing('activity_log', ['event' => 'user_access_updated']);
    }

    private function manager(): User
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::findOrCreate('manage users', 'web'));

        return $manager;
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return $owner;
    }
}
