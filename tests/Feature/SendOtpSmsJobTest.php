<?php

namespace Tests\Feature;

use App\Jobs\SendOtpSms;
use App\Models\Otp;
use App\Services\Auth\SmsDeliveryException;
use App\Services\Auth\SmsProvider;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class SendOtpSmsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_only_the_current_unused_otp_and_stores_only_its_hash(): void
    {
        $phone = '+989121234567';
        $code = '431827';
        $otp = $this->otp($phone, $code);
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendOtp')->once()->with($phone, $code);
        $job = new SendOtpSms((int) $otp->getKey(), $phone, $code);

        $job->handle($provider);

        $this->assertFalse($otp->fresh()->used);
        $this->assertNotSame($code, $otp->fresh()->code_hash);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
    }

    public function test_job_does_not_send_when_otp_is_missing_used_or_replaced(): void
    {
        $phone = '+989121234568';
        $usedOtp = $this->otp($phone, '123456', ['used' => true]);
        $replacedOtp = $this->otp($phone, '234567');
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldNotReceive('sendOtp');

        (new SendOtpSms(999999, $phone, '345678'))->handle($provider);
        (new SendOtpSms((int) $usedOtp->getKey(), $phone, '123456'))->handle($provider);
        (new SendOtpSms((int) $replacedOtp->getKey(), $phone, '999999'))->handle($provider);

        $this->assertTrue($usedOtp->fresh()->used);
        $this->assertFalse($replacedOtp->fresh()->used);
    }

    public function test_expired_otp_is_invalidated_without_sending(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00'));
        $phone = '+989121234569';
        $otp = $this->otp($phone, '123456', ['expires_at' => now()]);
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldNotReceive('sendOtp');

        (new SendOtpSms((int) $otp->getKey(), $phone, '123456'))->handle($provider);

        $this->assertTrue($otp->fresh()->used);
    }

    public function test_transient_provider_failure_can_retry_before_expiry(): void
    {
        $phone = '+989121234570';
        $code = '654321';
        $otp = $this->otp($phone, $code);
        $provider = Mockery::mock(SmsProvider::class);
        $attempts = 0;
        $provider->shouldReceive('sendOtp')
            ->twice()
            ->with($phone, $code)
            ->andReturnUsing(function () use (&$attempts): void {
                if ($attempts++ === 0) {
                    throw new SmsDeliveryException('Temporary SMS delivery failure.');
                }
            });
        $job = new SendOtpSms((int) $otp->getKey(), $phone, $code);

        try {
            $job->handle($provider);
            $this->fail('A transient provider failure must reach the queue worker for retry.');
        } catch (SmsDeliveryException $exception) {
            $this->assertSame('SMS delivery failed.', $exception->getMessage());
        }

        $this->assertFalse($otp->fresh()->used);
        $this->travel(20)->seconds();
        $job->handle($provider);

        $this->assertFalse($otp->fresh()->used);
        $this->assertSame(2, $attempts);
    }

    public function test_delayed_retry_after_expiry_does_not_send_the_code(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00'));
        $phone = '+989121234571';
        $code = '567890';
        $otp = $this->otp($phone, $code, ['expires_at' => now()->addMinutes(5)]);
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendOtp')
            ->once()
            ->with($phone, $code)
            ->andThrow(new SmsDeliveryException('Temporary SMS delivery failure.'));
        $job = new SendOtpSms((int) $otp->getKey(), $phone, $code);

        try {
            $job->handle($provider);
            $this->fail('The first provider failure should be retryable.');
        } catch (SmsDeliveryException $exception) {
            $this->assertSame('SMS delivery failed.', $exception->getMessage());
            $this->assertFalse($otp->fresh()->used);
        }

        $this->travel(5)->minutes();
        $job->handle($provider);

        $this->assertTrue($otp->fresh()->used);
    }

    public function test_final_failure_invalidates_otp_and_logs_no_phone_code_or_exception_secret(): void
    {
        $phone = '+989121234572';
        $code = '789012';
        $providerSecret = 'provider-secret-value';
        $otp = $this->otp($phone, $code);
        $job = new SendOtpSms((int) $otp->getKey(), $phone, $code);
        Log::spy();

        $job->failed(new SmsDeliveryException("Failure containing {$code}, {$phone}, {$providerSecret}"));

        $this->assertTrue($otp->fresh()->used);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($phone, $code, $providerSecret, $otp): bool {
                $this->assertSame('Queued OTP SMS delivery failed after all retries.', $message);
                $this->assertSame($otp->getKey(), $context['otp_id']);
                $this->assertSame(SmsDeliveryException::class, $context['exception_class']);
                $encodedContext = (string) json_encode($context);
                $this->assertStringNotContainsString($phone, $encodedContext);
                $this->assertStringNotContainsString($code, $encodedContext);
                $this->assertStringNotContainsString($providerSecret, $encodedContext);

                return true;
            });
    }

    public function test_retry_budget_fits_otp_ttl_and_database_queue_visibility_timeout(): void
    {
        $job = new SendOtpSms(1, '+989121234567', '123456');
        $otpTtlSeconds = 5 * 60;
        $maximumDeliveryBudget = ($job->tries * $job->timeout) + array_sum($job->backoff);

        $this->assertSame(3, $job->tries);
        $this->assertSame(20, $job->timeout);
        $this->assertSame([10, 30], $job->backoff);
        $this->assertLessThan($otpTtlSeconds, $maximumDeliveryBudget);
        $this->assertGreaterThan(
            $job->timeout,
            (int) config('queue.connections.database.retry_after'),
        );
    }

    private function otp(string $phone, string $code, array $attributes = []): Otp
    {
        return Otp::query()->create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
            'used' => false,
            ...$attributes,
        ]);
    }
}
