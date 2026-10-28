<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\User;
use App\Services\Admin\AdminRuntimeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminRuntimeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_plan_settings_merge_with_defaults_and_ignore_unknown_configuration_keys(): void
    {
        AdminSetting::query()->create([
            'setting_key' => 'plans',
            'value' => [
                'tiers' => [
                    'silver' => [
                        'name' => 'Injected tier name',
                        'features' => [
                            'base-salary' => true,
                            'unknown-feature' => true,
                        ],
                    ],
                ],
                'offers' => [
                    'gold_1_month' => [
                        'price' => 25000,
                        'unknown-field' => 'ignored',
                    ],
                    'unknown-offer' => ['price' => 1],
                ],
                'unknown-section' => ['secret' => 'ignored'],
            ],
        ]);

        $plans = app(AdminRuntimeSettings::class)->plans();
        $defaults = config('plans');

        $this->assertSame(array_keys($defaults['features']), array_keys($plans['features']));
        $this->assertSame(array_keys($defaults['tiers']), array_keys($plans['tiers']));
        $this->assertSame(array_keys($defaults['offers']), array_keys($plans['offers']));
        $this->assertSame($defaults['durations'], $plans['durations']);
        $this->assertSame($defaults['tiers']['silver']['name'], $plans['tiers']['silver']['name']);
        $this->assertTrue($plans['tiers']['silver']['features']['base-salary']);
        $this->assertFalse($plans['tiers']['silver']['features']['group-calculation']);
        $this->assertSame($defaults['tiers']['gold']['features'], $plans['tiers']['gold']['features']);
        $this->assertSame(25000, $plans['offers']['gold_1_month']['price']);
        $this->assertSame(0, $plans['offers']['gold_3_months']['price']);
        $this->assertSame($defaults['offers']['gold_1_month']['tier'], $plans['offers']['gold_1_month']['tier']);
        $this->assertSame($defaults['offers']['gold_1_month']['duration_days'], $plans['offers']['gold_1_month']['duration_days']);
        $this->assertArrayNotHasKey('unknown-feature', $plans['tiers']['silver']['features']);
        $this->assertArrayNotHasKey('unknown-offer', $plans['offers']);
        $this->assertArrayNotHasKey('unknown-field', $plans['offers']['gold_1_month']);
    }

    public function test_plan_cache_expires_after_five_minutes_and_save_invalidates_it_immediately(): void
    {
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));

        AdminSetting::query()->create([
            'setting_key' => 'plans',
            'value' => ['offers' => ['gold_1_month' => ['price' => 1000]]],
        ]);

        $runtimeSettings = app(AdminRuntimeSettings::class);
        $this->assertSame(1000, $runtimeSettings->plans()['offers']['gold_1_month']['price']);
        $this->assertTrue(Cache::store('array')->has('admin-runtime-settings:v2:plans'));

        DB::table('admin_settings')
            ->where('setting_key', 'plans')
            ->update(['value' => json_encode(['offers' => ['gold_1_month' => ['price' => 2000]]])]);

        $this->travel(299)->seconds();
        $this->assertSame(1000, $runtimeSettings->plans()['offers']['gold_1_month']['price']);

        $this->travel(1)->seconds();
        $this->assertSame(2000, $runtimeSettings->plans()['offers']['gold_1_month']['price']);

        $admin = User::factory()->create(['is_active' => true]);
        $runtimeSettings->save('plans', [
            'offers' => ['gold_1_month' => ['price' => 3000]],
        ], $admin);

        $this->assertFalse(Cache::store('array')->has('admin-runtime-settings:v2:plans'));
        $this->assertSame(3000, $runtimeSettings->plans()['offers']['gold_1_month']['price']);
    }
}
