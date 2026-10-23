<?php

namespace App\Services\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DeviceLimitExceededException extends RuntimeException
{
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'message' => 'تعداد دستگاه‌های فعال این حساب به حد مجاز رسیده است.',
        ], 409);
    }
}
