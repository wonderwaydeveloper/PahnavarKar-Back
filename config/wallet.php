<?php

return [
    'currency' => 'TOMAN',
    'unit' => 'toman',
    'withdrawals' => [
        'enabled' => env('WALLET_WITHDRAWALS_ENABLED', false),
        'max_per_request' => 100000,
        'max_per_day' => 500000,
    ],
];
