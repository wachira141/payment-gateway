<?php

namespace App\Services;

use App\Models\PaymentIntent;
use App\Models\Merchant;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Events\PaymentIntentCreated;
use App\Events\PaymentIntentConfirmed;
use App\Events\PaymentIntentSucceeded;
use App\Events\PaymentIntentFailed;
use App\Events\PaymentIntentCancelled;
use App\Events\PaymentIntentCaptured;
use App\Services\PaymentProcessorService;
use App\Services\PaymentIntentTransactionService;
use App\Services\PaymentMethodGatewayMapper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class PaymentIntentService
{
    protected $paymentProcessor;
    protected $intentTransactionService;
    protected $gatewayMapper; 
    protected $chargeService;
    protected $ledgerService;
    
    
    public function __construct(
        PaymentProcessorService $paymentProcessor,
        PaymentIntentTransactionService $intentTransactionService,
        PaymentMethodGatewayMapper $gatewayMapper,
        ChargeService $chargeService,
        LedgerService $ledgerService,
        ) {
            $this->paymentProcessor = $paymentProcessor;
            $this->intentTransactionService = $intentTransactionService;
            $this->gatewayMapper = $gatewayMapper;
            $this->chargeService = $chargeService;
            $this->ledgerService = $ledgerService;
    }

    public function create(Merchant $merchant, array $data): PaymentIntent
    {
        try {
            DB::beginTransaction();

            // Handle customer creation/finding if customer data is provided
            $customerId = null;
            if (!empty($data['customer'])) {
                $customer = Customer::findOrCreate($merchant->id, $data['customer']);
                $customerId = $customer->id;
                unset($data['customer']); // Remove from payment intent data
            }

            // Ensure merchant_app_id is provided
            $merchantAppId = $data['merchant_app_id'] ?? null;
            if (!$merchantAppId) {
                // Get the merchant's first active app
                $firstApp = $merchant->apps()->active()->first();
                if (!$firstApp) {
                    throw new Exception('Merchant has no active apps. Please create an app first.');
                }
                $merchantAppId = $firstApp->id;
            }

            $paymentIntent = PaymentIntent::create([
                'merchant_id' => $merchant->id,
                'merchant_app_id' => $merchantAppId,
                'customer_id' => $customerId,
                'country_code' => $data['country'],
                'amount' => $data['amount'],
                'amount_received' => 0,
                'amount_capturable' => $data['amount'],
                'currency' => strtoupper($data['currency']),
                'capture_method' => $data['capture_method'],
                'payment_method_types' => $data['payment_method_types'],
                'client_reference_id' => $data['client_reference_id'] ?? null,
                'description' => $data['description'] ?? null,
                'receipt_email' => $data['receipt_email'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'shipping' => $data['shipping'] ?? null,
                'billing_details' => $data['billing_details'] ?? null,
                'status' => 'requires_payment_method',
            ]);

            // Generate client secret for frontend integration
            $paymentIntent->update([
                'client_secret' => $paymentIntent->intent_id . '_secret_' . bin2hex(random_bytes(16)),
            ]);

            DB::commit();

            // Fire event for webhook dispatch
            PaymentIntentCreated::dispatch($paymentIntent);

            Log::info('Payment intent created', [
                'intent_id' => $paymentIntent->intent_id,
                'merchant_id' => $merchant->id,
                'customer_id' => $customerId,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
            ]);

            return $paymentIntent;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create payment intent', [
                'merchant_id' => $merchant->id,
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            throw $e;
        }
    }

    public function getForMerchant(Merchant $merchant, array $filters = []): LengthAwarePaginator
    {
        $query = PaymentIntent::getForMerchant($merchant->id, $filters);

        $limit = $filters['limit'] ?? 20;

        return $query->paginate($limit);
    }

    public function findByIdAndMerchant(string $intentId, Merchant $merchant): ?PaymentIntent
    {
        return PaymentIntent::findByIdAndMerchant($intentId, $merchant->id);
    }

    public function confirm(PaymentIntent $paymentIntent, array $paymentMethodData): PaymentIntent
    {
        try {
            if (!$paymentIntent->canBeConfirmed()) {
                throw new Exception('Payment intent cannot be confirmed in current status: ' . $paymentIntent->status);
            }

            DB::beginTransaction();

            // First fire confirmed event
            PaymentIntentConfirmed::dispatch($paymentIntent);

            //payment processing logic
            $isSuccessful = $this->processPayment($paymentIntent, $paymentMethodData);

            if ($isSuccessful) {
                $paymentIntent->updateStatus('succeeded', [
                    'confirmed_at' => now(),
                ]);

                // Fire succeeded event
                PaymentIntentSucceeded::dispatch($paymentIntent->fresh());

                // Store payment method for customer if successful and customer exists
                if ($paymentIntent->customer_id && !empty($paymentMethodData)) {
                    CustomerPaymentMethod::createFromPaymentData(
                        $paymentIntent->customer_id,
                        $paymentMethodData
                    );
                }
            } else {
                $paymentIntent->updateStatus('requires_action', );
                // Fire failed event for requires_action status
                PaymentIntentFailed::dispatch($paymentIntent->fresh());
            }

            DB::commit();

            Log::info('Payment intent confirmed', [
                'intent_id' => $paymentIntent->intent_id,
                'status' => $paymentIntent->status,
                'payment_method' => $paymentMethodData['type'] ?? 'unknown',
            ]);

            return $paymentIntent->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to confirm payment intent', [
                'intent_id' => $paymentIntent->intent_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function capture(PaymentIntent $paymentIntent): PaymentIntent
    {
        try {
            if (!$paymentIntent->canBeCaptured()) {
                throw new Exception('Payment intent cannot be captured in current status: ' . $paymentIntent->status);
            }

            DB::beginTransaction();

            $paymentIntent->updateStatus('succeeded', [
                'confirmed_at' => $paymentIntent->confirmed_at ?? now(),
                'amount_received' => $paymentIntent->amount,
            ]);

            // Fire captured event
            PaymentIntentCaptured::dispatch($paymentIntent->fresh());

            DB::commit();

            Log::info('Payment intent captured', [
                'intent_id' => $paymentIntent->intent_id,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
            ]);

            return $paymentIntent->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to capture payment intent', [
                'intent_id' => $paymentIntent->intent_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function cancel(PaymentIntent $paymentIntent, string $reason = null): PaymentIntent
    {
        try {
            if (!$paymentIntent->canBeCancelled()) {
                throw new Exception('Payment intent cannot be cancelled in current status: ' . $paymentIntent->status);
            }

            DB::beginTransaction();

            $paymentIntent->updateStatus('cancelled', [
                'cancellation_reason' => $reason,
            ]);

            // Fire cancelled event
            PaymentIntentCancelled::dispatch($paymentIntent->fresh());

            DB::commit();

            Log::info('Payment intent cancelled', [
                'intent_id' => $paymentIntent->intent_id,
                'reason' => $reason,
            ]);

            return $paymentIntent->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to cancel payment intent', [
                'intent_id' => $paymentIntent->intent_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getAnalytics(Merchant $merchant, array $filters = []): array
    {
        return PaymentIntent::getStatsForMerchant($merchant->id, $filters);
    }



    private function processPayment(PaymentIntent $paymentIntent, array $paymentMethodData): bool
    {
        try {
            $paymentMethodType = $paymentMethodData['type'] ?? 'card';

            // Determine country and currency for gateway selection
            // $countryCode = $paymentMethodData['billing_details']['address']['country'] ??
            //     $paymentIntent->billing_details['address']['country'] ?? 'US';

            $countryCode = $paymentIntent->country_code;
            // Get appropriate gateway for this payment method
            $gateway = $this->gatewayMapper->getGatewayForPaymentMethod(
                $paymentMethodType,
                $paymentIntent->currency,
                $countryCode,
                $paymentMethodData
            );

            if (!$gateway) {
                Log::error('No suitable gateway found for payment method', [
                    'payment_method' => $paymentMethodType,
                    'currency' => $paymentIntent->currency,
                    'country' => $countryCode,
                    'intent_id' => $paymentIntent->intent_id,
                ]);
                return false;
            }
            
            // Prepare payment data for PaymentProcessorService
            $paymentData = [
                'gateway_code' => $gateway->code,
                'merchant_id' => $paymentIntent->merchant_id,
                'payable_type' => 'payment_intent',
                'payable_id' => $paymentIntent->id,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'description' => $paymentIntent->description ?? 'Payment Intent: ' . $paymentIntent->intent_id,
                'metadata' => array_merge($paymentIntent->metadata ?? [], [
                    'payment_intent_id' => $paymentIntent->intent_id,
                    'payment_method_type' => $paymentMethodType,
                ]),
                'phone_number' => $paymentMethodData['mobile_money']['phone_number'] ?? null,
            ];

            // Add payment method specific data
            if ($paymentMethodType === 'mobile_money' && isset($paymentMethodData['mobile_money']['phone_number'])) {
                $paymentData['phone_number'] = $paymentMethodData['mobile_money']['phone_number'];
            }

            // Process the payment
            $result = $this->paymentProcessor->processPayment($paymentData);
            
            if ($result['success']) {
                // Store gateway data in the payment intent
                $paymentIntent->update([
                    'gateway_data' => [
                        'gateway_code' => $gateway->code,
                        'gateway_type' => $gateway->type,
                        'payment_intent_id' => $result['payment_intent_id'] ?? null,
                        'client_secret' => $result['client_secret'] ?? null,
                        'checkout_request_id' => $result['checkout_request_id'] ?? null,
                        'payment_url' => $result['payment_url'] ?? null,
                        'transaction_id' => $result['transaction_id'],
                    ],
                    'gateway_transaction_id' => $result['transaction_id'],
                    'gateway_payment_intent_id' => $result['payment_intent_id'] ?? null,
                    'amount_received' => $paymentIntent->amount, 
                ]);

                Log::info('Payment processing initiated successfully', [
                    'intent_id' => $paymentIntent->intent_id,
                    'gateway' => $gateway->code,
                    'transaction_id' => $result['transaction_id'],
                ]);

                return true;
            } else {
                Log::error('Payment processing failed', [
                    'intent_id' => $paymentIntent->intent_id,
                    'gateway' => $gateway->code,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);

                return false;
            }
        } catch (Exception $e) {
            Log::error('Exception during payment processing', [
                'intent_id' => $paymentIntent->intent_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }


    /**
     * Handle successful payment intent by creating charge and updating ledger
     */
    public function handleSuccessfulPaymentIntent($transaction, string $gatewayType, array $feeCalculation): void
    {
        try {
            $paymentIntent = \App\Models\PaymentIntent::find($transaction->payable_id);

            if (!$paymentIntent) {
                Log::warning('PaymentIntent not found for transaction', [
                    'transaction_id' => $transaction->id,
                    'payable_id' => $transaction->payable_id
                ]);
                return;
            }

            // Create charge from successful payment intent
            $charge = $this->chargeService->createChargeFromPaymentIntent($paymentIntent->id, [
                'payment_intent_id' => $paymentIntent->id,
                'merchant_id' => $paymentIntent->merchant_id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'status' => 'succeeded',
                'payment_method_type' => $transaction->payment_method_type ?? 'unknown',
                'payment_method_details' => [],
                'connector_name' => $gatewayType,
                'connector_charge_id' => $transaction->gateway_transaction_id,
                'connector_response' => $transaction->gateway_response ?? [],
                'fee_amount' => 0, // Will be calculated by ledger service
                'captured' => true,
                'captured_at' => now(),
                'gateway_code' => $transaction->gateway_code,
                'gateway_processing_fee' => 0, // Will be calculated by ledger service
                'platform_application_fee' => 0, // Will be calculated by ledger service
            ]);

            // Record payment in ledger and update merchant balance
            $this->ledgerService->recordPayment($charge, $feeCalculation);

            Log::info('Charge created and ledger updated for successful payment intent', [
                'charge_id' => $charge->charge_id,
                'payment_intent_id' => $paymentIntent->intent_id,
                'transaction_id' => $transaction->id,
                'amount' => $charge->amount,
                'currency' => $charge->currency,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to handle successful payment intent', [
                'transaction_id' => $transaction->id,
                'payable_id' => $transaction->payable_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
