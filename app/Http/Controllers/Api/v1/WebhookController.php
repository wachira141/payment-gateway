<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhook;
use App\Models\PaymentGateway;
use App\Services\PaymentProcessorService;
use App\Services\CommissionCalculationService;
use App\Services\WebhookProcessingService;
use App\Services\GatewayPricingService;
use App\Services\PaymentIntentService;
use App\Services\WebhookEventService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    protected $commissionCalculationService;
    protected $webhookProcessingService;
    protected $purchasedServiceService;
    protected $gatewayPricingService;
    protected $paymentIntentService;
    protected $webhookEventService;
    protected $goalRequestService;
    protected $paymentProcessor;
    protected $mealPlanService;
    protected $bookingService;
    protected $services;


    public function __construct(
        CommissionCalculationService $commissionCalculationService,
        WebhookProcessingService $webhookProcessingService,
        GatewayPricingService $gatewayPricingService,
        PaymentIntentService $paymentIntentService,
        PaymentProcessorService $paymentProcessor,
        WebhookEventService $webhookEventService,
    ) {
        $this->commissionCalculationService = $commissionCalculationService;
        $this->webhookProcessingService = $webhookProcessingService;
        $this->gatewayPricingService = $gatewayPricingService;
        $this->paymentIntentService = $paymentIntentService;
        $this->webhookEventService = $webhookEventService;
        $this->paymentProcessor = $paymentProcessor;
    }

    /**
     * Handle Stripe webhooks
     */
    public function handleStripe(Request $request)
    {
        return $this->processWebhook($request, 'stripe');
    }

    /**
     * Handle M-Pesa webhooks
     */
    public function handleMpesa(Request $request)
    {
        return $this->processWebhook($request, 'mpesa');
    }

    /**
     * Handle M-Pesa B2C result webhooks
     */
    public function handleMpesaB2CResult(Request $request)
    {
        return $this->processWebhook($request, 'mpesa', 'b2c_result');
    }

    /**
     * Handle M-Pesa B2C timeout webhooks
     */
    public function handleMpesaB2CTimeout(Request $request)
    {
        return $this->processWebhook($request, 'mpesa', 'b2c_timeout');
    }

    /**
     * Handle Telebirr webhooks
     */
    public function handleTelebirr(Request $request)
    {
        return $this->processWebhook($request, 'telebirr');
    }

    /**
     * Process webhook from any gateway
     */
    private function processWebhook(Request $request, string $gatewayType, string | null  $eventType = null)
    {
        try {
            // Get gateway
            $gateway = PaymentGateway::where('type', $gatewayType)->first();
            if (!$gateway) {
                Log::error("Webhook received for unknown gateway: {$gatewayType}");
                return response()->json(['error' => 'Gateway not found'], 404);
            }

            // Create webhook record
            $appId = $this->extractAppIdFromWebhook($request, $gatewayType);
            Log::info("Extracted app ID from webhook: " . ($appId ?: 'null'));

            $webhook = PaymentWebhook::create([
                'payment_gateway_id' => $gateway->id,
                'merchant_app_id' => $appId,
                'webhook_id' => Str::uuid(),
                'event_type' => $eventType ?: $this->determineEventType($request, $gatewayType),
                'gateway_event_id' => $this->extractEventId($request, $gatewayType),
                'payload' => $request->all(),
                'status' => 'pending',
            ]);

            // Dispatch webhook processing job for background processing
            \App\Jobs\WebhookProcessingJob::dispatch($webhook);

            // For B2C webhooks or disbursements, process immediately for faster response
            if ($gatewayType === 'mpesa' && in_array($eventType, ['b2c_result', 'b2c_timeout'])) {
                $result = $this->webhookProcessingService->processWebhook($webhook);

                if ($result['success']) {
                    $webhook->markProcessed();
                    return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Success']);
                } else {
                    $webhook->markFailed($result['error']);
                    Log::error("B2C webhook processing failed: " . $result['error']);
                    return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Failed']);
                }
            }

            // $result = $this->webhookProcessingService->processWebhook($webhook);

            // For regular payment webhooks, use legacy processing for backward compatibility
            $result = $this->paymentProcessor->processWebhook($webhook, $gatewayType);

            // Trigger outbound event handling
            $this->webhookEventService->processIncomingWebhook($webhook, $result);

            if ($result['success']) {
                $webhook->markProcessed();

                // Ensure transaction has gateway information
                $this->enrichTransactionWithGatewayInfo($result['transaction'], $gatewayType);

                // Calculate gateway-based fees and commission
                $feeCalculation = $this->gatewayPricingService->calculateFeesForTransaction($result['transaction']);

                // Process the commission for the platform using gateway-based pricing
                $this->commissionCalculationService->processCommission($result['transaction'], $feeCalculation);

                // Create charge and handle ledger balancing for successful payment intents
                $this->paymentIntentService->handleSuccessfulPaymentIntent($result['transaction'], $gatewayType, $feeCalculation);

                return response()->json(['success' => true]);
            } else {
                $webhook->markFailed($result['error']);
                Log::error("Webhook processing failed: " . $result['error']);
                return response()->json(['error' => 'Processing failed'], 500);
            }
        } catch (\Exception $e) {
            Log::error("Webhook error: " . $e->getMessage());

            if (isset($webhook)) {
                $webhook->markFailed($e->getMessage());
            }

            return response()->json(['error' => 'Internal error'], 500);
        }
    }


    /**
     * Determine event type from webhook payload
     */
    private function determineEventType(Request $request, string $gatewayType): string
    {
        $gatewayMappings = WebhookEventService::getGatewayEventMappings($gatewayType);

        switch ($gatewayType) {
            case 'mpesa':
                // Check if it's B2C callback
                if ($request->has('Result')) {
                    $resultCode = $request->input('Result.ResultCode');
                    $rawEvent = $resultCode == 0 ? 'b2c_success' : 'b2c_failure';
                    return $gatewayMappings[$rawEvent] ?? 'disbursement.' . ($resultCode == 0 ? 'completed' : 'failed');
                }
                // STK Push callback
                if ($request->has('Body.stkCallback')) {
                    $resultCode = $request->input('Body.stkCallback.ResultCode');
                    $rawEvent = $resultCode == 0 ? 'stk_success' : 'stk_failure';
                    return $gatewayMappings[$rawEvent] ?? 'payment.' . ($resultCode == 0 ? 'completed' : 'failed');
                }
                return 'unknown';
            case 'stripe':
                $stripeEventType = $request->input('type', 'unknown');
                return $gatewayMappings[$stripeEventType] ?? $stripeEventType;
            case 'telebirr':
                $telebirrEventType = $request->header('X-Event-Type', 'unknown');
                return $gatewayMappings[$telebirrEventType] ?? $telebirrEventType;
            default:
                return 'unknown';
        }
    }


    /**
     * Extract event ID from webhook payload based on gateway type
     */
    private function extractEventId(Request $request, string $gatewayType): ?string
    {
        switch ($gatewayType) {
            case 'stripe':
                return $request->input('id');
            case 'mpesa':
                // B2C callback
                if ($request->has('Result')) {
                    return $request->input('Result.ConversationID');
                }
                // STK Push callback
                return $request->input('Body.stkCallback.CheckoutRequestID');
            case 'telebirr':
                return $request->input('orderId');
            default:
                return null;
        }
    }

    /**
     * Retry failed webhooks
     */
    public function retryWebhook($webhookId)
    {
        $webhook = PaymentWebhook::where('webhook_id', $webhookId)->first();

        if (!$webhook) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook not found',
            ], 404);
        }

        if ($webhook->status !== 'failed' || $webhook->retry_count >= 3) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook cannot be retried',
            ], 400);
        }

        try {
            $result = $this->paymentProcessor->processWebhook($webhook, $webhook->paymentGateway->type);

            if ($result['success']) {
                $webhook->markProcessed();
                return response()->json([
                    'success' => true,
                    'message' => 'Webhook processed successfully',
                ]);
            } else {
                $webhook->markFailed($result['error']);
                return response()->json([
                    'success' => false,
                    'message' => 'Webhook processing failed',
                    'error' => $result['error'],
                ], 500);
            }
        } catch (\Exception $e) {
            $webhook->markFailed($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Webhook retry failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get webhook logs
     */

    public function getLogs(Request $request)
    {
        $merchantId = $request->user()->merchant_id;

        $query = PaymentWebhook::with(['paymentGateway', 'merchantApp'])
            ->whereHas('merchantApp', function ($q) use ($merchantId) {
                $q->where('merchant_id', $merchantId);
            })
            ->orderBy('created_at', 'desc');

        // Filter by specific app if provided
        if ($request->filled('app_id')) {
            $query->where('merchant_app_id', $request->app_id);
        }

        // Filter by gateway type
        if ($request->filled('gateway_type') && $request->gateway_type !== 'all') {
            $query->whereHas('paymentGateway', function ($q) use ($request) {
                $q->where('type', $request->gateway_type);
            });
        }

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by event type
        if ($request->filled('event_type') && $request->event_type !== 'all') {
            $query->where('event_type', $request->event_type);
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('webhook_id', 'like', "%{$search}%")
                    ->orWhere('gateway_event_id', 'like', "%{$search}%")
                    ->orWhere('event_type', 'like', "%{$search}%");
            });
        }

        $webhooks = $query->paginate($request->get('per_page', 20));

        // Transform the response to include app information
        $webhooks->getCollection()->transform(function ($webhook) {
            return [
                'id' => $webhook->id,
                'webhook_id' => $webhook->webhook_id,
                'event_type' => $webhook->event_type,
                'event_id' => $webhook->gateway_event_id,
                'gateway_type' => $webhook->paymentGateway ? $webhook->paymentGateway->type : null,
                'app_id' => $webhook->merchant_app_id,
                'app_name' => $webhook->merchantApp ? $webhook->merchantApp->name : null,
                'status' => $webhook->status,
                'retry_count' => $webhook->retry_count,
                'payload' => $webhook->payload,
                'error' => $webhook->processing_error,
                'created_at' => $webhook->created_at,
                'processed_at' => $webhook->processed_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $webhooks,
        ]);
    }



    /**
     * Get webhook details by ID
     */
    public function getWebhook(string $webhookId): JsonResponse
    {
        try {
            $webhook = $this->webhookProcessingService->getWebhookById($webhookId);

            return response()->json([
                'success' => true,
                'data' => $webhook,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Get webhook statistics
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $timeframe = $request->get('timeframe', '24h');
            $stats = $this->webhookProcessingService->getWebhookStats($timeframe);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk retry failed webhooks
     */
    public function bulkRetry(Request $request): JsonResponse
    {
        try {
            $webhookIds = $request->input('webhook_ids', []);
            $results = $this->webhookProcessingService->bulkRetryWebhooks($webhookIds);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available event types
     */
    public function getEventTypes(): JsonResponse
    {
        try {
            $eventTypes = $this->webhookProcessingService->getAvailableEventTypes();

            return response()->json([
                'success' => true,
                'data' => $eventTypes,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Replay/resend webhook
     */
    public function replay(string $webhookId): JsonResponse
    {
        try {
            $replayWebhook = $this->webhookProcessingService->replayWebhook($webhookId);

            return response()->json([
                'success' => true,
                'data' => $replayWebhook,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Enrich transaction with gateway information from webhook context
     */
    private function enrichTransactionWithGatewayInfo($transaction, string $gatewayType): void
    {
        // Map gateway type to standardized codes
        $gatewayMapping = [
            'stripe' => ['gateway_code' => 'stripe', 'payment_method_type' => 'card'],
            'mpesa' => ['gateway_code' => 'mpesa', 'payment_method_type' => 'mobile_money'],
            'telebirr' => ['gateway_code' => 'telebirr', 'payment_method_type' => 'mobile_money'],
        ];

        $mapping = $gatewayMapping[$gatewayType] ?? [
            'gateway_code' => $gatewayType,
            'payment_method_type' => 'unknown'
        ];

        // Update transaction with gateway information if not already set
        $updateData = [];
        if (!$transaction->gateway_code) {
            $updateData['gateway_code'] = $mapping['gateway_code'];
        }
        if (!$transaction->payment_method_type) {
            $updateData['payment_method_type'] = $mapping['payment_method_type'];
        }

        if (!empty($updateData)) {
            $transaction->update($updateData);
        }
    }

    /**
     * Extract app ID from webhook payload or related payment intent
     */
    private function extractAppIdFromWebhook(Request $request, string $gatewayType): ?string
    {
        try {
            // Try to get app_id from payment intent reference
            $eventId = $this->extractEventId($request, $gatewayType);
            Log::info("Extracted event ID from webhook: " . ($eventId ?: 'null'));
            if ($eventId) {
                // For Stripe webhooks, lookup payment intent by stripe_payment_intent_id
                if ($gatewayType === 'stripe' && $request->has('data.object.id')) {
                    $stripePaymentIntentId = $request->input('data.object.id');
                    $paymentIntent = \App\Models\PaymentIntent::where('gateway_payment_intent_id', $stripePaymentIntentId)->first();
                    if ($paymentIntent) {
                        return $paymentIntent->merchant_app_id;
                    }
                }

                // For M-Pesa, try to find by checkout request ID or conversation ID
                if ($gatewayType === 'mpesa') {
                    $paymentIntent = \App\Models\PaymentIntent::where('gateway_data->checkout_request_id', $eventId)
                        ->orWhere('intent_id', $eventId)
                        ->first();
                    Log::info(json_encode($paymentIntent));
                    if ($paymentIntent) {
                        return $paymentIntent->merchant_app_id;
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('Failed to extract app_id from webhook', [
                'gateway_type' => $gatewayType,
                'event_id' => $eventId ?? null,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
