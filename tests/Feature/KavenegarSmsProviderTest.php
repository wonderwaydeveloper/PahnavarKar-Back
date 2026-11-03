<?php

namespace Tests\Feature;

use App\Services\Auth\KavenegarSmsProvider;
use App\Services\Auth\SmsDeliveryException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KavenegarSmsProviderTest extends TestCase
{
    public function test_otp_is_sent_as_an_encoded_form_to_the_configured_api_key_endpoint(): void
    {
        $this->configureKavenegar();
        Http::fake([
            'https://api.kavenegar.com/v1/test-api-key/sms/send.json' => Http::response([
                'return' => ['status' => 200],
                'entries' => [['status' => 1]],
            ]),
        ]);

        app(KavenegarSmsProvider::class)->sendOtp('+989121234567', '123456');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.kavenegar.com/v1/test-api-key/sms/send.json'
            && $request->method() === 'POST'
            && $request->hasHeader('Accept', 'application/json')
            && $request->data() === [
                'receptor' => '09121234567',
                'message' => 'کد تأیید شما: 123456',
                'sender' => '10004346',
            ]);
    }

    public function test_inactivity_warning_uses_the_configured_sender_and_local_number_format(): void
    {
        $this->configureKavenegar();
        Http::fake([
            'https://api.kavenegar.com/v1/test-api-key/sms/send.json' => Http::response([
                'return' => ['status' => 200],
                'entries' => [['status' => 1]],
            ]),
        ]);

        app(KavenegarSmsProvider::class)->sendInactivityDeletionWarning(
            '+989121234567',
            'کاربر آزمایشی',
            7,
        );

        Http::assertSent(fn ($request): bool => $request->data() === [
            'receptor' => '09121234567',
            'message' => 'کاربر آزمایشی عزیز، در صورت عدم ورود تا 7 روز آینده، حساب شما طبق تنظیم حذف خودکار حذف خواهد شد.',
            'sender' => '10004346',
        ]);
    }

    public function test_test_message_sends_a_fixed_non_otp_message(): void
    {
        $this->configureKavenegar();
        Http::fake([
            'https://api.kavenegar.com/v1/test-api-key/sms/send.json' => Http::response([
                'return' => ['status' => 200],
                'entries' => [['status' => 1]],
            ]),
        ]);

        $provider = app(KavenegarSmsProvider::class);
        $this->assertTrue($provider->canSendTestMessage());
        $provider->sendTestMessage('+989121234567');

        Http::assertSent(fn ($request): bool => $request->data() === [
            'receptor' => '09121234567',
            'message' => 'پیام آزمایشی سرویس پیامک پهناورکار؛ نیازی به پاسخ نیست.',
            'sender' => '10004346',
        ]);
    }

    public function test_provider_rejects_missing_configuration_and_non_successful_api_responses(): void
    {
        Http::fake();
        config([
            'services.kavenegar.enabled' => true,
            'services.kavenegar.api_key' => null,
        ]);

        try {
            app(KavenegarSmsProvider::class)->sendOtp('+989121234567', '123456');
            $this->fail('Kavenegar must reject missing API credentials.');
        } catch (SmsDeliveryException) {
            Http::assertNothingSent();
        }

        $this->configureKavenegar();
        Http::fake([
            'https://api.kavenegar.com/v1/test-api-key/sms/send.json' => Http::response([
                'return' => ['status' => 200],
                'entries' => [['status' => 411]],
            ]),
        ]);

        try {
            app(KavenegarSmsProvider::class)->sendOtp('+989121234567', '123456');
            $this->fail('A rejected message must be surfaced for queue retries.');
        } catch (SmsDeliveryException $exception) {
            $this->assertStringNotContainsString('test-api-key', $exception->getMessage());
        }
    }

    private function configureKavenegar(): void
    {
        config([
            'services.kavenegar.enabled' => true,
            'services.kavenegar.api_key' => 'test-api-key',
            'services.kavenegar.sender' => '10004346',
        ]);
    }
}
