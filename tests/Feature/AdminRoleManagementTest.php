<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_role_management_page_is_restricted_to_owner_with_manage_roles(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'web')
            ->get('/admin/roles')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Roles/Index')
                ->has('roles', 3)
                ->has('permissions', count(config('admin-permissions')))
                ->where('roles.0.protected', true)
                ->where('permissions.0.system', true),
            );

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'web')->get('/admin/roles')->assertForbidden();

        $user = User::factory()->create();
        $user->assignRole('User');
        $this->actingAs($user, 'web')->get('/admin/roles')->assertForbidden();

        Auth::forgetGuards();
        $this->get('/admin/roles')->assertRedirect(route('admin.login'));
    }

    public function test_seeded_roles_receive_the_exact_configured_permission_catalog(): void
    {
        $allPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->all();
        $ownerPermissions = Role::findByName('Owner', 'web')->permissions()->pluck('name')->sort()->values()->all();
        $adminPermissions = Role::findByName('Admin', 'web')->permissions()->pluck('name')->sort()->values()->all();
        $userPermissions = Role::findByName('User', 'web')->permissions()->pluck('name')->all();

        $this->assertEqualsCanonicalizing($allPermissions, $ownerPermissions);
        $this->assertEqualsCanonicalizing(array_values(array_diff($allPermissions, ['manage roles'])), $adminPermissions);
        $this->assertSame([], $userPermissions);
    }

    public function test_owner_can_create_role_and_audit_actor_and_permissions(): void
    {
        $owner = $this->owner();
        $permission = Permission::findByName('manage users', 'web');

        $this->actingAs($owner, 'web')
            ->from('/admin/roles')
            ->post('/admin/roles', [
                'key' => 'Support',
                'name_fa' => 'پشتیبان',
                'name_en' => 'Support',
                'permission_ids' => [$permission->getKey()],
            ])
            ->assertRedirect('/admin/roles')
            ->assertSessionHas('status');

        $role = Role::findByName('Support', 'web');
        $this->assertSame('پشتیبان', $role->name_fa);
        $this->assertTrue($role->hasPermissionTo($permission));
        $activity = Activity::query()->where('event', 'role_created')->sole();
        $this->assertSame($owner->getKey(), $activity->causer_id);
        $this->assertSame($role->getKey(), $activity->subject_id);
        $this->assertSame(['manage users'], $activity->properties->get('permissions'));
    }

    public function test_role_creation_rejects_reserved_duplicate_and_invalid_role_keys(): void
    {
        $owner = $this->owner();
        $base = [
            'name_fa' => 'نقش',
            'name_en' => 'Role',
            'permission_ids' => [],
        ];

        foreach (['Owner', 'Admin', 'User'] as $key) {
            $this->actingAs($owner, 'web')
                ->post('/admin/roles', ['key' => $key, ...$base])
                ->assertSessionHasErrors('key');
        }

        $this->actingAs($owner, 'web')
            ->post('/admin/roles', ['key' => 'Owner ', ...$base])
            ->assertSessionHasErrors('key');
        $this->assertSame(3, Role::query()->count());

        $this->actingAs($owner, 'web')
            ->post('/admin/roles', ['key' => 'Support', ...$base])
            ->assertSessionHas('status');

        $this->actingAs($owner, 'web')
            ->post('/admin/roles', ['key' => 'Support', ...$base])
            ->assertSessionHasErrors('key');
        $this->assertSame(4, Role::query()->count());
    }

    public function test_role_creation_rejects_untrusted_permission_ids_and_manage_roles_grant(): void
    {
        $owner = $this->owner();
        $manageRoles = Permission::findByName('manage roles', 'web');
        $apiPermission = Permission::query()->create([
            'name' => 'api-only',
            'guard_name' => 'api',
        ]);

        $this->actingAs($owner, 'web')
            ->post('/admin/roles', [
                'key' => 'Support',
                'name_fa' => 'پشتیبان',
                'name_en' => 'Support',
                'permission_ids' => [$manageRoles->getKey()],
            ])
            ->assertSessionHasErrors('permission_ids');

        $this->actingAs($owner, 'web')
            ->post('/admin/roles', [
                'key' => 'Support',
                'name_fa' => 'پشتیبان',
                'name_en' => 'Support',
                'permission_ids' => [$apiPermission->getKey()],
            ])
            ->assertSessionHasErrors('permission_ids');

        $this->assertDatabaseMissing('roles', ['name' => 'Support', 'guard_name' => 'web']);
        $this->assertDatabaseMissing('activity_log', ['event' => 'role_created']);
    }

    public function test_role_update_syncs_permissions_and_cannot_edit_owner_or_grant_manage_roles(): void
    {
        $owner = $this->owner();
        $role = Role::query()->create([
            'name' => 'Support',
            'name_fa' => 'پشتیبان',
            'name_en' => 'Support',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo('manage users');
        $targetUser = User::factory()->create();
        $targetUser->assignRole($role);
        $manageWallet = Permission::findByName('manage wallet', 'web');
        $manageRoles = Permission::findByName('manage roles', 'web');
        $ownerRole = Role::findByName('Owner', 'web');
        app(PermissionRegistrar::class)->getPermissions();
        $this->assertTrue($targetUser->can('manage users'));

        $this->actingAs($owner, 'web')
            ->from('/admin/roles')
            ->put(route('admin.roles.update', $role), [
                'name_fa' => 'پشتیبان ارشد',
                'name_en' => 'Senior Support',
                'permission_ids' => [$manageWallet->getKey()],
            ])
            ->assertRedirect('/admin/roles')
            ->assertSessionHas('status');

        $role->refresh();
        $this->assertSame('پشتیبان ارشد', $role->name_fa);
        $this->assertSame(['manage wallet'], $role->permissions()->pluck('name')->all());
        $this->assertFalse($targetUser->fresh()->can('manage users'));
        $this->assertTrue($targetUser->fresh()->can('manage wallet'));
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $role->getKey(),
            'causer_id' => $owner->getKey(),
            'event' => 'role_updated',
        ]);

        $this->actingAs($owner, 'web')
            ->put(route('admin.roles.update', $role), [
                'name_fa' => 'پشتیبان',
                'name_en' => 'Support',
                'permission_ids' => [$manageRoles->getKey()],
            ])
            ->assertSessionHasErrors('permission_ids');

        $ownerRoleBefore = $ownerRole->getAttributes();
        $this->actingAs($owner, 'web')
            ->put(route('admin.roles.update', $ownerRole), [
                'name_fa' => 'ویرایش',
                'name_en' => 'Edited',
                'permission_ids' => [],
            ])
            ->assertForbidden();
        $this->assertSame($ownerRoleBefore, $ownerRole->fresh()->getAttributes());
    }

    public function test_role_deletion_rejects_protected_or_assigned_roles_and_audits_custom_deletion(): void
    {
        $owner = $this->owner();
        $adminRole = Role::findByName('Admin', 'web');

        $ownerRole = Role::findByName('Owner', 'web');
        $this->actingAs($owner, 'web')
            ->delete(route('admin.roles.destroy', $ownerRole))
            ->assertForbidden();

        foreach (['Admin', 'User'] as $protectedRoleName) {
            $protectedRole = Role::findByName($protectedRoleName, 'web');
            $this->actingAs($owner, 'web')
                ->delete(route('admin.roles.destroy', $protectedRole))
                ->assertSessionHasErrors('role');
            $this->assertDatabaseHas('roles', ['id' => $protectedRole->getKey()]);
        }

        $assignedRole = Role::query()->create([
            'name' => 'Assigned',
            'name_fa' => 'دارای کاربر',
            'name_en' => 'Assigned',
            'guard_name' => 'web',
        ]);
        User::factory()->create()->assignRole($assignedRole);
        $this->actingAs($owner, 'web')
            ->delete(route('admin.roles.destroy', $assignedRole))
            ->assertSessionHasErrors('role');
        $this->assertDatabaseHas('roles', ['id' => $assignedRole->getKey()]);

        $unusedRole = Role::query()->create([
            'name' => 'Unused',
            'name_fa' => 'بدون کاربر',
            'name_en' => 'Unused',
            'guard_name' => 'web',
        ]);
        $this->actingAs($owner, 'web')
            ->delete(route('admin.roles.destroy', $unusedRole))
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('roles', ['id' => $unusedRole->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $unusedRole->getKey(),
            'causer_id' => $owner->getKey(),
            'event' => 'role_deleted',
        ]);
    }

    public function test_owner_can_create_permission_and_it_is_granted_only_to_owner(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'web')
            ->from('/admin/roles')
            ->post('/admin/permissions', [
                'key' => 'view reports',
                'name_fa' => 'مشاهده گزارش‌ها',
                'name_en' => 'View reports',
            ])
            ->assertRedirect('/admin/roles')
            ->assertSessionHas('status');

        $permission = Permission::findByName('view reports', 'web');
        $this->assertTrue(Role::findByName('Owner', 'web')->hasPermissionTo($permission));
        $this->assertFalse(Role::findByName('Admin', 'web')->hasPermissionTo($permission));
        $this->assertFalse(Role::findByName('User', 'web')->hasPermissionTo($permission));
        $this->assertTrue($owner->fresh()->can('view reports'));
        $activity = Activity::query()->where('event', 'permission_created')->sole();
        $this->assertSame($owner->getKey(), $activity->causer_id);
        $this->assertSame($permission->getKey(), $activity->subject_id);
    }

    public function test_permission_labels_can_be_updated_but_base_permissions_cannot_be_deleted(): void
    {
        $owner = $this->owner();
        $permission = Permission::findByName('manage users', 'web');

        $this->actingAs($owner, 'web')
            ->put(route('admin.permissions.update', $permission), [
                'name_fa' => 'مدیریت حساب‌ها',
                'name_en' => 'Manage accounts',
            ])
            ->assertSessionHas('status');
        $this->assertSame('مدیریت حساب‌ها', $permission->fresh()->name_fa);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $permission->getKey(),
            'causer_id' => $owner->getKey(),
            'event' => 'permission_labels_updated',
        ]);

        $this->actingAs($owner, 'web')
            ->delete(route('admin.permissions.destroy', $permission))
            ->assertSessionHasErrors('permission');
        $this->assertDatabaseHas('permissions', ['id' => $permission->getKey()]);
    }

    public function test_permission_creation_rejects_duplicate_and_invalid_keys_and_labels(): void
    {
        $owner = $this->owner();
        $base = [
            'name_fa' => 'دسترسی',
            'name_en' => 'Permission',
        ];

        $this->actingAs($owner, 'web')
            ->post('/admin/permissions', ['key' => 'manage users', ...$base])
            ->assertSessionHasErrors('key');

        $this->actingAs($owner, 'web')
            ->post('/admin/permissions', ['key' => 'not-valid!', ...$base])
            ->assertSessionHasErrors('key');

        $this->actingAs($owner, 'web')
            ->post('/admin/permissions', ['key' => 'view reports', ...$base])
            ->assertSessionHas('status');

        $this->actingAs($owner, 'web')
            ->post('/admin/permissions', ['key' => 'view reports', ...$base])
            ->assertSessionHasErrors('key');

        $this->assertSame(9, Permission::query()->where('guard_name', 'web')->count());
    }

    public function test_custom_permission_deletion_removes_role_grants_and_logs_actor(): void
    {
        $owner = $this->owner();
        $permission = Permission::query()->create([
            'name' => 'view reports',
            'name_fa' => 'مشاهده گزارش‌ها',
            'name_en' => 'View reports',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->create([
            'name' => 'Analyst',
            'name_fa' => 'تحلیل‌گر',
            'name_en' => 'Analyst',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permission);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        app(PermissionRegistrar::class)->getPermissions();

        $this->actingAs($owner, 'web')
            ->delete(route('admin.permissions.destroy', $permission))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('permissions', ['id' => $permission->getKey()]);
        $this->assertDatabaseMissing('role_has_permissions', ['permission_id' => $permission->getKey()]);
        $this->assertDatabaseMissing('model_has_permissions', ['permission_id' => $permission->getKey()]);
        $this->assertSame(0, $role->fresh()->permissions()->count());
        $this->assertSame(0, $user->fresh()->getDirectPermissions()->count());
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $permission->getKey(),
            'causer_id' => $owner->getKey(),
            'event' => 'permission_deleted',
        ]);
        $this->assertFalse(app(PermissionRegistrar::class)
            ->getPermissions()
            ->contains('id', $permission->getKey()));
    }

    public function test_permission_mutations_reject_other_guard_permissions(): void
    {
        $owner = $this->owner();
        $apiPermission = Permission::query()->create([
            'name' => 'api-only',
            'name_fa' => 'ای‌پی‌آی',
            'name_en' => 'API only',
            'guard_name' => 'api',
        ]);

        $this->actingAs($owner, 'web')
            ->put(route('admin.permissions.update', $apiPermission), [
                'name_fa' => 'تغییر',
                'name_en' => 'Changed',
            ])
            ->assertNotFound();

        $this->actingAs($owner, 'web')
            ->delete(route('admin.permissions.destroy', $apiPermission))
            ->assertNotFound();

        $apiRole = Role::query()->create([
            'name' => 'ApiRole',
            'guard_name' => 'api',
        ]);
        $this->actingAs($owner, 'web')
            ->put(route('admin.roles.update', $apiRole), [
                'name_fa' => 'نقش',
                'name_en' => 'Role',
                'permission_ids' => [],
            ])
            ->assertNotFound();
        $this->actingAs($owner, 'web')
            ->delete(route('admin.roles.destroy', $apiRole))
            ->assertNotFound();

        $this->assertSame('API only', $apiPermission->fresh()->name_en);
        $this->assertSame('ApiRole', $apiRole->fresh()->name);
        $this->assertDatabaseMissing('activity_log', ['event' => 'permission_deleted']);
    }

    public function test_non_owner_permission_and_role_mutations_are_denied(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $role = Role::query()->create([
            'name' => 'Support',
            'name_fa' => 'پشتیبان',
            'name_en' => 'Support',
            'guard_name' => 'web',
        ]);
        $permission = Permission::query()->create([
            'name' => 'view reports',
            'name_fa' => 'گزارش',
            'name_en' => 'Reports',
            'guard_name' => 'web',
        ]);

        $this->actingAs($admin, 'web')->post('/admin/roles', [
            'key' => 'Another',
            'name_fa' => 'دیگر',
            'name_en' => 'Another',
            'permission_ids' => [],
        ])->assertForbidden();
        $this->actingAs($admin, 'web')->put(route('admin.roles.update', $role), [
            'name_fa' => 'تغییر',
            'name_en' => 'Change',
            'permission_ids' => [],
        ])->assertForbidden();
        $this->actingAs($admin, 'web')->delete(route('admin.roles.destroy', $role))->assertForbidden();
        $this->actingAs($admin, 'web')->post('/admin/permissions', [
            'key' => 'new permission',
            'name_fa' => 'جدید',
            'name_en' => 'New',
        ])->assertForbidden();
        $this->actingAs($admin, 'web')->put(route('admin.permissions.update', $permission), [
            'name_fa' => 'ویرایش',
            'name_en' => 'Edited',
        ])->assertForbidden();
        $this->actingAs($admin, 'web')->delete(route('admin.permissions.destroy', $permission))->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'Another']);
        $this->assertSame('Support', $role->fresh()->name);
        $this->assertSame('Reports', $permission->fresh()->name_en);
        $this->assertDatabaseMissing('activity_log', ['event' => 'role_created']);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return $owner;
    }
}
