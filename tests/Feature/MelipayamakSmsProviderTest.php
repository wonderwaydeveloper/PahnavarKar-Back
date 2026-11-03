<?php

namespace Tests\Feature;

use App\Services\Auth\MelipayamakSmsProvider;
use App\Services\Auth\SmsDeliveryException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MelipayamakSmsProviderTest extends TestCase
{
    public function test_otp_is_sent_to_the_iranian_local_number_using_the_configured_template(): void
    {
        config([
            'services.melipayamak.enabled' => true,
            'services.melipayamak.url' => 'https://console.melipayamak.com/api/send/shared/test-secret',
            'services.melipayamak.otp_body_id' => 'otp-template',
        ]);
        Http::fake([
            'https://console.melipayamak.com/api/send/shared/*' => Http::response(['success' => true]),
        ]);

        app(MelipayamakSmsProvider::class)->sendOtp('+989121234567', '123456');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://console.melipayamak.com/api/send/shared/test-secret'
            && $request->method() === 'POST'
            && $request->hasHeader('Accept', 'application/json')
            && $request->data() === [
                'bodyId' => 'otp-template',
                'to' => '09121234567',
                'args' => ['123456'],
            ]);
    }

    public function test_inactivity_warning_uses_its_own_template_and_arguments(): void
    {
        config([
            'services.melipayamak.enabled' => true,
            'services.melipayamak.url' => 'https://console.melipayamak.com/api/send/shared/test-secret',
            'services.melipayamak.inactivity_warning_body_id' => 'warning-template',
        ]);
        Http::fake([
            'https://console.melipayamak.com/api/send/shared/*' => Http::response(['success' => true]),
        ]);

        app(MelipayamakSmsProvider::class)->sendInactivityDeletionWarning(
            '+989121234567',
            'کاربر آزمایشی',
            7,
        );

        Http::assertSent(fn ($request): bool => $request->data() === [
            'bodyId' => 'warning-template',
            'to' => '09121234567',
            'args' => ['کاربر آزمایشی', '7'],
        ]);
    }

    public function test_test_message_requires_and_uses_its_dedicated_template_without_arguments(): void
    {
        config([
            'services.melipayamak.enabled' => true,
            'services.melipayamak.url' => 'https://console.melipayamak.com/api/send/shared/test-secret',
            'services.melipayamak.otp_body_id' => 'otp-template',
            'services.melipayamak.test_body_id' => 'test-template',
        ]);
        Http::fake([
            'https://console.melipayamak.com/api/send/shared/*' => Http::response(['success' => true]),
        ]);

        $provider = app(MelipayamakSmsProvider::class);
        $this->assertTrue($provider->canSendTestMessage());
        $provider->sendTestMessage('+989121234567');

        Http::assertSent(fn ($request): bool => $request->data() === [
            'bodyId' => 'test-template',
            'to' => '09121234567',
            'args' => [],
        ]);
    }

    public function test_test_message_is_unavailable_without_a_dedicated_template(): void
    {
        config([
            'services.melipayamak.enabled' => true,
            'services.melipayamak.url' => 'https://console.melipayamak.com/api/send/shared/test-secret',
            'services.melipayamak.otp_body_id' => 'otp-template',
            'services.melipayamak.test_body_id' => null,
        ]);
        Http::fake();

        $provider = app(MelipayamakSmsProvider::class);
        $this->assertFalse($provider->canSendTestMessage());

        try {
            $provider->sendTestMessage('+989121234567');
            $this->fail('A test message must not use the authentication template.');
        } catch (SmsDeliveryException) {
            Http::assertNothingSent();
        }
    }

    public function test_provider_rejects_disabled_or_invalid_configuration_without_network_calls(): void
    {
        Http::fake();
        $provider = app(MelipayamakSmsProvider::class);

        config(['services.melipayamak.enabled' => false]);
        try {
            $provider->sendOtp('+989121234567', '123456');
            $this->fail('Disabled SMS delivery must be rejected.');
        } catch (SmsDeliveryException) {
            Http::assertNothingSent();
        }

        config([
            'services.melipayamak.enabled' => true,
            'services.melipayamak.url' => 'http://invalid.example/send',
            'services.melipayamak.otp_body_id' => 'otp-template',
        ]);
        try {
            $provider->sendOtp('+989121234567', '123456');
            $this->fail('An insecure or unsupported provider URL must be rejected.');
        } catch (SmsDeliveryException) {
            Http::assertNothingSent();
        }
    }

    public function test_provider_rejects_http_or_provider_level_failures_without_leaking_provider_response(): void
    {
        config([
            'services.melipayamak.enabled' => true,
            'services.melipayamak.url' => 'https://console.melipayamak.com/api/send/shared/test-secret',
            'services.melipayamak.otp_body_id' => 'otp-template',
        ]);

        foreach ([
            Http::response(['success' => false, 'message' => 'sensitive provider detail'], 200),
            Http::response(['success' => true], 503),
        ] as $providerResponse) {
            Http::fake([
                'https://console.melipayamak.com/api/send/shared/*' => $providerResponse,
            ]);

            try {
                app(MelipayamakSmsProvider::class)->sendOtp('+989121234567', '123456');
                $this->fail('Provider failures must be surfaced for the queue retry policy.');
            } catch (SmsDeliveryException $exception) {
                $this->assertStringNotContainsString('sensitive provider detail', $exception->getMessage());
                $this->assertStringNotContainsString('test-secret', $exception->getMessage());
            }
        }
    }
}
