<?php

namespace App\Services\Admin;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

class AdminRuntimeSettings
{
    public function plans(): array
    {
        $defaults = config('plans', []);
        $saved = array_replace_recursive($defaults, $this->get('plans', $defaults));
        $featureKeys = array_keys($defaults['features']);
        $tiers = [];

        foreach ($defaults['tiers'] as $key => $tier) {
            $savedFeatures = $saved['tiers'][$key]['features'] ?? [];
            $features = [];
            foreach ($featureKeys as $featureKey) {
                $features[$featureKey] = (bool) ($savedFeatures[$featureKey] ?? false);
            }
            $tiers[$key] = ['name' => $tier['name'], 'features' => $features];
        }

        $offers = [];
        foreach ($defaults['offers'] as $key => $offer) {
            $offers[$key] = [
                ...$offer,
                'price' => max(0, (int) ($saved['offers'][$key]['price'] ?? $offer['price'])),
            ];
        }

        return [
            'features' => $defaults['features'],
            'tiers' => $tiers,
            'durations' => $defaults['durations'],
            'offers' => $offers,
        ];
    }

    public function walletWithdrawals(): array
    {
        return array_replace_recursive(
            config('wallet.withdrawals', []),
            $this->get('wallet_withdrawals', config('wallet.withdrawals', [])),
        );
    }

    public function siteContent(): array
    {
        return array_replace(
            config('site', []),
            $this->get('site_content', config('site', [])),
        );
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function save(string $key, array $value, User $user): void
    {
        app(CauserResolver::class)->withCauser($user, function () use ($key, $value, $user): void {
            DB::transaction(function () use ($key, $value, $user): void {
                AdminSetting::query()->updateOrCreate(
                    ['setting_key' => $key],
                    ['value' => $value, 'updated_by' => $user->getKey()],
                );
            });
        });

        Cache::forget($this->cacheKey($key));
    }

    /**
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    private function get(string $key, array $default): array
    {
        return Cache::remember(
            $this->cacheKey($key),
            now()->addMinutes(5),
            fn (): array => AdminSetting::query()
                ->where('setting_key', $key)
                ->first()?->value ?? $default,
        );
    }

    private function cacheKey(string $key): string
    {
        return 'admin-runtime-settings:v2:'.$key;
    }
}
