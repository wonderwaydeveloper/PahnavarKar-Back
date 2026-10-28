<?php

namespace Tests\Feature\Auth;

use App\Jobs\SendOtpSms;
use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Auth\SmsDeliveryException;
use App\Services\Auth\SmsProvider;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class OtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_otp_response_does_not_disclose_whether_phone_has_an_account(): void
    {
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendOtp')->twice();
        $this->app->instance(SmsProvider::class, $provider);

        $existingPhone = '+989121234567';
        User::factory()->create(['phone' => $existingPhone]);

        $existingResponse = $this->postJson('/api/v1/auth/otp/send', [
            'phone' => $existingPhone,
            'type' => 'login',
        ]);
        $newResponse = $this->postJson('/api/v1/auth/otp/send', [
            'phone' => '+989121234568',
            'type' => 'login',
        ]);

        $existingResponse->assertAccepted();
        $newResponse->assertAccepted();
        $this->assertSame($existingResponse->json(), $newResponse->json());
    }

    public function test_resending_otp_invalidates_the_previous_code_and_stores_only_a_hash(): void
    {
        $codes = [];
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendOtp')
            ->twice()
            ->andReturnUsing(function (string $phone, string $code) use (&$codes): void {
                $codes[] = [$phone, $code];
            });
        $this->app->instance(SmsProvider::class, $provider);

        $phone = '+989121234567';
        $payload = ['phone' => $phone, 'type' => 'login'];
        $this->freezeSecond();
        $firstExpiry = now()->addMinutes(5);
        $firstResponse = $this->postJson('/api/v1/auth/otp/send', $payload)->assertAccepted();
        $firstOtp = Otp::query()->firstOrFail();

        $secondExpiry = now()->addMinutes(5);
        $secondResponse = $this->postJson('/api/v1/auth/otp/send', $payload)->assertAccepted();
        $latestOtp = Otp::query()->latest('id')->firstOrFail();

        $this->assertTrue($firstOtp->fresh()->used);
        $this->assertFalse($latestOtp->used);
        $this->assertSame($firstExpiry->getTimestamp(), $firstOtp->expires_at->getTimestamp());
        $this->assertSame($secondExpiry->getTimestamp(), $latestOtp->expires_at->getTimestamp());
        $this->assertCount(2, $codes);
        $this->assertSame($phone, $codes[1][0]);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $codes[0][1]);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $codes[1][1]);
        $this->assertStringNotContainsString($codes[0][1], $firstResponse->getContent());
        $this->assertStringNotContainsString($codes[1][1], $secondResponse->getContent());
        $this->assertTrue(Hash::check($codes[1][1], $latestOtp->code_hash));
        $this->assertNotSame($codes[1][1], $latestOtp->code_hash);
    }

    public function test_invalid_otp_does_not_create_a_session_and_records_an_attempt(): void
    {
        $user = User::factory()->create([
            'phone' => '+989121234567',
            'phone_verified_at' => now(),
        ]);
        $otp = Otp::query()->create([
            'user_id' => $user->getKey(),
            'phone' => $user->phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $user->phone,
            'type' => 'login',
            'code' => '000000',
            'device_id' => '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1174',
        ])->assertUnprocessable();

        $this->assertSame(1, $otp->fresh()->attempts);
        $this->assertFalse($otp->fresh()->used);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_devices', 0);
    }

    public function test_new_login_requires_a_profile_then_completes_registration_with_the_same_otp(): void
    {
        $phone = '+989121234567';
        $otp = Otp::query()->create([
            'phone' => $phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);
        $deviceId = '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1174';

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'type' => 'login',
            'code' => '123456',
            'device_id' => $deviceId,
        ])->assertOk()->assertExactJson(['status' => 'profile_required']);

        $this->assertFalse($otp->fresh()->used);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'type' => 'login',
            'code' => '123456',
            'device_id' => $deviceId,
            'name' => 'Test User',
        ])->assertOk()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('user.phone', $phone);

        $user = User::query()->where('phone', $phone)->firstOrFail();
        $this->assertSame('Test User', $user->name);
        $this->assertTrue($user->hasRole(Role::findOrCreate('User', 'web')));
        $this->assertTrue($otp->fresh()->used);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('user_devices', 1);
        $this->assertIsString($response->json('token'));

        $roleActivity = Activity::query()
            ->where('event', 'user_roles_changed')
            ->where('subject_id', $user->getKey())
            ->sole();
        $this->assertNull($roleActivity->causer_id);
        $this->assertSame([], $roleActivity->properties->get('before'));
        $this->assertSame(['User'], $roleActivity->properties->get('after'));
        $this->assertSame('otp:registration', $roleActivity->properties->get('source'));
        $this->assertSame(['before', 'after', 'source'], array_keys($roleActivity->properties->toArray()));
        $this->assertArrayNotHasKey('phone', $roleActivity->properties->toArray());
        $this->assertArrayNotHasKey('code', $roleActivity->properties->toArray());

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'type' => 'login',
            'code' => '123456',
            'device_id' => $deviceId,
            'name' => 'Test User',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('user_devices', 1);
    }

    public function test_register_otp_role_assignment_is_audited_without_otp_or_phone_data(): void
    {
        $phone = '+989121234569';
        Otp::query()->create([
            'phone' => $phone,
            'code_hash' => Hash::make('654321'),
            'type' => 'register',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'type' => 'register',
            'code' => '654321',
            'device_id' => '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1175',
            'name' => 'Registered User',
        ])->assertOk()->assertJsonPath('status', 'authenticated');

        $user = User::query()->where('phone', $phone)->firstOrFail();
        $activity = Activity::query()
            ->where('event', 'user_roles_changed')
            ->where('subject_id', $user->getKey())
            ->sole();

        $this->assertNull($activity->causer_id);
        $this->assertSame([], $activity->properties->get('before'));
        $this->assertSame(['User'], $activity->properties->get('after'));
        $this->assertSame('otp:registration', $activity->properties->get('source'));
        $this->assertSame(['before', 'after', 'source'], array_keys($activity->properties->toArray()));
        $this->assertArrayNotHasKey('phone', $activity->properties->toArray());
        $this->assertArrayNotHasKey('code', $activity->properties->toArray());
    }

    public function test_invalid_code_format_does_not_record_an_attempt_or_create_a_session(): void
    {
        $user = User::factory()->create([
            'phone' => '+989121234567',
            'phone_verified_at' => now(),
        ]);
        $otp = Otp::query()->create([
            'user_id' => $user->getKey(),
            'phone' => $user->phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $user->phone,
            'type' => 'login',
            'code' => '۱۲۳۴۵۶',
            'device_id' => '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1174',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $user->phone,
            'type' => 'login',
            'code' => '123456',
            'device_id' => 'not-a-uuid',
        ])->assertUnprocessable();

        $this->assertSame(0, $otp->fresh()->attempts);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_devices', 0);
    }

    public function test_expired_otp_is_consumed_and_cannot_be_used(): void
    {
        $user = User::factory()->create([
            'phone' => '+989121234567',
            'phone_verified_at' => now(),
        ]);
        $otp = Otp::query()->create([
            'user_id' => $user->getKey(),
            'phone' => $user->phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->subSecond(),
        ]);

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $user->phone,
            'type' => 'login',
            'code' => '123456',
            'device_id' => '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1174',
        ])->assertUnprocessable();

        $this->assertTrue($otp->fresh()->used);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_devices', 0);
    }

    public function test_failed_verification_locks_progressively_at_three_six_and_ten_attempts(): void
    {
        $service = app(OtpService::class);
        $phone = '+989121234567';

        foreach (range(1, 10) as $attempt) {
            $service->recordFailedVerification($phone, 'login');

            $expectedLockSeconds = match (true) {
                $attempt >= 10 => 86400,
                $attempt >= 6 => 3600,
                $attempt >= 3 => 300,
                default => 0,
            };

            $this->assertSame($expectedLockSeconds, $service->availableLockSeconds($phone, 'login'));

            if ($expectedLockSeconds > 0 && $attempt < 10) {
                $this->travel($expectedLockSeconds + 1)->seconds();
            }
        }

        $this->assertSame(0, $service->availableLockSeconds('+989121234568', 'login'));
        $this->assertSame(0, $service->availableLockSeconds($phone, 'register'));
    }

    public function test_locked_verification_has_no_session_side_effects_and_success_clears_the_lock(): void
    {
        $phone = '+989121234567';
        $user = User::factory()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ]);
        $otp = Otp::query()->create([
            'user_id' => $user->getKey(),
            'phone' => $phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(30),
        ]);
        $payload = [
            'phone' => $phone,
            'type' => 'login',
            'device_id' => '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1174',
        ];

        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/auth/otp/verify', $payload + ['code' => '000000'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/otp/verify', $payload + ['code' => '123456'])
            ->assertTooManyRequests();

        $this->assertSame(3, $otp->fresh()->attempts);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_devices', 0);

        $this->travel(299)->seconds();

        $this->postJson('/api/v1/auth/otp/verify', $payload + ['code' => '123456'])
            ->assertTooManyRequests();

        $this->travel(1)->seconds();

        $this->postJson('/api/v1/auth/otp/verify', $payload + ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath('status', 'authenticated');

        $this->assertSame(0, app(OtpService::class)->availableLockSeconds($phone, 'login'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('user_devices', 1);
    }

    public function test_equivalent_phone_formats_share_the_otp_send_limit(): void
    {
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendOtp')
            ->twice()
            ->with('+989121234567', Mockery::type('string'));
        $this->app->instance(SmsProvider::class, $provider);

        $this->postJson('/api/v1/auth/otp/send', [
            'phone' => 'not-a-phone',
            'type' => 'login',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('otps', 0);

        $this->postJson('/api/v1/auth/otp/send', [
            'phone' => '09121234567',
            'type' => 'login',
        ])->assertAccepted();
        $this->postJson('/api/v1/auth/otp/send', [
            'phone' => '+989121234567',
            'type' => 'login',
        ])->assertAccepted();
        $this->postJson('/api/v1/auth/otp/send', [
            'phone' => '0098 912 123 4567',
            'type' => 'login',
        ])->assertTooManyRequests();

        $this->assertDatabaseCount('otps', 2);
    }

    public function test_synchronous_sms_delivery_failure_invalidates_the_otp(): void
    {
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendOtp')
            ->once()
            ->andThrow(new SmsDeliveryException('provider rejected the message'));
        $this->app->instance(SmsProvider::class, $provider);

        $this->postJson('/api/v1/auth/otp/send', [
            'phone' => '+989121234567',
            'type' => 'login',
        ])->assertServiceUnavailable();

        $this->assertDatabaseCount('otps', 1);
        $this->assertTrue(Otp::query()->firstOrFail()->used);
    }

    public function test_queue_dispatch_failure_invalidates_the_otp(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'services.sms.queue_enabled' => true,
            'services.sms.queue' => 'otp',
            'services.melipayamak.enabled' => true,
            'services.melipayamak.local_log_enabled' => false,
            'services.melipayamak.url' => 'https://console.melipayamak.com/api/send/shared/test',
            'services.melipayamak.otp_body_id' => 'test-template',
        ]);
        Bus::shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('queue unavailable'));

        $phone = '+989121234567';
        $response = $this->postJson('/api/v1/auth/otp/send', [
            'phone' => $phone,
            'type' => 'login',
        ]);
        $response->assertServiceUnavailable()
            ->assertDontSee('queue unavailable')
            ->assertDontSee($phone)
            ->assertDontSee('provider-secret-value');

        $this->assertDatabaseCount('otps', 1);
        $this->assertTrue(Otp::query()->firstOrFail()->used);
    }

    public function test_queued_delivery_dispatches_encrypted_job_and_failure_invalidates_the_otp(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'services.sms.queue_enabled' => true,
            'services.sms.queue' => 'otp',
            'services.melipayamak.enabled' => true,
            'services.melipayamak.local_log_enabled' => false,
            'services.melipayamak.url' => 'https://console.melipayamak.com/api/send/shared/test',
            'services.melipayamak.otp_body_id' => 'test-template',
        ]);
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldNotReceive('sendOtp');
        $this->app->instance(SmsProvider::class, $provider);
        Bus::fake();

        $phone = '+989121234567';
        $this->postJson('/api/v1/auth/otp/send', [
            'phone' => $phone,
            'type' => 'login',
        ])->assertAccepted();

        $otp = Otp::query()->firstOrFail();
        $dispatchedJob = null;
        Bus::assertDispatched(SendOtpSms::class, function (SendOtpSms $job) use ($otp, &$dispatchedJob): bool {
            $dispatchedJob = $job;

            return $job->otpId === $otp->id
                && $job->phone === $otp->phone
                && Hash::check($job->code, $otp->code_hash);
        });

        $this->assertInstanceOf(ShouldBeEncrypted::class, $dispatchedJob);
        $this->assertNotNull($dispatchedJob);
        Log::spy();
        $dispatchedJob->failed(new RuntimeException('provider failed'));

        $this->assertTrue($otp->fresh()->used);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Queued OTP SMS delivery failed after all retries.'
                && ! str_contains((string) json_encode($context), $dispatchedJob->code));
    }
}
