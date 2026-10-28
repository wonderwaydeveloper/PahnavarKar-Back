<?php

use App\Services\Payments\SadadGateway;
use App\Services\Payments\SepGateway;
use App\Services\Payments\ZarinpalGateway;

return [
    'environment' => in_array(env('PAYMENT_ENVIRONMENT', 'sandbox'), ['sandbox', 'production'], true)
        ? env('PAYMENT_ENVIRONMENT', 'sandbox')
        : 'invalid',
    'http' => [
        'connect_timeout' => (int) env('PAYMENT_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('PAYMENT_TIMEOUT', 15),
    ],
    'max_top_up' => (int) (env('PAYMENT_MAX_TOP_UP') ?: 100000000),
    'default' => env('PAYMENT_GATEWAY', 'zarinpal'),
    'app_return_url' => env('PAYMENT_APP_RETURN_URL', 'pahnavarkar://payment-return'),
    'failover' => array_values(array_filter(explode(',', (string) env('PAYMENT_GATEWAY_FAILOVER', '')))),
    'gateways' => [
        'zarinpal' => [
            'class' => ZarinpalGateway::class,
            'credentials_configured' => (bool) env('ZARINPAL_SANDBOX_MERCHANT_ID')
                || (bool) env('ZARINPAL_PRODUCTION_MERCHANT_ID'),
            'enabled' => (bool) env('ZARINPAL_PAYMENT_ENABLED', false)
                && (bool) (
                    env('PAYMENT_ENVIRONMENT', 'sandbox') === 'production'
                        ? env('ZARINPAL_PRODUCTION_MERCHANT_ID')
                        : env('ZARINPAL_SANDBOX_MERCHANT_ID')
                )
                && in_array(env('PAYMENT_ENVIRONMENT', 'sandbox'), ['sandbox', 'production'], true),
            'merchant_ids' => [
                'sandbox' => env('ZARINPAL_SANDBOX_MERCHANT_ID'),
                'production' => env('ZARINPAL_PRODUCTION_MERCHANT_ID'),
            ],
            'api_urls' => [
                'sandbox' => 'https://sandbox.zarinpal.com/pg/v4/payment',
                'production' => 'https://payment.zarinpal.com/pg/v4/payment',
            ],
            'start_urls' => [
                'sandbox' => 'https://sandbox.zarinpal.com/pg/StartPay',
                'production' => 'https://payment.zarinpal.com/pg/StartPay',
            ],
        ],
        'sadad' => [
            'class' => SadadGateway::class,
            'enabled' => env('SADAD_PAYMENT_ENABLED', false),
            'merchant' => env('SADAD_MERCHANT_ID'),
            'terminal_id' => env('SADAD_TERMINAL_ID'),
            'key' => env('SADAD_KEY'),
            'webhook_secret' => env('SADAD_WEBHOOK_SECRET'),
            'callback_secret' => env('SADAD_CALLBACK_SECRET'),
            'use_webhook' => false,
        ],
        'sep' => [
            'class' => SepGateway::class,
            'enabled' => env('SEP_PAYMENT_ENABLED', false),
            'merchant' => env('SEP_MERCHANT_ID'),
            'terminal_id' => env('SEP_TERMINAL_ID'),
            'password' => env('SEP_PASSWORD'),
            'webhook_secret' => env('SEP_WEBHOOK_SECRET'),
            'callback_secret' => env('SEP_CALLBACK_SECRET'),
            'use_webhook' => true,
        ],
    ],
];
