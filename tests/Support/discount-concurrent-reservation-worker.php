<?php

use App\Exceptions\DiscountNotValidException;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Services\DiscountService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$worker = $argv[1] ?? '';
$directory = (string) getenv('DISCOUNT_CONCURRENCY_DIR');
$code = (string) getenv('DISCOUNT_CONCURRENCY_CODE');
$userId = (string) getenv('DISCOUNT_CONCURRENCY_USER_ID');
$orderId = (string) getenv('DISCOUNT_CONCURRENCY_ORDER_ID');
$database = (string) getenv('DB_DATABASE');

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (
    ! in_array($worker, ['1', '2'], true)
    || ! preg_match('/^pahnavarkar_discount_concurrency_test_[a-f0-9]{12}$/', $database)
) {
    fwrite(STDERR, 'Unsafe discount concurrency worker configuration.'.PHP_EOL);
    exit(2);
}

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => (string) getenv('DB_HOST'),
    'database.connections.mysql.port' => (string) getenv('DB_PORT'),
    'database.connections.mysql.database' => $database,
    'database.connections.mysql.username' => (string) getenv('DB_USERNAME'),
    'database.connections.mysql.password' => (string) getenv('DB_PASSWORD'),
    'cache.default' => 'array',
]);
DB::purge('mysql');
DB::setDefaultConnection('mysql');

touch($directory.DIRECTORY_SEPARATOR.'ready-'.$worker);

$deadline = microtime(true) + 10;
while (! is_file($directory.DIRECTORY_SEPARATOR.'release') && microtime(true) < $deadline) {
    usleep(10000);
}

if (! is_file($directory.DIRECTORY_SEPARATOR.'release')) {
    fwrite(STDERR, 'Timed out waiting for the discount concurrency barrier.'.PHP_EOL);
    exit(2);
}

try {
    $reservation = $app->make(DiscountService::class)->reserveForOrder(
        User::query()->findOrFail((int) $userId),
        $code,
        1000,
        PaymentOrder::query()->findOrFail((int) $orderId),
    );
    $result = [
        'status' => 'reserved',
        'discount_code_id' => $reservation['discount_code_id'],
    ];
} catch (DiscountNotValidException) {
    $result = ['status' => 'invalid'];
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

file_put_contents(
    $directory.DIRECTORY_SEPARATOR.'result-'.$worker,
    json_encode($result, JSON_THROW_ON_ERROR),
);
