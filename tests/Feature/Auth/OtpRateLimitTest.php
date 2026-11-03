<?php

namespace Tests\Feature\Auth;

use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\SmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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

    public function test_api_429_headers_report_the_actual_retry_window(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(2);
        $this->freezeSecond();
        $phone = '+989121234567';

        $this->sendOtp($phone)->assertAccepted();
        $this->sendOtp($phone)->assertAccepted();
        $response = $this->sendOtp($phone);

        $this->assertRateLimited($response, 2);
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertSame(
            (string) now()->addSeconds(60)->timestamp,
            $response->headers->get('X-RateLimit-Reset'),
        );
    }

    public function test_send_phone_limit_is_shared_between_mobile_api_and_admin_web_login(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(2);
        $phone = '+989121234567';
        $admin = User::factory()->create(['phone' => $phone]);
        $admin->assignRole(Role::findOrCreate('Owner', 'web'));

        $this->sendOtp($phone)->assertAccepted();

        $this->from('/admin/login')
            ->post(route('admin.login.send-otp'), ['phone' => '0912 123 4567'])
            ->assertRedirect(route('admin.login.verify'));

        $this->assertRateLimited($this->sendOtp($phone), 2);
        $this->assertDatabaseCount('otps', 2);
        $this->assertDatabaseHas('otps', [
            'phone' => $phone,
            'type' => 'login',
            'used' => true,
        ]);
        $this->assertDatabaseHas('otps', [
            'phone' => $phone,
            'type' => 'login',
            'used' => false,
        ]);
    }

    public function test_invalid_admin_send_phone_does_not_consume_the_session_phone_limit(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(2);
        $phone = '+989121234567';
        $admin = User::factory()->create(['phone' => $phone]);
        $admin->assignRole(Role::findOrCreate('Owner', 'web'));

        $this->from('/admin/login')
            ->post(route('admin.login.send-otp'), ['phone' => $phone])
            ->assertRedirect(route('admin.login.verify'));
        $this->from('/admin/login')
            ->post(route('admin.login.send-otp'), ['phone' => []])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('phone');
        $this->from('/admin/login')
            ->post(route('admin.login.send-otp'), ['phone' => $phone])
            ->assertRedirect(route('admin.login.verify'));

        $this->postJson(route('admin.login.send-otp'), ['phone' => $phone])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertDatabaseCount('otps', 2);
    }

    public function test_admin_resend_uses_the_session_phone_for_the_shared_send_limit(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(2);
        $phone = '+989121234567';
        $admin = User::factory()->create(['phone' => $phone]);
        $admin->assignRole(Role::findOrCreate('Owner', 'web'));

        $this->from('/admin/login')
            ->post(route('admin.login.send-otp'), ['phone' => $phone])
            ->assertRedirect(route('admin.login.verify'));
        $this->from(route('admin.login.verify'))
            ->post(route('admin.login.resend'))
            ->assertRedirect(route('admin.login.verify'));

        $this->assertRateLimited($this->sendOtp($phone), 2);
        $this->assertDatabaseCount('otps', 2);
    }

    public function test_admin_send_and_api_share_the_phone_hour_limit(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(5);
        $phone = '+989121234567';
        $admin = User::factory()->create(['phone' => $phone]);
        $admin->assignRole(Role::findOrCreate('Owner', 'web'));

        $this->sendOtp($phone)->assertAccepted();
        $this->sendOtp($phone)->assertAccepted();
        $this->travel(61)->seconds();
        $this->sendOtp($phone)->assertAccepted();
        $this->sendOtp($phone)->assertAccepted();
        $this->travel(61)->seconds();
        $this->from('/admin/login')
            ->post(route('admin.login.send-otp'), ['phone' => $phone])
            ->assertRedirect(route('admin.login.verify'));
        $this->travel(61)->seconds();

        $this->assertRateLimited($this->sendOtp($phone), 5);
        $this->assertDatabaseCount('otps', 5);
    }

    public function test_admin_send_and_api_share_the_ip_minute_limit(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(10);
        $ip = '203.0.113.15';
        $adminPhone = $this->phoneFor(10);
        $admin = User::factory()->create(['phone' => $adminPhone]);
        $admin->assignRole(Role::findOrCreate('Owner', 'web'));

        foreach (range(1, 9) as $index) {
            $this->sendOtp($this->phoneFor($index), $ip)->assertAccepted();
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from('/admin/login')
            ->post(route('admin.login.send-otp'), ['phone' => $adminPhone])
            ->assertRedirect(route('admin.login.verify'));

        $this->assertRateLimited($this->sendOtp($this->phoneFor(11), $ip), 10);
        $this->assertDatabaseCount('otps', 10);
    }

    public function test_admin_verify_phone_minute_limit_blocks_before_otp_side_effects(): void
    {
        $this->useIsolatedRateLimitStore();
        $phone = '+989121234567';

        foreach (range(1, 5) as $_) {
            $this->verifyAdminOtp($phone)->assertUnprocessable();
        }

        $this->assertWebRateLimited($this->verifyAdminOtp($phone), 5);
        $this->assertDatabaseCount('otps', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_verify_phone_hour_limit_survives_minute_resets(): void
    {
        $this->useIsolatedRateLimitStore();
        $phone = '+989121234567';

        foreach (range(1, 4) as $minute) {
            foreach (range(1, 5) as $_) {
                $this->verifyAdminOtp($phone)->assertUnprocessable();
            }

            if ($minute < 4) {
                $this->travel(61)->seconds();
            }
        }

        $this->travel(61)->seconds();

        $this->assertWebRateLimited($this->verifyAdminOtp($phone), 20);
        $this->assertDatabaseCount('otps', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_verify_ip_minute_limit_is_shared_across_session_phones(): void
    {
        $this->useIsolatedRateLimitStore();
        $ip = '203.0.113.25';

        foreach (range(1, 20) as $index) {
            $this->verifyAdminOtp($this->phoneFor($index), $ip)->assertUnprocessable();
        }

        $this->assertWebRateLimited($this->verifyAdminOtp($this->phoneFor(21), $ip), 20);
        $this->assertDatabaseCount('otps', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_verify_ip_hour_limit_survives_minute_resets(): void
    {
        $this->useIsolatedRateLimitStore();
        $ip = '203.0.113.26';
        $index = 0;

        foreach (range(1, 3) as $minute) {
            foreach (range(1, 20) as $_) {
                $index++;
                $this->verifyAdminOtp($this->phoneFor($index), $ip)->assertUnprocessable();
            }

            if ($minute < 3) {
                $this->travel(61)->seconds();
            }
        }

        $this->travel(61)->seconds();

        $this->assertWebRateLimited($this->verifyAdminOtp($this->phoneFor(61), $ip), 60);
        $this->assertDatabaseCount('otps', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_form_rate_limit_returns_retry_headers_with_a_non_json_request(): void
    {
        $this->useIsolatedRateLimitStore();
        $phone = '+989121234567';

        foreach (range(1, 5) as $_) {
            $this->withSession(['admin_login_phone' => $phone])
                ->from(route('admin.login.verify'))
                ->post(route('admin.login.verify.submit'), ['code' => '123456'])
                ->assertSessionHasErrors('code');
        }

        $response = $this->withSession(['admin_login_phone' => $phone])
            ->from(route('admin.login.verify'))
            ->post(route('admin.login.verify.submit'), ['code' => '123456']);

        $response->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset');
    }

    public function test_phone_change_and_login_verification_share_the_api_phone_limit(): void
    {
        $this->useIsolatedRateLimitStore();
        $user = User::factory()->create(['phone' => '+989121234567']);
        $token = $user->createToken('test-device')->plainTextToken;
        $targetPhone = '+989121234568';

        foreach (range(1, 5) as $_) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.80'])
                ->postJson('/api/v1/auth/profile/phone', [
                    'phone' => $targetPhone,
                    'code' => '123456',
                ], ['Authorization' => 'Bearer '.$token])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.81'])
            ->postJson('/api/v1/auth/otp/verify', [
                'phone' => $targetPhone,
                'type' => 'login',
                'code' => '123456',
                'device_id' => (string) Str::uuid(),
            ])
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5');

        $this->assertSame('+989121234567', $user->fresh()->phone);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_admin_and_api_verification_use_independent_phone_and_ip_buckets(): void
    {
        $this->useIsolatedRateLimitStore();
        $phone = '+989121234567';
        $admin = User::factory()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ]);
        $admin->assignRole(Role::findOrCreate('Owner', 'web'));
        $ip = '203.0.113.90';
        $deviceId = (string) Str::uuid();

        foreach (range(1, 5) as $_) {
            $this->createLoginOtp($admin);
            $this->verifyOtp($phone, $deviceId, $ip)->assertOk();
        }

        $this->createLoginOtp($admin);
        $this->withSession(['admin_login_phone' => $phone])
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('admin.login.verify.submit'), ['code' => '123456'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_send_phone_daily_limit_is_shared_between_mobile_api_and_admin_web_login(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(10);
        $phone = '+989121234567';
        $admin = User::factory()->create(['phone' => $phone]);
        $admin->assignRole(Role::findOrCreate('Owner', 'web'));

        $this->sendFiveOtpRequests($phone);
        $this->travel(3601)->seconds();
        $this->sendFiveAdminOtpRequests($phone);
        $this->travel(3601)->seconds();

        $this->assertRateLimited($this->sendOtp($phone), 10);
        $this->assertDatabaseCount('otps', 10);
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

    public function test_otp_ip_limit_uses_forwarded_client_ip_from_a_configured_proxy(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(11);
        config(['trustedproxy.proxies' => ['10.20.0.10']]);

        foreach (range(1, 10) as $index) {
            $this->sendOtpThroughProxy($this->phoneFor($index), '10.20.0.10', '198.51.100.24')
                ->assertAccepted();
        }

        $this->sendOtpThroughProxy($this->phoneFor(11), '10.20.0.10', '198.51.100.25')
            ->assertAccepted();
        $this->assertRateLimited(
            $this->sendOtpThroughProxy($this->phoneFor(12), '10.20.0.10', '198.51.100.24'),
            10,
        );
        $this->assertDatabaseCount('otps', 11);
    }

    public function test_untrusted_client_cannot_rotate_forwarded_ip_to_bypass_otp_ip_limit(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(10);
        config(['trustedproxy.proxies' => ['10.20.0.10']]);

        foreach (range(1, 10) as $index) {
            $this->sendOtpThroughProxy(
                $this->phoneFor($index),
                '203.0.113.15',
                '198.51.100.'.$index,
            )->assertAccepted();
        }

        $this->assertRateLimited(
            $this->sendOtpThroughProxy($this->phoneFor(11), '203.0.113.15', '198.51.100.99'),
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

    public function test_send_device_hour_limit_hashes_valid_uuid_and_ignores_invalid_or_absent_headers(): void
    {
        $this->useIsolatedRateLimitStore();
        $this->mockSmsProvider(32);
        $deviceId = 'a1234567-89ab-4cde-8f01-234567890abc';

        foreach (range(1, 10) as $index) {
            $this->sendOtp(
                $this->phoneFor($index),
                '203.0.113.'.($index + 30),
                $index % 2 === 0 ? strtoupper($deviceId) : $deviceId,
            )->assertAccepted();
        }

        $this->assertRateLimited(
            $this->sendOtp($this->phoneFor(11), '203.0.113.41', strtoupper($deviceId)),
            10,
        );
        $this->assertDatabaseCount('otps', 10);

        $this->useIsolatedRateLimitStore();

        foreach (range(1, 11) as $index) {
            $this->sendOtp(
                $this->phoneFor($index + 100),
                '203.0.114.'.$index,
                'not-a-uuid-device-id',
            )->assertAccepted();
        }

        $this->useIsolatedRateLimitStore();

        foreach (range(1, 11) as $index) {
            $this->sendOtp(
                $this->phoneFor($index + 200),
                '203.0.115.'.$index,
            )->assertAccepted();
        }

        $this->assertDatabaseCount('otps', 32);
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

    public function test_verify_device_hour_limit_hashes_valid_uuid_and_ignores_invalid_or_absent_headers(): void
    {
        $this->useIsolatedRateLimitStore();
        $rateLimitDeviceId = 'a1234567-89ab-4cde-8f01-234567890abc';

        foreach (range(1, 30) as $index) {
            $user = $this->userFor($index);
            $this->createLoginOtp($user);
            $this->verifyOtp(
                $user->phone,
                (string) Str::uuid(),
                '203.0.113.'.($index + 70),
                $index % 2 === 0 ? strtoupper($rateLimitDeviceId) : $rateLimitDeviceId,
            )
                ->assertOk();
        }

        $blockedUser = $this->userFor(31);
        $blockedOtp = $this->createLoginOtp($blockedUser);
        $this->assertRateLimited(
            $this->verifyOtp(
                $blockedUser->phone,
                (string) Str::uuid(),
                '203.0.113.101',
                strtoupper($rateLimitDeviceId),
            ),
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

        $this->useIsolatedRateLimitStore();

        foreach (range(1, 31) as $index) {
            $user = $this->userFor($index + 200);
            $this->createLoginOtp($user);
            $this->verifyOtp(
                $user->phone,
                (string) Str::uuid(),
                '203.0.115.'.$index,
                'not-a-uuid-device-id',
            )->assertOk();
        }
    }

    public function test_account_deletion_rate_limit_does_not_share_the_missing_phone_otp_bucket(): void
    {
        $this->useIsolatedRateLimitStore();
        $user = User::factory()->create([
            'phone' => '+989121234567',
            'phone_verified_at' => now(),
        ]);
        $token = $user->createToken('test-device')->plainTextToken;
        $otp = $this->createLoginOtp($user);

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/otp/verify', [
                'type' => 'login',
                'code' => '123456',
                'device_id' => (string) Str::uuid(),
            ])->assertUnprocessable();
        }

        $this->deleteJson('/api/v1/auth/account', ['code' => '123456'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertNoContent();

        $this->assertDatabaseMissing('otps', ['id' => $otp->getKey()]);
        $this->assertDatabaseMissing('users', ['id' => $user->getKey()]);
    }

    public function test_account_deletion_rate_limit_is_scoped_to_user_and_ip(): void
    {
        $this->useIsolatedRateLimitStore();
        $firstUser = User::factory()->create([
            'phone' => '+989121234567',
            'phone_verified_at' => now(),
        ]);
        $firstToken = $firstUser->createToken('first-device')->plainTextToken;

        foreach (range(1, 5) as $_) {
            $this->deleteJson('/api/v1/auth/account', [], [
                'Authorization' => 'Bearer '.$firstToken,
            ])->assertUnprocessable();
        }

        $this->deleteJson('/api/v1/auth/account', [], [
            'Authorization' => 'Bearer '.$firstToken,
        ])->assertTooManyRequests();

        $secondUser = User::factory()->create([
            'phone' => '+989121234568',
            'phone_verified_at' => now(),
        ]);
        $secondToken = $secondUser->createToken('second-device')->plainTextToken;
        $otp = $this->createLoginOtp($secondUser);
        Auth::forgetGuards();

        $this->deleteJson('/api/v1/auth/account', ['code' => '123456'], [
            'Authorization' => 'Bearer '.$secondToken,
        ])->assertNoContent();

        $this->assertDatabaseMissing('otps', ['id' => $otp->getKey()]);
        $this->assertDatabaseMissing('users', ['id' => $secondUser->getKey()]);
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

    private function sendFiveAdminOtpRequests(string $phone): void
    {
        foreach (range(1, 2) as $_) {
            $this->from('/admin/login')
                ->post(route('admin.login.send-otp'), ['phone' => $phone])
                ->assertRedirect(route('admin.login.verify'));
        }
        $this->travel(61)->seconds();
        foreach (range(1, 2) as $_) {
            $this->from('/admin/login')
                ->post(route('admin.login.send-otp'), ['phone' => $phone])
                ->assertRedirect(route('admin.login.verify'));
        }
        $this->travel(61)->seconds();
        $this->from('/admin/login')
            ->post(route('admin.login.send-otp'), ['phone' => $phone])
            ->assertRedirect(route('admin.login.verify'));
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

    private function sendOtpThroughProxy(string $phone, string $proxyIp, string $forwardedFor)
    {
        return $this->withServerVariables([
            'REMOTE_ADDR' => $proxyIp,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        ])->postJson('/api/v1/auth/otp/send', [
            'phone' => $phone,
            'type' => 'login',
        ]);
    }

    private function verifyAdminOtp(string $phone, string $ip = '203.0.113.2')
    {
        return $this->withSession(['admin_login_phone' => $phone])
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('admin.login.verify.submit'), ['code' => '123456']);
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
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', Response::HTTP_TOO_MANY_REQUESTS)
            ->assertJsonPath('title', 'Too Many Requests')
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', (string) $limit)
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset');

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertGreaterThan(
            now()->timestamp,
            (int) $response->headers->get('X-RateLimit-Reset'),
        );
    }

    private function assertWebRateLimited($response, int $limit): void
    {
        $response->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', (string) $limit)
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset');
    }
}
