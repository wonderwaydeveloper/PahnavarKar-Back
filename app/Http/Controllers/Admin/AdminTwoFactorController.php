<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TotpService;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminTwoFactorController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAccessAdminPanel(), 403);

        return Inertia::render('Admin/Profile/TwoFactor', [
            'twoFactorEnabled' => $user->two_factor_enabled_at !== null,
            'recoveryCodesRemaining' => count($user->two_factor_recovery_codes ?? []),
            'enrollment' => null,
            'recoveryCodes' => [],
        ]);
    }

    public function begin(Request $request, TotpService $totp): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAccessAdminPanel(), 403);
        abort_if($user->two_factor_enabled_at !== null, 409, 'تأیید دومرحله‌ای از قبل فعال است.');

        return Inertia::render('Admin/Profile/TwoFactor', [
            'twoFactorEnabled' => false,
            'recoveryCodesRemaining' => 0,
            'enrollment' => $totp->beginEnrollment($user),
            'recoveryCodes' => [],
        ]);
    }

    public function confirm(Request $request, TotpService $totp, ExpoPushService $pushNotifications): Response
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAccessAdminPanel(), 403);

        $recoveryCodes = $totp->confirmEnrollment($user, $data['code']);
        $pushNotifications->sendToUser(
            $user,
            'تأیید دومرحله‌ای فعال شد',
            'تأیید دومرحله‌ای برای حساب مدیریتی شما فعال شد.',
            ['event' => 'security.two_factor_enabled'],
        );

        return Inertia::render('Admin/Profile/TwoFactor', [
            'twoFactorEnabled' => true,
            'recoveryCodesRemaining' => count($recoveryCodes),
            'enrollment' => null,
            'recoveryCodes' => $recoveryCodes,
        ]);
    }

    public function disable(Request $request, TotpService $totp, ExpoPushService $pushNotifications): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAccessAdminPanel(), 403);

        $totp->disable($user, $data['code']);
        $pushNotifications->sendToUser(
            $user,
            'تأیید دومرحله‌ای غیرفعال شد',
            'تأیید دومرحله‌ای برای حساب مدیریتی شما غیرفعال شد.',
            ['event' => 'security.two_factor_disabled'],
        );

        return to_route('admin.profile.two-factor')->with('status', 'تأیید دومرحله‌ای غیرفعال شد.');
    }
}
