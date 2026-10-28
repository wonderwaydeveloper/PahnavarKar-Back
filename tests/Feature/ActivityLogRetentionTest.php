<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_command_removes_only_records_strictly_older_than_the_configured_retention_period(): void
    {
        $now = Carbon::parse('2026-10-26 12:00:00');
        $this->travelTo($now);

        $old = $this->activityAt('users', $now->copy()->subDays(365)->subSecond());
        $atBoundary = $this->activityAt('users', $now->copy()->subDays(365));
        $newer = $this->activityAt('security', $now->copy()->subDays(365)->addSecond());

        $this->artisan('activitylog:clean', ['--force' => true])
            ->expectsOutput('Deleted 1 record(s) from the activity log.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('activity_log', ['id' => $old->getKey()]);
        $this->assertDatabaseHas('activity_log', ['id' => $atBoundary->getKey()]);
        $this->assertDatabaseHas('activity_log', ['id' => $newer->getKey()]);
        $this->assertSame(2, Activity::query()->count());
    }

    public function test_clean_command_can_be_limited_to_one_log_name(): void
    {
        $now = Carbon::parse('2026-10-26 12:00:00');
        $this->travelTo($now);
        $oldUserLog = $this->activityAt('users', $now->copy()->subDays(400));
        $oldSecurityLog = $this->activityAt('security', $now->copy()->subDays(400));

        $this->artisan('activitylog:clean', [
            'log' => 'users',
            '--days' => 365,
            '--force' => true,
        ])
            ->expectsOutput('Deleted 1 record(s) from the activity log.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('activity_log', ['id' => $oldUserLog->getKey()]);
        $this->assertDatabaseHas('activity_log', ['id' => $oldSecurityLog->getKey()]);
    }

    public function test_invalid_retention_period_does_not_delete_any_activity(): void
    {
        $activity = $this->activityAt('users', now()->subYears(2));

        $this->artisan('activitylog:clean', [
            '--days' => 0,
            '--force' => true,
        ])
            ->expectsOutput('The days option must be a positive integer.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('activity_log', ['id' => $activity->getKey()]);
    }

    public function test_activity_log_cleanup_is_scheduled_daily_at_three_with_overlap_protection(): void
    {
        Artisan::call('schedule:list');
        $events = collect(app(Schedule::class)->events());
        $cleanup = $events->first(
            fn ($event): bool => str_contains($event->command ?? '', 'activitylog:clean'),
        );

        $this->assertNotNull($cleanup);
        $this->assertSame('0 3 * * *', $cleanup->expression);
        $this->assertStringContainsString('--force', $cleanup->command);
        $this->assertTrue($cleanup->withoutOverlapping);
        $this->assertSame(1440, $cleanup->expiresAt);
        $this->assertSame('UTC', config('app.timezone'));
    }

    private function activityAt(string $logName, Carbon $createdAt): Activity
    {
        return Activity::query()->create([
            'log_name' => $logName,
            'description' => 'Retention test activity',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
