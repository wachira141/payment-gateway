<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Models\PaymentGateway;
use App\Models\PaymentWebhook;
use App\Services\StripePaymentService;
use App\Services\MpesaPaymentService;
use App\Services\TelebirrPaymentService;
use App\Services\PaymentIntentTransactionService;

use Illuminate\Console\Application;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PaymentProcessorService
{
    protected $stripeService;
    protected $mpesaService;
    protected $telebirrService;
    protected $applicationDataService;

    public function __construct(
        ApplicationDataService $applicationDataService,
        StripePaymentService $stripeService,
        MpesaPaymentService $mpesaService,
        TelebirrPaymentService $telebirrService
    ) {
        $this->applicationDataService = $applicationDataService;
        $this->stripeService = $stripeService;
        $this->mpesaService = $mpesaService;
        $this->telebirrService = $telebirrService;
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

            if(!$payable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payable type',
                    'error' => 'The provided payable type is not recognized.'
                ], 500);
            }
            // Create transaction record
            $transaction = PaymentTransaction::createTransaction([
                'transaction_id' => Str::uuid(),
                'user_id' => $data['user_id'],
                'payment_gateway_id' => $gateway->id,
                'payable_type' => $payable,
                'payable_id' => $data['payable_id'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'description' => $data['description'] ?? null,
                'metadata' => $data['metadata'] ?? [],
            ]);

            // Process payment based on gateway type
            $result = $this->processPaymentByGateway($gateway, $transaction, $data);

            // Update transaction with result
            if ($result['success']) {
                $transaction->updateWithGatewayResponse($result['gateway_response'], 'processing');
            } else {
                $transaction->markAsFailed($result['error']);
            }

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
            'user_id' => $transaction->user_id,
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
}
