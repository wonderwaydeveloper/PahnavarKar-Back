<?php

namespace Tests\Feature\MySql;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class WalletConcurrentDebitTest extends TestCase
{
    public function test_concurrent_debits_cannot_spend_more_than_the_wallet_balance(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $user = User::factory()->create(['wallet_balance' => 1000]);
        $results = $this->runConcurrentWalletOperations(
            (int) $user->getKey(),
            'debit',
            700,
            [(string) Str::uuid(), (string) Str::uuid()],
            ['purchase-a', 'purchase-b'],
        );

        $this->assertSame(
            ['insufficient_balance', 'succeeded'],
            collect($results)->pluck('status')->sort()->values()->all(),
        );
        $successfulResult = collect($results)->firstWhere('status', 'succeeded');
        $this->assertIsArray($successfulResult);
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());
        $transaction = Transaction::query()->where('user_id', $user->getKey())->sole();
        $this->assertSame(700, $transaction->amount);
        $this->assertSame(300, $transaction->balance_after);
        $this->assertSame((int) $transaction->getKey(), (int) $successfulResult['transaction_id']);
    }

    public function test_concurrent_debits_with_the_same_idempotency_key_create_one_transaction(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $user = User::factory()->create(['wallet_balance' => 1000]);
        $idempotencyKey = (string) Str::uuid();
        $results = $this->runConcurrentWalletOperations(
            (int) $user->getKey(),
            'debit',
            700,
            [$idempotencyKey, $idempotencyKey],
            ['purchase-same', 'purchase-same'],
        );

        $this->assertSame(['succeeded', 'succeeded'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertCount(1, array_unique(array_column($results, 'transaction_id')));
        $this->assertSame(300, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());

        $transaction = Transaction::query()->where('user_id', $user->getKey())->sole();
        $this->assertSame($idempotencyKey, $transaction->idempotency_key);
        $this->assertSame(700, $transaction->amount);
        $this->assertSame(300, $transaction->balance_after);
        $this->assertSame((int) $transaction->getKey(), (int) $results[0]['transaction_id']);
        $this->assertSame((int) $transaction->getKey(), (int) $results[1]['transaction_id']);
    }

    public function test_concurrent_verified_credits_with_the_same_gateway_reference_credit_once(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $user = User::factory()->create(['wallet_balance' => 1000]);
        $gatewayReference = (string) Str::uuid();
        $results = $this->runConcurrentWalletOperations(
            (int) $user->getKey(),
            'credit',
            700,
            ['zarinpal', 'zarinpal'],
            [$gatewayReference, $gatewayReference],
        );

        $this->assertSame(['succeeded', 'succeeded'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertCount(1, array_unique(array_column($results, 'transaction_id')));
        $this->assertSame(1700, $user->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());

        $transaction = Transaction::query()->where('user_id', $user->getKey())->sole();
        $this->assertSame('deposit', $transaction->type);
        $this->assertSame('zarinpal', $transaction->gateway);
        $this->assertSame($gatewayReference, $transaction->reference_id);
        $this->assertSame(700, $transaction->amount);
        $this->assertSame(1700, $transaction->balance_after);
        $this->assertSame((int) $transaction->getKey(), (int) $results[0]['transaction_id']);
        $this->assertSame((int) $transaction->getKey(), (int) $results[1]['transaction_id']);
    }

    private function assertIsolatedMysqlDatabase(): void
    {
        if (env('WALLET_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set WALLET_MYSQL_CONCURRENCY=1 to run against a disposable MySQL test database.');
        }

        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('mysql', config('database.connections.mysql.driver'));

        $database = (string) config('database.connections.mysql.database');
        $this->assertStringStartsWith('pahnavarkar_wallet_concurrency_test_', $database);

        foreach (['users', 'transactions'] as $table) {
            $engine = DB::selectOne(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$database, $table],
            )?->ENGINE;
            $this->assertSame('InnoDB', $engine);
        }
    }

    /**
     * @param  'credit'|'debit'  $operation
     * @param  array{0: string, 1: string}  $operationKeys
     * @param  array{0: string, 1: string}  $references
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function runConcurrentWalletOperations(
        int $userId,
        string $operation,
        int $amount,
        array $operationKeys,
        array $references,
    ): array {
        $barrierDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wallet-concurrency-'.bin2hex(random_bytes(12));
        mkdir($barrierDirectory);
        $processes = [];

        try {
            foreach ([1, 2] as $worker) {
                $processes[$worker] = new Process(
                    [PHP_BINARY, base_path('tests/Support/wallet-concurrent-operation-worker.php'), (string) $worker],
                    base_path(),
                    [
                        'APP_ENV' => 'testing',
                        'WALLET_CONCURRENCY_DIR' => $barrierDirectory,
                        'WALLET_CONCURRENCY_OPERATION' => $operation,
                        'WALLET_CONCURRENCY_USER_ID' => (string) $userId,
                        'WALLET_CONCURRENCY_AMOUNT' => (string) $amount,
                        'WALLET_CONCURRENCY_OPERATION_KEY' => $operationKeys[$worker - 1],
                        'WALLET_CONCURRENCY_REFERENCE' => $references[$worker - 1],
                        'DB_HOST' => (string) config('database.connections.mysql.host'),
                        'DB_PORT' => (string) config('database.connections.mysql.port'),
                        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
                        'DB_USERNAME' => (string) config('database.connections.mysql.username'),
                        'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
                    ],
                    null,
                    null,
                    20,
                );
                $processes[$worker]->start();
            }

            $this->waitForWorkers($barrierDirectory, $processes);
            touch($barrierDirectory.DIRECTORY_SEPARATOR.'release');

            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }

            return [
                json_decode(
                    (string) file_get_contents($barrierDirectory.DIRECTORY_SEPARATOR.'result-1'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
                json_decode(
                    (string) file_get_contents($barrierDirectory.DIRECTORY_SEPARATOR.'result-2'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
            ];
        } catch (Throwable $exception) {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            throw $exception;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            foreach (['ready-1', 'ready-2', 'release', 'result-1', 'result-2'] as $file) {
                $path = $barrierDirectory.DIRECTORY_SEPARATOR.$file;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($barrierDirectory);
        }
    }

    /**
     * @param  array<int, Process>  $processes
     */
    private function waitForWorkers(string $directory, array $processes): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            if (
                is_file($directory.DIRECTORY_SEPARATOR.'ready-1')
                && is_file($directory.DIRECTORY_SEPARATOR.'ready-2')
            ) {
                return;
            }

            foreach ($processes as $process) {
                if (! $process->isRunning() && $process->getExitCode() !== 0) {
                    $this->fail($process->getErrorOutput());
                }
            }

            usleep(10000);
        }

        $this->fail('Timed out waiting for concurrent wallet workers to become ready.');
    }
}
