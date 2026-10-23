<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MelipayamakSmsProvider implements SmsProvider
{
    public function sendOtp(string $phone, string $code): void
    {
        $this->sendTemplate($phone, config('services.melipayamak.otp_body_id'), [$code]);
    }

    public function sendInactivityDeletionWarning(string $phone, string $userName, int $graceDays): void
    {
        $bodyId = config('services.melipayamak.inactivity_warning_body_id');
        if (! is_string($bodyId) || $bodyId === '') {
            throw new SmsDeliveryException('Melipayamak inactivity warning template is not configured.');
        }

        $this->sendTemplate(
            $phone,
            $bodyId,
            [$userName, (string) $graceDays],
        );
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function sendTemplate(string $phone, mixed $bodyId, array $arguments): void
    {
        if (! config('services.melipayamak.enabled')) {
            throw new SmsDeliveryException('SMS delivery is temporarily disabled.');
        }

        $url = config('services.melipayamak.url');

        if (! is_string($url) || ! str_starts_with($url, 'https://console.melipayamak.com/api/send/shared/')) {
            throw new SmsDeliveryException('Melipayamak shared API URL is not configured.');
        }

        if (! is_string($bodyId) || $bodyId === '') {
            throw new SmsDeliveryException('Melipayamak OTP template is not configured.');
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post($url, [
                    'bodyId' => $bodyId,
                    'to' => '0'.substr($phone, 3),
                    'args' => $arguments,
                ]);
        } catch (ConnectionException $exception) {
            throw new SmsDeliveryException('Could not connect to Melipayamak.', 0, $exception);
        }

        $success = $response->json('success');

        if (! $response->successful() || $success !== true) {
            throw new SmsDeliveryException('Melipayamak did not accept the OTP message.');
        }
    }
}
