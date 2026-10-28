<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\SmsProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProcessInactiveAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactivity_command_sends_one_warning_and_schedules_deletion_once(): void
    {
        $now = Carbon::parse('2026-10-27 12:00:00');
        $this->travelTo($now);
        $this->configureSms();
        $user = $this->eligibleUser();
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendInactivityDeletionWarning')
            ->once()
            ->with($user->phone, $user->name, 30);
        $this->app->instance(SmsProvider::class, $provider);

        $this->runCommand();
        $this->runCommand();

        $user->refresh();
        $this->assertSame($now->toDateTimeString(), $user->inactivity_deletion_sms_notified_at->toDateTimeString());
        $this->assertSame(
            $now->copy()->addDays(30)->toDateTimeString(),
            $user->inactivity_deletion_scheduled_at->toDateTimeString(),
        );
    }

    public function test_delivery_failure_does_not_schedule_deletion_and_a_later_run_retries(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00'));
        $this->configureSms();
        $user = $this->eligibleUser();
        $provider = Mockery::mock(SmsProvider::class);
        $attempts = 0;
        $provider->shouldReceive('sendInactivityDeletionWarning')
            ->twice()
            ->with($user->phone, $user->name, 30)
            ->andReturnUsing(function () use (&$attempts): void {
                if ($attempts++ === 0) {
                    throw new \RuntimeException('Temporary SMS failure');
                }
            });
        $this->app->instance(SmsProvider::class, $provider);
        Log::spy();

        $this->runCommand();

        $this->assertNull($user->fresh()->inactivity_deletion_sms_notified_at);
        $this->assertNull($user->fresh()->inactivity_deletion_scheduled_at);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Could not send account inactivity SMS warning.'
                && $context['user_id'] === $user->id
                && $context['exception'] instanceof \RuntimeException);

        $this->runCommand();

        $this->assertNotNull($user->fresh()->inactivity_deletion_sms_notified_at);
        $this->assertSame(
            now()->addDays(30)->toDateTimeString(),
            $user->fresh()->inactivity_deletion_scheduled_at->toDateTimeString(),
        );
    }

    public function test_unverified_phone_does_not_send_or_schedule_an_inactivity_deletion(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00'));
        $this->configureSms();
        $user = $this->eligibleUser(['phone_verified_at' => null]);
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldNotReceive('sendInactivityDeletionWarning');
        $this->app->instance(SmsProvider::class, $provider);

        $this->runCommand();

        $this->assertNull($user->fresh()->inactivity_deletion_sms_notified_at);
        $this->assertNull($user->fresh()->inactivity_deletion_scheduled_at);
    }

    public function test_sms_configuration_is_required_before_scheduling_deletion(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00'));
        $this->configureSms(enabled: false);
        $user = $this->eligibleUser();
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldNotReceive('sendInactivityDeletionWarning');
        $this->app->instance(SmsProvider::class, $provider);
        Log::spy();

        $this->runCommand();

        $this->assertNull($user->fresh()->inactivity_deletion_sms_notified_at);
        $this->assertNull($user->fresh()->inactivity_deletion_scheduled_at);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_warning_eligibility_starts_at_the_exact_inactivity_period_boundary(): void
    {
        $now = Carbon::parse('2026-10-27 12:00:00');
        $this->travelTo($now);
        $this->configureSms();
        $cutoff = $now->copy()->subMonthsNoOverflow(6);
        $user = $this->eligibleUser([
            'last_active_at' => $cutoff->copy()->addSecond(),
        ]);
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendInactivityDeletionWarning')
            ->once()
            ->with($user->phone, $user->name, 30);
        $this->app->instance(SmsProvider::class, $provider);

        $this->runCommand();

        $this->assertNull($user->fresh()->inactivity_deletion_sms_notified_at);

        $user->forceFill(['last_active_at' => $cutoff])->saveOrFail();
        $this->runCommand();

        $this->assertNotNull($user->fresh()->inactivity_deletion_sms_notified_at);
    }

    public function test_recent_activity_cancels_a_pending_inactivity_deletion(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00'));
        $user = $this->eligibleUser([
            'last_active_at' => now(),
            'inactivity_deletion_sms_notified_at' => now()->subDays(31),
            'inactivity_deletion_scheduled_at' => now()->subDay(),
        ]);
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldNotReceive('sendInactivityDeletionWarning');
        $this->app->instance(SmsProvider::class, $provider);

        $this->runCommand();

        $user->refresh();
        $this->assertTrue($user->exists);
        $this->assertNull($user->inactivity_deletion_sms_notified_at);
        $this->assertNull($user->inactivity_deletion_scheduled_at);
    }

    public function test_account_is_retained_until_the_scheduled_deletion_time(): void
    {
        $now = Carbon::parse('2026-10-27 12:00:00');
        $this->travelTo($now);
        $user = $this->eligibleUser([
            'inactivity_deletion_sms_notified_at' => $now->copy()->subDays(31),
            'inactivity_deletion_scheduled_at' => $now->copy()->addSecond(),
        ]);
        $user->createToken('inactivity-test');

        $this->runCommand();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_account_and_access_artifacts_are_deleted_at_the_scheduled_time(): void
    {
        $now = Carbon::parse('2026-10-27 12:00:00');
        $this->travelTo($now);
        Storage::fake('public');
        Storage::disk('public')->put('profile-photos/inactive-user.jpg', 'image');
        $user = $this->eligibleUser([
            'profile_photo_path' => 'profile-photos/inactive-user.jpg',
            'inactivity_deletion_sms_notified_at' => $now->copy()->subDays(30),
            'inactivity_deletion_scheduled_at' => $now,
        ]);
        $user->createToken('inactivity-test');
        DB::table('sessions')->insert([
            'id' => 'inactive-user-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->runCommand();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'inactive-user-session']);
        Storage::disk('public')->assertMissing('profile-photos/inactive-user.jpg');
    }

    public function test_inactivity_command_runs_daily_with_overlap_protection(): void
    {
        $this->artisan('schedule:list');
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => str_contains($event->command ?? '', 'accounts:process-inactivity'),
        );

        $this->assertNotNull($event);
        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(1440, $event->expiresAt);
    }

    private function eligibleUser(array $attributes = []): User
    {
        return User::factory()->create([
            'phone' => '+989121234567',
            'phone_verified_at' => now()->subYear(),
            'last_active_at' => null,
            'inactivity_deletion_period_months' => 6,
            'created_at' => now()->subMonthsNoOverflow(7),
            ...$attributes,
        ]);
    }

    private function configureSms(bool $enabled = true): void
    {
        config([
            'services.melipayamak.enabled' => $enabled,
            'services.melipayamak.url' => 'https://console.melipayamak.com/api/send/shared/test',
            'services.melipayamak.inactivity_warning_body_id' => '12345',
        ]);
    }

    private function runCommand(): void
    {
        $this->artisan('accounts:process-inactivity')->assertSuccessful();
    }
}
