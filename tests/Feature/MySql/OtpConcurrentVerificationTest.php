<?php

namespace Tests\Feature\MySql;

use App\Models\Otp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class OtpConcurrentVerificationTest extends TestCase
{
    public function test_two_concurrent_verifications_can_consume_an_otp_only_once(): void
    {
        if (env('OTP_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set OTP_MYSQL_CONCURRENCY=1 to run against a disposable MySQL test database.');
        }

        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('mysql', config('database.connections.mysql.driver'));

        $database = (string) config('database.connections.mysql.database');
        $this->assertStringStartsWith('pahnavarkar_otp_concurrency_test_', $database);

        $engine = DB::selectOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, 'otps'],
        )?->ENGINE;
        $this->assertSame('InnoDB', $engine);

        $phone = '+989'.random_int(100000000, 999999999);
        $user = User::factory()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ]);
        $otp = Otp::query()->create([
            'user_id' => $user->getKey(),
            'phone' => $phone,
            'code_hash' => Hash::make('123456'),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);
        $deviceId = '73e16eb1-a2d5-4a54-a4a7-59c1c3bf1174';
        $barrierDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'otp-concurrency-'.bin2hex(random_bytes(12));
        mkdir($barrierDirectory);
        $processes = [];

        try {
            foreach ([1, 2] as $worker) {
                $processes[$worker] = new Process(
                    [PHP_BINARY, base_path('tests/Support/otp-concurrent-verification-worker.php'), (string) $worker],
                    base_path(),
                    [
                        'APP_ENV' => 'testing',
                        'OTP_CONCURRENCY_DIR' => $barrierDirectory,
                        'OTP_CONCURRENCY_PHONE' => $phone,
                        'OTP_CONCURRENCY_DEVICE_ID' => $deviceId,
                        'DB_HOST' => (string) config('database.connections.mysql.host'),
                        'DB_PORT' => (string) config('database.connections.mysql.port'),
                        'DB_DATABASE' => $database,
                        'DB_USERNAME' => (string) config('database.connections.mysql.username'),
                        'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
                    ],
                    null,
                    null,
                    20,
                );
                $processes[$worker]->start();
            }

            $this->waitForFiles($barrierDirectory, ['ready-1', 'ready-2'], $processes);
            touch($barrierDirectory.DIRECTORY_SEPARATOR.'release');

            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }

            $results = [
                json_decode((string) file_get_contents($barrierDirectory.DIRECTORY_SEPARATOR.'result-1'), true, flags: JSON_THROW_ON_ERROR),
                json_decode((string) file_get_contents($barrierDirectory.DIRECTORY_SEPARATOR.'result-2'), true, flags: JSON_THROW_ON_ERROR),
            ];

            $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['status'] === 'authenticated')));
            $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['status'] === 'rejected')));
            $this->assertTrue($otp->fresh()->used);
            $this->assertSame(1, $user->tokens()->count());
            $this->assertSame(1, $user->devices()->count());
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            throw $exception;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            foreach (glob($barrierDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($barrierDirectory);
        }
    }

    /**
     * @param  array<int, string>  $filenames
     */
    private function waitForFiles(string $directory, array $filenames, array $processes): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            if (count(array_filter(
                $filenames,
                fn (string $filename): bool => is_file($directory.DIRECTORY_SEPARATOR.$filename),
            )) === count($filenames)) {
                return;
            }

            foreach ($processes as $process) {
                if ($process instanceof Process && ! $process->isRunning() && $process->getExitCode() !== 0) {
                    $this->fail($process->getErrorOutput());
                }
            }

            usleep(10000);
        }

        $this->fail('Timed out waiting for concurrent OTP worker readiness.');
    }
}
