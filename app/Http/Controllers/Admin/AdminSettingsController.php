<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminRuntimeSettings;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewaySettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminSettingsController extends Controller
{
    public function index(
        Request $request,
    ): RedirectResponse {
        $admin = $request->user();
        foreach ([
            'plans' => 'manage plans',
            'wallet' => 'manage wallet',
            'gateways' => 'manage payment gateways',
            'site-content' => 'manage site content',
        ] as $section => $permission) {
            if ($admin->can($permission)) {
                return to_route('admin.settings.section', ['section' => $section]);
            }
        }

        abort(403);
    }

    public function show(
        Request $request,
        string $section,
        AdminRuntimeSettings $runtimeSettings,
        PaymentGatewayManager $gateways,
        PaymentGatewaySettingsService $gatewaySettings,
    ): Response {
        $admin = $request->user();
        $sectionPermissions = [
            'plans' => 'manage plans',
            'wallet' => 'manage wallet',
            'gateways' => 'manage payment gateways',
            'site-content' => 'manage site content',
        ];
        abort_unless(array_key_exists($section, $sectionPermissions), 404);
        abort_unless($admin->can($sectionPermissions[$section]), 403);

        $permissions = [
            'plans' => $section === 'plans',
            'wallet' => $section === 'wallet',
            'gateways' => $section === 'gateways',
            'siteContent' => $section === 'site-content',
        ];

        return Inertia::render('Admin/Settings/Index', [
            'admin' => ['name' => $admin->name],
            'section' => $section,
            'permissions' => $permissions,
            'plans' => $permissions['plans'] ? $runtimeSettings->plans() : null,
            'featureGroups' => $permissions['plans'] ? collect(config('plans.features'))
                ->map(fn (array $feature, string $key): array => ['key' => $key, ...$feature])
                ->groupBy('group')
                ->map(fn ($features): array => $features->values()->all())
                ->all() : [],
            'wallet' => $permissions['wallet'] ? $runtimeSettings->walletWithdrawals() : null,
            'gateways' => $permissions['gateways']
                ? $gatewaySettings->current($gateways)
                : null,
            'siteContent' => $permissions['siteContent'] ? $runtimeSettings->siteContent() : null,
        ]);
    }

    public function updatePlans(Request $request, AdminRuntimeSettings $runtimeSettings): RedirectResponse
    {
        abort_unless($request->user()->can('manage plans'), 403);
        $featureKeys = array_keys(config('plans.features'));
        $tierKeys = array_keys(config('plans.tiers'));
        $offerKeys = array_keys(config('plans.offers'));
        $rules = [
            'plans' => ['required', 'array:tiers,offers'],
            'plans.tiers' => ['required', 'array:'.implode(',', $tierKeys)],
            'plans.offers' => ['required', 'array:'.implode(',', $offerKeys)],
        ];

        foreach ($tierKeys as $tierKey) {
            $rules["plans.tiers.{$tierKey}"] = ['required', 'array:features'];
            $rules["plans.tiers.{$tierKey}.features"] = ['required', 'array:'.implode(',', $featureKeys)];
            foreach ($featureKeys as $featureKey) {
                $rules["plans.tiers.{$tierKey}.features.{$featureKey}"] = ['required', 'boolean'];
            }
        }

        foreach ($offerKeys as $offerKey) {
            $rules["plans.offers.{$offerKey}"] = ['required', 'array:price'];
            $rules["plans.offers.{$offerKey}.price"] = [
                'required',
                'integer',
                'min:0',
                'max:'.config('payment.max_top_up'),
            ];
        }

        $validated = $request->validate($rules);
        $plans = ['tiers' => [], 'offers' => []];
        foreach ($tierKeys as $tierKey) {
            $plans['tiers'][$tierKey] = [
                'features' => array_map(
                    static fn ($enabled): bool => (bool) $enabled,
                    $validated['plans']['tiers'][$tierKey]['features'],
                ),
            ];
        }
        foreach ($offerKeys as $offerKey) {
            $plans['offers'][$offerKey] = [
                'price' => (int) $validated['plans']['offers'][$offerKey]['price'],
            ];
        }

        $runtimeSettings->save('plans', $plans, $request->user());

        return to_route('admin.settings.section', ['section' => 'plans'])->with('status', 'سطح دسترسی و قیمت پلن‌ها ذخیره شد.');
    }

    public function updateWallet(Request $request, AdminRuntimeSettings $runtimeSettings): RedirectResponse
    {
        abort_unless($request->user()->can('manage wallet'), 403);
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'max_per_request' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'max_per_day' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
        ]);
        if ($validated['max_per_day'] < $validated['max_per_request']) {
            throw ValidationException::withMessages([
                'max_per_day' => 'سقف روزانه باید حداقل به‌اندازهٔ سقف هر درخواست باشد.',
            ]);
        }

        $runtimeSettings->save('wallet_withdrawals', [
            'enabled' => (bool) $validated['enabled'],
            'max_per_request' => (int) $validated['max_per_request'],
            'max_per_day' => (int) $validated['max_per_day'],
        ], $request->user());

        return to_route('admin.settings.section', ['section' => 'wallet'])->with('status', 'تنظیمات کیف پول ذخیره شد.');
    }

    public function updateGateways(
        Request $request,
        PaymentGatewayManager $gateways,
        PaymentGatewaySettingsService $gatewaySettings,
    ): RedirectResponse {
        abort_unless($request->user()->can('manage payment gateways'), 403);
        $gatewaySettings->update($request, $gateways);

        return to_route('admin.settings.section', ['section' => 'gateways'])->with('status', 'تنظیمات درگاه پرداخت ذخیره شد.');
    }

    public function updateSiteContent(Request $request, AdminRuntimeSettings $runtimeSettings): RedirectResponse
    {
        abort_unless($request->user()->can('manage site content'), 403);
        $validated = $request->validate([
            'brand_name' => ['required', 'string', 'max:80'],
            'tagline' => ['required', 'string', 'max:120'],
            'meta_description' => ['required', 'string', 'max:300'],
            'hero_badge' => ['required', 'string', 'max:120'],
            'hero_title' => ['required', 'string', 'max:120'],
            'hero_title_highlight' => ['required', 'string', 'max:120'],
            'hero_description' => ['required', 'string', 'max:500'],
        ]);

        $runtimeSettings->save('site_content', $validated, $request->user());

        return to_route('admin.settings.section', ['section' => 'site-content'])->with('status', 'محتوای اصلی سایت ذخیره شد.');
    }
}
