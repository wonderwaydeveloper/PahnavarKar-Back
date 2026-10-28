<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\PaymentGatewaySettings;
use App\Models\Permission;
use App\Models\User;
use App\Services\Admin\AdminRuntimeSettings;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminFinancialSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_index_redirects_by_available_permission_and_denies_unauthorized_users(): void
    {
        $this->get('/admin/settings')
            ->assertRedirect('/admin/login');

        $userWithoutSettingsPermission = User::factory()->create(['is_active' => true]);
        $this->actingAs($userWithoutSettingsPermission, 'web')
            ->get('/admin/settings')
            ->assertForbidden();

        foreach ([
            'manage plans' => 'plans',
            'manage wallet' => 'wallet',
            'manage payment gateways' => 'gateways',
            'manage site content' => 'site-content',
        ] as $permission => $section) {
            $admin = User::factory()->create(['is_active' => true]);
            $admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));

            $this->actingAs($admin, 'web')
                ->get('/admin/settings')
                ->assertRedirect(route('admin.settings.section', ['section' => $section]));
        }

        $adminWithMultipleSettingsPermissions = User::factory()->create(['is_active' => true]);
        $adminWithMultipleSettingsPermissions->givePermissionTo([
            Permission::findOrCreate('manage wallet', 'web'),
            Permission::findOrCreate('manage plans', 'web'),
            Permission::findOrCreate('manage site content', 'web'),
            Permission::findOrCreate('manage payment gateways', 'web'),
        ]);
        $this->actingAs($adminWithMultipleSettingsPermissions, 'web')
            ->get('/admin/settings')
            ->assertRedirect(route('admin.settings.section', ['section' => 'plans']));
    }

    public function test_settings_sections_enforce_individual_permissions_and_only_share_section_data(): void
    {
        $sectionPermissions = [
            'plans' => 'manage plans',
            'wallet' => 'manage wallet',
            'gateways' => 'manage payment gateways',
            'site-content' => 'manage site content',
        ];
        $this->mockGatewayManager();

        foreach ($sectionPermissions as $section => $permission) {
            $admin = User::factory()->create(['is_active' => true]);
            $admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            $this->actingAs($admin, 'web');

            foreach (array_keys($sectionPermissions) as $requestedSection) {
                $response = $this->get('/admin/settings/'.$requestedSection);
                if ($requestedSection !== $section) {
                    $response->assertForbidden();

                    continue;
                }

                $response->assertOk()
                    ->assertInertia(fn (AssertableInertia $page) => $page
                        ->component('Admin/Settings/Index')
                        ->where('section', $section)
                        ->where('permissions', [
                            'plans' => $section === 'plans',
                            'wallet' => $section === 'wallet',
                            'gateways' => $section === 'gateways',
                            'siteContent' => $section === 'site-content',
                        ])
                        ->where('plans', $section === 'plans' ? config('plans') : null)
                        ->where('featureGroups', $section === 'plans' ? fn ($groups) => $groups !== [] : [])
                        ->where('wallet', $section === 'wallet' ? config('wallet.withdrawals') : null)
                        ->where('gateways', $section === 'gateways' ? fn ($gateways) => $gateways['configured_gateways'] === ['zarinpal', 'sep'] : null)
                        ->where('siteContent', $section === 'site-content' ? config('site') : null),
                    );
            }
        }

        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('manage plans', 'web'));
        $this->actingAs($admin, 'web')
            ->get('/admin/settings/not-a-section')
            ->assertNotFound();
    }

    public function test_plan_settings_require_permission_and_update_runtime_price_cache(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $payload = $this->validPlanPayload();
        $payload['plans']['tiers']['silver']['features']['base-salary'] = true;
        $settings = app(AdminRuntimeSettings::class);
        $this->assertSame(0, $settings->plans()['offers']['gold_1_month']['price']);

        $this->actingAs($admin, 'web')
            ->put('/admin/settings/plans', $payload)
            ->assertForbidden();
        $this->assertDatabaseCount('admin_settings', 0);

        $admin->givePermissionTo(Permission::findOrCreate('manage plans', 'web'));
        $payload['plans']['offers']['gold_1_month']['price'] = 25000;

        $this->actingAs($admin, 'web')
            ->put('/admin/settings/plans', $payload)
            ->assertRedirect(route('admin.settings.section', ['section' => 'plans']));

        $this->assertSame(25000, $settings->plans()['offers']['gold_1_month']['price']);
        $this->assertTrue($settings->plans()['tiers']['silver']['features']['base-salary']);
        $setting = AdminSetting::query()->where('setting_key', 'plans')->sole();
        $this->assertSame($this->normalizedPlanPayload($payload), $setting->value);
        $this->assertSame($admin->getKey(), $setting->updated_by);
        $createdActivity = Activity::query()->forSubject($setting)->where('event', 'created')->sole();
        $this->assertSame('admin_settings', $createdActivity->log_name);
        $this->assertSame($admin->getKey(), $createdActivity->causer_id);
        $this->assertSame(
            ['setting_key', 'value', 'updated_by'],
            array_keys($createdActivity->attribute_changes->get('attributes')),
        );
        $this->assertSame($admin->getKey(), $createdActivity->attribute_changes->get('attributes')['updated_by']);

        $otherAdmin = User::factory()->create(['is_active' => true]);
        $otherAdmin->givePermissionTo(Permission::findOrCreate('manage plans', 'web'));
        $updatedPayload = $payload;
        $updatedPayload['plans']['offers']['gold_1_month']['price'] = 30000;
        $updatedPayload['plans']['tiers']['silver']['features']['base-salary'] = false;
        $this->actingAs($otherAdmin, 'web')
            ->put('/admin/settings/plans', $updatedPayload)
            ->assertRedirect(route('admin.settings.section', ['section' => 'plans']));
        $this->assertFalse($settings->plans()['tiers']['silver']['features']['base-salary']);
        $this->assertSame($this->normalizedPlanPayload($updatedPayload), $setting->fresh()->value);
        $updatedActivity = Activity::query()->forSubject($setting)->where('event', 'updated')->sole();
        $this->assertSame($otherAdmin->getKey(), $updatedActivity->causer_id);
        $this->assertSame(
            ['value', 'updated_by'],
            array_keys($updatedActivity->attribute_changes->get('attributes')),
        );
        $this->assertSame(
            ['value', 'updated_by'],
            array_keys($updatedActivity->attribute_changes->get('old')),
        );

        $activityCount = Activity::query()->forSubject($setting)->count();
        $this->actingAs($otherAdmin, 'web')
            ->put('/admin/settings/plans', $updatedPayload)
            ->assertRedirect(route('admin.settings.section', ['section' => 'plans']));
        $this->assertSame($activityCount, Activity::query()->forSubject($setting)->count());

        $this->actingAs($otherAdmin, 'web')
            ->get('/admin/settings/plans')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Settings/Index')
                ->where('section', 'plans')
                ->where('plans.offers.gold_1_month.price', 30000)
                ->where('plans.tiers.silver.features.base-salary', false)
                ->where('permissions.plans', true)
                ->where('permissions.gateways', false),
            );
    }

    public function test_plan_settings_reject_unknown_fields_and_prices_outside_limits(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('manage plans', 'web'));
        $payload = $this->validPlanPayload();
        $payload['plans']['api_secret'] = 'sensitive-settings-marker';

        $this->actingAs($admin, 'web')
            ->from('/admin/settings/plans')
            ->put('/admin/settings/plans', $payload)
            ->assertSessionHasErrors('plans');
        $this->assertDatabaseCount('admin_settings', 0);
        $this->assertSame(0, Activity::query()->where('subject_type', AdminSetting::class)->count());

        unset($payload['plans']['api_secret']);
        $payload['plans']['offers']['gold_1_month']['price'] = config('payment.max_top_up') + 1;

        $this->actingAs($admin, 'web')
            ->from('/admin/settings/plans')
            ->put('/admin/settings/plans', $payload)
            ->assertSessionHasErrors('plans.offers.gold_1_month.price');

        $this->assertDatabaseCount('admin_settings', 0);
        $this->assertSame(0, Activity::query()->where('subject_type', AdminSetting::class)->count());
    }

    public function test_wallet_settings_audit_validated_changes_with_actor_and_suppress_noops(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $settings = app(AdminRuntimeSettings::class);

        $this->actingAs($admin, 'web')
            ->put('/admin/settings/wallet', [
                'enabled' => true,
                'max_per_request' => 100000,
                'max_per_day' => 500000,
            ])
            ->assertForbidden();
        $this->assertDatabaseCount('admin_settings', 0);
        $this->assertSame(0, Activity::query()->where('subject_type', AdminSetting::class)->count());

        $admin->givePermissionTo(Permission::findOrCreate('manage wallet', 'web'));
        $payload = [
            'enabled' => true,
            'max_per_request' => 75000,
            'max_per_day' => 300000,
        ];
        $this->actingAs($admin, 'web')
            ->put('/admin/settings/wallet', $payload)
            ->assertRedirect(route('admin.settings.section', ['section' => 'wallet']));

        $setting = AdminSetting::query()->where('setting_key', 'wallet_withdrawals')->sole();
        $this->assertSame($admin->getKey(), $setting->updated_by);
        $this->assertSame([
            'enabled' => true,
            'max_per_request' => 75000,
            'max_per_day' => 300000,
        ], $settings->walletWithdrawals());

        $createdActivity = Activity::query()->forSubject($setting)->where('event', 'created')->sole();
        $this->assertSame('admin_settings', $createdActivity->log_name);
        $this->assertSame($admin->getKey(), $createdActivity->causer_id);
        $this->assertSame(
            ['setting_key', 'value', 'updated_by'],
            array_keys($createdActivity->attribute_changes->get('attributes')),
        );
        $this->assertSame($payload, $createdActivity->attribute_changes->get('attributes')['value']);

        $activityCount = Activity::query()->forSubject($setting)->count();
        $this->actingAs($admin, 'web')
            ->put('/admin/settings/wallet', $payload)
            ->assertRedirect(route('admin.settings.section', ['section' => 'wallet']));
        $this->assertSame($activityCount, Activity::query()->forSubject($setting)->count());

        $otherAdmin = User::factory()->create(['is_active' => true]);
        $otherAdmin->givePermissionTo(Permission::findOrCreate('manage wallet', 'web'));
        $updatedPayload = [
            'enabled' => false,
            'max_per_request' => 50000,
            'max_per_day' => 200000,
        ];
        $this->actingAs($otherAdmin, 'web')
            ->put('/admin/settings/wallet', $updatedPayload)
            ->assertRedirect(route('admin.settings.section', ['section' => 'wallet']));

        $updatedActivity = Activity::query()->forSubject($setting)->where('event', 'updated')->sole();
        $this->assertSame($otherAdmin->getKey(), $updatedActivity->causer_id);
        $this->assertSame($otherAdmin->getKey(), $setting->fresh()->updated_by);
        $this->assertSame($updatedPayload, $settings->walletWithdrawals());
        $this->assertSame(['value', 'updated_by'], array_keys($updatedActivity->attribute_changes->get('attributes')));
        $this->assertSame(['value', 'updated_by'], array_keys($updatedActivity->attribute_changes->get('old')));
    }

    public function test_wallet_settings_reject_invalid_fields_and_ignore_unrecognized_secret_inputs(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('manage wallet', 'web'));
        $base = [
            'enabled' => true,
            'max_per_request' => 100000,
            'max_per_day' => 500000,
        ];

        $this->actingAs($admin, 'web')
            ->from('/admin/settings/wallet')
            ->put('/admin/settings/wallet', [
                ...$base,
                'max_per_request' => 300000,
                'max_per_day' => 200000,
            ])
            ->assertSessionHasErrors('max_per_day');
        $this->assertDatabaseCount('admin_settings', 0);

        $this->actingAs($admin, 'web')
            ->put('/admin/settings/wallet', [...$base, 'provider_secret' => 'wallet-secret-marker'])
            ->assertRedirect(route('admin.settings.section', ['section' => 'wallet']));

        $setting = AdminSetting::query()->where('setting_key', 'wallet_withdrawals')->sole();
        $this->assertSame($base, $setting->value);
        $this->assertArrayNotHasKey('provider_secret', $setting->value);
        $this->assertStringNotContainsString(
            'wallet-secret-marker',
            json_encode(Activity::query()->forSubject($setting)->get()),
        );
        $this->assertSame(1, Activity::query()->forSubject($setting)->count());
    }

    public function test_site_content_settings_require_permission_and_audit_only_validated_fields(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $payload = [
            'brand_name' => 'New brand',
            'tagline' => 'New tagline',
            'meta_description' => 'New description',
            'hero_badge' => 'Badge',
            'hero_title' => 'Title',
            'hero_title_highlight' => 'Highlight',
            'hero_description' => 'Description',
        ];

        $this->actingAs($admin, 'web')
            ->put('/admin/settings/site-content', $payload)
            ->assertForbidden();
        $this->assertDatabaseCount('admin_settings', 0);

        $admin->givePermissionTo(Permission::findOrCreate('manage site content', 'web'));
        $settings = app(AdminRuntimeSettings::class);
        $this->actingAs($admin, 'web')
            ->put('/admin/settings/site-content', [...$payload, 'api_key' => 'site-secret-marker'])
            ->assertRedirect(route('admin.settings.section', ['section' => 'site-content']));

        $setting = AdminSetting::query()->where('setting_key', 'site_content')->sole();
        $this->assertSame($payload, $setting->value);
        $this->assertArrayNotHasKey('api_key', $setting->value);
        $this->assertSame($payload, $settings->siteContent());
        $createdActivity = Activity::query()->forSubject($setting)->where('event', 'created')->sole();
        $this->assertSame($admin->getKey(), $createdActivity->causer_id);
        $this->assertSame(
            ['setting_key', 'value', 'updated_by'],
            array_keys($createdActivity->attribute_changes->get('attributes')),
        );
        $this->assertSame(
            $payload,
            $createdActivity->attribute_changes->get('attributes')['value'],
        );
        $this->assertStringNotContainsString(
            'site-secret-marker',
            json_encode(Activity::query()->forSubject($setting)->get()),
        );

        $activityCount = Activity::query()->forSubject($setting)->count();
        $this->actingAs($admin, 'web')
            ->put('/admin/settings/site-content', $payload)
            ->assertRedirect(route('admin.settings.section', ['section' => 'site-content']));
        $this->assertSame($activityCount, Activity::query()->forSubject($setting)->count());

        $otherAdmin = User::factory()->create(['is_active' => true]);
        $otherAdmin->givePermissionTo(Permission::findOrCreate('manage site content', 'web'));
        $updatedPayload = [...$payload, 'tagline' => 'Updated tagline'];
        $this->actingAs($otherAdmin, 'web')
            ->put('/admin/settings/site-content', $updatedPayload)
            ->assertRedirect(route('admin.settings.section', ['section' => 'site-content']));
        $updatedActivity = Activity::query()->forSubject($setting)->where('event', 'updated')->sole();
        $this->assertSame($otherAdmin->getKey(), $updatedActivity->causer_id);
        $this->assertSame(
            ['value', 'updated_by'],
            array_keys($updatedActivity->attribute_changes->get('attributes')),
        );
        $this->assertSame(
            $updatedPayload,
            $updatedActivity->attribute_changes->get('attributes')['value'],
        );
        $this->assertSame($otherAdmin->getKey(), $setting->fresh()->updated_by);
        $this->assertSame($updatedPayload, $settings->siteContent());
    }

    public function test_site_content_settings_reject_invalid_fields_without_persistence_or_audit(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('manage site content', 'web'));
        $payload = [
            'brand_name' => 'New brand',
            'tagline' => 'New tagline',
            'meta_description' => 'New description',
            'hero_badge' => 'Badge',
            'hero_title' => 'Title',
            'hero_title_highlight' => 'Highlight',
            'hero_description' => 'Description',
        ];

        $this->actingAs($admin, 'web')
            ->from('/admin/settings/site-content')
            ->put('/admin/settings/site-content', [...$payload, 'hero_title' => str_repeat('x', 121)])
            ->assertSessionHasErrors('hero_title');
        $this->assertDatabaseCount('admin_settings', 0);
        $this->assertSame(0, Activity::query()->where('subject_type', AdminSetting::class)->count());
    }

    public function test_gateway_settings_web_route_is_permission_gated_and_audits_validated_values(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $payload = [
            'default_gateway' => 'zarinpal',
            'enabled_gateways' => ['zarinpal', 'sep'],
            'failover_order' => ['sep'],
        ];

        $this->actingAs($admin, 'web')
            ->put('/admin/settings/payment-gateways', $payload)
            ->assertForbidden();
        $this->assertDatabaseCount('payment_gateway_settings', 0);
        $this->assertSame(0, Activity::query()->where('subject_type', PaymentGatewaySettings::class)->count());

        $admin->givePermissionTo(Permission::findOrCreate('manage payment gateways', 'web'));
        $this->mockGatewayManager();
        $payloadWithUnknownSecrets = [
            ...$payload,
            'merchant_id' => 'injected-merchant-secret',
            'credentials' => ['secret' => 'injected-credential-secret'],
        ];

        $this->actingAs($admin, 'web')
            ->put('/admin/settings/payment-gateways', $payloadWithUnknownSecrets)
            ->assertRedirect(route('admin.settings.section', ['section' => 'gateways']));

        $settings = PaymentGatewaySettings::query()->sole();
        $this->assertSame('zarinpal', $settings->default_gateway);
        $this->assertSame(['zarinpal', 'sep'], $settings->enabled_gateways);
        $this->assertSame(['sep'], $settings->failover_order);
        $this->assertSame($admin->getKey(), $settings->updated_by);
        $createdActivity = Activity::query()->forSubject($settings)->where('event', 'created')->sole();
        $this->assertSame($admin->getKey(), $createdActivity->causer_id);
        $this->assertSame(
            ['default_gateway', 'failover_order', 'enabled_gateways', 'updated_by'],
            array_keys($createdActivity->attribute_changes->get('attributes')),
        );
        $activityCount = Activity::query()->forSubject($settings)->count();

        $this->actingAs($admin, 'web')
            ->put('/admin/settings/payment-gateways', $payloadWithUnknownSecrets)
            ->assertRedirect(route('admin.settings.section', ['section' => 'gateways']));
        $this->assertSame($activityCount, Activity::query()->forSubject($settings)->count());
        $this->assertStringNotContainsString(
            'injected-credential-secret',
            json_encode(Activity::query()->forSubject($settings)->get()),
        );

        $otherAdmin = User::factory()->create(['is_active' => true]);
        $otherAdmin->givePermissionTo(Permission::findOrCreate('manage payment gateways', 'web'));
        $this->actingAs($otherAdmin, 'web')
            ->put('/admin/settings/payment-gateways', [
                'default_gateway' => 'sep',
                'enabled_gateways' => ['zarinpal', 'sep'],
                'failover_order' => ['zarinpal'],
            ])
            ->assertRedirect(route('admin.settings.section', ['section' => 'gateways']));

        $updatedActivity = Activity::query()->forSubject($settings)->where('event', 'updated')->sole();
        $this->assertSame($otherAdmin->getKey(), $updatedActivity->causer_id);
        $this->assertSame($otherAdmin->getKey(), $settings->fresh()->updated_by);

        $this->actingAs($admin, 'web')
            ->from('/admin/settings/gateways')
            ->put('/admin/settings/payment-gateways', [
                'default_gateway' => 'sep',
                'enabled_gateways' => ['zarinpal'],
                'failover_order' => [],
            ])
            ->assertSessionHasErrors('default_gateway');
        $this->assertSame('sep', $settings->fresh()->default_gateway);
        $this->assertSame(2, Activity::query()->forSubject($settings)->count());

        $this->actingAs($otherAdmin, 'web')
            ->get('/admin/settings/gateways')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Settings/Index')
                ->where('section', 'gateways')
                ->where('gateways.default_gateway', 'sep')
                ->where('permissions.gateways', true)
                ->missing('gateways.credentials')
                ->missing('gateways.merchant_id'),
            );
    }

    public function test_gateway_settings_api_is_permission_gated_and_returns_no_credentials(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $this->mockGatewayManager();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/payment-gateways')
            ->assertForbidden();

        $admin->givePermissionTo(Permission::findOrCreate('manage payment gateways', 'web'));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/payment-gateways')
            ->assertOk()
            ->assertJsonPath('configured_gateways.0', 'zarinpal')
            ->assertJsonPath('available_gateways.0', 'zarinpal')
            ->assertJsonMissingPath('merchant_id')
            ->assertJsonMissingPath('credentials')
            ->assertJsonMissingPath('key');
    }

    public function test_gateway_settings_update_persists_actor_and_enforces_configured_enabled_order(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('manage payment gateways', 'web'));
        $this->mockGatewayManager();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/payment-gateways', [
                'default_gateway' => 'zarinpal',
                'enabled_gateways' => ['zarinpal', 'sep'],
                'failover_order' => ['sep'],
            ])
            ->assertOk()
            ->assertJsonPath('default_gateway', 'zarinpal')
            ->assertJsonPath('enabled_gateways.0', 'zarinpal')
            ->assertJsonPath('failover_order.0', 'sep')
            ->assertJsonMissingPath('credentials');

        $settings = PaymentGatewaySettings::query()->sole();
        $this->assertSame('zarinpal', $settings->default_gateway);
        $this->assertSame(['zarinpal', 'sep'], $settings->enabled_gateways);
        $this->assertSame(['sep'], $settings->failover_order);
        $this->assertSame($admin->getKey(), $settings->updated_by);
        $createdActivity = Activity::query()
            ->forSubject($settings)
            ->where('event', 'created')
            ->sole();
        $this->assertSame('payment_gateway_settings', $createdActivity->log_name);
        $this->assertSame($admin->getKey(), $createdActivity->causer_id);
        $this->assertSame(
            ['default_gateway', 'failover_order', 'enabled_gateways', 'updated_by'],
            array_keys($createdActivity->attribute_changes->get('attributes')),
        );
        $this->assertStringNotContainsString('injected-merchant-secret', json_encode($createdActivity));

        $activityCount = Activity::query()->forSubject($settings)->count();
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/payment-gateways', [
                'default_gateway' => 'zarinpal',
                'enabled_gateways' => ['zarinpal', 'sep'],
                'failover_order' => ['sep'],
                'merchant_id' => 'injected-merchant-secret',
                'credentials' => ['secret' => 'injected-credential-secret'],
            ])
            ->assertOk();
        $this->assertSame($activityCount, Activity::query()->forSubject($settings)->count());
        $this->assertStringNotContainsString(
            'injected-credential-secret',
            json_encode(Activity::query()->forSubject($settings)->get()),
        );

        $otherAdmin = User::factory()->create(['is_active' => true]);
        $otherAdmin->givePermissionTo(Permission::findOrCreate('manage payment gateways', 'web'));
        $this->actingAs($otherAdmin, 'sanctum')
            ->putJson('/api/v1/admin/payment-gateways', [
                'default_gateway' => 'sep',
                'enabled_gateways' => ['zarinpal', 'sep'],
                'failover_order' => ['zarinpal'],
            ])
            ->assertOk();
        $updatedActivity = Activity::query()
            ->forSubject($settings)
            ->where('event', 'updated')
            ->sole();
        $this->assertSame($otherAdmin->getKey(), $updatedActivity->causer_id);
        $this->assertSame(
            ['default_gateway', 'failover_order', 'updated_by'],
            array_keys($updatedActivity->attribute_changes->get('attributes')),
        );
        $this->assertSame(
            ['default_gateway', 'failover_order', 'updated_by'],
            array_keys($updatedActivity->attribute_changes->get('old')),
        );
        $this->assertStringNotContainsString(
            'injected-merchant-secret',
            json_encode(Activity::query()->forSubject($settings)->get()),
        );

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/payment-gateways', [
                'default_gateway' => 'sep',
                'enabled_gateways' => ['zarinpal'],
                'failover_order' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_gateway');

        $this->assertSame('sep', $settings->fresh()->default_gateway);
    }

    private function mockGatewayManager(): void
    {
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('configuredGateways')
            ->zeroOrMoreTimes()
            ->andReturn(['zarinpal', 'sep']);
        $manager->shouldReceive('availableGateways')
            ->zeroOrMoreTimes()
            ->andReturn(['zarinpal']);

        $this->app->instance(PaymentGatewayManager::class, $manager);
    }

    /**
     * @return array{plans: array{tiers: array<string, mixed>, offers: array<string, mixed>}}
     */
    private function validPlanPayload(): array
    {
        $config = config('plans');
        $tiers = [];
        foreach (array_keys($config['tiers']) as $tierKey) {
            $tiers[$tierKey] = [
                'features' => array_fill_keys(array_keys($config['features']), false),
            ];
        }

        $offers = [];
        foreach (array_keys($config['offers']) as $offerKey) {
            $offers[$offerKey] = ['price' => 0];
        }

        return ['plans' => ['tiers' => $tiers, 'offers' => $offers]];
    }

    private function normalizedPlanPayload(array $payload): array
    {
        $plans = ['tiers' => [], 'offers' => []];
        foreach (array_keys(config('plans.tiers')) as $tierKey) {
            $plans['tiers'][$tierKey] = [
                'features' => array_map(
                    static fn ($enabled): bool => (bool) $enabled,
                    $payload['plans']['tiers'][$tierKey]['features'],
                ),
            ];
        }
        foreach (array_keys(config('plans.offers')) as $offerKey) {
            $plans['offers'][$offerKey] = [
                'price' => (int) $payload['plans']['offers'][$offerKey]['price'],
            ];
        }

        return $plans;
    }
}
