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

    'resend' => [
        'key' => env('RESEND_KEY'),
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

        /*
    |--------------------------------------------------------------------------
    | FX Rate Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for external FX rate providers
    |
    */

    'fixer' => [
        'key' => env('FIXER_API_KEY'),
        'url' => 'http://data.fixer.io/api/latest',
    ],

    'currencylayer' => [
        'key' => env('CURRENCYLAYER_API_KEY'),
        'url' => 'http://api.currencylayer.com/live',
    ],

    'openexchangerates' => [
        'key' => env('OPENEXCHANGERATES_API_KEY'),
        'url' => 'https://openexchangerates.org/api/latest.json',
    ],

    'exchangerate_api' => [
        'url' => 'https://api.exchangerate-api.com/v4/latest/',
        // No API key required for basic tier
    ],



    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Services
    |--------------------------------------------------------------------------
    */

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'mpesa' => [
        // Consumer credentials
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        
        // Environment configuration
        'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
        
        // C2B (Customer to Business) configuration
        'shortcode' => env('MPESA_SHORTCODE'),
        'passkey' => env('MPESA_PASSKEY'),
        'callback_url' => env('APP_URL') . '/api/v1/webhooks/mpesa',
        
        // B2C (Business to Customer) configuration
        'b2c_shortcode' => env('MPESA_B2C_SHORTCODE'),
        'b2c_initiator_name' => env('MPESA_B2C_INITIATOR_NAME'),
        'b2c_security_credential' => env('MPESA_B2C_SECURITY_CREDENTIAL'),
        'b2c_result_url' => env('APP_URL') . '/api/v1/webhooks/mpesa/b2c/result',
        'b2c_timeout_url' => env('APP_URL') . '/api/v1/webhooks/mpesa/b2c/timeout',

        // Transaction limits
        'b2c_min_amount' => env('MPESA_B2C_MIN_AMOUNT', 10),
        'b2c_max_amount' => env('MPESA_B2C_MAX_AMOUNT', 70000),
        'b2c_daily_limit' => env('MPESA_B2C_DAILY_LIMIT', 150000),
    ],

    'telebirr' => [
        'app_id' => env('TELEBIRR_APP_ID'),
        'app_key' => env('TELEBIRR_APP_KEY'),
        'merchant_id' => env('TELEBIRR_MERCHANT_ID'),
        'environment' => env('TELEBIRR_ENVIRONMENT', 'sandbox'),
        'callback_url' => env('APP_URL') . '/api/v1/webhooks/telebirr',
    ],
];
