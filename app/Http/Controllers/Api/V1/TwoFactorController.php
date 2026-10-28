<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TotpService;
use App\Services\Auth\TwoFactorLoginService;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'enabled' => $user->two_factor_enabled_at !== null,
            'enabled_at' => $user->two_factor_enabled_at?->toISOString(),
            'recovery_codes_remaining' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }

    public function begin(Request $request, TotpService $totp): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->two_factor_enabled_at !== null, 409, 'تأیید دومرحله‌ای از قبل فعال است.');

        return response()->json($totp->beginEnrollment($user));
    }

    public function confirm(Request $request, TotpService $totp, ExpoPushService $pushNotifications): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);
        /** @var User $user */
        $user = $request->user();
        abort_if($user->two_factor_enabled_at !== null, 409, 'تأیید دومرحله‌ای از قبل فعال است.');
        $recoveryCodes = $totp->confirmEnrollment($user, $data['code']);
        $pushNotifications->sendToUser(
            $user,
            'تأیید دومرحله‌ای فعال شد',
            'تأیید دومرحله‌ای برای حساب شما فعال شد. اگر این تغییر را انجام نداده‌اید، حساب‌تان را بررسی کنید.',
            ['event' => 'security.two_factor_enabled'],
        );

        return response()->json([
            'enabled' => true,
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function disable(Request $request, TotpService $totp, ExpoPushService $pushNotifications): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $totp->disable($user, $data['code']);
        $pushNotifications->sendToUser(
            $user,
            'تأیید دومرحله‌ای غیرفعال شد',
            'تأیید دومرحله‌ای برای حساب شما غیرفعال شد. اگر این تغییر را انجام نداده‌اید، حساب‌تان را بررسی کنید.',
            ['event' => 'security.two_factor_disabled'],
        );

        return response()->json(['enabled' => false]);
    }

    public function verifyLogin(Request $request, TwoFactorLoginService $login): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'min:6', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        $session = $login->complete($data['challenge_token'], $data['code']);

        return response()->json([
            'status' => 'authenticated',
            'token' => $session['token'],
            'token_type' => 'Bearer',
            'expires_at' => $session['expires_at']?->toISOString(),
            'user' => $session['user'],
        ]);
    }
}
