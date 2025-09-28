<?php

return [


    /*
  |--------------------------------------------------------------------------
  | Application Scopes
  |--------------------------------------------------------------------------
  |
  | Define the available API scopes for applications
  |
  */
  'scopes' => [
      'payments:read' => 'Read payment intents and transactions',
      'payments:write' => 'Create and modify payment intents',
      'payouts:read' => 'Read payout information',
      'payouts:write' => 'Create and manage payouts',
      'balances:read' => 'Read account balances',
      'webhooks:manage' => 'Manage webhook endpoints and events',
      'refunds:read' => 'Read refund information',
      'refunds:write' => 'Create and process refunds',
  ],

  /*
  |--------------------------------------------------------------------------
  | Webhook Events
  |--------------------------------------------------------------------------
  |
  | Define the available webhook events for merchants
  |
  */
  'webhook_events' => [
      'payment_intent.created' => 'Payment intent created',
      'payment_intent.confirmed' => 'payment_intent.confirmed',
      'payment_intent.requires_action' => 'Payment intent requires action',
      'payment_intent.succeeded' => 'Payment intent succeeded',
      'payment_intent.failed' => 'Payment intent failed',
      'payment_intent.canceled' => 'Payment intent canceled',
      'refund.created' => 'Refund created',
      'refund.succeeded' => 'Refund succeeded',
      'refund.failed' => 'Refund failed',
      'payout.created' => 'Payout created',
      'payout.succeeded' => 'Payout succeeded',
      'payout.failed' => 'Payout failed',
      'account.updated' => 'Account updated',
      'balance.updated' => 'Balance updated',
      'charge.created' => 'Charge created',
      'charge.succeeded' => 'Charge succeeded',
      'charge.failed' => 'Charge failed',
      'settlement.created' => 'Settlement created',
      'settlement.completed' => 'Settlement completed',
  ],

  /*
  |--------------------------------------------------------------------------
  | Webhook Event Mappings
  |--------------------------------------------------------------------------
  |
  | Maps gateway-specific events to merchant-facing events
  |
  */
  'webhook_event_mappings' => [
      // Payment Intent Events - normalize variations to standard merchant events
      'payment_intent.succeeded' => 'payment_intent.succeeded',
      'payment_intent.payment_succeeded' => 'payment_intent.succeeded',
      'payment_intent.failed' => 'payment_intent.failed',
      'payment_intent.payment_failed' => 'payment_intent.failed',
      'payment_intent.cancelled' => 'payment_intent.canceled',
      'payment_intent.confirmed' => 'payment_intent.requires_action',
      'payment_intent.captured' => 'payment_intent.succeeded',
      'payment_intent.created' => 'payment_intent.created',

      // Generic payment events to payment intent events
      'payment.completed' => 'payment_intent.succeeded',
      'payment.failed' => 'payment_intent.failed',
      'payment.pending' => 'payment_intent.requires_action',
      'payment.cancelled' => 'payment_intent.canceled',

      // Charge Events
      'charge.succeeded' => 'charge.succeeded',
      'charge.failed' => 'charge.failed',

      // Refund Events
      'refund.completed' => 'refund.succeeded',
      'refund.failed' => 'refund.failed',

      // Disbursement Events to Payout Events
      'disbursement.completed' => 'payout.succeeded',
      'disbursement.failed' => 'payout.failed',
  ],

  /*
  |--------------------------------------------------------------------------
  | Gateway Event Type Mappings
  |--------------------------------------------------------------------------
  |
  | Maps gateway-specific event determination to standard event types
  |
  */
  'gateway_event_mappings' => [
      'mpesa' => [
          // STK Push callbacks
          'payment_intent.succeeded' => 'payment_intent.succeeded',
          'payment_intent.failed' => 'payment_intent.failed',
          // B2C disbursement callbacks  
          'b2c_success' => 'payout.succeeded',
          'b2c_failure' => 'payout.failed',
      ],
      'stripe' => [
          // Direct mapping to merchant events
          'payment_intent.succeeded' => 'payment_intent.succeeded',
          'payment_intent.payment_failed' => 'payment_intent.failed',
          'payment_intent.canceled' => 'payment_intent.canceled',
          'charge.succeeded' => 'charge.succeeded',
          'charge.failed' => 'charge.failed',
      ],
      'telebirr' => [
          // Telebirr uses payment.* format
          'payment.success' => 'payment_intent.succeeded',
          'payment.failed' => 'payment_intent.failed',
      ],
  ],

    /*
  |--------------------------------------------------------------------------
  | Application Defaults
  |--------------------------------------------------------------------------
  |
  | Default settings for new applications
  |
  */
  'defaults' => [
      'webhook_events' => [
          'payment_intent.succeeded',
          'payment_intent.failed',
          'charge.succeeded',
          'charge.failed',
      ],
      'settings' => [
          'auto_capture' => false,
          'webhook_version' => '2024-01-01',
          'rate_limit' => [
              'requests_per_minute' => 100,
              'burst_limit' => 200,
          ],
      ],
  ],

  /*
  |--------------------------------------------------------------------------
  | Validation Rules
  |--------------------------------------------------------------------------
  |
  | Common validation rules for applications
  |
  */
  'validation' => [
      'name' => 'required|string|max:255',
      'description' => 'nullable|string|max:1000',
      'webhook_url' => 'nullable|url|max:2048',
      'redirect_urls' => 'nullable|array|max:10',
      'redirect_urls.*' => 'url|max:2048',
      'logo_url' => 'nullable|url|max:2048',
      'website_url' => 'nullable|url|max:2048',
      // 'scopes' => 'required|array|min:1',
      'webhook_events' => 'nullable|array',
  ],

  /*
  |--------------------------------------------------------------------------
  | Application Name
  |--------------------------------------------------------------------------
  |
  | This value is the name of your application, which will be used when the
  | framework needs to place the application's name in a notification or
  | other UI elements where an application name needs to be displayed.
  |
  */

  'name' => env('APP_NAME', 'Laravel'),

  /*
  |--------------------------------------------------------------------------
  | Application Environment
  |--------------------------------------------------------------------------
  |
  | This value determines the "environment" your application is currently
  | running in. This may determine how you prefer to configure various
  | services the application utilizes. Set this in your ".env" file.
  |
  */

  'env' => env('APP_ENV', 'production'),

  /*
  |--------------------------------------------------------------------------
  | Application Debug Mode
  |--------------------------------------------------------------------------
  |
  | When your application is in debug mode, detailed error messages with
  | stack traces will be shown on every error that occurs within your
  | application. If disabled, a simple generic error page is shown.
  |
  */

  'debug' => (bool) env('APP_DEBUG', false),

  /*
  |--------------------------------------------------------------------------
  | Application URL
  |--------------------------------------------------------------------------
  |
  | This URL is used by the console to properly generate URLs when using
  | the Artisan command line tool. You should set this to the root of
  | the application so that it's available within Artisan commands.
  |
  */

  'url' => env('APP_URL', 'http://localhost'),

  /*
  |--------------------------------------------------------------------------
  | Application Timezone
  |--------------------------------------------------------------------------
  |
  | Here you may specify the default timezone for your application, which
  | will be used by the PHP date and date-time functions. The timezone
  | is set to "UTC" by default as it is suitable for most use cases.
  |
  */

  'timezone' => 'UTC',

  /*
  |--------------------------------------------------------------------------
  | Application Locale Configuration
  |--------------------------------------------------------------------------
  |
  | The application locale determines the default locale that will be used
  | by Laravel's translation / localization methods. This option can be
  | set to any locale for which you plan to have translation strings.
  |
  */

  'locale' => env('APP_LOCALE', 'en'),

  'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

  'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

  /*
  |--------------------------------------------------------------------------
  | Encryption Key
  |--------------------------------------------------------------------------
  |
  | This key is utilized by Laravel's encryption services and should be set
  | to a random, 32 character string to ensure that all encrypted values
  | are secure. You should do this prior to deploying the application.
  |
  */

  'cipher' => 'AES-256-CBC',

  'key' => env('APP_KEY'),

  'previous_keys' => [
      ...array_filter(
          explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
      ),
  ],

  /*
  |--------------------------------------------------------------------------
  | Maintenance Mode Driver
  |--------------------------------------------------------------------------
  |
  | These configuration options determine the driver used to determine and
  | manage Laravel's "maintenance mode" status. The "cache" driver will
  | allow maintenance mode to be controlled across multiple machines.
  |
  | Supported drivers: "file", "cache"
  |
  */

  'maintenance' => [
      'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
      'store' => env('APP_MAINTENANCE_STORE', 'database'),
  ],

];
