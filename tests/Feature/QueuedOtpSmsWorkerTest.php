<?php

namespace Tests\Feature;

use App\Jobs\SendOtpSms;
use App\Models\Otp;
use App\Services\Auth\SmsDeliveryException;
use App\Services\Auth\SmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class QueuedOtpSmsWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_worker_retries_three_times_and_records_a_redacted_failed_job(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.connection' => null,
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.queue' => 'default',
            'queue.connections.database.retry_after' => 90,
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.database' => 'sqlite',
            'queue.failed.table' => 'failed_jobs',
        ]);
        $phone = '+989121234567';
        $code = '431827';
        $providerSecret = 'provider-secret-value';
        $exceptionMessage = "Provider failed for {$phone}; code={$code}; secret={$providerSecret}";
        $otp = Otp::query()->create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('sendOtp')
            ->times(3)
            ->with($phone, $code)
            ->andThrow(new SmsDeliveryException($exceptionMessage));
        $this->app->instance(SmsProvider::class, $provider);
        Log::spy();

        SendOtpSms::dispatch((int) $otp->getKey(), $phone, $code)->onQueue('otp');

        $queued = DB::table('jobs')->sole();
        $this->assertSame('otp', $queued->queue);
        $this->assertSame(0, (int) $queued->attempts);
        $queuedPayload = json_decode($queued->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsString($queuedPayload['data']['command'] ?? null);
        $this->assertStringNotContainsString(SendOtpSms::class, $queuedPayload['data']['command']);
        $this->assertStringNotContainsString($phone, $queued->payload);
        $this->assertStringNotContainsString($code, $queued->payload);

        $this->workOnce();
        $this->assertSame(1, (int) DB::table('jobs')->sole()->attempts);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertFalse($otp->fresh()->used);

        $this->makeQueuedJobAvailableNow();
        $this->workOnce();
        $this->assertSame(2, (int) DB::table('jobs')->sole()->attempts);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertFalse($otp->fresh()->used);

        $this->makeQueuedJobAvailableNow();
        $this->workOnce();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertTrue($otp->fresh()->used);

        $failedJob = DB::table('failed_jobs')->sole();
        $this->assertSame('database', $failedJob->connection);
        $this->assertSame('otp', $failedJob->queue);
        foreach ([$phone, $code, $providerSecret, $exceptionMessage] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $failedJob->payload);
            $this->assertStringNotContainsString($sensitiveValue, $failedJob->exception);
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Queued OTP SMS delivery failed after all retries.'
                && $context['otp_id'] === $otp->getKey()
                && $context['exception_class'] === SmsDeliveryException::class
                && $context['delivery_error'] === 'SMS delivery failed after all retries.');
    }

    private function workOnce(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => 'otp',
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 3,
            '--timeout' => 20,
        ])->assertSuccessful();
    }

    private function makeQueuedJobAvailableNow(): void
    {
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
    }
}
