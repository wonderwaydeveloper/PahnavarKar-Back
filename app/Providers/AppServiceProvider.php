<?php

namespace App\Providers;

use App\Services\Auth\LocalLogSmsProvider;
use App\Services\Auth\MelipayamakSmsProvider;
use App\Services\Auth\PhoneNumber;
use App\Services\Auth\SmsProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('local') && config('services.melipayamak.local_log_enabled')) {
            $this->app->bind(SmsProvider::class, LocalLogSmsProvider::class);

            return;
        }

        $this->app->bind(SmsProvider::class, MelipayamakSmsProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('otp-send', function (Request $request): array {
            $phone = PhoneNumber::forRateLimit($request->input('phone'));
            $ip = (string) $request->ip();
            $deviceId = $request->header('X-Device-Id');

            $limits = [
                Limit::perMinute(2)->by('otp-send:phone-minute:'.$phone),
                Limit::perHour(5)->by('otp-send:phone-hour:'.$phone),
                Limit::perDay(10)->by('otp-send:phone-day:'.$phone),
                Limit::perMinute(10)->by('otp-send:ip-minute:'.$ip),
                Limit::perHour(30)->by('otp-send:ip-hour:'.$ip),
            ];

            if (is_string($deviceId) && $deviceId !== '') {
                $limits[] = Limit::perHour(10)->by('otp-send:device:'.hash('sha256', $deviceId));
            }

            return $limits;
        });

        RateLimiter::for('otp-verify', function (Request $request): array {
            $phone = PhoneNumber::forRateLimit($request->input('phone'));
            $ip = (string) $request->ip();
            $deviceId = $request->header('X-Device-Id');

            $limits = [
                Limit::perMinute(5)->by('otp-verify:phone-minute:'.$phone),
                Limit::perHour(20)->by('otp-verify:phone-hour:'.$phone),
                Limit::perMinute(20)->by('otp-verify:ip-minute:'.$ip),
                Limit::perHour(60)->by('otp-verify:ip-hour:'.$ip),
            ];

            if (is_string($deviceId) && $deviceId !== '') {
                $limits[] = Limit::perHour(30)->by('otp-verify:device:'.hash('sha256', $deviceId));
            }

            return $limits;
        });
    }
}
