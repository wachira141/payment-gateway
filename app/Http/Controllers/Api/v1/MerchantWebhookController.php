<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;

class MerchantWebhookController extends Controller
{
    protected $webhookService;

    public function __construct(WebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

   /**
     * Get all webhooks for the merchant across all apps
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant_id;
            $webhooks = $this->webhookService->getMerchantWebhooks($merchant);

            return response()->json([
                'success' => true,
                'data' => $webhooks
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch webhooks',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}