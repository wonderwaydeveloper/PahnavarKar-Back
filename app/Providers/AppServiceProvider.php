<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Auth\LocalLogSmsProvider;
use App\Services\Auth\MelipayamakSmsProvider;
use App\Services\Auth\PhoneNumber;
use App\Services\Auth\SmsProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        Gate::define('viewApiDocs', static fn (?User $user): bool => app()->environment('local') || $user?->canAccessAdminPanel() === true,
        );

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

        RateLimiter::for('sensitive-api', function (Request $request): array {
            $ip = (string) $request->ip();
            $route = $request->route()?->uri() ?? 'unknown';
            $routeKey = hash('sha256', $request->method().':'.$route);
            $isReadOnly = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
            $limits = [
                Limit::perMinute(120)->by('sensitive-api:ip:'.$ip),
                Limit::perMinute($isReadOnly ? 120 : 30)->by('sensitive-api:route:ip:'.$routeKey.':'.$ip),
            ];
            $userId = $request->user()?->getAuthIdentifier();

            if ($userId !== null) {
                $userKey = (string) $userId;
                $limits[] = Limit::perMinute(120)->by('sensitive-api:user:'.$userKey);
                $limits[] = Limit::perMinute($isReadOnly ? 60 : 10)
                    ->by('sensitive-api:route:user:'.$routeKey.':'.$userKey);
            }

            return $limits;
        });

        RateLimiter::for('admin-login-send', function (Request $request): array {
            $phone = PhoneNumber::forRateLimit(
                $request->input('phone', $request->session()->get('admin_login_phone')),
            );
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(2)->by('admin-login-send:phone-minute:'.$phone),
                Limit::perHour(5)->by('admin-login-send:phone-hour:'.$phone),
                Limit::perMinute(10)->by('admin-login-send:ip-minute:'.$ip),
                Limit::perHour(30)->by('admin-login-send:ip-hour:'.$ip),
            ];
        });

        RateLimiter::for('admin-login-verify', function (Request $request): array {
            $phone = PhoneNumber::forRateLimit($request->session()->get('admin_login_phone'));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('admin-login-verify:phone-minute:'.$phone),
                Limit::perHour(20)->by('admin-login-verify:phone-hour:'.$phone),
                Limit::perMinute(20)->by('admin-login-verify:ip-minute:'.$ip),
                Limit::perHour(60)->by('admin-login-verify:ip-hour:'.$ip),
            ];
        });
    }
}
