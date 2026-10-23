<?php

namespace App\Services\Auth;

class InactivityWarningDeliveryConfiguration
{
    public function canDeliverWarnings(): bool
    {
        return $this->emailIsConfigured() && $this->smsIsConfigured();
    }

    public function emailIsConfigured(): bool
    {
        $mailer = config('mail.default');

        return is_string($mailer) && $this->mailerCanDeliver($mailer);
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

    private function mailerCanDeliver(string $name, array $visited = []): bool
    {
        if (in_array($name, $visited, true)) {
            return false;
        }

        $mailer = config("mail.mailers.{$name}");
        if (! is_array($mailer) || ! is_string($mailer['transport'] ?? null)) {
            return false;
        }

        $transport = $mailer['transport'];
        if (in_array($transport, ['log', 'array'], true)) {
            return false;
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $mailers = $mailer['mailers'] ?? null;
            if (! is_array($mailers) || $mailers === []) {
                return false;
            }

            $visited[] = $name;
            foreach ($mailers as $fallbackMailer) {
                if (! is_string($fallbackMailer) || ! $this->mailerCanDeliver($fallbackMailer, $visited)) {
                    return false;
                }
            }
        }

        return true;
    }
}
