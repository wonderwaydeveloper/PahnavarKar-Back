<?php

namespace Tests\Feature\Auth;

use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\SmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class AdminWebAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_otp_send_response_does_not_disclose_account_eligibility(): void
    {
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendOtp')
            ->once()
            ->with('+989121234568', Mockery::type('string'));
        $this->app->instance(SmsProvider::class, $provider);

        User::factory()->create(['phone' => '+989121234567']);
        $admin = User::factory()->create(['phone' => '+989121234568']);
        $admin->assignRole(Role::findOrCreate('Owner', 'web'));

        $regularUserResponse = $this->from('/admin/login')
            ->post(route('admin.login.send-otp'), ['phone' => '+989121234567']);
        $adminResponse = $this->from('/admin/login')
            ->post(route('admin.login.send-otp'), ['phone' => '+989121234568']);

        $regularUserResponse->assertRedirect(route('admin.login.verify'))
            ->assertSessionHas('status', 'اگر این شماره به حساب مدیر مجاز متصل باشد، کد ورود ارسال شده است.');
        $adminResponse->assertRedirect(route('admin.login.verify'))
            ->assertSessionHas('status', 'اگر این شماره به حساب مدیر مجاز متصل باشد، کد ورود ارسال شده است.');
        $this->assertDatabaseCount('otps', 1);
        $this->assertDatabaseHas('otps', ['phone' => $admin->phone, 'type' => 'login']);
        $this->assertDatabaseMissing('otps', ['phone' => '+989121234567']);
    }

    public function test_regular_user_cannot_complete_admin_login_with_a_valid_otp(): void
    {
        $user = User::factory()->create([
            'phone' => '+989121234569',
            'phone_verified_at' => now(),
        ]);
        $otp = $this->createOtp($user);

        $this->withSession(['admin_login_phone' => $user->phone])
            ->post(route('admin.login.verify.submit'), ['code' => '123456'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('phone');

        $this->assertGuest('web');
        $this->assertTrue($otp->fresh()->used);
        $this->assertFalse($this->app['session']->has('admin_login_phone'));
    }

    public function test_admin_login_rotates_web_session_and_logout_invalidates_authentication(): void
    {
        $admin = User::factory()->create([
            'phone' => '+989121234570',
            'phone_verified_at' => now(),
        ]);
        $admin->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->createOtp($admin);
        $this->withSession(['admin_login_phone' => $admin->phone]);
        $initialSessionId = $this->app['session']->getId();

        $this->post(route('admin.login.verify.submit'), ['code' => '123456'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'web');
        $authenticatedSessionId = $this->app['session']->getId();
        $this->assertNotSame($initialSessionId, $authenticatedSessionId);

        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));

        $this->assertGuest('web');
        $this->assertNotSame($authenticatedSessionId, $this->app['session']->getId());
        $this->assertFalse($this->app['session']->has('admin_login_phone'));
    }

    private function createOtp(User $user): Otp
    {
        return Otp::query()->create([
            'user_id' => $user->getKey(),
            'phone' => $user->phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);
    }
}
