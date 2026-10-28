<?php

namespace App\Services\Auth;

class InactivityWarningDeliveryConfiguration
{
    public function canDeliverWarnings(): bool
    {
        return $this->smsIsConfigured();
    }

    public function smsIsConfigured(): bool
    {
        $url = config('services.melipayamak.url');
        $template = config('services.melipayamak.inactivity_warning_body_id');

        return (bool) config('services.melipayamak.enabled')
            && is_string($url)
            && str_starts_with($url, 'https://console.melipayamak.com/api/send/shared/')
            && is_string($template)
            && $template !== '';
    }
}
