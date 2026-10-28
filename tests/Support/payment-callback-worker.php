<?php

use App\Services\Notifications\ExpoPushService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentCallbackNotificationRecorder;
use Tests\Support\ConcurrentPaymentGateway;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$worker = $argv[1] ?? '';
$directory = (string) getenv('PAYMENT_CALLBACK_CONCURRENCY_DIR');

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
    'payment.gateways.concurrency-test.class' => ConcurrentPaymentGateway::class,
    'payment.gateways.concurrency-test.credentials_configured' => true,
    'payment.gateways.concurrency-test.concurrent_verification' => getenv('PAYMENT_CALLBACK_CONCURRENT_VERIFICATION') === '1',
]);
DB::purge('mysql');
DB::setDefaultConnection('mysql');
$app->instance(ExpoPushService::class, new ConcurrentCallbackNotificationRecorder);

touch($directory.DIRECTORY_SEPARATOR.'ready-'.$worker);

$deadline = microtime(true) + 10;
while (! is_file($directory.DIRECTORY_SEPARATOR.'release') && microtime(true) < $deadline) {
    usleep(10000);
}

if (! is_file($directory.DIRECTORY_SEPARATOR.'release')) {
    fwrite(STDERR, 'Timed out waiting for the callback concurrency barrier.'.PHP_EOL);
    exit(2);
}

$route = (string) (getenv('PAYMENT_CALLBACK_ROUTE') ?: 'callback');
$method = strtoupper((string) (getenv('PAYMENT_CALLBACK_METHOD') ?: 'POST'));
if (! in_array($route, ['callback', 'webhook'], true) || ! in_array($method, ['GET', 'POST'], true)) {
    fwrite(STDERR, 'Invalid callback worker route or HTTP method.'.PHP_EOL);
    exit(2);
}

$request = Request::create(
    'http://localhost/api/v1/'.($route === 'webhook' ? 'webhook/payment' : 'payment/callback').'/concurrency-test',
    $method,
    [
        'merchant_reference' => (string) getenv('PAYMENT_CALLBACK_CONCURRENCY_MERCHANT_REFERENCE'),
        'callback_proof' => 'isolated-test-proof',
        'concurrency_result' => (string) getenv('PAYMENT_CALLBACK_CONCURRENCY_RESULT'),
    ],
    server: ['HTTP_ACCEPT' => 'application/json'],
);

try {
    $response = $app->handle($request);
    if ($response->isRedirect()) {
        $result = [
            'status_code' => $response->getStatusCode(),
            'content_type' => $response->headers->get('Content-Type'),
            'location' => $response->headers->get('Location'),
        ];
    } else {
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $result = [
            'status_code' => $response->getStatusCode(),
            'content_type' => $response->headers->get('Content-Type'),
            ...$body,
        ];
    }
    $app->terminate($request, $response);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

file_put_contents(
    $directory.DIRECTORY_SEPARATOR.'result-'.$worker,
    json_encode($result, JSON_THROW_ON_ERROR),
);
