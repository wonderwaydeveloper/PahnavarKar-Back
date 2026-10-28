<?php

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$worker = $argv[1] ?? '';
$directory = (string) getenv('WALLET_CONCURRENCY_DIR');
$operation = (string) getenv('WALLET_CONCURRENCY_OPERATION');
$userId = (string) getenv('WALLET_CONCURRENCY_USER_ID');
$amount = (string) getenv('WALLET_CONCURRENCY_AMOUNT');
$operationKey = (string) getenv('WALLET_CONCURRENCY_OPERATION_KEY');
$reference = (string) getenv('WALLET_CONCURRENCY_REFERENCE');

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
    fwrite(STDERR, 'Timed out waiting for the wallet concurrency barrier.'.PHP_EOL);
    exit(2);
}

try {
    $user = User::query()->findOrFail((int) $userId);
    $wallet = $app->make(WalletService::class);
    $transaction = match ($operation) {
        'credit' => $wallet->creditAfterVerifiedPayment($user, (int) $amount, $operationKey, $reference),
        'debit' => $wallet->debitForPurchase($user, (int) $amount, $operationKey, $reference),
        default => throw new InvalidArgumentException('Unsupported wallet operation.'),
    };
    $result = [
        'status' => 'succeeded',
        'transaction_id' => $transaction->getKey(),
    ];
} catch (InsufficientWalletBalanceException) {
    $result = ['status' => 'insufficient_balance'];
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

file_put_contents(
    $directory.DIRECTORY_SEPARATOR.'result-'.$worker,
    json_encode($result, JSON_THROW_ON_ERROR),
);
