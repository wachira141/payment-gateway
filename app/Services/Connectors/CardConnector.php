<?php

namespace App\Services\Connectors;

use App\Services\ConnectorInterface;
use App\Models\Charge;
use Illuminate\Support\Facades\Log;

class CardConnector implements ConnectorInterface
{
    /**
     * Get connector name
     */
    public function getName(): string
    {
        return 'card';
    }

    /**
     * Check if connector supports country and currency
     */
    public function supportsCountryAndCurrency(string $countryCode, string $currency): bool
    {
        // Card payments support all countries and major currencies for development
        $supportedCurrencies = ['USD', 'EUR', 'GBP', 'KES', 'TZS', 'UGX', 'NGN', 'GHS'];
        
        return in_array($currency, $supportedCurrencies);
    }

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods(): array
    {
        return ['card'];
    }

    /**
     * Validate payment method details
     */
    public function validatePaymentMethod(array $paymentMethodDetails): bool
    {
        // Basic validation for card details
        $requiredFields = ['number', 'exp_month', 'exp_year', 'cvc'];
        
        foreach ($requiredFields as $field) {
            if (!isset($paymentMethodDetails[$field]) || empty($paymentMethodDetails[$field])) {
                return false;
            }
        }

        // Basic card number validation (should be 13-19 digits)
        $cardNumber = preg_replace('/\D/', '', $paymentMethodDetails['number']);
        if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
            return false;
        }

        // Expiry validation
        $expMonth = intval($paymentMethodDetails['exp_month']);
        $expYear = intval($paymentMethodDetails['exp_year']);
        
        if ($expMonth < 1 || $expMonth > 12) {
            return false;
        }

        $currentYear = intval(date('Y'));
        $currentMonth = intval(date('n'));
        
        if ($expYear < $currentYear || ($expYear === $currentYear && $expMonth < $currentMonth)) {
            return false;
        }

        // CVC validation (3-4 digits)
        $cvc = preg_replace('/\D/', '', $paymentMethodDetails['cvc']);
        if (strlen($cvc) < 3 || strlen($cvc) > 4) {
            return false;
        }

        return true;
    }

    /**
     * Process payment
     */
    public function processPayment(Charge $charge, array $paymentData): array
    {
        try {
            Log::info('Processing card payment', [
                'charge_id' => $charge->charge_id,
                'amount' => $charge->amount,
                'currency' => $charge->currency,
                'environment' => app()->environment(),
            ]);

            // In production, we should not simulate payments
            if (app()->environment('production')) {
                Log::warning('Card payment attempted in production without real processor', [
                    'charge_id' => $charge->charge_id,
                ]);

                return [
                    'success' => false,
                    'status' => 'failed',
                    'error_code' => 'not_implemented',
                    'error_message' => 'Card payments require integration with a real payment processor (e.g., Stripe, PayStack). This is a development simulator.',
                ];
            }

            $paymentMethod = $paymentData['payment_method'] ?? [];
            
            if (!$this->validatePaymentMethod($paymentMethod)) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'error_code' => 'invalid_payment_method',
                    'error_message' => 'Invalid card details provided',
                ];
            }

            // Simulate different scenarios based on card number for testing
            $cardNumber = preg_replace('/\D/', '', $paymentMethod['number']);
            $lastFour = substr($cardNumber, -4);

            // Simulate failure scenarios for testing
            if (in_array($lastFour, ['0002', '0004', '0008'])) {
                $errorMessages = [
                    '0002' => 'Card declined',
                    '0004' => 'Insufficient funds',
                    '0008' => 'Expired card',
                ];

                return [
                    'success' => false,
                    'status' => 'failed',
                    'error_code' => 'card_declined',
                    'error_message' => $errorMessages[$lastFour],
                    'connector_response' => [
                        'simulated' => true,
                        'test_scenario' => $lastFour,
                    ],
                ];
            }

            // Simulate successful payment
            $connectorChargeId = 'sim_' . uniqid();
            
            Log::info('Card payment simulated successfully', [
                'charge_id' => $charge->charge_id,
                'connector_charge_id' => $connectorChargeId,
                'simulated' => true,
            ]);

            return [
                'success' => true,
                'status' => 'succeeded',
                'connector_charge_id' => $connectorChargeId,
                'connector_response' => [
                    'simulated' => true,
                    'transaction_id' => $connectorChargeId,
                    'card_last_four' => $lastFour,
                    'brand' => $this->detectCardBrand($cardNumber),
                    'processed_at' => now()->toISOString(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Card payment processing error', [
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

            Log::info('Checking card payment status', [
                'charge_id' => $charge->charge_id,
                'connector_charge_id' => $charge->connector_charge_id,
            ]);

            // In a real implementation, this would query the actual payment processor
            // For simulation, we'll return the current status
            $isSimulated = str_starts_with($charge->connector_charge_id, 'sim_');
            
            return [
                'success' => true,
                'status' => $charge->status, // Return current status
                'connector_response' => [
                    'simulated' => $isSimulated,
                    'last_checked' => now()->toISOString(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Card status check error', [
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
        try {
            Log::info('Processing card refund', [
                'charge_id' => $charge->charge_id,
                'amount' => $amount,
                'environment' => app()->environment(),
            ]);

            // In production, this should integrate with real payment processor
            if (app()->environment('production')) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'error_code' => 'not_implemented',
                    'error_message' => 'Card refunds require integration with a real payment processor',
                ];
            }

            // Simulate refund for development
            $refundId = 'refund_sim_' . uniqid();
            
            Log::info('Card refund simulated successfully', [
                'charge_id' => $charge->charge_id,
                'refund_id' => $refundId,
                'amount' => $amount,
                'simulated' => true,
            ]);

            return [
                'success' => true,
                'status' => 'succeeded',
                'refund_id' => $refundId,
                'connector_response' => [
                    'simulated' => true,
                    'refund_id' => $refundId,
                    'amount' => $amount,
                    'processed_at' => now()->toISOString(),
                    'metadata' => $metadata,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Card refund processing error', [
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
     * Detect card brand from card number
     */
    private function detectCardBrand(string $cardNumber): string
    {
        $cardNumber = preg_replace('/\D/', '', $cardNumber);
        
        if (preg_match('/^4/', $cardNumber)) {
            return 'visa';
        } elseif (preg_match('/^5[1-5]|^2[2-7]/', $cardNumber)) {
            return 'mastercard';
        } elseif (preg_match('/^3[47]/', $cardNumber)) {
            return 'amex';
        } elseif (preg_match('/^6/', $cardNumber)) {
            return 'discover';
        }
        
        return 'unknown';
    }
}