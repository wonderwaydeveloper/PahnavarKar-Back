<?php

use App\Services\Auth\AuthService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$worker = $argv[1] ?? '';
$directory = (string) getenv('OTP_CONCURRENCY_DIR');
$phone = (string) getenv('OTP_CONCURRENCY_PHONE');
$deviceId = (string) getenv('OTP_CONCURRENCY_DEVICE_ID');

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => (string) getenv('DB_HOST'),
    'database.connections.mysql.port' => (string) getenv('DB_PORT'),
    'database.connections.mysql.database' => (string) getenv('DB_DATABASE'),
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
    fwrite(STDERR, 'Timed out waiting for the OTP concurrency barrier.'.PHP_EOL);
    exit(2);
}

try {
    $app->make(AuthService::class)->verify(
        $phone,
        'login',
        '123456',
        $deviceId,
        null,
        null,
        null,
        'unknown',
        null,
    );
    $result = ['status' => 'authenticated'];
} catch (ValidationException) {
    $result = ['status' => 'rejected'];
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

file_put_contents(
    $directory.DIRECTORY_SEPARATOR.'result-'.$worker,
    json_encode($result, JSON_THROW_ON_ERROR),
);
