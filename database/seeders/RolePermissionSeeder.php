<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\UserRoleChangeAuditor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(config('admin-permissions'))
            ->mapWithKeys(fn (array $labels, string $name): array => [
                $name => tap(Permission::findOrCreate($name, 'web'), fn (Permission $permission) => $permission->forceFill($labels)->save()),
            ]);

        $owner = Role::findOrCreate('Owner', 'web');
        $owner->forceFill(['name_fa' => 'مالک سامانه', 'name_en' => 'System owner'])->save();
        $owner->syncPermissions(Permission::query()->where('guard_name', 'web')->get());

        $admin = Role::findOrCreate('Admin', 'web');
        $admin->forceFill(['name_fa' => 'مدیر', 'name_en' => 'Administrator'])->save();
        $admin->syncPermissions($permissions->except('manage roles')->values());

        $userRole = Role::findOrCreate('User', 'web');
        $userRole->forceFill(['name_fa' => 'کاربر', 'name_en' => 'User'])->save();
        $userRole->syncPermissions([]);

        User::query()
            ->doesntHave('roles')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($userRole): void {
                foreach ($users as $user) {
                    DB::transaction(function () use ($user, $userRole): void {
                        $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
                        if ($lockedUser->roles()->exists()) {
                            return;
                        }

                        $lockedUser->assignRole($userRole);
                        app(UserRoleChangeAuditor::class)->record(
                            $lockedUser,
                            [],
                            [$userRole->name],
                            null,
                            'seeder:role-permissions',
                        );
                    });
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
