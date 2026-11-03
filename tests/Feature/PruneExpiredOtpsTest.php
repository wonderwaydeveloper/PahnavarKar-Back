<?php

namespace Tests\Feature;

use App\Models\Otp;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PruneExpiredOtpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_deletes_used_and_unused_otps_after_one_day_of_expiry_only(): void
    {
        $now = Carbon::parse('2026-11-02 12:00:00');
        $this->travelTo($now);

        $oldUnused = $this->createOtp($now->copy()->subDay()->subSecond());
        $oldUsed = $this->createOtp($now->copy()->subDays(2), used: true);
        $atCutoff = $this->createOtp($now->copy()->subDay());
        $recentlyExpired = $this->createOtp($now->copy()->subDay()->addSecond());
        $valid = $this->createOtp($now->copy()->addMinutes(5));

        $this->artisan('auth:prune-expired-otps')
            ->expectsOutput('Pruned 3 expired OTP record(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('otps', ['id' => $oldUnused->getKey()]);
        $this->assertDatabaseMissing('otps', ['id' => $oldUsed->getKey()]);
        $this->assertDatabaseMissing('otps', ['id' => $atCutoff->getKey()]);
        $this->assertDatabaseHas('otps', ['id' => $recentlyExpired->getKey()]);
        $this->assertDatabaseHas('otps', ['id' => $valid->getKey()]);
        $this->assertSame(2, Otp::query()->count());
    }

    public function test_otp_cleanup_is_scheduled_daily_with_overlap_protection(): void
    {
        $this->artisan('schedule:list');
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event) => str_contains($event->command ?? '', 'auth:prune-expired-otps'),
        );

        $this->assertNotNull($event);
        $this->assertSame('20 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_expiration_column_has_an_index_for_the_cleanup_query(): void
    {
        $this->assertTrue(Schema::hasIndex('otps', 'otps_expires_at_index'));
    }

    private function createOtp(Carbon $expiresAt, bool $used = false): Otp
    {
        return Otp::query()->create([
            'phone' => '+989121234567',
            'code_hash' => bcrypt('123456'),
            'type' => 'login',
            'expires_at' => $expiresAt,
            'used' => $used,
        ]);
    }
}
