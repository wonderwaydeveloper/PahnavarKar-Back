<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Services\Admin\UserRoleChangeAuditor;
use App\Services\Auth\PhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AdminAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $userRole = Role::query()
            ->where('name', 'User')
            ->where('guard_name', 'web')
            ->firstOrFail();

        $accounts = [
            [
                'name' => 'وحید پهناور',
                'phone' => '09175207767',
            ],
            [
                'name' => 'یوسف پهناور',
                'phone' => '09177426403',
            ],
        ];

        foreach ($accounts as $account) {
            $phone = PhoneNumber::normalize($account['phone']);
            if ($phone === null) {
                throw new RuntimeException('Invalid phone number configured for a bootstrap account.');
            }

            $user = User::query()->firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => $account['name'],
                    'password' => Str::random(64),
                ],
            );

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
                    'seeder:admin-accounts',
                );
            });
        }
    }
}
