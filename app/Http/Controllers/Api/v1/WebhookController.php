<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhook;
use App\Models\PaymentGateway;
use App\Models\PaymentIntent;
use App\Models\PaymentTransaction;
use App\Services\PaymentProcessorService;
use App\Services\CommissionCalculationService;
use App\Services\WebhookProcessingService;
use App\Services\GatewayPricingService;
use App\Services\PaymentIntentService;
use App\Services\WebhookEventService;
use Illuminate\Http\Request;
use App\Services\WalletTopUpService;
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
    protected $walletTopUpService;
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
        WalletTopUpService $walletTopUpService,
    ) {
        $this->commissionCalculationService = $commissionCalculationService;
        $this->webhookProcessingService = $webhookProcessingService;
        $this->gatewayPricingService = $gatewayPricingService;
        $this->paymentIntentService = $paymentIntentService;
        $this->webhookEventService = $webhookEventService;
        $this->walletTopUpService = $walletTopUpService;
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
     * Handle Airtel Money C2B (collection) webhooks
     */
    public function handleAirtelMoneyCollection(Request $request)
    {
        return $this->processWebhook($request, 'airtel_money', 'c2b');
    }

    /**
     * Handle Airtel Money B2C (disbursement) webhooks
     */
    public function handleAirtelMoneyDisbursement(Request $request)
    {
        return $this->processWebhook($request, 'airtel_money', 'b2c');
    }

    /**
     * Handle MTN MoMo Collection (C2B) webhooks
     */
    public function handleMTNMoMoCollection(Request $request)
    {
        return $this->processWebhook($request, 'mtn_momo', 'c2b');
    }

    /**
     * Handle MTN MoMo Disbursement (B2C) webhooks
     */
    public function handleMTNMoMoDisbursement(Request $request)
    {
        return $this->processWebhook($request, 'mtn_momo', 'b2c');
    }


    /**
     * Process webhook from any gateway
     */
    private function processWebhook(Request $request, string $gatewayType, string | null  $eventType = null)
    {
        try {
            // Get gateway
            // $gateway = PaymentGateway::where('code', $gatewayType)->first();
            // if (!$gateway) {
            //     Log::error("Webhook received for unknown gateway: {$gatewayType}");
            //     return response()->json(['error' => 'Gateway not found'], 404);
            // }

            // // Create webhook record
            // $paymentIntent = $this->extractPaymentIntentFromWebhook($request, $gatewayType);

            // $appId = $paymentIntent ? $paymentIntent->merchant_app_id : null;

            // Log::info("Extracted app ID from webhook: " . ($paymentIntent->gateway_data['transaction_id'] ?: 'null'));

            // $webhook = PaymentWebhook::create([
            //     'payment_gateway_id' => $gateway->id,
            //     'merchant_app_id' => $appId,
            //     'webhook_id' => Str::uuid(),
            //     'event_type' => $eventType ?: $this->determineEventType($request, $gatewayType),
            //     'gateway_event_id' => $this->extractEventId($request, $gatewayType),
            //     'payment_intent_id' => $paymentIntent ? $paymentIntent->id : null,
            //     'payment_transaction_id' => $paymentIntent ? $paymentIntent->gateway_data['transaction_id'] ?? null : null,
            //     'payload' => $request->all(),
            //     'status' => 'pending',
            // ]);

            // Get gateway
            $gateway = PaymentGateway::where('code', $gatewayType)->first();
            if (!$gateway) {
                Log::error("Webhook received for unknown gateway: {$gatewayType}");
                return response()->json(['error' => 'Gateway not found'], 404);
            }

            // Extract payable information (PaymentIntent or WalletTopUp)
            $payableInfo = $this->extractPayableFromWebhook($request, $gatewayType);

            $appId = $payableInfo['app_id'];
            $merchantId = $payableInfo['merchant_id'];
            $paymentIntent = $payableInfo['type'] === 'payment_intent' ? $payableInfo['payable'] : null;
            $walletTopUp = $payableInfo['type'] === 'wallet_top_up' ? $payableInfo['payable'] : null;
            $existingTransaction = $payableInfo['transaction'];

            // Log what was extracted
            $transactionIdForLog = $existingTransaction?->transaction_id
                ?? $paymentIntent?->gateway_data['transaction_id']
                ?? 'null';
            Log::info("Extracted from webhook - Type: {$payableInfo['type']}, Transaction: {$transactionIdForLog}");

            // Create webhook record
            $webhook = PaymentWebhook::create([
                'payment_gateway_id' => $gateway->id,
                'merchant_app_id' => $appId,
                'merchant_id' => $merchantId, // Add merchant_id for wallet top-ups
                'webhook_id' => Str::uuid(),
                'event_type' => $eventType ?: $this->determineEventType($request, $gatewayType),
                'gateway_event_id' => $this->extractEventId($request, $gatewayType),
                'payment_intent_id' => $paymentIntent?->id,
                'payment_transaction_id' => $existingTransaction?->transaction_id ?? $paymentIntent?->gateway_data['transaction_id'] ?? null,
                'wallet_top_up_id' => $walletTopUp?->id, // Add wallet_top_up_id
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

            // For Airtel Money B2C webhooks, process immediately
            if ($gatewayType === 'airtel_money' && $eventType === 'b2c') {
                $result = $this->webhookProcessingService->processWebhook($webhook);

                if ($result['success']) {
                    $webhook->markProcessed();
                    return response()->json(['status' => 'success', 'message' => 'Callback received']);
                } else {
                    $webhook->markFailed($result['error']);
                    Log::error("Airtel Money B2C webhook processing failed: " . $result['error']);
                    return response()->json(['status' => 'error', 'message' => 'Processing failed'], 500);
                }
            }
            // For MTN MoMo B2C webhooks, process immediately
            if ($gatewayType === 'mtn_momo' && $eventType === 'b2c') {
                $result = $this->webhookProcessingService->processWebhook($webhook);

                if ($result['success']) {
                    $webhook->markProcessed();
                    return response()->json(['status' => 'success', 'message' => 'Callback received']);
                } else {
                    $webhook->markFailed($result['error']);
                    Log::error("MTN MoMo B2C webhook processing failed: " . $result['error']);
                    return response()->json(['status' => 'error', 'message' => 'Processing failed'], 500);
                }
            }

            // $result = $this->webhookProcessingService->processWebhook($webhook);

            // For regular payment webhooks, use legacy processing for backward compatibility
            $result = $this->paymentProcessor->processWebhook($webhook, $gatewayType);

            // Trigger outbound event handling
            $this->webhookEventService->processIncomingWebhook($webhook, $gateway->type, $result);

            if ($result['success']) {
                $webhook->markProcessed();
                $transaction = $result['transaction'] ?? null;

                if ($transaction) {
                    // Check if this is a wallet top-up transaction
                    if ($transaction->isWalletTopUp()) {
                        $this->handleWalletTopUpWebhook($transaction);
                    } else {
                        // Regular payment intent handling
                        // Ensure transaction has gateway information
                        $this->enrichTransactionWithGatewayInfo($transaction, $gatewayType);

                        // Calculate gateway-based fees and commission
                        $feeCalculation = $this->gatewayPricingService->calculateFeesForTransaction($transaction);

                        // Process the commission for the platform using gateway-based pricing
                        $this->commissionCalculationService->processCommission($transaction, $feeCalculation);

                        // Create charge and handle ledger balancing for successful payment intents
                        $this->paymentIntentService->handleSuccessfulPaymentIntent($transaction, $gatewayType, $feeCalculation);
                    }
                }
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
     * Handle wallet top-up webhook completion
     */
    protected function handleWalletTopUpWebhook(\App\Models\PaymentTransaction $transaction): void
    {
        try {
            $this->walletTopUpService->processTopUpFromTransaction($transaction);

            Log::info('Wallet top-up processed successfully', [
                'transaction_id' => $transaction->transaction_id,
                'payable_id' => $transaction->payable_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process wallet top-up from webhook', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $e->getMessage(),
            ]);
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
                    return $gatewayMappings[$rawEvent] ?? 'payment_intent.' . ($resultCode == 0 ? 'succeeded' : 'failed');
                }
                return 'unknown';
            case 'stripe':
                $stripeEventType = $request->input('type', 'unknown');
                return $gatewayMappings[$stripeEventType] ?? $stripeEventType;
            case 'telebirr':
                $telebirrEventType = $request->header('X-Event-Type', 'unknown');
                return $gatewayMappings[$telebirrEventType] ?? $telebirrEventType;
            case 'airtel_money':
                // Check transaction status from Airtel Money callback
                $statusCode = $request->input('transaction.status_code', $request->input('status_code'));
                $airtelEventMappings = [
                    'TS' => 'payment_intent.succeeded',
                    'TF' => 'payment_intent.failed',
                    'TIP' => 'payment_intent.processing',
                    'TA' => 'payment_intent.cancelled',
                ];
                return $airtelEventMappings[$statusCode] ?? ($gatewayMappings[$statusCode] ?? 'unknown');
            case 'mtn_momo':
                // MTN MoMo uses status field
                $status = $request->input('status', $request->input('financialTransactionId') ? 'SUCCESSFUL' : 'unknown');
                $mtnEventMappings = [
                    'SUCCESSFUL' => 'payment_intent.succeeded',
                    'FAILED' => 'payment_intent.failed',
                    'PENDING' => 'payment_intent.processing',
                    'REJECTED' => 'payment_intent.cancelled',
                    'TIMEOUT' => 'payment_intent.failed',
                ];
                return $mtnEventMappings[$status] ?? ($gatewayMappings[$status] ?? 'unknown');
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
            case 'airtel_money':
                // Airtel Money transaction ID
                return $request->input('transaction.id', $request->input('transaction_id'));
            case 'mtn_momo':
                // MTN MoMo uses externalId or referenceId
                return $request->input('externalId', $request->input('referenceId'));
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
            'data' => $webhooks->items(),
            'pagination' => [
                'current_page' => $webhooks->currentPage(),
                'last_page' => $webhooks->lastPage(),
                'per_page' => $webhooks->perPage(),
                'total' => $webhooks->total(),
                'from' => $webhooks->firstItem(),
                'to' => $webhooks->lastItem(),
            ],
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
            'airtel_money' => ['gateway_code' => 'airtel_money', 'payment_method_type' => 'mobile_money'],
            'mtn_momo' => ['gateway_code' => 'mtn_momo', 'payment_method_type' => 'mobile_money'],
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
    private function extractPaymentIntentFromWebhook(Request $request, string $gatewayType): ?PaymentIntent
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
                        return $paymentIntent;
                    }
                }

                // For M-Pesa, try to find by checkout request ID or conversation ID
                if ($gatewayType === 'mpesa') {
                    $paymentIntent = \App\Models\PaymentIntent::where('gateway_data->checkout_request_id', $eventId)
                        ->orWhere('intent_id', $eventId)
                        ->first();
                    Log::info(json_encode($paymentIntent));
                    if ($paymentIntent) {
                        return $paymentIntent;
                    }
                }
                // For Airtel Money, try to find by transaction ID or reference
                if ($gatewayType === 'airtel_money') {
                    $transactionId = $request->input('transaction.id', $request->input('transaction_id'));
                    $reference = $request->input('transaction.airtel_money_id', $request->input('reference'));

                    $paymentIntent = \App\Models\PaymentIntent::where('gateway_data->transaction_id', $transactionId)
                        ->orWhere('gateway_data->airtel_reference', $reference)
                        ->orWhere('intent_id', $transactionId)
                        ->first();

                    if ($paymentIntent) {
                        return $paymentIntent;
                    }
                }

                // For MTN MoMo, try to find by reference ID or external ID
                if ($gatewayType === 'mtn_momo') {
                    $referenceId = $request->input('referenceId', $request->input('externalId'));
                    $externalId = $request->input('externalId');

                    $paymentIntent = \App\Models\PaymentIntent::where('gateway_data->reference_id', $referenceId)
                        ->orWhere('gateway_data->external_id', $externalId)
                        ->orWhere('intent_id', $externalId)
                        ->first();

                    if ($paymentIntent) {
                        return $paymentIntent;
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

    /**
     * Extract payable information from webhook (PaymentIntent or WalletTopUp)
     */
    private function extractPayableFromWebhook(Request $request, string $gatewayType): array
    {
        // First try to find by PaymentTransaction (linked to any payable type)
        $transaction = $this->findPaymentTransactionFromWebhook($request, $gatewayType);

        if ($transaction) {
            // Check if it's a wallet top-up
            if ($transaction->isWalletTopUp()) {
                return [
                    'type' => 'wallet_top_up',
                    'payable' => $transaction->getWalletTopUp(),
                    'transaction' => $transaction,
                    'merchant_id' => $transaction->merchant_id,
                    'app_id' => null,
                ];
            }

            // It's a regular payment - get payment intent
            $paymentIntent = PaymentIntent::where('id', $transaction->payable_id)->first();
            return [
                'type' => 'payment_intent',
                'payable' => $paymentIntent,
                'transaction' => $transaction,
                'merchant_id' => $transaction->merchant_id,
                'app_id' => $paymentIntent?->merchant_app_id,
            ];
        }

        // Fall back to legacy PaymentIntent extraction
        $paymentIntent = $this->extractPaymentIntentFromWebhook($request, $gatewayType);

        return [
            'type' => $paymentIntent ? 'payment_intent' : null,
            'payable' => $paymentIntent,
            'transaction' => null,
            'merchant_id' => $paymentIntent?->merchant?->id,
            'app_id' => $paymentIntent?->merchant_app_id,
        ];
    }

    /**
     * Find PaymentTransaction from webhook payload
     */
    private function findPaymentTransactionFromWebhook(Request $request, string $gatewayType): ?PaymentTransaction
    {
        switch ($gatewayType) {
            case 'stripe':
                $stripePaymentIntentId = $request->input('data.object.id');
                return PaymentTransaction::where('gateway_payment_intent_id', $stripePaymentIntentId)->first();

            case 'mpesa':
                $checkoutRequestId = $request->input('Body.stkCallback.CheckoutRequestID');
                return PaymentTransaction::where('gateway_response->checkout_request_id', $checkoutRequestId)->first();

            case 'airtel_money':
                $transactionId = $request->input('transaction.id', $request->input('transaction_id'));
                return PaymentTransaction::where('transaction_id', $transactionId)
                    ->orWhere('gateway_response->transaction_id', $transactionId)->first();

            case 'mtn_momo':
                $externalId = $request->input('externalId', $request->input('referenceId'));
                return PaymentTransaction::where('transaction_id', $externalId)
                    ->orWhere('gateway_response->reference_id', $externalId)->first();

            default:
                return null;
        }
    }




    // ==================== WALLET TOP-UP WEBHOOKS ====================

    /**
     * Handle generic wallet top-up callback
     */
    public function handleWalletTopUpCallback(Request $request, string $topUpId): JsonResponse
    {
        Log::info('Wallet top-up callback received', [
            'top_up_id' => $topUpId,
            'payload' => $request->all(),
        ]);

        try {
            $topUp = $this->walletTopUpService->processTopUpCallback($topUpId, [
                'success' => $request->input('success', false),
                'gateway_reference' => $request->input('gateway_reference') ?? $request->input('transaction_id'),
                'gateway_response' => $request->all(),
                'failure_reason' => $request->input('failure_reason') ?? $request->input('error_message'),
            ]);

            return response()->json([
                'success' => true,
                'status' => $topUp->status,
                'message' => $topUp->status === 'completed' ? 'Top-up processed successfully' : 'Top-up processing failed',
            ]);
        } catch (\Exception $e) {
            Log::error('Wallet top-up callback processing failed', [
                'top_up_id' => $topUpId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Handle bank transfer confirmation callback
     */
    public function handleWalletBankTransferCallback(Request $request): JsonResponse
    {
        Log::info('Wallet bank transfer callback received', [
            'payload' => $request->all(),
        ]);

        $bankReference = $request->input('bank_reference') ?? $request->input('reference');

        if (!$bankReference) {
            return response()->json([
                'success' => false,
                'message' => 'Bank reference is required',
            ], 400);
        }

        try {
            // Find top-up by bank reference
            $topUp = \App\Models\WalletTopUp::where('bank_reference', $bankReference)
                ->where('status', 'pending')
                ->first();

            if (!$topUp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Top-up not found or already processed',
                ], 404);
            }

            $topUp = $this->walletTopUpService->processTopUpCallback($topUp->top_up_id, [
                'success' => $request->input('success', true),
                'gateway_reference' => $bankReference,
                'gateway_response' => $request->all(),
            ]);

            return response()->json([
                'success' => true,
                'status' => $topUp->status,
                'message' => 'Bank transfer processed',
            ]);
        } catch (\Exception $e) {
            Log::error('Bank transfer callback processing failed', [
                'bank_reference' => $bankReference,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Handle mobile money top-up callback
     */
    public function handleWalletMobileMoneyCallback(Request $request, string $provider): JsonResponse
    {
        Log::info('Wallet mobile money callback received', [
            'provider' => $provider,
            'payload' => $request->all(),
        ]);

        // Parse callback based on provider
        $callbackData = $this->parseMobileMoneyCallback($request, $provider);

        if (!$callbackData['top_up_id']) {
            return response()->json([
                'success' => false,
                'message' => 'Could not identify top-up from callback',
            ], 400);
        }

        try {
            $topUp = $this->walletTopUpService->processTopUpCallback(
                $callbackData['top_up_id'],
                $callbackData
            );

            // Return provider-specific response
            return $this->formatMobileMoneyResponse($provider, $topUp);
        } catch (\Exception $e) {
            Log::error('Mobile money callback processing failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return $this->formatMobileMoneyErrorResponse($provider, $e->getMessage());
        }
    }

    /**
     * Parse mobile money callback based on provider
     */
    protected function parseMobileMoneyCallback(Request $request, string $provider): array
    {
        switch ($provider) {
            case 'mpesa':
                $resultCode = $request->input('Body.stkCallback.ResultCode', $request->input('ResultCode'));
                $checkoutRequestId = $request->input('Body.stkCallback.CheckoutRequestID', $request->input('CheckoutRequestID'));

                // Find top-up by gateway reference
                $topUp = \App\Models\WalletTopUp::where('gateway_reference', $checkoutRequestId)
                    ->orWhereJsonContains('metadata->checkout_request_id', $checkoutRequestId)
                    ->first();

                return [
                    'top_up_id' => $topUp?->top_up_id,
                    'success' => $resultCode === 0 || $resultCode === '0',
                    'gateway_reference' => $checkoutRequestId,
                    'gateway_response' => $request->all(),
                    'failure_reason' => $resultCode !== 0 ? ($request->input('Body.stkCallback.ResultDesc') ?? 'Payment failed') : null,
                ];

            case 'mtn':
                $status = $request->input('status');
                $externalId = $request->input('externalId');

                $topUp = \App\Models\WalletTopUp::where('gateway_reference', $externalId)
                    ->orWhereJsonContains('metadata->external_id', $externalId)
                    ->first();

                return [
                    'top_up_id' => $topUp?->top_up_id,
                    'success' => strtoupper($status) === 'SUCCESSFUL',
                    'gateway_reference' => $request->input('financialTransactionId') ?? $externalId,
                    'gateway_response' => $request->all(),
                    'failure_reason' => strtoupper($status) !== 'SUCCESSFUL' ? ($request->input('reason') ?? 'Payment failed') : null,
                ];

            case 'airtel':
                $statusCode = $request->input('transaction.status_code', $request->input('status_code'));
                $transactionId = $request->input('transaction.id', $request->input('transaction_id'));

                $topUp = \App\Models\WalletTopUp::where('gateway_reference', $transactionId)
                    ->first();

                return [
                    'top_up_id' => $topUp?->top_up_id,
                    'success' => $statusCode === 'TS',
                    'gateway_reference' => $transactionId,
                    'gateway_response' => $request->all(),
                    'failure_reason' => $statusCode !== 'TS' ? ($request->input('transaction.message') ?? 'Payment failed') : null,
                ];

            default:
                return [
                    'top_up_id' => $request->input('top_up_id'),
                    'success' => $request->input('success', false),
                    'gateway_reference' => $request->input('reference'),
                    'gateway_response' => $request->all(),
                    'failure_reason' => $request->input('error'),
                ];
        }
    }

    /**
     * Format mobile money success response based on provider
     */
    protected function formatMobileMoneyResponse(string $provider, $topUp): JsonResponse
    {
        switch ($provider) {
            case 'mpesa':
                return response()->json([
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                ]);

            case 'mtn':
            case 'airtel':
            default:
                return response()->json([
                    'success' => true,
                    'status' => $topUp->status,
                    'message' => 'Callback processed',
                ]);
        }
    }

    /**
     * Format mobile money error response based on provider
     */
    protected function formatMobileMoneyErrorResponse(string $provider, string $message): JsonResponse
    {
        switch ($provider) {
            case 'mpesa':
                return response()->json([
                    'ResultCode' => 1,
                    'ResultDesc' => $message,
                ]);

            case 'mtn':
            case 'airtel':
            default:
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 400);
        }
    }
}
