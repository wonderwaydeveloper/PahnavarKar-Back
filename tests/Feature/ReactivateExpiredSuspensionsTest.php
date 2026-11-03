<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ReactivateExpiredSuspensionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_temporary_suspension_is_reactivated_and_audited(): void
    {
        $user = User::factory()->inactive()->create([
            'account_status_reason' => 'Temporary suspension',
            'suspended_until' => now()->subSecond(),
            'account_status_changed_at' => now()->subDays(7),
        ]);
        $user->devices()->create([
            'device_id' => fake()->uuid(),
            'platform' => 'android',
            'is_active' => false,
        ]);

        Artisan::call('accounts:reactivate-expired-suspensions');

        $user->refresh();
        $this->assertTrue($user->is_active);
        $this->assertNull($user->suspended_until);
        $this->assertNull($user->account_status_reason);
        $this->assertNull($user->account_status_changed_by);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $user->getKey(),
            'event' => 'account_suspension_expired',
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->getKey()]);
        $this->assertFalse($user->devices()->firstOrFail()->is_active);
    }

    public function test_manual_and_not_yet_expired_suspensions_are_not_reactivated(): void
    {
        $manual = User::factory()->inactive()->create(['suspended_until' => null]);
        $future = User::factory()->inactive()->create(['suspended_until' => now()->addDay()]);

        Artisan::call('accounts:reactivate-expired-suspensions');

        $this->assertFalse($manual->fresh()->is_active);
        $this->assertFalse($future->fresh()->is_active);
        $this->assertSame(0, Activity::query()->where('event', 'account_suspension_expired')->count());
    }
}
