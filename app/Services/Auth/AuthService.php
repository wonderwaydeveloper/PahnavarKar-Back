<?php

namespace App\Services\Auth;

use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\UserRoleChangeAuditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly DeviceService $deviceService,
        private readonly TwoFactorLoginService $twoFactorLogin,
    ) {}

    public function verify(
        string $phone,
        string $type,
        string $code,
        string $deviceId,
        ?string $deviceName,
        ?string $deviceModel,
        ?string $pushToken,
        ?string $platform,
        ?string $name,
    ): array {
        $lockSeconds = $this->otpService->availableLockSeconds($phone, $type);

        if ($lockSeconds > 0) {
            abort(429, "تعداد تلاش‌های ناموفق زیاد است. {$lockSeconds} ثانیه دیگر تلاش کنید.");
        }

        $result = DB::transaction(function () use (
            $phone,
            $type,
            $code,
            $deviceId,
            $deviceName,
            $deviceModel,
            $pushToken,
            $platform,
            $name,
        ): array {
            $otp = Otp::lockLatestUnusedForVerification($phone, $type);

            if (! $otp) {
                return ['error' => 'invalid'];
            }

            if ($otp->isExpired()) {
                $otp->update(['used' => true]);

                return ['error' => 'expired'];
            }

            if (! Hash::check($code, $otp->code_hash)) {
                $otp->increment('attempts');

                return ['error' => 'invalid'];
            }

            $user = User::query()->where('phone', $phone)->lockForUpdate()->first();

            if ($type === 'register') {
                if ($user) {
                    $otp->update(['used' => true]);

                    return ['error' => 'already_registered'];
                }

                $user = new User([
                    'name' => $name,
                    'phone' => $phone,
                    'phone_verified_at' => now(),
                    'password' => Str::random(64),
                ]);
                $user->forceFill(['is_active' => true])->saveOrFail();
                $userRole = Role::findOrCreate('User', 'web');
                $user->assignRole($userRole);
                app(UserRoleChangeAuditor::class)->record(
                    $user,
                    [],
                    [$userRole->name],
                    null,
                    'otp:registration',
                );
            } elseif (! $user) {
                if ($name === null) {
                    return ['status' => 'profile_required'];
                }

                $user = new User([
                    'name' => $name,
                    'phone' => $phone,
                    'phone_verified_at' => now(),
                    'password' => Str::random(64),
                ]);
                $user->forceFill(['is_active' => true])->saveOrFail();
                $userRole = Role::findOrCreate('User', 'web');
                $user->assignRole($userRole);
                app(UserRoleChangeAuditor::class)->record(
                    $user,
                    [],
                    [$userRole->name],
                    null,
                    'otp:registration',
                );
            }

            if (! $user) {
                $otp->update(['used' => true]);

                return ['error' => 'invalid'];
            }

            if (! $user->is_active) {
                $otp->update(['used' => true]);

                return ['error' => 'account_disabled'];
            }

            if ($user->phone_verified_at === null) {
                $user->forceFill(['phone_verified_at' => now()])->save();
            }

            $otp->update(['used' => true]);

            if ($user->two_factor_enabled_at !== null) {
                return [
                    'two_factor_challenge' => $this->twoFactorLogin->createChallenge(
                        $user,
                        $deviceId,
                        $deviceName,
                        $deviceModel,
                        $platform,
                        $pushToken,
                    ),
                ];
            }

            $session = $this->deviceService->register(
                $user,
                $deviceId,
                $deviceName,
                $deviceModel,
                $pushToken,
                $platform,
            );

            return ['session' => $session];
        });

        if (($result['status'] ?? null) === 'profile_required') {
            $this->otpService->clearFailedVerifications($phone, $type);

            return $result;
        }

        if (isset($result['two_factor_challenge'])) {
            $this->otpService->clearFailedVerifications($phone, $type);

            return $result['two_factor_challenge'];
        }

        if (isset($result['error'])) {
            if (in_array($result['error'], ['invalid'], true)) {
                $this->otpService->recordFailedVerification($phone, $type);
            } elseif ($result['error'] !== 'expired') {
                $this->otpService->clearFailedVerifications($phone, $type);
            }

            $message = $result['error'] === 'expired'
                ? 'کد تأیید منقضی شده است. کد تازه درخواست کنید.'
                : match ($result['error']) {
                    'already_registered' => 'این شماره قبلاً ثبت شده است. برای ورود اقدام کنید.',
                    'account_disabled' => 'دسترسی این حساب غیرفعال شده است.',
                    default => 'کد تأیید معتبر نیست.',
                };

            throw ValidationException::withMessages([
                'code' => $message,
            ]);
        }

        $this->otpService->clearFailedVerifications($phone, $type);

        return $result['session'];
    }
}
