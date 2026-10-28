<?php

namespace Tests\Feature\Auth;

use App\Models\Otp;
use App\Models\User;
use App\Services\Auth\SmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class OtpRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_phone_minute_limit_uses_normalized_phone_and_resets_after_its_window(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(3);

        $this->sendOtp('09121234567')->assertAccepted();
        $this->sendOtp('0098 912 123 4567')->assertAccepted();
        $this->assertRateLimited($this->sendOtp('+989121234567'), 2);
        $this->assertDatabaseCount('otps', 2);

        $this->travel(61)->seconds();

        $this->sendOtp('+989121234567')->assertAccepted();
        $this->assertDatabaseCount('otps', 3);
    }

    public function test_send_phone_hour_limit_survives_minute_resets_and_expires_after_one_hour(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(6);
        $phone = '+989121234567';

        $this->sendOtp($phone)->assertAccepted();
        $this->sendOtp($phone)->assertAccepted();
        $this->travel(61)->seconds();
        $this->sendOtp($phone)->assertAccepted();
        $this->sendOtp($phone)->assertAccepted();
        $this->travel(61)->seconds();
        $this->sendOtp($phone)->assertAccepted();
        $this->assertRateLimited($this->sendOtp($phone), 5);
        $this->assertDatabaseCount('otps', 5);

        $this->travel(3601)->seconds();

        $this->sendOtp($phone)->assertAccepted();
        $this->assertDatabaseCount('otps', 6);
    }

    public function test_send_phone_daily_limit_is_independent_of_hourly_windows(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(11);
        $phone = '+989121234567';

        $this->sendFiveOtpRequests($phone);
        $this->travel(3601)->seconds();
        $this->sendFiveOtpRequests($phone);
        $this->travel(3601)->seconds();

        $this->assertRateLimited($this->sendOtp($phone), 10);
        $this->assertDatabaseCount('otps', 10);

        $this->travel(86401)->seconds();

        $this->sendOtp($phone)->assertAccepted();
        $this->assertDatabaseCount('otps', 11);
    }

    public function test_send_ip_minute_limit_is_shared_across_different_phone_numbers(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(10);
        $ip = '203.0.113.10';

        foreach (range(1, 10) as $index) {
            $this->sendOtp($this->phoneFor($index), $ip, 'device-'.$index)->assertAccepted();
        }

        $this->assertRateLimited(
            $this->sendOtp($this->phoneFor(11), $ip, 'device-11'),
            10,
        );
        $this->assertDatabaseCount('otps', 10);
    }

    public function test_send_ip_hour_limit_survives_minute_resets(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(30);
        $index = 0;

        foreach (range(1, 3) as $_minute) {
            foreach (range(1, 10) as $_request) {
                $index++;
                $this->sendOtp($this->phoneFor($index), '203.0.113.20', 'device-'.$index)
                    ->assertAccepted();
            }

            if ($index < 30) {
                $this->travel(61)->seconds();
            }
        }

        $this->travel(61)->seconds();

        $this->assertRateLimited(
            $this->sendOtp($this->phoneFor(31), '203.0.113.20', 'device-31'),
            30,
        );
        $this->assertDatabaseCount('otps', 30);
    }

    public function test_send_device_hour_limit_hashes_arbitrary_header_and_absent_header_is_not_a_shared_bucket(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(21);

        foreach (range(1, 10) as $index) {
            $this->sendOtp(
                $this->phoneFor($index),
                '203.0.113.'.($index + 30),
                'not-a-uuid-device-id',
            )->assertAccepted();
        }

        $this->assertRateLimited(
            $this->sendOtp($this->phoneFor(11), '203.0.113.41', 'not-a-uuid-device-id'),
            10,
        );
        $this->assertDatabaseCount('otps', 10);

        $this->useIsolatedRateLimitStore();

        foreach (range(1, 11) as $index) {
            $this->sendOtp(
                $this->phoneFor($index + 100),
                '203.0.114.'.$index,
            )->assertAccepted();
        }

        $this->assertDatabaseCount('otps', 21);
    }

    public function test_verify_phone_minute_limit_does_not_consume_otp_or_create_another_session(): void
    {
        $this->useIsolatedRateLimitStore();
        $phone = '+989121234567';
        $user = User::factory()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ]);
        $deviceId = (string) Str::uuid();

        foreach (range(1, 5) as $_) {
            $otp = $this->createLoginOtp($user);
            $this->verifyOtp($phone, $deviceId)->assertOk();
        }

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, $user->devices()->count());

        $blockedOtp = $this->createLoginOtp($user);
        $this->assertRateLimited($this->verifyOtp($phone, $deviceId), 5);

        $this->assertFalse($blockedOtp->fresh()->used);
        $this->assertSame(0, $blockedOtp->fresh()->attempts);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, $user->devices()->count());

        $this->travel(61)->seconds();

        $this->verifyOtp($phone, $deviceId)->assertOk();
        $this->assertTrue($blockedOtp->fresh()->used);
    }

    public function test_verify_phone_hour_limit_survives_minute_resets_and_does_not_touch_the_blocked_otp(): void
    {
        $this->useIsolatedRateLimitStore();
        $phone = '+989121234567';
        $user = User::factory()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ]);
        $deviceId = (string) Str::uuid();

        foreach (range(1, 4) as $minute) {
            foreach (range(1, 5) as $_request) {
                $otp = $this->createLoginOtp($user);
                $this->verifyOtp($phone, $deviceId)->assertOk();
            }

            if ($minute < 4) {
                $this->travel(61)->seconds();
            }
        }

        $this->travel(61)->seconds();

        $blockedOtp = $this->createLoginOtp($user);
        $this->assertRateLimited($this->verifyOtp($phone, $deviceId), 20);
        $this->assertFalse($blockedOtp->fresh()->used);
        $this->assertSame(0, $blockedOtp->fresh()->attempts);

        $this->travel(3601)->seconds();

        $expiredOtp = $this->createLoginOtp($user);
        $this->verifyOtp($phone, $deviceId)->assertOk();
        $this->assertFalse($blockedOtp->fresh()->used);
        $this->assertTrue($expiredOtp->fresh()->used);
    }

    public function test_verify_ip_minute_limit_is_shared_across_phones_and_blocks_without_side_effects(): void
    {
        $this->useIsolatedRateLimitStore();

        foreach (range(1, 20) as $index) {
            $user = $this->userFor($index);
            $this->createLoginOtp($user);
            $this->verifyOtp($user->phone, (string) Str::uuid(), '203.0.113.50')->assertOk();
        }

        $blockedUser = $this->userFor(21);
        $blockedOtp = $this->createLoginOtp($blockedUser);
        $this->assertRateLimited(
            $this->verifyOtp($blockedUser->phone, (string) Str::uuid(), '203.0.113.50'),
            20,
        );

        $this->assertFalse($blockedOtp->fresh()->used);
        $this->assertSame(0, $blockedOtp->fresh()->attempts);
        $this->assertDatabaseCount('personal_access_tokens', 20);
        $this->assertDatabaseCount('user_devices', 20);
    }

    public function test_verify_ip_hour_limit_survives_minute_resets(): void
    {
        $index = 0;
        $this->useIsolatedRateLimitStore();

        foreach (range(1, 3) as $minute) {
            foreach (range(1, 20) as $_request) {
                $index++;
                $user = $this->userFor($index);
                $this->createLoginOtp($user);
                $this->verifyOtp($user->phone, (string) Str::uuid(), '203.0.113.60')->assertOk();
            }

            if ($minute < 3) {
                $this->travel(61)->seconds();
            }
        }

        $this->travel(61)->seconds();

        $blockedUser = $this->userFor(61);
        $blockedOtp = $this->createLoginOtp($blockedUser);
        $this->assertRateLimited(
            $this->verifyOtp($blockedUser->phone, (string) Str::uuid(), '203.0.113.60'),
            60,
        );

        $this->assertFalse($blockedOtp->fresh()->used);
        $this->assertSame(0, $blockedOtp->fresh()->attempts);
        $this->assertDatabaseCount('personal_access_tokens', 60);
        $this->assertDatabaseCount('user_devices', 60);
    }

    public function test_verify_device_hour_limit_hashes_arbitrary_header_and_absent_header_is_not_a_shared_bucket(): void
    {
        $this->useIsolatedRateLimitStore();

        foreach (range(1, 30) as $index) {
            $user = $this->userFor($index);
            $this->createLoginOtp($user);
            $this->verifyOtp($user->phone, (string) Str::uuid(), '203.0.113.'.($index + 70), 'malformed')
                ->assertOk();
        }

        $blockedUser = $this->userFor(31);
        $blockedOtp = $this->createLoginOtp($blockedUser);
        $this->assertRateLimited(
            $this->verifyOtp($blockedUser->phone, (string) Str::uuid(), '203.0.113.101', 'malformed'),
            30,
        );
        $this->assertFalse($blockedOtp->fresh()->used);
        $this->assertSame(0, $blockedOtp->fresh()->attempts);

        $this->useIsolatedRateLimitStore();

        foreach (range(1, 31) as $index) {
            $user = $this->userFor($index + 100);
            $this->createLoginOtp($user);
            $this->verifyOtp($user->phone, (string) Str::uuid(), '203.0.114.'.$index)
                ->assertOk();
        }
    }

    private function useIsolatedRateLimitStore(): void
    {
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    private function mockSmsProvider(int $expectedCalls): void
    {
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendOtp')->times($expectedCalls);
        $this->app->instance(SmsProvider::class, $provider);
    }

    private function sendFiveOtpRequests(string $phone): void
    {
        $this->sendOtp($phone)->assertAccepted();
        $this->sendOtp($phone)->assertAccepted();
        $this->travel(61)->seconds();
        $this->sendOtp($phone)->assertAccepted();
        $this->sendOtp($phone)->assertAccepted();
        $this->travel(61)->seconds();
        $this->sendOtp($phone)->assertAccepted();
    }

    private function sendOtp(string $phone, string $ip = '203.0.113.1', ?string $deviceId = null)
    {
        $headers = $deviceId === null ? [] : ['X-Device-Id' => $deviceId];

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/otp/send', [
                'phone' => $phone,
                'type' => 'login',
            ], $headers);
    }

    private function verifyOtp(
        string $phone,
        string $deviceId,
        string $ip = '203.0.113.2',
        ?string $rateLimitDeviceId = null,
    ) {
        $headers = $rateLimitDeviceId === null ? [] : ['X-Device-Id' => $rateLimitDeviceId];

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/otp/verify', [
                'phone' => $phone,
                'type' => 'login',
                'code' => '123456',
                'device_id' => $deviceId,
            ], $headers);
    }

    private function createLoginOtp(User $user): Otp
    {
        return Otp::query()->create([
            'user_id' => $user->getKey(),
            'phone' => $user->phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    private function userFor(int $index): User
    {
        return User::factory()->create([
            'phone' => $this->phoneFor($index),
            'phone_verified_at' => now(),
        ]);
    }

    private function phoneFor(int $index): string
    {
        return sprintf('+989%09d', $index);
    }

    private function assertRateLimited($response, int $limit): void
    {
        $response->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)
            ->assertJsonPath('status', Response::HTTP_TOO_MANY_REQUESTS)
            ->assertJsonPath('title', 'Too Many Requests')
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', (string) $limit)
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset');
    }
}
