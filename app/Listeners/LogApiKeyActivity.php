<?php

namespace App\Listeners;

use App\Models\SystemActivity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogApiKeyActivity implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle($event): void
    {
        if (property_exists($event, 'apiKey') && property_exists($event, 'action')) {
            $apiKey = $event->apiKey;
            $action = $event->action;
            
            // Get merchant ID from the API key's app
            $merchantId = $apiKey->app->merchant_id ?? null;
            
            if ($merchantId) {
                SystemActivity::logApiKeyActivity(
                    $merchantId,
                    $action,
                    [
                        'api_key_id' => $apiKey->id,
                        'api_key_name' => $apiKey->name,
                        'app_id' => $apiKey->app_id
                    ]
                );
            }
        }
    }
}