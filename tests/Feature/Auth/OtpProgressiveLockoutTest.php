<?php

namespace Tests\Feature\Auth;

use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OtpProgressiveLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_progressive_thresholds_apply_the_exact_lock_windows_and_are_scoped_by_phone_and_type(): void
    {
        $service = app(OtpService::class);
        $thresholds = [
            3 => 300,
            6 => 3600,
            10 => 86400,
        ];

        foreach ($thresholds as $threshold => $duration) {
            $phone = sprintf('+989%09d', $threshold);

            foreach (range(1, $threshold) as $attempt) {
                $service->recordFailedVerification($phone, 'login');

                $attemptLockSeconds = match (true) {
                    $attempt >= 10 => 86400,
                    $attempt >= 6 => 3600,
                    $attempt >= 3 => 300,
                    default => 0,
                };

                if ($attemptLockSeconds > 0 && $attempt < $threshold) {
                    $this->travel($attemptLockSeconds + 1)->seconds();
                }
            }

            $this->assertSame($duration, $service->availableLockSeconds($phone, 'login'));
            $this->assertSame(0, $service->availableLockSeconds($phone, 'register'));
            $this->assertSame(0, $service->availableLockSeconds('+989000000001', 'login'));

            $this->travel($duration - 1)->seconds();
            $this->assertGreaterThan(0, $service->availableLockSeconds($phone, 'login'));

            $this->travel(1)->seconds();
            $this->assertSame(0, $service->availableLockSeconds($phone, 'login'));

            RateLimiter::clear('otp-attempts:'.hash('sha256', $phone.':login'));
        }
    }

    public function test_missing_code_is_rejected_without_counting_an_otp_failure(): void
    {
        $user = $this->userFor('+989121234567');
        $otp = $this->createOtp($user);
        $payload = [
            'phone' => $user->phone,
            'type' => 'login',
            'device_id' => '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1174',
        ];

        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/auth/otp/verify', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('code');
        }

        $this->assertSame(0, $otp->fresh()->attempts);
        $this->assertSame(0, app(OtpService::class)->availableLockSeconds($user->phone, 'login'));

        $this->postJson('/api/v1/auth/otp/verify', $payload + ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath('status', 'authenticated');
    }

    public function test_missing_otp_counts_as_failed_attempt_and_locks_verification_at_three(): void
    {
        $user = $this->userFor('+989121234568');
        $payload = [
            'phone' => $user->phone,
            'type' => 'login',
            'device_id' => '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1175',
        ];

        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/auth/otp/verify', $payload + ['code' => '123456'])
                ->assertUnprocessable();
        }

        $otp = $this->createOtp($user);

        $this->postJson('/api/v1/auth/otp/verify', $payload + ['code' => '123456'])
            ->assertTooManyRequests();

        $this->assertFalse($otp->fresh()->used);
        $this->assertSame(0, $otp->fresh()->attempts);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_devices', 0);
    }

    public function test_expired_otp_does_not_clear_prior_failed_attempts(): void
    {
        $user = $this->userFor('+989121234569');
        $payload = [
            'phone' => $user->phone,
            'type' => 'login',
            'device_id' => '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1176',
        ];
        $validOtp = $this->createOtp($user);

        foreach (range(1, 2) as $_) {
            $this->postJson('/api/v1/auth/otp/verify', $payload + ['code' => '000000'])
                ->assertUnprocessable();
        }

        $expiredOtp = $this->createOtp($user, now()->subSecond());
        $this->postJson('/api/v1/auth/otp/verify', $payload + ['code' => '123456'])
            ->assertUnprocessable();

        $this->assertTrue($expiredOtp->fresh()->used);
        $this->assertSame(2, $validOtp->fresh()->attempts);

        $latestOtp = $this->createOtp($user);
        $this->postJson('/api/v1/auth/otp/verify', $payload + ['code' => '000000'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/auth/otp/verify', $payload + ['code' => '123456'])
            ->assertTooManyRequests();

        $this->assertFalse($latestOtp->fresh()->used);
        $this->assertSame(1, $latestOtp->fresh()->attempts);
    }

    public function test_admin_web_login_obeys_the_same_lock_and_succeeds_at_expiration(): void
    {
        $user = $this->userFor('+989121234570');
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $otp = $this->createOtp($user, now()->addHour());
        $payload = ['code' => '000000'];
        $this->withSession(['admin_login_phone' => $user->phone]);

        foreach (range(1, 3) as $_) {
            $this->post(route('admin.login.verify.submit'), $payload)
                ->assertSessionHasErrors('code');
        }

        $this->post(route('admin.login.verify.submit'), ['code' => '123456'])
            ->assertSessionHasErrors('code');

        $this->assertSame(3, $otp->fresh()->attempts);
        $this->assertGuest('web');

        $this->travel(300)->seconds();

        $this->post(route('admin.login.verify.submit'), ['code' => '123456'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertTrue($otp->fresh()->used);
        $this->assertSame(0, app(OtpService::class)->availableLockSeconds($user->phone, 'login'));
    }

    public function test_expired_admin_otp_does_not_clear_prior_failed_attempts(): void
    {
        $user = $this->userFor('+989121234571');
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $validOtp = $this->createOtp($user);
        $this->withSession(['admin_login_phone' => $user->phone]);

        foreach (range(1, 2) as $_) {
            $this->post(route('admin.login.verify.submit'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $expiredOtp = $this->createOtp($user, now()->subSecond());
        $this->post(route('admin.login.verify.submit'), ['code' => '123456'])
            ->assertSessionHasErrors('code');

        $this->assertTrue($expiredOtp->fresh()->used);
        $this->assertSame(2, $validOtp->fresh()->attempts);

        $latestOtp = $this->createOtp($user);
        $this->post(route('admin.login.verify.submit'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->post(route('admin.login.verify.submit'), ['code' => '123456'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($latestOtp->fresh()->used);
        $this->assertSame(1, $latestOtp->fresh()->attempts);
        $this->assertGuest('web');
    }

    private function userFor(string $phone): User
    {
        return User::factory()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ]);
    }

    private function createOtp(User $user, $expiresAt = null): Otp
    {
        return Otp::query()->create([
            'user_id' => $user->getKey(),
            'phone' => $user->phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => $expiresAt ?? now()->addMinutes(5),
        ]);
    }
}
