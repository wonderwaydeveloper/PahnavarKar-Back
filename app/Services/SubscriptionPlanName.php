<?php

namespace App\Services;

use App\Services\Admin\AdminRuntimeSettings;

class SubscriptionPlanName
{
    public function __construct(private readonly AdminRuntimeSettings $runtimeSettings) {}

    public function forKey(string $planKey): string
    {
        [$tierKey, $durationKey] = array_pad(explode('_', $planKey, 2), 2, null);
        $catalog = $this->runtimeSettings->plans();
        $tierName = $catalog['tiers'][$tierKey]['name'] ?? 'اشتراکی';
        $durationName = $durationKey !== null
            ? ($catalog['durations'][$durationKey]['name'] ?? null)
            : null;

        return $durationName === null
            ? $tierName
            : "{$tierName} {$durationName}";
    }
}
