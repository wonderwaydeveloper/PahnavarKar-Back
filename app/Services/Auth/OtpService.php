<?php

namespace App\Services\Auth;

use App\Models\Otp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private readonly SmsProvider $smsProvider) {}

    public function send(string $phone, string $type, ?string $email = null): void
    {
        $user = User::query()->where('phone', $phone)->first();

        if ($type === 'register') {
            if ($user || ($email !== null && User::query()->where('email', $email)->exists())) {
                throw ValidationException::withMessages([
                    'phone' => 'این شماره یا ایمیل قبلاً ثبت شده است. برای ورود، گزینهٔ ورود را انتخاب کنید.',
                ]);
            }
        }

        $code = (string) random_int(100000, 999999);
        $otp = DB::transaction(function () use ($phone, $type, $user, $code): Otp {
            Otp::query()
                ->where('phone', $phone)
                ->where('type', $type)
                ->where('used', false)
                ->update(['used' => true]);

            return Otp::query()->create([
                'user_id' => $user?->id,
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'type' => $type,
                'expires_at' => now()->addMinutes(5),
            ]);
        });

        try {
            $this->smsProvider->sendOtp($phone, $code);
        } catch (SmsDeliveryException $exception) {
            $otp->update(['used' => true]);

            throw $exception;
        }
    }

    public function recordFailedVerification(string $phone, string $type): void
    {
        $key = $this->attemptKey($phone, $type);
        $attempts = RateLimiter::hit($key, 86400);

        $lockSeconds = match (true) {
            $attempts >= 10 => 86400,
            $attempts >= 6 => 3600,
            $attempts >= 3 => 300,
            default => 0,
        };

        if ($lockSeconds > 0) {
            RateLimiter::hit($this->lockKey($phone, $type), $lockSeconds);
        }
    }

    public function availableLockSeconds(string $phone, string $type): int
    {
        return RateLimiter::availableIn($this->lockKey($phone, $type));
    }

    public function clearFailedVerifications(string $phone, string $type): void
    {
        RateLimiter::clear($this->attemptKey($phone, $type));
        RateLimiter::clear($this->lockKey($phone, $type));
    }

    private function attemptKey(string $phone, string $type): string
    {
        return 'otp-attempts:'.hash('sha256', $phone.':'.$type);
    }

    private function lockKey(string $phone, string $type): string
    {
        return 'otp-lock:'.hash('sha256', $phone.':'.$type);
    }
}
