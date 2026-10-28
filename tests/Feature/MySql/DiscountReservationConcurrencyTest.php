<?php

namespace Tests\Feature\MySql;

use App\Models\DiscountCode;
use App\Models\DiscountUsage;
use App\Models\PaymentOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class DiscountReservationConcurrencyTest extends TestCase
{
    public function test_concurrent_users_cannot_exceed_the_last_discount_capacity_slot(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $code = $this->createCode(['usage_limit' => 1]);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstOrder = $this->createOrder($firstUser, $code);
        $secondOrder = $this->createOrder($secondUser, $code);

        $results = $this->runConcurrentReservations(
            $code->code,
            [
                ['user_id' => (int) $firstUser->getKey(), 'order_id' => (int) $firstOrder->getKey()],
                ['user_id' => (int) $secondUser->getKey(), 'order_id' => (int) $secondOrder->getKey()],
            ],
        );

        $this->assertSame(
            ['invalid', 'reserved'],
            collect($results)->pluck('status')->sort()->values()->all(),
        );
        $this->assertSame(1, $code->fresh()->usage_count);
        $this->assertSame(1, DiscountUsage::query()->where('discount_code_id', $code->getKey())->count());
        $this->assertSame(
            1,
            DiscountUsage::query()
                ->where('discount_code_id', $code->getKey())
                ->where('status', 'reserved')
                ->count(),
        );
        $this->assertCapacityMatchesActiveUsages($code);
    }

    public function test_concurrent_reservations_cannot_use_the_same_code_twice_for_one_user(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $code = $this->createCode(['usage_limit' => 5]);
        $user = User::factory()->create();
        $firstOrder = $this->createOrder($user, $code);
        $secondOrder = $this->createOrder($user, $code);

        $results = $this->runConcurrentReservations(
            $code->code,
            [
                ['user_id' => (int) $user->getKey(), 'order_id' => (int) $firstOrder->getKey()],
                ['user_id' => (int) $user->getKey(), 'order_id' => (int) $secondOrder->getKey()],
            ],
        );

        $this->assertSame(
            ['invalid', 'reserved'],
            collect($results)->pluck('status')->sort()->values()->all(),
        );
        $this->assertSame(1, $code->fresh()->usage_count);
        $this->assertSame(1, DiscountUsage::query()
            ->where('discount_code_id', $code->getKey())
            ->where('user_id', $user->getKey())
            ->whereIn('status', ['reserved', 'consumed'])
            ->count());
        $this->assertCapacityMatchesActiveUsages($code);
    }

    public function test_concurrent_different_users_can_reserve_available_discount_capacity(): void
    {
        $this->assertIsolatedMysqlDatabase();

        $code = $this->createCode(['usage_limit' => 2]);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstOrder = $this->createOrder($firstUser, $code);
        $secondOrder = $this->createOrder($secondUser, $code);

        $results = $this->runConcurrentReservations(
            $code->code,
            [
                ['user_id' => (int) $firstUser->getKey(), 'order_id' => (int) $firstOrder->getKey()],
                ['user_id' => (int) $secondUser->getKey(), 'order_id' => (int) $secondOrder->getKey()],
            ],
        );

        $this->assertSame(['reserved', 'reserved'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(2, $code->fresh()->usage_count);
        $this->assertSame(2, DiscountUsage::query()->where('discount_code_id', $code->getKey())->count());
        $this->assertSame(
            [$firstUser->getKey(), $secondUser->getKey()],
            DiscountUsage::query()
                ->where('discount_code_id', $code->getKey())
                ->orderBy('user_id')
                ->pluck('user_id')
                ->all(),
        );
        $this->assertCapacityMatchesActiveUsages($code);
    }

    private function assertIsolatedMysqlDatabase(): void
    {
        if (env('DISCOUNT_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped(
                'Set DISCOUNT_MYSQL_CONCURRENCY=1 to run against a disposable MySQL test database.',
            );
        }

        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('mysql', config('database.connections.mysql.driver'));

        $database = (string) config('database.connections.mysql.database');
        $this->assertStringStartsWith('pahnavarkar_discount_concurrency_test_', $database);

        foreach (['users', 'discount_codes', 'discount_usages', 'payment_orders'] as $table) {
            $engine = DB::selectOne(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$database, $table],
            )?->ENGINE;

            $this->assertSame('InnoDB', $engine);
        }
    }

    /**
     * @param  array{0: array{user_id: int, order_id: int}, 1: array{user_id: int, order_id: int}}  $reservations
     * @return array{0: array{status: string}, 1: array{status: string}}
     */
    private function runConcurrentReservations(string $code, array $reservations): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'discount-reservation-concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory);
        $processes = [];

        try {
            foreach ([1, 2] as $worker) {
                $reservation = $reservations[$worker - 1];
                $processes[$worker] = new Process(
                    [PHP_BINARY, base_path('tests/Support/discount-concurrent-reservation-worker.php'), (string) $worker],
                    base_path(),
                    [
                        'APP_ENV' => 'testing',
                        'DISCOUNT_CONCURRENCY_DIR' => $directory,
                        'DISCOUNT_CONCURRENCY_CODE' => $code,
                        'DISCOUNT_CONCURRENCY_USER_ID' => (string) $reservation['user_id'],
                        'DISCOUNT_CONCURRENCY_ORDER_ID' => (string) $reservation['order_id'],
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

        $this->fail('Timed out waiting for discount reservation workers to become ready.');
    }

    private function assertCapacityMatchesActiveUsages(DiscountCode $code): void
    {
        $activeUsages = DiscountUsage::query()
            ->where('discount_code_id', $code->getKey())
            ->whereIn('status', ['reserved', 'consumed'])
            ->count();

        $this->assertSame($activeUsages, $code->fresh()->usage_count);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCode(array $attributes = []): DiscountCode
    {
        return DiscountCode::query()->create(array_merge([
            'code' => 'CONCURRENT-'.Str::upper(Str::random(8)),
            'type' => 'fixed',
            'value' => 100,
            'min_order_amount' => 0,
            'usage_limit' => null,
            'usage_count' => 0,
            'is_active' => true,
            'expires_at' => now()->addDay(),
        ], $attributes));
    }

    private function createOrder(User $user, DiscountCode $code): PaymentOrder
    {
        return PaymentOrder::query()->create([
            'user_id' => $user->getKey(),
            'order_reference' => (string) Str::uuid(),
            'kind' => 'subscription',
            'discount_code_id' => $code->getKey(),
            'discount_amount' => 0,
            'gross_amount' => 1000,
            'wallet_amount' => 0,
            'gateway_amount' => 900,
            'currency' => 'TOMAN',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
    }
}
