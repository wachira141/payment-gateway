<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\WebhookEventService;
use Illuminate\Http\Request;

class WebhookFlowController extends Controller
{
    protected WebhookEventService $webhookEventService;

    public function __construct(WebhookEventService $webhookEventService)
    {
        $this->webhookEventService = $webhookEventService;
    }

    /**
     * Get webhook flow statistics
     */
    public function getFlowStats(Request $request)
    {
        $timeframe = $request->get('timeframe', '24h');
        
        return response()->json(
            $this->webhookEventService->getWebhookFlowStats($timeframe)
        );
    }

    /**
     * Get available merchant event types for webhook configuration
     */
    public function getEventTypes()
    {
        return response()->json([
            'event_types' => WebhookEventService::getMerchantEventTypes()
        ]);
    }
}