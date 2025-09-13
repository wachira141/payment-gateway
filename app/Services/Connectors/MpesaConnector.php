<?php

namespace App\Services\Connectors;

use App\Services\ConnectorInterface;
use App\Services\MpesaPaymentService;
use App\Models\Charge;
use Illuminate\Support\Facades\Log;

class MpesaConnector implements ConnectorInterface
{
    private MpesaPaymentService $mpesaService;

    public function __construct()
    {
        $this->mpesaService = new MpesaPaymentService();
    }

    /**
     * Get connector name
     */
    public function getName(): string
    {
        return 'mpesa';
    }

    /**
     * Check if connector supports country and currency
     */
    public function supportsCountryAndCurrency(string $countryCode, string $currency): bool
    {
        $supportedCombinations = [
            'KE' => ['KES'],
            'TZ' => ['TZS'], 
            'UG' => ['UGX'],
        ];

        return isset($supportedCombinations[$countryCode]) && 
               in_array($currency, $supportedCombinations[$countryCode]);
    }

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods(): array
    {
        return ['mobile_money'];
    }

    /**
     * Validate payment method details
     */
    public function validatePaymentMethod(array $paymentMethodDetails): bool
    {
        if (!isset($paymentMethodDetails['phone_number'])) {
            return false;
        }

        $phoneNumber = $paymentMethodDetails['phone_number'];
        
        // Validate phone number format for supported countries
        if (isset($paymentMethodDetails['country']) && $paymentMethodDetails['country'] === 'KE') {
            return $this->mpesaService->formatPhoneNumber($phoneNumber) !== null;
        }

        // Basic validation for other East African countries (TZ, UG)
        $cleanPhone = preg_replace('/\D/', '', $phoneNumber);
        return strlen($cleanPhone) >= 9 && strlen($cleanPhone) <= 15;
    }

    /**
     * Process payment
     */
    public function processPayment(Charge $charge, array $paymentData): array
    {
        try {
            Log::info('Processing M-Pesa payment', [
                'charge_id' => $charge->charge_id,
                'amount' => $charge->amount,
                'currency' => $charge->currency,
            ]);

            $paymentMethod = $paymentData['payment_method'] ?? [];
            $phoneNumber = $paymentMethod['phone_number'] ?? null;

            if (!$phoneNumber) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'error_code' => 'missing_phone_number',
                    'error_message' => 'Phone number is required for M-Pesa payments',
                ];
            }

            // Format phone number for Kenya (primary market)
            $formattedPhone = $this->mpesaService->formatPhoneNumber($phoneNumber);
            if (!$formattedPhone) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'error_code' => 'invalid_phone_number',
                    'error_message' => 'Invalid phone number format',
                ];
            }

            // Initiate STK Push
            $result = $this->mpesaService->initiateSTKPush(
                $charge->amount,
                $formattedPhone,
                $charge->charge_id,
                $charge->paymentIntent->description ?? 'Payment'
            );

            if (!$result['success']) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'error_code' => 'stk_push_failed',
                    'error_message' => $result['error'],
                ];
            }

            Log::info('M-Pesa STK Push initiated successfully', [
                'charge_id' => $charge->charge_id,
                'checkout_request_id' => $result['checkout_request_id'],
            ]);

            return [
                'success' => true,
                'status' => 'pending',
                'connector_charge_id' => $result['checkout_request_id'],
                'connector_response' => $result,
                'next_action' => [
                    'type' => 'mobile_money_stk_push',
                    'phone_number' => $phoneNumber,
                    'checkout_request_id' => $result['checkout_request_id'],
                    'instructions' => 'Please check your phone and enter your M-Pesa PIN to complete the payment',
                ],
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa payment processing error', [
                'charge_id' => $charge->charge_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 'failed',
                'error_code' => 'processing_error',
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check payment status
     */
    public function checkPaymentStatus(Charge $charge): array
    {
        try {
            if (!$charge->connector_charge_id) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'error_message' => 'No connector charge ID found',
                ];
            }

            Log::info('Checking M-Pesa payment status', [
                'charge_id' => $charge->charge_id,
                'checkout_request_id' => $charge->connector_charge_id,
            ]);

            $result = $this->mpesaService->querySTKPushStatus($charge->connector_charge_id);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'error_message' => $result['error'],
                ];
            }

            // Map M-Pesa result codes to our status
            $resultCode = $result['result_code'] ?? null;
            
            if ($resultCode === 0) {
                // Payment successful
                return [
                    'success' => true,
                    'status' => 'succeeded',
                    'connector_response' => $result,
                ];
            } elseif ($resultCode === null || $resultCode === '') {
                // Still pending
                return [
                    'success' => true,
                    'status' => 'pending',
                    'connector_response' => $result,
                ];
            } else {
                // Payment failed
                return [
                    'success' => false,
                    'status' => 'failed',
                    'error_code' => 'payment_declined',
                    'error_message' => $result['result_desc'] ?? 'Payment was declined',
                    'connector_response' => $result,
                ];
            }
        } catch (\Exception $e) {
            Log::error('M-Pesa status check error', [
                'charge_id' => $charge->charge_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process refund
     */
    public function processRefund(Charge $charge, float $amount, array $metadata = []): array
    {
        // M-Pesa refunds are not supported in this implementation
        Log::warning('M-Pesa refund attempted but not supported', [
            'charge_id' => $charge->charge_id,
            'amount' => $amount,
        ]);

        return [
            'success' => false,
            'status' => 'failed',
            'error_code' => 'not_supported',
            'error_message' => 'M-Pesa refunds are not currently supported',
        ];
    }
}