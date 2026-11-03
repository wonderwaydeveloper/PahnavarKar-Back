<?php

namespace Tests\Feature;

use App\Jobs\SendOtpSms;
use App\Models\AdminSetting;
use App\Models\Otp;
use App\Models\Permission;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Auth\SmsProvider;
use App\Services\Auth\SmsProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminSmsProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_only_configured_provider_names_are_shared_and_admin_settings_never_expose_credentials(): void
    {
        $this->configureKavenegar();
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->get('/admin/settings/sms')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Settings/Index')
                ->where('section', 'sms')
                ->where('permissions.smsProviders', true)
                ->where('smsProviders.active_provider', 'melipayamak')
                ->where('smsProviders.active_provider_configured', false)
                ->where('smsProviders.configured_providers', ['kavenegar' => 'کاوه‌نگار'])
                ->where('smsProviders.test_available', false)
                ->missing('smsProviders.api_key')
                ->missing('smsProviders.credentials'),
            );
    }

    public function test_unprivileged_admin_cannot_read_or_change_sms_provider_selection(): void
    {
        $this->configureKavenegar();
        $admin = User::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'web')
            ->get('/admin/settings/sms')
            ->assertForbidden();

        $this->put('/admin/settings/sms-provider', ['provider' => 'kavenegar'])
            ->assertForbidden();

        $this->assertDatabaseCount('admin_settings', 0);
    }

    public function test_admin_can_send_a_fixed_test_message_to_an_entered_iranian_number(): void
    {
        $this->configureKavenegar();
        AdminSetting::query()->create([
            'setting_key' => 'sms_provider',
            'value' => ['provider' => 'kavenegar'],
        ]);
        $admin = $this->admin();
        Http::fake([
            'https://api.kavenegar.com/v1/server-secret/sms/send.json' => Http::response([
                'return' => ['status' => 200],
                'entries' => [['status' => 1]],
            ]),
        ]);

        $this->actingAs($admin, 'web')
            ->post('/admin/settings/sms-test', ['phone' => '+989121234567'])
            ->assertRedirect(route('admin.settings.section', ['section' => 'sms']))
            ->assertSessionHas('status', 'provider پیام آزمایشی را پذیرفت؛ برای تأیید تحویل، گوشی مقصد را بررسی کنید.');

        Http::assertSent(fn ($request): bool => $request->data() === [
            'receptor' => '09121234567',
            'message' => 'پیام آزمایشی سرویس پیامک پهناورکار؛ نیازی به پاسخ نیست.',
        ]);

        $activity = Activity::query()->where('event', 'sms_test_completed')->sole();
        $this->assertSame($admin->getKey(), $activity->causer_id);
        $this->assertSame('kavenegar', $activity->properties->get('provider'));
        $this->assertSame('4567', $activity->properties->get('phone_last4'));
        $this->assertStringNotContainsString('+989121234567', json_encode($activity->properties));
    }

    public function test_test_message_requires_permission_and_valid_iranian_phone_without_sending(): void
    {
        $this->configureKavenegar();
        AdminSetting::query()->create([
            'setting_key' => 'sms_provider',
            'value' => ['provider' => 'kavenegar'],
        ]);
        Http::fake();
        $admin = User::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'web')
            ->post('/admin/settings/sms-test', ['phone' => '+989121234567'])
            ->assertForbidden();

        $authorizedAdmin = $this->admin();
        $this->actingAs($authorizedAdmin, 'web')
            ->from('/admin/settings/sms')
            ->post('/admin/settings/sms-test', ['phone' => '09121234567'])
            ->assertSessionHasErrors('phone');

        Http::assertNothingSent();
    }

    public function test_sms_test_is_rate_limited_per_admin_and_destination(): void
    {
        $this->configureKavenegar();
        AdminSetting::query()->create([
            'setting_key' => 'sms_provider',
            'value' => ['provider' => 'kavenegar'],
        ]);
        $admin = $this->admin();
        Http::fake([
            'https://api.kavenegar.com/v1/server-secret/sms/send.json' => Http::response([
                'return' => ['status' => 200],
                'entries' => [['status' => 1]],
            ]),
        ]);

        $this->actingAs($admin, 'web')
            ->post('/admin/settings/sms-test', ['phone' => '+989121234567'])
            ->assertRedirect(route('admin.settings.section', ['section' => 'sms']));
        $this->post('/admin/settings/sms-test', ['phone' => '+989121234568'])
            ->assertTooManyRequests();

        $otherAdmin = $this->admin();
        $this->actingAs($otherAdmin, 'web')
            ->post('/admin/settings/sms-test', ['phone' => '+989121234567'])
            ->assertTooManyRequests();

        Http::assertSentCount(1);
    }

    public function test_local_log_test_message_is_available_without_a_real_provider_or_sms_delivery(): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
        config([
            'services.melipayamak.local_log_enabled' => true,
        ]);
        Cache::store('array')->flush();
        Log::spy();
        Http::fake();
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->get('/admin/settings/sms')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('smsProviders.configured_providers', [])
                ->where('smsProviders.test_available', true)
                ->where('smsProviders.test_mode', 'local_log'),
            );

        app(SmsProviderManager::class)->sendTestMessage('+989121234567');

        Log::shouldHaveReceived('info')
            ->with('Local SMS provider test requested.', ['phone_last4' => '4567'])
            ->once();
        Http::assertNothingSent();
    }

    public function test_provider_rejection_is_reported_without_logging_the_full_destination(): void
    {
        $this->configureKavenegar();
        AdminSetting::query()->create([
            'setting_key' => 'sms_provider',
            'value' => ['provider' => 'kavenegar'],
        ]);
        $admin = $this->admin();
        Log::spy();
        Http::fake([
            'https://api.kavenegar.com/v1/server-secret/sms/send.json' => Http::response([
                'return' => ['status' => 200],
                'entries' => [['status' => 411]],
            ]),
        ]);

        $this->actingAs($admin, 'web')
            ->from('/admin/settings/sms')
            ->post('/admin/settings/sms-test', ['phone' => '+989121234567'])
            ->assertSessionHasErrors('test');

        Log::shouldHaveReceived('warning')
            ->with('Admin SMS provider test was rejected.', [
                'provider' => 'kavenegar',
                'admin_id' => $admin->getKey(),
                'phone_last4' => '4567',
            ])
            ->once();
    }

    public function test_admin_can_switch_to_a_configured_provider_and_new_otp_uses_it_immediately(): void
    {
        $this->configureKavenegar();
        $admin = $this->admin();
        Http::fake([
            'https://api.kavenegar.com/v1/server-secret/sms/send.json' => Http::response([
                'return' => ['status' => 200],
                'entries' => [['status' => 1]],
            ]),
        ]);

        $this->actingAs($admin, 'web')
            ->put('/admin/settings/sms-provider', ['provider' => 'kavenegar'])
            ->assertRedirect(route('admin.settings.section', ['section' => 'sms']));

        $setting = AdminSetting::query()->where('setting_key', 'sms_provider')->sole();
        $this->assertSame(['provider' => 'kavenegar'], $setting->value);
        $this->assertSame($admin->getKey(), $setting->updated_by);
        $activity = Activity::query()->forSubject($setting)->sole();
        $this->assertSame($admin->getKey(), $activity->causer_id);
        $this->assertStringNotContainsString('server-secret', json_encode($activity->attribute_changes));

        Http::fake([
            'https://api.kavenegar.com/v1/server-secret/sms/send.json' => Http::response([
                'return' => ['status' => 200],
                'entries' => [['status' => 1]],
            ]),
        ]);
        app(SmsProvider::class)->sendOtp('+989121234567', '123456');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.kavenegar.com/v1/server-secret/sms/send.json');
    }

    public function test_unconfigured_provider_cannot_be_selected_and_unknown_input_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->from('/admin/settings/sms')
            ->put('/admin/settings/sms-provider', ['provider' => 'kavenegar'])
            ->assertSessionHasErrors('provider');
        $this->assertDatabaseCount('admin_settings', 0);

        config([
            'services.kavenegar.enabled' => true,
            'services.kavenegar.api_key' => 'server-secret',
        ]);
        Cache::store('array')->flush();

        $this->put('/admin/settings/sms-provider', ['provider' => 'unknown'])
            ->assertSessionHasErrors('provider');
        $this->assertDatabaseCount('admin_settings', 0);
    }

    public function test_sms_queue_can_use_the_selected_kavenegar_provider_when_persistently_configured(): void
    {
        $this->configureKavenegar();
        AdminSetting::query()->create([
            'setting_key' => 'sms_provider',
            'value' => ['provider' => 'kavenegar'],
        ]);
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'services.sms.queue_enabled' => true,
            'services.sms.queue' => 'sms',
        ]);
        Bus::fake();

        app(OtpService::class)->send('+989121234567', 'login');

        Bus::assertDispatched(SendOtpSms::class, fn (SendOtpSms $job): bool => $job->queue === 'sms'
            && $job->otpId === Otp::query()->sole()->getKey()
            && Hash::check($job->code, Otp::query()->sole()->code_hash));
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('manage sms providers', 'web'));

        return $admin;
    }

    private function configureKavenegar(): void
    {
        config([
            'services.kavenegar.enabled' => true,
            'services.kavenegar.api_key' => 'server-secret',
            'services.kavenegar.sender' => null,
        ]);
    }
}
