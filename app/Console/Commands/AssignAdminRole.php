<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Admin\UserRoleChangeAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignAdminRole extends Command
{
    protected $signature = 'admin:assign-role {userId : Verified user account ID} {role=Admin : Admin or Owner}';

    protected $description = 'Assign an elevated web role to a verified account from a trusted server console.';

    public function handle(): int
    {
        $userId = filter_var($this->argument('userId'), FILTER_VALIDATE_INT);
        $roleName = (string) $this->argument('role');

        if ($userId === false || $userId < 1 || ! in_array($roleName, ['Admin', 'Owner'], true)) {
            $this->error('Provide a valid user ID and either Admin or Owner.');

            return self::INVALID;
        }

        $user = User::query()->find($userId);
        if (! $user || ! $user->phone_verified_at) {
            $this->error('The verified user account was not found.');

            return self::FAILURE;
        }

        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
        if (! $role || $role->permissions()->doesntExist()) {
            $this->error('Role permissions are not initialized. Run RolePermissionSeeder first.');

            return self::FAILURE;
        }

        if ($user->hasRole($role)) {
            $this->info("User {$userId} already has the {$roleName} role.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Assign {$roleName} to verified user {$userId} ({$user->name})?")) {
            $this->warn('No role changes were made.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $role): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $before = $lockedUser->roles()->orderBy('name')->pluck('name')->all();
            $lockedUser->syncRoles([$role]);
            $after = $lockedUser->roles()->orderBy('name')->pluck('name')->all();

            app(UserRoleChangeAuditor::class)->record(
                $lockedUser,
                $before,
                $after,
                null,
                'console:admin:assign-role',
            );
        });
        $this->info("Assigned {$roleName} to verified user {$userId}.");

        return self::SUCCESS;
    }
}
