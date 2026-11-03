<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Auth\SmsDeliveryException;
use App\Services\Auth\SmsProviderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SmsProviderSettings
{
    public function __construct(
        private readonly AdminRuntimeSettings $runtimeSettings,
        private readonly SmsProviderManager $providers,
    ) {}

    /**
     * @return array{active_provider: string, active_provider_configured: bool, configured_providers: array<string, string>, test_available: bool, test_mode: string}
     */
    public function current(): array
    {
        $activeProvider = $this->providers->selectedProvider();
        $configuredProviders = $this->providers->configuredProviders();

        return [
            'active_provider' => $activeProvider,
            'active_provider_configured' => array_key_exists($activeProvider, $configuredProviders),
            'configured_providers' => $configuredProviders,
            'test_available' => $this->providers->canSendTestMessage(),
            'test_mode' => $this->providers->isUsingLocalLogProvider() ? 'local_log' : 'provider',
        ];
    }

    public function update(Request $request): void
    {
        $configured = array_keys($this->providers->configuredProviders());
        if ($configured === []) {
            throw ValidationException::withMessages([
                'provider' => 'هیچ سرویس پیامکی از طریق secretهای محیط سرور پیکربندی نشده است.',
            ]);
        }

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in($configured)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $this->runtimeSettings->save('sms_provider', [
            'provider' => $validated['provider'],
        ], $user);
    }

    public function sendTestMessage(Request $request): string
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+989[0-9]{9}$/'],
        ], [
            'phone.regex' => 'شمارهٔ مقصد باید با قالب ‎+989xxxxxxxxx‎ وارد شود.',
        ]);

        if (! $this->providers->canSendTestMessage()) {
            throw ValidationException::withMessages([
                'test' => 'provider فعال برای ارسال آزمون آماده نیست؛ تنظیمات سرور و قالب آزمون را بررسی کنید.',
            ]);
        }

        try {
            $provider = $this->providers->isUsingLocalLogProvider()
                ? 'local_log'
                : $this->providers->selectedProvider();
            $this->providers->sendTestMessage($validated['phone']);
        } catch (SmsDeliveryException) {
            Log::warning('Admin SMS provider test was rejected.', [
                'provider' => $this->providers->isUsingLocalLogProvider()
                    ? 'local_log'
                    : $this->providers->selectedProvider(),
                'admin_id' => $request->user()->getAuthIdentifier(),
                'phone_last4' => substr($validated['phone'], -4),
            ]);

            throw ValidationException::withMessages([
                'test' => 'ارسال پیام آزمایشی پذیرفته نشد؛ پیکربندی provider و log امن سرور را بررسی کنید.',
            ]);
        }

        activity('security')
            ->causedBy($request->user())
            ->event('sms_test_completed')
            ->withProperties([
                'provider' => $provider,
                'phone_last4' => substr($validated['phone'], -4),
            ])
            ->log('Admin SMS provider test completed');

        return $provider;
    }
}
