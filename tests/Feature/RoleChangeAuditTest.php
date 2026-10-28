<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RoleChangeAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permission_seeder_audits_default_role_assignment_only_when_needed(): void
    {
        $user = User::factory()->create();
        $this->assertSame(0, Activity::query()->where('event', 'user_roles_changed')->count());

        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue($user->fresh()->hasRole('User'));
        $activity = Activity::query()
            ->where('event', 'user_roles_changed')
            ->where('subject_id', $user->getKey())
            ->sole();

        $this->assertNull($activity->causer_id);
        $this->assertSame([], $activity->properties->get('before'));
        $this->assertSame(['User'], $activity->properties->get('after'));
        $this->assertSame('seeder:role-permissions', $activity->properties->get('source'));
        $this->assertSame(['before', 'after', 'source'], array_keys($activity->properties->toArray()));

        $this->seed(RolePermissionSeeder::class);
        $this->assertSame(1, Activity::query()->where('event', 'user_roles_changed')->count());
    }
}
