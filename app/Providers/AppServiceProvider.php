<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Auth\PhoneNumber;
use App\Services\Auth\SmsProvider;
use App\Services\Auth\SmsProviderManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsProviderManager::class);
        $this->app->bind(SmsProvider::class, SmsProviderManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->environment('local', 'testing')) {
            $limiterStore = config('cache.limiter') ?? config('cache.default');
            $limiterDriver = is_string($limiterStore)
                ? config('cache.stores.'.$limiterStore.'.driver')
                : null;

            if (! in_array($limiterDriver, ['database', 'redis', 'memcached', 'dynamodb'], true)) {
                throw new \LogicException(
                    'Rate limiting requires a shared atomic cache store in non-local environments.'
                );
            }
        }

        Gate::define('viewApiDocs', static fn (?User $user): bool => app()->environment('local') || $user?->canAccessAdminPanel() === true,
        );

        RateLimiter::for('otp-send', function (Request $request): array {
            $phoneInput = $request->input('phone');
            if (
                ! is_string($phoneInput)
                && $request->routeIs('admin.login.resend')
                && $request->hasSession()
            ) {
                $phoneInput = $request->session()->get('admin_login_phone');
            }
            $phone = PhoneNumber::forRateLimit($phoneInput);
            $ip = (string) $request->ip();
            $deviceId = $request->header('X-Device-Id');

            $limits = [
                Limit::perMinute(2)->by('otp-send:phone-minute:'.$phone),
                Limit::perHour(5)->by('otp-send:phone-hour:'.$phone),
                Limit::perDay(10)->by('otp-send:phone-day:'.$phone),
                Limit::perMinute(10)->by('otp-send:ip-minute:'.$ip),
                Limit::perHour(30)->by('otp-send:ip-hour:'.$ip),
            ];

            if (is_string($deviceId) && Str::isUuid($deviceId)) {
                $limits[] = Limit::perHour(10)->by('otp-send:device:'.hash('sha256', strtolower($deviceId)));
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

            if (is_string($deviceId) && Str::isUuid($deviceId)) {
                $limits[] = Limit::perHour(30)->by('otp-verify:device:'.hash('sha256', strtolower($deviceId)));
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

        RateLimiter::for('otp-delete-account', function (Request $request): array {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('otp-delete-account:user-minute:'.$userId),
                Limit::perHour(20)->by('otp-delete-account:user-hour:'.$userId),
                Limit::perMinute(20)->by('otp-delete-account:ip-minute:'.$ip),
                Limit::perHour(60)->by('otp-delete-account:ip-hour:'.$ip),
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

        RateLimiter::for('admin-sms-test', function (Request $request): array {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $phone = $request->input('phone');
            $phoneKey = is_string($phone) ? hash('sha256', $phone) : 'invalid';

            return [
                Limit::perMinute(1)->by('admin-sms-test:user-minute:'.$userId),
                Limit::perHour(5)->by('admin-sms-test:user-hour:'.$userId),
                Limit::perMinute(1)->by('admin-sms-test:phone-minute:'.$phoneKey),
                Limit::perHour(5)->by('admin-sms-test:phone-hour:'.$phoneKey),
            ];
        });
    }
}
