<?php

namespace App\Services\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SmsDeliveryException extends RuntimeException
{
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
