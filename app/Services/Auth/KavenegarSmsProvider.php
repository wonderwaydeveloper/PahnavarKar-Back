<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class KavenegarSmsProvider implements ConfigurableSmsProvider
{
    public function isConfigured(): bool
    {
        return config('services.kavenegar.enabled') === true
            && is_string(config('services.kavenegar.api_key'))
            && config('services.kavenegar.api_key') !== '';
    }

    public function sendOtp(string $phone, string $code): void
    {
        $this->send($phone, "کد تأیید شما: {$code}");
    }

    public function sendInactivityDeletionWarning(string $phone, string $userName, int $graceDays): void
    {
        $this->send(
            $phone,
            "{$userName} عزیز، در صورت عدم ورود تا {$graceDays} روز آینده، حساب شما طبق تنظیم حذف خودکار حذف خواهد شد.",
        );
    }

    public function canSendTestMessage(): bool
    {
        return $this->isConfigured();
    }

    public function sendTestMessage(string $phone): void
    {
        $this->send($phone, 'پیام آزمایشی سرویس پیامک پهناورکار؛ نیازی به پاسخ نیست.');
    }

    private function send(string $phone, string $message): void
    {
        if (! $this->isConfigured()) {
            throw new SmsDeliveryException('Kavenegar SMS delivery is not configured.');
        }

        $apiKey = config('services.kavenegar.api_key');
        $url = 'https://api.kavenegar.com/v1/'.rawurlencode($apiKey).'/sms/send.json';
        $payload = [
            'receptor' => '0'.substr($phone, 3),
            'message' => $message,
        ];
        $sender = config('services.kavenegar.sender');
        if (is_string($sender) && $sender !== '') {
            $payload['sender'] = $sender;
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->post($url, $payload);
        } catch (ConnectionException) {
            throw new SmsDeliveryException('Could not connect to Kavenegar.');
        }

        $status = $response->json('return.status');
        $entries = $response->json('entries');
        $messageStatus = is_array($entries) && isset($entries[0]['status'])
            ? $entries[0]['status']
            : null;

        if (
            ! $response->successful()
            || $status !== 200
            || ! is_int($messageStatus)
            || ! in_array($messageStatus, [1, 2, 4, 5], true)
        ) {
            throw new SmsDeliveryException('Kavenegar did not accept the SMS message.');
        }
    }
}
