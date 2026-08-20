<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cybersource' => [
        'merchant_id' => env('CYBERSOURCE_MERCHANT_ID'),
        'key_id' => env('CYBERSOURCE_KEY_ID'),
        'secret_key' => env('CYBERSOURCE_SECRET_KEY'),
        'api_url' => env('CYBERSOURCE_API_URL', 'https://api.cybersource.com'),
        'device_org_id' => env('CYBERSOURCE_DEVICE_ORG_ID'),
    ],

    'payment_api' => [
        'username' => env('PAYMENT_API_USERNAME', 'demo-user'),
        'password' => env('PAYMENT_API_PASSWORD', 'demo-pass'),
    ],

    'payment_permissions' => [
        'demo-user' => [
            'provider' => 'cybersource',
            'roles' => ['payments:read', 'payments:charge'],
            'status' => 'active',
            'legacy_user_id' => 1001,
        ],
        'merchant-alpha' => [
            'provider' => 'cybersource',
            'roles' => ['payments:charge'],
            'status' => 'active',
            'legacy_user_id' => 1002,
        ],
        'merchant-beta' => [
            'provider' => 'cybersource',
            'roles' => ['payments:read'],
            'status' => 'inactive',
            'legacy_user_id' => 1003,
        ],
    ],

    'payment_providers_map' => [
        'demo-user' => 'cybersource',
        'merchant-alpha' => 'cybersource',
    ],

    'merchant_notifications' => [
        'api_key' => env('MERCHANT_NOTIFICATION_API_KEY'),
        'success_endpoints' => array_filter(array_map('trim', explode(',', env('MERCHANT_NOTIFICATION_SUCCESS_ENDPOINTS', '')))),
        'error_endpoints' => array_filter(array_map('trim', explode(',', env('MERCHANT_NOTIFICATION_ERROR_ENDPOINTS', '')))),
    ],

];
