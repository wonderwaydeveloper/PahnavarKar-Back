<?php

namespace Tests\Feature;

use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_login_does_not_issue_a_session_until_two_factor_is_verified(): void
    {
        $user = $this->createTwoFactorUser();
        $this->createLoginOtp($user);

        $challengeResponse = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $user->phone,
            'type' => 'login',
            'code' => '123456',
            'device_id' => '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1174',
            'device_name' => 'Test Phone',
            'device_model' => 'Test Model',
            'platform' => 'android',
        ])->assertOk()->assertJsonPath('status', 'two_factor_required');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $challenge = $challengeResponse->json('challenge_token');
        $this->assertIsString($challenge);

        $this->postJson('/api/v1/auth/two-factor/verify-login', [
            'challenge_token' => $challenge,
            'code' => $this->totpCode((string) $user->two_factor_secret),
        ])->assertOk()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonStructure(['token', 'user' => ['id', 'phone']]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->postJson('/api/v1/auth/two-factor/verify-login', [
            'challenge_token' => $challenge,
            'code' => $this->totpCode((string) $user->two_factor_secret),
        ])->assertUnprocessable();
    }

    public function test_mobile_two_factor_challenge_is_consumed_after_five_failed_attempts(): void
    {
        $user = $this->createTwoFactorUser();
        $this->createLoginOtp($user);

        $challenge = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $user->phone,
            'type' => 'login',
            'code' => '123456',
            'device_id' => '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1174',
            'platform' => 'android',
        ])->assertOk()->json('challenge_token');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/two-factor/verify-login', [
                'challenge_token' => $challenge,
                'code' => 'INVALID1',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/two-factor/verify-login', [
            'challenge_token' => $challenge,
            'code' => $this->totpCode((string) $user->two_factor_secret),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_recovery_codes_are_one_time_and_two_factor_can_be_disabled_with_one(): void
    {
        $user = User::factory()->create([
            'phone' => '+989121234567',
            'phone_verified_at' => now(),
        ]);
        $totp = app(TotpService::class);
        $enrollment = $totp->beginEnrollment($user);
        $codes = $totp->confirmEnrollment($user, $this->totpCode($enrollment['secret']));

        $this->assertCount(10, $codes);
        $user->refresh();
        $this->assertNotSame($codes[0], $user->two_factor_recovery_codes[0]);
        $this->assertTrue($totp->verifyAndConsume($user, $codes[0]));
        $this->assertFalse($totp->verifyAndConsume($user, $codes[0]));

        try {
            $totp->disable($user, 'INVALID');
            $this->fail('Invalid credentials must not disable two-factor authentication.');
        } catch (ValidationException) {
            $this->assertNotNull($user->fresh()->two_factor_enabled_at);
        }

        $totp->disable($user, $codes[1]);
        $this->assertNull($user->fresh()->two_factor_enabled_at);
    }

    public function test_admin_web_login_requires_the_second_factor_before_authentication(): void
    {
        $user = $this->createTwoFactorUser();
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->createLoginOtp($user);

        $this->withSession(['admin_login_phone' => $user->phone])
            ->post('/admin/login/verify', ['code' => '123456'])
            ->assertRedirect(route('admin.login.two-factor'));

        $this->assertGuest('web');
        $this->withSession([
            'admin_two_factor_user_id' => $user->getKey(),
            'admin_two_factor_expires_at' => now()->addMinutes(5)->timestamp,
        ])->post('/admin/login/two-factor', [
            'code' => $this->totpCode((string) $user->two_factor_secret),
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_admin_web_two_factor_challenge_expires_after_five_failed_attempts(): void
    {
        $user = $this->createTwoFactorUser();
        $user->assignRole(Role::findOrCreate('Owner', 'web'));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withSession([
                'admin_two_factor_user_id' => $user->getKey(),
                'admin_two_factor_expires_at' => now()->addMinutes(5)->timestamp,
            ])->post('/admin/login/two-factor', [
                'code' => 'INVALID1',
            ])->assertSessionHasErrors('code');
        }

        $this->get('/admin/login/two-factor')->assertRedirect(route('admin.login'));
        $this->assertGuest('web');
    }

    private function createTwoFactorUser(): User
    {
        return User::factory()->create([
            'phone' => '+989121234567',
            'phone_verified_at' => now(),
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_enabled_at' => now(),
            'two_factor_last_used_step' => intdiv(time(), 30) - 2,
            'two_factor_recovery_codes' => [Hash::make('ABCDEF0123')],
        ]);
    }

    private function createLoginOtp(User $user): void
    {
        Otp::query()->create([
            'user_id' => $user->getKey(),
            'phone' => $user->phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    private function totpCode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin(strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }

        $counter = intdiv(time(), 30);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
