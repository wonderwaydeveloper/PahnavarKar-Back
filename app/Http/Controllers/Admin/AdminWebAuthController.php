<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Otp;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Auth\PhoneNumber;
use App\Services\Auth\SmsDeliveryException;
use App\Services\Auth\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AdminWebAuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly TotpService $totpService,
    ) {}

    public function showLogin(Request $request): InertiaResponse|RedirectResponse
    {
        if ($request->user('web')?->canAccessAdminPanel()) {
            return redirect()->route('admin.dashboard');
        }

        return Inertia::render('Admin/Auth/Login');
    }

    public function sendOtp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
        ]);
        $phone = PhoneNumber::normalize($data['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'شمارهٔ موبایل معتبر وارد کنید.',
            ]);
        }

        $user = $this->eligibleAdmin($phone);
        if ($user) {
            try {
                $this->otpService->send($phone, 'login');
            } catch (SmsDeliveryException $exception) {
                report($exception);

                return back()
                    ->withInput(['phone' => $data['phone']])
                    ->withErrors(['phone' => 'ارسال پیامک در حال حاضر ممکن نیست. کمی بعد دوباره تلاش کنید.']);
            }
        }

        $request->session()->put('admin_login_phone', $phone);

        return redirect()->route('admin.login.verify')
            ->with('status', 'اگر این شماره به حساب مدیر مجاز متصل باشد، کد ورود ارسال شده است.');
    }

    public function showVerify(Request $request): InertiaResponse|RedirectResponse
    {
        if (! is_string($request->session()->get('admin_login_phone'))) {
            return redirect()->route('admin.login');
        }

        return Inertia::render('Admin/Auth/Verify');
    }

    public function showTwoFactor(Request $request): InertiaResponse|RedirectResponse
    {
        $userId = $request->session()->get('admin_two_factor_user_id');
        $expiresAt = $request->session()->get('admin_two_factor_expires_at');
        if (! is_int($userId) || ! is_int($expiresAt) || $expiresAt < now()->timestamp) {
            $request->session()->forget([
                'admin_two_factor_user_id',
                'admin_two_factor_expires_at',
                'admin_two_factor_attempts',
            ]);

            return redirect()->route('admin.login');
        }

        return Inertia::render('Admin/Auth/TwoFactor');
    }

    public function verifyTwoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        $userId = $request->session()->get('admin_two_factor_user_id');
        $expiresAt = $request->session()->get('admin_two_factor_expires_at');
        if (! is_int($userId) || ! is_int($expiresAt) || $expiresAt < now()->timestamp) {
            $request->session()->forget([
                'admin_two_factor_user_id',
                'admin_two_factor_expires_at',
                'admin_two_factor_attempts',
            ]);

            return redirect()->route('admin.login')->withErrors([
                'code' => 'درخواست ورود دومرحله‌ای منقضی شده است. دوباره وارد شوید.',
            ]);
        }

        $user = DB::transaction(function () use ($data, $userId): ?User {
            $user = User::query()->lockForUpdate()->find($userId);
            if (
                ! $user
                || ! $user->is_active
                || ! $user->canAccessAdminPanel()
                || ! $this->totpService->verifyAndConsume($user, $data['code'])
            ) {
                return null;
            }

            return $user;
        });

        if (! $user) {
            $attempts = (int) $request->session()->get('admin_two_factor_attempts', 0) + 1;
            if ($attempts >= 5) {
                $request->session()->forget([
                    'admin_two_factor_user_id',
                    'admin_two_factor_expires_at',
                    'admin_two_factor_attempts',
                ]);
            } else {
                $request->session()->put('admin_two_factor_attempts', $attempts);
            }

            throw ValidationException::withMessages([
                'code' => $attempts >= 5
                    ? 'تعداد تلاش‌های ناموفق به پایان رسید؛ دوباره وارد شوید.'
                    : 'کد برنامهٔ احراز هویت یا کد بازیابی معتبر نیست.',
            ]);
        }

        $request->session()->forget([
            'admin_two_factor_user_id',
            'admin_two_factor_expires_at',
            'admin_two_factor_attempts',
            'admin_login_phone',
        ]);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->merge([
            'code' => is_string($request->input('code'))
                ? strtr($request->input('code'), [
                    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                    '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                    '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                ])
                : $request->input('code'),
        ]);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);
        $phone = $request->session()->get('admin_login_phone');
        if (! is_string($phone)) {
            return redirect()->route('admin.login');
        }

        $lockSeconds = $this->otpService->availableLockSeconds($phone, 'login');
        if ($lockSeconds > 0) {
            throw ValidationException::withMessages([
                'code' => "تعداد تلاش‌های ناموفق زیاد است. {$lockSeconds} ثانیه دیگر تلاش کنید.",
            ]);
        }

        $result = DB::transaction(function () use ($phone, $data): array {
            $otp = Otp::lockLatestUnusedForVerification($phone, 'login');

            if (! $otp) {
                return ['error' => 'invalid'];
            }

            if ($otp->isExpired()) {
                $otp->update(['used' => true]);

                return ['error' => 'expired'];
            }

            if (! Hash::check($data['code'], $otp->code_hash)) {
                $otp->increment('attempts');

                return ['error' => 'invalid'];
            }

            $user = User::query()->where('phone', $phone)->lockForUpdate()->first();
            if (! $user || ! $user->is_active || ! $user->canAccessAdminPanel()) {
                $otp->update(['used' => true]);

                return ['error' => 'unauthorized'];
            }

            if (! $user->phone_verified_at) {
                $user->forceFill(['phone_verified_at' => now()])->save();
            }

            $otp->update(['used' => true]);

            return ['user' => $user];
        });

        if (isset($result['error'])) {
            if ($result['error'] === 'invalid') {
                $this->otpService->recordFailedVerification($phone, 'login');
            } elseif ($result['error'] !== 'expired') {
                $this->otpService->clearFailedVerifications($phone, 'login');
            }

            if ($result['error'] === 'unauthorized') {
                $request->session()->forget('admin_login_phone');

                return redirect()->route('admin.login')
                    ->withErrors(['phone' => 'ورود به پنل با این شماره امکان‌پذیر نیست.']);
            }

            throw ValidationException::withMessages([
                'code' => $result['error'] === 'expired'
                    ? 'کد ورود منقضی شده است. کد تازه درخواست کنید.'
                    : 'کد ورود معتبر نیست.',
            ]);
        }

        $this->otpService->clearFailedVerifications($phone, 'login');
        $request->session()->forget('admin_login_phone');
        if ($result['user']->two_factor_enabled_at !== null) {
            $request->session()->put([
                'admin_two_factor_user_id' => $result['user']->getKey(),
                'admin_two_factor_expires_at' => now()->addMinutes(5)->timestamp,
                'admin_two_factor_attempts' => 0,
            ]);

            return redirect()->route('admin.login.two-factor');
        }

        Auth::guard('web')->login($result['user']);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function resendOtp(Request $request): RedirectResponse
    {
        $phone = $request->session()->get('admin_login_phone');
        if (! is_string($phone)) {
            return redirect()->route('admin.login');
        }

        if ($this->eligibleAdmin($phone)) {
            try {
                $this->otpService->send($phone, 'login');
            } catch (SmsDeliveryException $exception) {
                report($exception);

                return back()->withErrors(['code' => 'ارسال پیامک در حال حاضر ممکن نیست. کمی بعد دوباره تلاش کنید.']);
            }
        }

        return back()->with('status', 'اگر این شماره به حساب مدیر مجاز متصل باشد، کد تازه ارسال شده است.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function eligibleAdmin(string $phone): ?User
    {
        $user = User::query()
            ->where('phone', $phone)
            ->where('is_active', true)
            ->first();

        return $user?->canAccessAdminPanel() ? $user : null;
    }
}
