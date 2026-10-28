<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FailedQueueJobRetentionTest extends TestCase
{
    public function test_failed_queue_jobs_are_scheduled_for_pruning_after_thirty_days(): void
    {
        Artisan::call('schedule:list');
        $events = collect(app(Schedule::class)->events());
        $prune = $events->first(
            fn ($event): bool => str_contains($event->command ?? '', 'queue:prune-failed'),
        );

        $this->assertNotNull($prune);
        $this->assertSame('10 3 * * *', $prune->expression);
        $this->assertStringContainsString('--hours=720', $prune->command);
        $this->assertTrue($prune->withoutOverlapping);
        $this->assertSame(1440, $prune->expiresAt);
    }
}
