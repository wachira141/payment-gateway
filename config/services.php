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

    /*
    |--------------------------------------------------------------------------
    | Airtel Money Configuration
    |--------------------------------------------------------------------------
    */

    'airtel_money' => [
        // OAuth credentials
        'client_id' => env('AIRTEL_MONEY_CLIENT_ID'),
        'client_secret' => env('AIRTEL_MONEY_CLIENT_SECRET'),

        // Environment configuration
        'environment' => env('AIRTEL_MONEY_ENVIRONMENT', 'sandbox'),

        // Default country and currency
        'country' => env('AIRTEL_MONEY_COUNTRY', 'UG'),
        'currency' => env('AIRTEL_MONEY_CURRENCY', 'UGX'),

        // B2C configuration (disbursement)
        'b2c_pin' => env('AIRTEL_MONEY_B2C_PIN'),

        // Callback URLs
        'c2b_callback_url' => env('APP_URL') . '/api/v1/webhooks/airtel/collection',
        'b2c_callback_url' => env('APP_URL') . '/api/v1/webhooks/airtel/disbursement',

        // Transaction limits
        'min_amount' => env('AIRTEL_MONEY_MIN_AMOUNT', 100),
        'c2b_max_amount' => env('AIRTEL_MONEY_C2B_MAX_AMOUNT', 500000),
        'b2c_max_amount' => env('AIRTEL_MONEY_B2C_MAX_AMOUNT', 500000),
        'c2b_daily_limit' => env('AIRTEL_MONEY_C2B_DAILY_LIMIT', 5000000),
        'b2c_daily_limit' => env('AIRTEL_MONEY_B2C_DAILY_LIMIT', 5000000),
    ],

    /*
    |--------------------------------------------------------------------------
    | MTN Mobile Money Configuration
    |--------------------------------------------------------------------------
    */

    'mtn_momo' => [
        // API User credentials (created during onboarding)
        'api_user' => env('MTN_MOMO_API_USER'),
        'api_key' => env('MTN_MOMO_API_KEY'),

        // Subscription keys (from MTN Developer Portal)
        'subscription_key_collections' => env('MTN_MOMO_SUBSCRIPTION_KEY_COLLECTIONS'),
        'subscription_key_disbursements' => env('MTN_MOMO_SUBSCRIPTION_KEY_DISBURSEMENTS'),

        // Environment configuration
        'environment' => env('MTN_MOMO_ENVIRONMENT', 'sandbox'),
        'callback_host' => env('MTN_MOMO_CALLBACK_HOST', env('APP_URL')),

        // Provider callback host (target environment in production)
        'provider_callback_host' => env('MTN_MOMO_PROVIDER_CALLBACK_HOST'),

        // Callback URLs
        'collection_callback_url' => env('APP_URL') . '/api/v1/webhooks/mtn-momo/collection',
        'disbursement_callback_url' => env('APP_URL') . '/api/v1/webhooks/mtn-momo/disbursement',

        // Default country and currency
        'country' => env('MTN_MOMO_COUNTRY', 'UG'),
        'currency' => env('MTN_MOMO_CURRENCY', 'UGX'),

        // Transaction limits
        'min_amount' => env('MTN_MOMO_MIN_AMOUNT', 100),
        'c2b_max_amount' => env('MTN_MOMO_C2B_MAX_AMOUNT', 5000000),
        'b2c_max_amount' => env('MTN_MOMO_B2C_MAX_AMOUNT', 5000000),
        'c2b_daily_limit' => env('MTN_MOMO_C2B_DAILY_LIMIT', 50000000),
        'b2c_daily_limit' => env('MTN_MOMO_B2C_DAILY_LIMIT', 50000000),
    ],

    'telebirr' => [
        'app_id' => env('TELEBIRR_APP_ID'),
        'app_key' => env('TELEBIRR_APP_KEY'),
        'merchant_id' => env('TELEBIRR_MERCHANT_ID'),
        'environment' => env('TELEBIRR_ENVIRONMENT', 'sandbox'),
        'callback_url' => env('APP_URL') . '/api/v1/webhooks/telebirr',
    ],
];
