<?php

use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use App\Services\Payments\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentCallbackNotificationRecorder;
use Tests\Support\ConcurrentPaymentGateway;

require dirname(__DIR__, 2).'/vendor/autoload.php';

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
    'payment.default' => 'concurrency-test',
    'payment.failover' => [],
    'payment.gateways.concurrency-test.class' => ConcurrentPaymentGateway::class,
    'payment.gateways.concurrency-test.enabled' => true,
    'payment.gateways.concurrency-test.credentials_configured' => true,
    'payment.gateways.concurrency-test.concurrent_verification' => false,
]);
DB::purge('mysql');
DB::setDefaultConnection('mysql');
$app->instance(ExpoPushService::class, new ConcurrentCallbackNotificationRecorder);

try {
    $user = User::query()->findOrFail((int) getenv('PAYMENT_CALLBACK_CONCURRENCY_USER_ID'));
    $payments = $app->make(PaymentService::class);
    $idempotencyKey = (string) getenv('PAYMENT_CALLBACK_CONCURRENCY_IDEMPOTENCY_KEY');

    if (getenv('PAYMENT_CALLBACK_CONCURRENCY_PAYMENT_TYPE') === 'top-up') {
        $payment = $payments->startWalletTopUp(
            $user,
            (int) getenv('PAYMENT_CALLBACK_CONCURRENCY_AMOUNT'),
            $idempotencyKey,
        );
        $result = [
            'payment_id' => $payment->getKey(),
            'status' => $payment->status,
            'failure_code' => $payment->failure_code,
        ];
    } elseif (getenv('PAYMENT_CALLBACK_CONCURRENCY_PAYMENT_TYPE') === 'subscription') {
        $quote = json_decode(
            (string) getenv('PAYMENT_CALLBACK_CONCURRENCY_QUOTE'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $order = $payments->purchasePremium(
            $user,
            'gold_1_month',
            null,
            $idempotencyKey,
            $quote,
        );
        $result = [
            'payment_id' => $order->getKey(),
            'status' => $order->status,
            'failure_code' => $order->failure_code,
            'attempt_status' => $order->attempts()->sole()->status,
        ];
    } else {
        throw new RuntimeException('Invalid payment type for the initiation concurrency worker.');
    }

    file_put_contents(
        (string) getenv('PAYMENT_CALLBACK_CONCURRENCY_DIR').DIRECTORY_SEPARATOR.'initiation-result.json',
        json_encode($result, JSON_THROW_ON_ERROR),
    );
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
