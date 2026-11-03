<?php

namespace App\Services\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Sentry\Laravel\Facade as Sentry;

class SmsDeliveryException extends RuntimeException
{
    public function report(): void
    {
        Log::warning('SMS delivery failed.', [
            'exception_class' => self::class,
            'cause_class' => $this->getPrevious() ? $this->getPrevious()::class : null,
        ]);

        Sentry::captureException(new RuntimeException('SMS delivery failed.'));
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'message' => 'ارسال پیامک انجام نشد. تنظیمات سرویس پیامک را بررسی و دوباره تلاش کنید.',
        ], 503);
    }
}
