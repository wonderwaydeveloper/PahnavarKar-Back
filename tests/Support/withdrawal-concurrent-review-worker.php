<?php

use App\Exceptions\WithdrawalRequestConflictException;
use App\Models\User;
use App\Services\WithdrawalRequestService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$worker = $argv[1] ?? '';
$directory = (string) getenv('WITHDRAWAL_REVIEW_CONCURRENCY_DIR');
$requestId = (string) getenv('WITHDRAWAL_REVIEW_CONCURRENCY_REQUEST_ID');
$reviewerId = (string) getenv('WITHDRAWAL_REVIEW_CONCURRENCY_REVIEWER_ID');
$decision = (string) getenv('WITHDRAWAL_REVIEW_CONCURRENCY_DECISION');

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
    'wallet.withdrawals.enabled' => true,
]);
DB::purge('mysql');
DB::setDefaultConnection('mysql');

touch($directory.DIRECTORY_SEPARATOR.'ready-'.$worker);

$deadline = microtime(true) + 10;
while (! is_file($directory.DIRECTORY_SEPARATOR.'release') && microtime(true) < $deadline) {
    usleep(10000);
}

if (! is_file($directory.DIRECTORY_SEPARATOR.'release')) {
    fwrite(STDERR, 'Timed out waiting for the withdrawal review concurrency barrier.'.PHP_EOL);
    exit(2);
}

try {
    $withdrawal = $app->make(WithdrawalRequestService::class)->review(
        (int) $requestId,
        User::query()->findOrFail((int) $reviewerId),
        $decision,
        'Concurrent '.$decision,
    );
    $result = [
        'status' => $decision === 'approve' ? 'approved' : 'rejected',
        'withdrawal_id' => $withdrawal->getKey(),
    ];
} catch (WithdrawalRequestConflictException) {
    $result = ['status' => 'conflict'];
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

file_put_contents(
    $directory.DIRECTORY_SEPARATOR.'result-'.$worker,
    json_encode($result, JSON_THROW_ON_ERROR),
);
