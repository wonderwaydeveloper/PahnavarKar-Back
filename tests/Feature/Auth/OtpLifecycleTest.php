<?php

namespace Tests\Feature\Auth;

use App\Jobs\SendOtpSms;
use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Auth\SmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class OtpLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_otp_is_valid_before_expiry_but_rejected_and_consumed_at_the_exact_expiry_time(): void
    {
        $this->freezeSecond();
        $phone = '+989121234567';
        $user = $this->userFor($phone);
        $expiresAt = now()->addMinutes(5);
        $otp = $this->createOtp($user, $expiresAt);
        $payload = $this->verifyPayload($phone);

        $this->travelTo($expiresAt->copy()->subSecond());
        $this->postJson('/api/v1/auth/otp/verify', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'authenticated');

        $this->assertTrue($otp->fresh()->used);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $secondPhone = '+989121234568';
        $secondUser = $this->userFor($secondPhone);
        $exactExpiryOtp = $this->createOtp($secondUser, $expiresAt);

        $this->travelTo($expiresAt);
        $this->postJson('/api/v1/auth/otp/verify', $this->verifyPayload($secondPhone))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertTrue($exactExpiryOtp->fresh()->used);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('user_devices', 1);
    }

    public function test_successfully_used_api_otp_cannot_be_replayed_to_create_another_session(): void
    {
        $phone = '+989121234569';
        $user = $this->userFor($phone);
        $otp = $this->createOtp($user);
        $payload = $this->verifyPayload($phone);

        $this->postJson('/api/v1/auth/otp/verify', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'authenticated');

        $this->postJson('/api/v1/auth/otp/verify', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertTrue($otp->fresh()->used);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('user_devices', 1);
    }

    public function test_resending_for_another_otp_type_does_not_invalidate_the_existing_type(): void
    {
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendOtp')->twice();
        $this->app->instance(SmsProvider::class, $provider);

        $service = app(OtpService::class);
        $phone = '+989121234570';
        $service->send($phone, 'login');
        $loginOtp = Otp::query()->where('type', 'login')->firstOrFail();

        $service->send($phone, 'register');
        $registerOtp = Otp::query()->where('type', 'register')->firstOrFail();

        $this->assertFalse($loginOtp->fresh()->used);
        $this->assertFalse($registerOtp->fresh()->used);
        $this->assertSame(2, Otp::query()->where('phone', $phone)->count());
    }

    public function test_admin_otp_is_consumed_once_and_replay_does_not_create_another_authenticated_session(): void
    {
        $phone = '+989121234571';
        $user = $this->userFor($phone);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $otp = $this->createOtp($user, now()->addHour());
        $this->withSession(['admin_login_phone' => $phone]);

        $this->post(route('admin.login.verify.submit'), ['code' => '123456'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user, 'web');

        $this->post(route('admin.login.verify.submit'), ['code' => '123456'])
            ->assertRedirect(route('admin.login'));

        $this->assertTrue($otp->fresh()->used);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_admin_otp_is_rejected_at_the_exact_expiry_time(): void
    {
        $this->freezeSecond();
        $phone = '+989121234575';
        $user = $this->userFor($phone);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $otp = $this->createOtp($user, now());
        $this->withSession(['admin_login_phone' => $phone]);

        $this->post(route('admin.login.verify.submit'), ['code' => '123456'])
            ->assertSessionHasErrors('code');

        $this->assertTrue($otp->fresh()->used);
        $this->assertGuest('web');
    }

    public function test_queued_sms_job_does_not_send_an_otp_at_the_exact_expiry_time(): void
    {
        $this->freezeSecond();
        $phone = '+989121234576';
        $expiresAt = now();
        $otp = $this->createOtp(null, $expiresAt, $phone);
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldNotReceive('sendOtp');

        (new SendOtpSms($otp->id, $phone, '123456'))->handle($provider);

        $this->assertTrue($otp->fresh()->used);
    }

    public function test_expired_phone_change_otp_preserves_prior_failed_attempts(): void
    {
        $phone = '+989121234572';
        $user = $this->userFor('+989121234573');
        $service = app(OtpService::class);
        $service->recordFailedVerification($phone, 'login');
        $service->recordFailedVerification($phone, 'login');
        $expiredOtp = $this->createOtp(null, now()->subSecond(), $phone);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('phone-change')->plainTextToken];

        $this->postJson('/api/v1/auth/profile/phone', [
            'phone' => $phone,
            'code' => '123456',
        ], $headers)->assertUnprocessable();
        $this->assertTrue($expiredOtp->fresh()->used);

        $this->createOtp(null, now()->addMinutes(5), $phone);
        $this->postJson('/api/v1/auth/profile/phone', [
            'phone' => $phone,
            'code' => '000000',
        ], $headers)->assertUnprocessable();

        $this->assertGreaterThan(0, $service->availableLockSeconds($phone, 'login'));
        $this->postJson('/api/v1/auth/profile/phone', [
            'phone' => $phone,
            'code' => '123456',
        ], $headers)->assertUnprocessable();
        $this->assertSame($user->phone, $user->fresh()->phone);
    }

    public function test_expired_account_deletion_otp_preserves_prior_failed_attempts(): void
    {
        $phone = '+989121234574';
        $user = $this->userFor($phone);
        $token = $user->createToken('test-device')->plainTextToken;
        $service = app(OtpService::class);
        $service->recordFailedVerification($phone, 'login');
        $service->recordFailedVerification($phone, 'login');
        $expiredOtp = $this->createOtp($user, now()->subSecond());
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->deleteJson('/api/v1/auth/account', ['code' => '123456'], $headers)
            ->assertUnprocessable();
        $this->assertTrue($expiredOtp->fresh()->used);

        $this->createOtp($user, now()->addMinutes(5));
        $this->deleteJson('/api/v1/auth/account', ['code' => '000000'], $headers)
            ->assertUnprocessable();

        $this->assertGreaterThan(0, $service->availableLockSeconds($phone, 'login'));
        $this->deleteJson('/api/v1/auth/account', ['code' => '123456'], $headers)
            ->assertUnprocessable();
        $this->assertNotNull($user->fresh());
    }

    private function userFor(string $phone): User
    {
        return User::factory()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ]);
    }

    private function createOtp(?User $user, $expiresAt = null, ?string $phone = null): Otp
    {
        return Otp::query()->create([
            'user_id' => $user?->getKey(),
            'phone' => $phone ?? $user?->phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => $expiresAt ?? now()->addMinutes(5),
        ]);
    }

    private function verifyPayload(string $phone): array
    {
        return [
            'phone' => $phone,
            'type' => 'login',
            'code' => '123456',
            'device_id' => '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1174',
        ];
    }
}
