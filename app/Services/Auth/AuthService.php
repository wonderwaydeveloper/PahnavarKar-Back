<?php

namespace App\Services\Auth;

use App\Models\Otp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly DeviceService $deviceService,
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
        ?string $email,
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
            $email,
        ): array {
            $otp = Otp::query()
                ->where('phone', $phone)
                ->where('type', $type)
                ->where('used', false)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                return ['error' => 'invalid'];
            }

            if ($otp->expires_at->isPast()) {
                $otp->update(['used' => true]);

                return ['error' => 'expired'];
            }

            if (! Hash::check($code, $otp->code_hash)) {
                $otp->increment('attempts');

                return ['error' => 'invalid'];
            }

            $user = User::query()->where('phone', $phone)->lockForUpdate()->first();

            if ($type === 'register') {
                if ($user || ($email !== null && User::query()
                    ->where(fn ($query) => $query
                        ->where('email', $email)
                        ->orWhere('pending_email', $email))
                    ->exists())) {
                    $otp->update(['used' => true]);

                    return ['error' => 'already_registered'];
                }

                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'phone_verified_at' => now(),
                    'password' => Str::random(64),
                ]);
            } elseif (! $user) {
                if ($name === null) {
                    return ['status' => 'profile_required'];
                }

                if ($email !== null && User::query()
                    ->where(fn ($query) => $query
                        ->where('email', $email)
                        ->orWhere('pending_email', $email))
                    ->exists()) {
                    return ['error' => 'email_taken'];
                }

                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'phone_verified_at' => now(),
                    'password' => Str::random(64),
                ]);
            }

            if (! $user) {
                $otp->update(['used' => true]);

                return ['error' => 'invalid'];
            }

            if ($user->phone_verified_at === null) {
                $user->forceFill(['phone_verified_at' => now()])->save();
            }

            $otp->update(['used' => true]);

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

        if (isset($result['error'])) {
            if (in_array($result['error'], ['invalid'], true)) {
                $this->otpService->recordFailedVerification($phone, $type);
            } else {
                $this->otpService->clearFailedVerifications($phone, $type);
            }

            $message = $result['error'] === 'expired'
                ? 'کد تأیید منقضی شده است. کد تازه درخواست کنید.'
                : match ($result['error']) {
                    'already_registered' => 'این شماره یا ایمیل قبلاً ثبت شده است. برای ورود اقدام کنید.',
                    'email_taken' => 'این ایمیل قبلاً ثبت شده است. ایمیل دیگری وارد کنید یا آن را خالی بگذارید.',
                    default => 'کد تأیید معتبر نیست.',
                };

            throw ValidationException::withMessages([
                ($result['error'] === 'email_taken' ? 'email' : 'code') => $message,
            ]);
        }

        $this->otpService->clearFailedVerifications($phone, $type);

        return $result['session'];
    }
}
