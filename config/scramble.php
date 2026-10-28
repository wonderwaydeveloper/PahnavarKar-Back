<?php

use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [
    'api_path' => 'api',
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'Versioned Laravel API for PahnavarKar. Error responses use RFC 7807 with message/errors compatibility fields.',
    ],
    'ui' => [
        'title' => 'PahnavarKar API',
    ],
    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
];
