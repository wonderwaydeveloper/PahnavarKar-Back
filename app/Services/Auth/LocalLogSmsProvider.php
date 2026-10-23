<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Log;

class LocalLogSmsProvider implements SmsProvider
{
    public function sendOtp(string $phone, string $code): void
    {
        if (! app()->environment('local') || ! config('services.melipayamak.local_log_enabled')) {
            throw new SmsDeliveryException('Local OTP logging is only available when explicitly enabled locally.');
        }

        Log::info('Local OTP generated for authentication testing.', [
            'phone_last4' => substr($phone, -4),
            'code' => $code,
        ]);
    }

    public function sendInactivityDeletionWarning(string $phone, string $userName, int $graceDays): void
    {
        if (! app()->environment('local') || ! config('services.melipayamak.local_log_enabled')) {
            throw new SmsDeliveryException('Local SMS logging is only available when explicitly enabled locally.');
        }

        Log::info('Local account inactivity warning generated.', [
            'phone_last4' => substr($phone, -4),
            'grace_days' => $graceDays,
        ]);
    }
}
