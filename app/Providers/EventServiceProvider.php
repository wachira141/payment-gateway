<?php

namespace App\Providers;

use App\Events\PaymentIntentCreated;
use App\Events\PaymentIntentConfirmed;
use App\Events\PaymentIntentSucceeded;
use App\Events\PaymentIntentFailed;
use App\Events\PaymentIntentCancelled;
use App\Events\PaymentIntentCaptured;
use App\Listeners\SendOutboundWebhooks;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        
        // Payment Intent Events
        PaymentIntentCreated::class => [
            [SendOutboundWebhooks::class, 'handlePaymentIntentCreated'],
        ],
        PaymentIntentConfirmed::class => [
            [SendOutboundWebhooks::class, 'handlePaymentIntentConfirmed'],
        ],
        PaymentIntentSucceeded::class => [
            [SendOutboundWebhooks::class, 'handlePaymentIntentSucceeded'],
        ],
        PaymentIntentFailed::class => [
            [SendOutboundWebhooks::class, 'handlePaymentIntentFailed'],
        ],
        PaymentIntentCancelled::class => [
            [SendOutboundWebhooks::class, 'handlePaymentIntentCancelled'],
        ],
        PaymentIntentCaptured::class => [
            [SendOutboundWebhooks::class, 'handlePaymentIntentCaptured'],
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}