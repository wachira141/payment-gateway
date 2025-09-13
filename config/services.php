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

];
