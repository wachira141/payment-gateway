<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Models\PaymentGateway;
use App\Models\PaymentWebhook;
use App\Services\StripePaymentService;
use App\Services\MpesaPaymentService;
use App\Services\TelebirrPaymentService;
use App\Services\AirtelMoneyPaymentService;
use App\Services\MTNMobileMoneyPaymentService;
use App\Helpers\CurrencyHelper;
use App\Services\PaymentIntentTransactionService;
use App\Services\CustomerResolutionService;

use Illuminate\Console\Application;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PaymentProcessorService
{
    protected $stripeService;
    protected $mpesaService;
    protected $telebirrService;
    protected $airtelMoneyService;
    protected $mtnMoMoService;
    protected $applicationDataService;
    protected $customerResolver;

    public function __construct(
        ApplicationDataService $applicationDataService,
        StripePaymentService $stripeService,
        MpesaPaymentService $mpesaService,
        TelebirrPaymentService $telebirrService,
        AirtelMoneyPaymentService $airtelMoneyService,
        MTNMobileMoneyPaymentService $mtnMoMoService,
        CustomerResolutionService $customerResolver
    ) {
        $this->applicationDataService = $applicationDataService;
        $this->stripeService = $stripeService;
        $this->mpesaService = $mpesaService;
        $this->telebirrService = $telebirrService;
        $this->airtelMoneyService = $airtelMoneyService;
        $this->mtnMoMoService = $mtnMoMoService;
        $this->customerResolver = $customerResolver;
    }
    /**
     * Process a payment request
     */
    public function processPayment(array $data)
    {

        try {
            // Get payment gateway
            $gateway = PaymentGateway::getByCode($data['gateway_code']);
            if (!$gateway) {
                return [
                    'success' => false,
                    'message' => 'Payment gateway not found',
                ];
            }

            $payable = $this->applicationDataService->getClassFromType($data['payable_type']);

            if (!$payable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payable type',
                    'error' => 'The provided payable type is not recognized.'
                ], 500);
            }

            // Resolve customer information from payment data
            $customerId = $this->resolveCustomerForPayment($data);


            // Create transaction record
            $transaction = PaymentTransaction::createTransaction([
                'transaction_id' => Str::uuid(),
                'merchant_id' => $data['merchant_id'],
                'customer_id' => $customerId,
                'payment_gateway_id' => $gateway->id,
                'payable_type' => $payable,
                'payable_id' => $data['payable_id'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'description' => $data['description'] ?? null,
                'metadata' => $data['metadata'] ?? [],
            ]);

            $data['amount'] = CurrencyHelper::fromMinorUnits($data['amount'], $data['currency']);
            // Process payment based on gateway type
            $result = $this->processPaymentByGateway($gateway, $transaction, $data);


            // Update transaction with result
            if ($result['success']) {
                $transaction->updateWithGatewayResponse($result['gateway_response'], 'processing');
            } else {
                $transaction->markAsFailed($result['error']);
            }

            # TODO: Need to think it critically
            // $webhookData = [
            //     'payment_gateway_id' => $gateway->id,
            //     'merchant_app_id' => $data['metadata']['app_id'],
            //     'webhook_id' => Str::uuid(),
            //     'event_type' => $data['event_type'] ?? null,
            //     'gateway_event_id' => $result['checkout_request_id'] ?? null,
            //     'payment_intent_id' => $data['payable_id'],
            //     'payment_transaction_id' => $transaction->transaction_id,
            //     'payload' => $result['gateway_response'],
            //     'status' => 'pending',
            // ];

            // PaymentWebhook::createWebhook($webhookData);

            return array_merge($result, [
                'transaction_id' => $transaction->transaction_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Payment processing error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Payment processing failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process payment based on gateway type
     */
    private function processPaymentByGateway($gateway, $transaction, $data)
    {
        switch ($gateway->type) {
            case 'stripe':
                return $this->processStripePayment($transaction, $data);
            case 'mpesa':
                return $this->processMpesaPayment($transaction, $data);
            case 'telebirr':
                return $this->processTelebirrPayment($transaction, $data);
            case 'airtel_money':
                return $this->processAirtelMoneyPayment($transaction, $data);
            case 'mtn_momo':
                return $this->processMTNMoMoPayment($transaction, $data);
            default:
                return [
                    'success' => false,
                    'error' => 'Unsupported payment gateway',
                ];
        }
    }

    /**
     * Process Stripe payment
     */
    private function processStripePayment($transaction, $data)
    {
        try {
            $user = $transaction->user;
            $result = $this->stripeService->createPaymentIntent(
                $data['amount'],
                $data['currency'],
                $user,
                $data['metadata'] ?? []
            );

            if ($result['success']) {
                $transaction->update([
                    'gateway_payment_intent_id' => $result['payment_intent_id'],
                ]);

                return [
                    'success' => true,
                    'payment_intent_id' => $result['payment_intent_id'],
                    'client_secret' => $result['client_secret'],
                    'gateway_response' => $result,
                ];
            }

            return [
                'success' => false,
                'error' => $result['error'],
                'gateway_response' => $result,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process M-Pesa payment
     */
    private function processMpesaPayment($transaction, $data)
    {


        try {
            $result = $this->mpesaService->initiateSTKPush(
                $data['amount'],
                $data['phone_number'],
                $transaction->transaction_id,
                $data['description'] ?? 'Payment'
            );

            return [
                'success' => $result['success'],
                'error' => $result['error'] ?? null,
                'gateway_response' => $result,
                'checkout_request_id' => $result['checkout_request_id'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process Telebirr payment
     */
    private function processTelebirrPayment($transaction, $data)
    {
        try {
            $result = $this->telebirrService->initiatePayment(
                $data['amount'],
                $data['phone_number'],
                $transaction->transaction_id,
                $data['description'] ?? 'Payment'
            );

            return [
                'success' => $result['success'],
                'error' => $result['error'] ?? null,
                'gateway_response' => $result,
                'payment_url' => $result['payment_url'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process Airtel Money payment
     */
    private function processAirtelMoneyPayment($transaction, $data)
    {
        try {
            $result = $this->airtelMoneyService->initiatePayment(
                $data['amount'],
                $data['phone_number'],
                $transaction->transaction_id,
                $data['country_code'] ?? null,
                $data['currency'] ?? null
            );

            return [
                'success' => $result['success'],
                'error' => $result['error'] ?? null,
                'gateway_response' => $result,
                'transaction_id' => $result['transaction_id'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process MTN MoMo payment
     */
    private function processMTNMoMoPayment($transaction, $data)
    {
        try {
            $result = $this->mtnMoMoService->requestToPay(
                $data['amount'],
                $data['phone_number'],
                $transaction->transaction_id,
                $data['country_code'] ?? null,
                $data['currency'] ?? null,
                $data['payer_message'] ?? null,
                $data['payee_note'] ?? null
            );

            return [
                'success' => $result['success'],
                'error' => $result['error'] ?? null,
                'gateway_response' => $result,
                'reference_id' => $result['reference_id'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Retry a failed payment
     */
    public function retryPayment(PaymentTransaction $transaction)
    {
        if (!$transaction->canRetry()) {
            return [
                'success' => false,
                'message' => 'Transaction cannot be retried',
            ];
        }

        // Reset transaction status
        $transaction->update(['status' => 'pending']);

        // Retry the payment using the original data
        $data = [
            'gateway_code' => $transaction->paymentGateway->code,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'merchant_id' => $transaction->merchant_id,
            'payable_type' => $transaction->payable_type,
            'payable_id' => $transaction->payable_id,
            'description' => $transaction->description,
            'metadata' => $transaction->metadata,
        ];

        return $this->processPaymentByGateway($transaction->paymentGateway, $transaction, $data);
    }

    /**
     * Process webhook
     */
    public function processWebhook(PaymentWebhook $webhook, $gatewayType)
    {
        try {
            switch ($gatewayType) {
                case 'stripe':
                    return $this->processStripeWebhook($webhook);
                case 'mpesa':
                    return $this->processMpesaWebhook($webhook);
                case 'telebirr':
                    return $this->processTelebirrWebhook($webhook);
                case 'airtel_money':
                    return $this->processAirtelMoneyWebhook($webhook);
                case 'mtn_momo':
                    return $this->processMTNMoMoWebhook($webhook);
                default:
                    return [
                        'success' => false,
                        'error' => 'Unsupported webhook type',
                    ];
            }
        } catch (\Exception $e) {
            Log::error("Webhook processing error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process Stripe webhook
     */
    private function processStripeWebhook($webhook)
    {
        $payload = $webhook->payload;

        // Handle different Stripe event types
        switch ($webhook->event_type) {
            case 'payment_intent.succeeded':
                return $this->handleStripePaymentSuccess($payload);
            case 'payment_intent.payment_failed':
                return $this->handleStripePaymentFailed($payload);
            default:
                return ['success' => true]; // Ignore unknown events
        }
    }

    /**
     * Process M-Pesa webhook
     */
    private function processMpesaWebhook($webhook)
    {
        $payload = $webhook->payload;

        // Handle M-Pesa callback
        if (isset($payload['Body']['stkCallback'])) {
            $callback = $payload['Body']['stkCallback'];
            $checkoutRequestId = $callback['CheckoutRequestID'];

            // Find transaction by checkout request ID
            $transaction = PaymentTransaction::where('gateway_response->checkout_request_id', $checkoutRequestId)->first();

            if ($transaction) {
                if ($callback['ResultCode'] == 0) {
                    $transaction->markAsCompleted($callback['MpesaReceiptNumber'] ?? null);
                } else {
                    $transaction->markAsFailed($callback['ResultDesc']);
                }

                // Sync with PaymentIntent if linked
                app(PaymentIntentTransactionService::class)->syncIntentStatusFromTransaction($transaction);
            }
        }

        return ['success' => true, 'transaction' => $transaction ?? null];
    }

    /** 
     * Process Telebirr webhook
     */
    private function processTelebirrWebhook($webhook)
    {
        $payload = $webhook->payload;

        // Handle Telebirr notification
        $orderId = $payload['orderId'] ?? null;
        if ($orderId) {
            $transaction = PaymentTransaction::where('transaction_id', $orderId)->first();

            if ($transaction) {
                if ($payload['status'] === 'SUCCESS') {
                    $transaction->markAsCompleted($payload['transactionId'] ?? null);
                } else {
                    $transaction->markAsFailed($payload['status']);
                }

                // Sync with PaymentIntent if linked
                app(PaymentIntentTransactionService::class)->syncIntentStatusFromTransaction($transaction);
            }
        }

        return ['success' => true];
    }

    /**
     * Process Airtel Money webhook
     */
    private function processAirtelMoneyWebhook($webhook)
    {
        $payload = $webhook->payload;
        $callbackData = $this->airtelMoneyService->processC2BCallback($payload);

        if (!$callbackData['success']) {
            return ['success' => false, 'error' => $callbackData['error'] ?? 'Failed to process callback'];
        }

        $data = $callbackData['data'];
        $transactionId = $data['transaction_id'] ?? null;

        if ($transactionId) {
            $transaction = PaymentTransaction::where('transaction_id', $transactionId)
                ->orWhere('gateway_response->transaction_id', $transactionId)
                ->first();

            if ($transaction) {
                if ($data['status'] === 'TS') {
                    $transaction->markAsCompleted($data['airtel_reference'] ?? null);
                } else {
                    $transaction->markAsFailed($data['status_message'] ?? 'Payment failed');
                }

                // Sync with PaymentIntent if linked
                app(PaymentIntentTransactionService::class)->syncIntentStatusFromTransaction($transaction);
            }
        }

        return ['success' => true, 'transaction' => $transaction ?? null];
    }

    /**
     * Process MTN MoMo webhook
     */
    private function processMTNMoMoWebhook($webhook)
    {
        $payload = $webhook->payload;
        $callbackData = $this->mtnMoMoService->processCollectionCallback($payload);

        if (!$callbackData['success']) {
            return ['success' => false, 'error' => $callbackData['error'] ?? 'Failed to process callback'];
        }

        $data = $callbackData['data'];
        $externalId = $data['external_id'] ?? $data['reference_id'] ?? null;

        if ($externalId) {
            $transaction = PaymentTransaction::where('transaction_id', $externalId)
                ->orWhere('gateway_response->reference_id', $data['reference_id'])
                ->orWhere('gateway_response->external_id', $externalId)
                ->first();

            if ($transaction) {
                if ($data['normalized_status'] === 'completed') {
                    $transaction->markAsCompleted($data['financial_transaction_id'] ?? null);
                } else {
                    $transaction->markAsFailed($data['reason'] ?? 'Payment failed');
                }

                // Sync with PaymentIntent if linked
                app(PaymentIntentTransactionService::class)->syncIntentStatusFromTransaction($transaction);
            }
        }

        return ['success' => true, 'transaction' => $transaction ?? null];
    }

    /**
     * Handle Stripe payment success
     */
    private function handleStripePaymentSuccess($payload)
    {
        $paymentIntentId = $payload['id'];
        $transaction = PaymentTransaction::where('gateway_payment_intent_id', $paymentIntentId)->first();

        if ($transaction) {
            $transaction->markAsCompleted($paymentIntentId);

            // Sync with PaymentIntent if linked
            app(PaymentIntentTransactionService::class)->syncIntentStatusFromTransaction($transaction);
        }

        return ['success' => true];
    }

    /**
     * Handle Stripe payment failure
     */
    private function handleStripePaymentFailed($payload)
    {
        $paymentIntentId = $payload['id'];
        $transaction = PaymentTransaction::where('gateway_payment_intent_id', $paymentIntentId)->first();

        if ($transaction) {
            $failureReason = $payload['last_payment_error']['message'] ?? 'Payment failed';
            $transaction->markAsFailed($failureReason);

            // Sync with PaymentIntent if linked
            app(PaymentIntentTransactionService::class)->syncIntentStatusFromTransaction($transaction);
        }

        return ['success' => true];
    }

    /**
     * Resolve customer for payment transaction
     */
    private function resolveCustomerForPayment(array $data): ?int
    {
        // If customer_id is explicitly provided, use it
        if (isset($data['customer_id']) && $data['customer_id']) {
            return $data['customer_id'];
        }

        // Try to resolve from payment intent if provided
        if (isset($data['payment_intent_id'])) {
            $paymentIntent = \App\Models\PaymentIntent::where('intent_id', $data['payment_intent_id'])->first();
            if ($paymentIntent) {
                $customer = $this->customerResolver->resolveFromPaymentIntent($paymentIntent);
                return $customer ? $customer->id : null;
            }
        }

        // Try to resolve from user and merchant context
        if (isset($data['merchant_id']) && (isset($data['billing_details']) || isset($data['customer_email']) || isset($data['customer_phone']))) {
            $merchant = \App\Models\Merchant::find($data['merchant_id']);
            if ($merchant) {
                $paymentData = [
                    'billing_details' => $data['billing_details'] ?? null,
                    'receipt_email' => $data['customer_email'] ?? null,
                    'metadata' => array_merge($data['metadata'] ?? [], [
                        'phone' => $data['customer_phone'] ?? null,
                        'name' => $data['customer_name'] ?? null,
                    ]),
                ];

                $customer = $this->customerResolver->resolveFromPaymentData($merchant, $paymentData);
                return $customer ? $customer->id : null;
            }
        }

        return null;
    }
}
