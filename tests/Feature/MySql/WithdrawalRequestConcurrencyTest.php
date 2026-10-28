<?php

namespace Tests\Feature\MySql;

use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalRequestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class WithdrawalRequestConcurrencyTest extends TestCase
{
    public function test_concurrent_withdrawals_cannot_reserve_more_than_the_wallet_balance(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $user = User::factory()->create(['wallet_balance' => 150000]);
        $results = $this->runConcurrentRequests(
            (int) $user->getKey(),
            100000,
            [(string) Str::uuid(), (string) Str::uuid()],
        );

        $this->assertSame(
            ['created', 'insufficient_balance'],
            collect($results)->pluck('status')->sort()->values()->all(),
        );
        $this->assertSame(50000, $user->fresh()->wallet_balance);
        $this->assertSame(1, WithdrawalRequest::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());

        $withdrawal = WithdrawalRequest::query()->where('user_id', $user->getKey())->sole();
        $hold = Transaction::query()->where('user_id', $user->getKey())->sole();
        $this->assertSame(100000, $withdrawal->amount);
        $this->assertSame('pending', $withdrawal->status);
        $this->assertSame($hold->getKey(), $withdrawal->hold_transaction_id);
        $this->assertSame('withdrawal_hold', $hold->type);
        $this->assertSame(100000, $hold->amount);
        $this->assertSame(50000, $hold->balance_after);
    }

    public function test_concurrent_withdrawal_replays_with_the_same_key_reserve_balance_once(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $user = User::factory()->create(['wallet_balance' => 200000]);
        $idempotencyKey = (string) Str::uuid();
        $results = $this->runConcurrentRequests(
            (int) $user->getKey(),
            100000,
            [$idempotencyKey, $idempotencyKey],
        );

        $this->assertSame(['created', 'created'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertCount(1, array_unique(array_column($results, 'withdrawal_id')));
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertSame(1, WithdrawalRequest::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(1, Transaction::query()->where('user_id', $user->getKey())->count());

        $withdrawal = WithdrawalRequest::query()->where('user_id', $user->getKey())->sole();
        $hold = Transaction::query()->where('user_id', $user->getKey())->sole();
        $this->assertSame($idempotencyKey, $withdrawal->idempotency_key);
        $this->assertSame($hold->getKey(), $withdrawal->hold_transaction_id);
        $this->assertSame(100000, $hold->amount);
        $this->assertSame(100000, $hold->balance_after);
    }

    public function test_concurrent_approval_and_rejection_are_serialized_and_release_the_hold_once(): void
    {
        $this->assertIsolatedMysqlDatabase();

        config(['wallet.withdrawals.enabled' => true]);
        $user = User::factory()->create(['wallet_balance' => 1000000]);
        $approver = User::factory()->create(['is_active' => true]);
        $rejecter = User::factory()->create(['is_active' => true]);

        for ($round = 0; $round < 5; $round++) {
            $balanceBeforeWithdrawal = $user->fresh()->wallet_balance;
            $withdrawal = app(WithdrawalRequestService::class)->create(
                $user,
                1000,
                (string) Str::uuid(),
            );
            $hold = $withdrawal->holdTransaction;
            $results = $this->runConcurrentReviews(
                (int) $withdrawal->getKey(),
                (int) $approver->getKey(),
                (int) $rejecter->getKey(),
            );

            $this->assertContains(
                collect($results)->pluck('status')->sort()->values()->all(),
                [
                    ['conflict', 'rejected'],
                    ['approved', 'rejected'],
                ],
            );
            $this->assertSame('rejected', $withdrawal->fresh()->status);
            $this->assertSame($rejecter->getKey(), $withdrawal->fresh()->reviewed_by);
            $this->assertNotNull($withdrawal->fresh()->reviewed_at);
            $this->assertNull($withdrawal->fresh()->paid_at);
            $this->assertNull($withdrawal->fresh()->payout_reference);
            $this->assertSame('released', $hold->fresh()->status);
            $this->assertSame($balanceBeforeWithdrawal, $user->fresh()->wallet_balance);
            $this->assertSame(1, Transaction::query()
                ->where('reverses_transaction_id', $hold->getKey())
                ->count());
            $this->assertSame(
                $round + 1,
                WithdrawalRequest::query()->where('user_id', $user->getKey())->count(),
            );
        }

        $this->assertSame(10, Transaction::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(1000000, $user->fresh()->wallet_balance);
    }

    private function assertIsolatedMysqlDatabase(): void
    {
        if (env('WITHDRAWAL_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set WITHDRAWAL_MYSQL_CONCURRENCY=1 to run against a disposable MySQL test database.');
        }

        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('mysql', config('database.connections.mysql.driver'));

        $database = (string) config('database.connections.mysql.database');
        $this->assertStringStartsWith('pahnavarkar_withdrawal_concurrency_test_', $database);

        foreach (['users', 'transactions', 'withdrawal_requests'] as $table) {
            $engine = DB::selectOne(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$database, $table],
            )?->ENGINE;
            $this->assertSame('InnoDB', $engine);
        }
    }

    /**
     * @param  array{0: string, 1: string}  $idempotencyKeys
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function runConcurrentRequests(int $userId, int $amount, array $idempotencyKeys): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'withdrawal-concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory);
        $processes = [];

        try {
            foreach ([1, 2] as $worker) {
                $processes[$worker] = new Process(
                    [PHP_BINARY, base_path('tests/Support/withdrawal-concurrent-create-worker.php'), (string) $worker],
                    base_path(),
                    [
                        'APP_ENV' => 'testing',
                        'WITHDRAWAL_CONCURRENCY_DIR' => $directory,
                        'WITHDRAWAL_CONCURRENCY_USER_ID' => (string) $userId,
                        'WITHDRAWAL_CONCURRENCY_AMOUNT' => (string) $amount,
                        'WITHDRAWAL_CONCURRENCY_IDEMPOTENCY_KEY' => $idempotencyKeys[$worker - 1],
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

            $this->waitForWorkers($directory, $processes);
            touch($directory.DIRECTORY_SEPARATOR.'release');

            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }

            return [
                json_decode(
                    (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
                json_decode(
                    (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-2'),
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
                $path = $directory.DIRECTORY_SEPARATOR.$file;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function runConcurrentReviews(int $withdrawalId, int $approverId, int $rejecterId): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'withdrawal-review-concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory);
        $reviewers = ['approve' => $approverId, 'reject' => $rejecterId];
        $processes = [];

        try {
            foreach ([1 => 'approve', 2 => 'reject'] as $worker => $decision) {
                $processes[$worker] = new Process(
                    [PHP_BINARY, base_path('tests/Support/withdrawal-concurrent-review-worker.php'), (string) $worker],
                    base_path(),
                    [
                        'APP_ENV' => 'testing',
                        'WITHDRAWAL_REVIEW_CONCURRENCY_DIR' => $directory,
                        'WITHDRAWAL_REVIEW_CONCURRENCY_REQUEST_ID' => (string) $withdrawalId,
                        'WITHDRAWAL_REVIEW_CONCURRENCY_REVIEWER_ID' => (string) $reviewers[$decision],
                        'WITHDRAWAL_REVIEW_CONCURRENCY_DECISION' => $decision,
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

            $this->waitForWorkers($directory, $processes);
            touch($directory.DIRECTORY_SEPARATOR.'release');

            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }

            return [
                json_decode(
                    (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
                json_decode(
                    (string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-2'),
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
                $path = $directory.DIRECTORY_SEPARATOR.$file;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
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

        $this->fail('Timed out waiting for concurrent withdrawal workers to become ready.');
    }
}
