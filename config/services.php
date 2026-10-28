<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'melipayamak' => [
        'enabled' => env('MELIPAYAMAK_ENABLED', false),
        'local_log_enabled' => env('MELIPAYAMAK_LOCAL_LOG_ENABLED', false),
        'url' => env('MELIPAYAMAK_API_URL'),
        'otp_body_id' => env('MELIPAYAMAK_OTP_BODY_ID'),
        'inactivity_warning_body_id' => env('MELIPAYAMAK_INACTIVITY_WARNING_BODY_ID'),
    ],

    'sms' => [
        'queue_enabled' => env('SMS_OTP_QUEUE_ENABLED', false),
        'queue' => env('SMS_OTP_QUEUE', 'sms'),
    ],

    'expo_push' => [
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

];
