<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminAccountsSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminAccountsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_bootstrap_accounts_with_only_the_user_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $accounts = User::query()->with('roles')->get();
        $this->assertCount(2, $accounts);

        foreach ($accounts as $account) {
            $this->assertTrue($account->hasRole('User'));
            $this->assertFalse($account->hasRole('Admin'));
            $this->assertFalse($account->hasRole('Owner'));
        }
        $this->assertSame(2, Activity::query()->where('event', 'user_roles_changed')->count());

        $this->seed(AdminAccountsSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertSame(2, Activity::query()->where('event', 'user_roles_changed')->count());
        foreach (User::query()->get() as $account) {
            $this->assertTrue($account->hasRole('User'));
            $this->assertFalse($account->hasRole('Admin'));
            $this->assertFalse($account->hasRole('Owner'));
        }
    }

    public function test_rerunning_account_seeder_preserves_a_role_assigned_explicitly_by_the_console_command(): void
    {
        $this->seed(DatabaseSeeder::class);

        $account = User::query()->firstOrFail();
        $account->forceFill(['phone_verified_at' => now()])->saveOrFail();

        $this->artisan('admin:assign-role', [
            'userId' => $account->getKey(),
            'role' => 'Owner',
        ])
            ->expectsConfirmation(
                "Assign Owner to verified user {$account->getKey()} ({$account->name})?",
                'yes',
            )
            ->expectsOutput("Assigned Owner to verified user {$account->getKey()}.")
            ->assertExitCode(0);

        $this->seed(AdminAccountsSeeder::class);

        $this->assertTrue($account->fresh()->hasRole('Owner'));
        $this->assertFalse($account->fresh()->hasRole('User'));
        $activity = Activity::query()
            ->where('event', 'user_roles_changed')
            ->where('subject_id', $account->getKey())
            ->where('properties->source', 'console:admin:assign-role')
            ->sole();
        $this->assertNull($activity->causer_id);
        $this->assertSame(['User'], $activity->properties->get('before'));
        $this->assertSame(['Owner'], $activity->properties->get('after'));
        $this->assertSame('console:admin:assign-role', $activity->properties->get('source'));
        $this->assertSame(['before', 'after', 'source'], array_keys($activity->properties->toArray()));
        $this->assertArrayNotHasKey('phone', $activity->properties->toArray());

        $activityCount = Activity::query()->where('event', 'user_roles_changed')->count();
        $this->artisan('admin:assign-role', [
            'userId' => $account->getKey(),
            'role' => 'Owner',
        ])
            ->expectsOutput("User {$account->getKey()} already has the Owner role.")
            ->assertExitCode(0);
        $this->assertSame($activityCount, Activity::query()->where('event', 'user_roles_changed')->count());
    }

    public function test_declining_console_role_assignment_makes_no_role_change_or_audit_record(): void
    {
        $this->seed(DatabaseSeeder::class);
        $account = User::query()->firstOrFail();
        $account->forceFill(['phone_verified_at' => now()])->saveOrFail();
        $activityCount = Activity::query()->where('event', 'user_roles_changed')->count();

        $this->artisan('admin:assign-role', [
            'userId' => $account->getKey(),
            'role' => 'Admin',
        ])
            ->expectsConfirmation(
                "Assign Admin to verified user {$account->getKey()} ({$account->name})?",
                'no',
            )
            ->expectsOutput('No role changes were made.')
            ->assertExitCode(0);

        $this->assertTrue($account->fresh()->hasRole('User'));
        $this->assertFalse($account->fresh()->hasRole('Admin'));
        $this->assertSame($activityCount, Activity::query()->where('event', 'user_roles_changed')->count());
    }
}
