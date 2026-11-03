<?php

namespace App\Services\Auth;

use App\Services\Admin\AdminRuntimeSettings;

class SmsProviderManager implements SmsProvider
{
    public function __construct(
        private readonly AdminRuntimeSettings $runtimeSettings,
        private readonly MelipayamakSmsProvider $melipayamak,
        private readonly KavenegarSmsProvider $kavenegar,
        private readonly LocalLogSmsProvider $localLog,
    ) {}

    /**
     * @return array<string, string>
     */
    public function configuredProviders(): array
    {
        $providers = [];
        if ($this->melipayamak->isConfigured()) {
            $providers['melipayamak'] = 'ملی‌پیامک';
        }
        if ($this->kavenegar->isConfigured()) {
            $providers['kavenegar'] = 'کاوه‌نگار';
        }

        return $providers;
    }

    public function selectedProvider(): string
    {
        return $this->runtimeSettings->smsProvider();
    }

    public function canQueueActiveProvider(): bool
    {
        return ! $this->usesLocalLogProvider()
            && array_key_exists($this->selectedProvider(), $this->configuredProviders());
    }

    public function canSendTestMessage(): bool
    {
        if ($this->usesLocalLogProvider()) {
            return $this->localLog->canSendTestMessage();
        }

        $provider = $this->configuredProviders()[$this->selectedProvider()] ?? null;
        if ($provider === null) {
            return false;
        }

        return match ($this->selectedProvider()) {
            'melipayamak' => $this->melipayamak->canSendTestMessage(),
            'kavenegar' => $this->kavenegar->canSendTestMessage(),
            default => false,
        };
    }

    public function isUsingLocalLogProvider(): bool
    {
        return $this->usesLocalLogProvider();
    }

    public function sendOtp(string $phone, string $code): void
    {
        $this->activeProvider()->sendOtp($phone, $code);
    }

    public function sendTestMessage(string $phone): void
    {
        $provider = $this->activeProvider();
        if (! $provider->canSendTestMessage()) {
            throw new SmsDeliveryException('The active SMS provider is not configured for test messages.');
        }

        $provider->sendTestMessage($phone);
    }

    public function sendInactivityDeletionWarning(string $phone, string $userName, int $graceDays): void
    {
        $this->activeProvider()->sendInactivityDeletionWarning($phone, $userName, $graceDays);
    }

    private function activeProvider(): SmsProvider
    {
        if ($this->usesLocalLogProvider()) {
            return $this->localLog;
        }

        $provider = $this->selectedProvider();

        return match ($provider) {
            'melipayamak' => $this->configured($this->melipayamak, $provider),
            'kavenegar' => $this->configured($this->kavenegar, $provider),
            default => throw new SmsDeliveryException('The selected SMS provider is not supported.'),
        };
    }

    private function configured(ConfigurableSmsProvider $provider, string $name): SmsProvider
    {
        if (! $provider->isConfigured()) {
            throw new SmsDeliveryException("The selected SMS provider [{$name}] is not configured.");
        }

        return $provider;
    }

    private function usesLocalLogProvider(): bool
    {
        return app()->environment('local')
            && config('services.melipayamak.local_log_enabled') === true;
    }
}
