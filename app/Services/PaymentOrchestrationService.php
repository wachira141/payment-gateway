<?php

namespace App\Services;

use App\Models\Charge;
use App\Services\ConnectorInterface;
use App\Services\Connectors\MpesaConnector;
use App\Services\Connectors\CardConnector;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Log;

class PaymentOrchestrationService
{
    protected $ledgerService;
    protected $connectors = [];

    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
        $this->initializeConnectors();
    }

    /**
     * Initialize available connectors
     */
    protected function initializeConnectors()
    {
        $this->connectors = [
            'mpesa' => new MpesaConnector(),
            'card' => new CardConnector(),
        ];
    }

    /**
     * Process charge through appropriate connector
     */
    public function processCharge(Charge $charge, array $paymentData)
    {
        try {
            Log::info('Processing charge', [
                'charge_id' => $charge->charge_id,
                'payment_method_type' => $charge->payment_method_type,
                'amount' => $charge->amount,
                'currency' => $charge->currency,
            ]);

            // Route to appropriate connector
            $connector = $this->routeToConnector($charge, $paymentData);
            
            if (!$connector) {
                throw new \Exception('No suitable connector found for payment method: ' . $charge->payment_method_type);
            }

            // Update charge with connector name
            $charge->update(['connector_name' => $connector->getName()]);

            // Process payment through connector
            $result = $connector->processPayment($charge, $paymentData);

            // Handle result
            if ($result['status'] === 'succeeded') {
                $this->handleSuccessfulPayment($charge, $result);
            } elseif ($result['status'] === 'failed') {
                $this->handleFailedPayment($charge, $result);
            } elseif ($result['status'] === 'pending') {
                $this->handlePendingPayment($charge, $result);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Payment processing failed', [
                'charge_id' => $charge->charge_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->handleFailedPayment($charge, [
                'status' => 'failed',
                'failure_code' => 'processing_error',
                'failure_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Route charge to appropriate connector
     */
    protected function routeToConnector(Charge $charge, array $paymentData)
    {
        $paymentMethodType = $charge->payment_method_type;
        $country = $this->extractCountryFromPaymentData($paymentData);
        
        // Simple routing logic - can be enhanced with more sophisticated rules
        switch ($paymentMethodType) {
            case 'mobile_money':
                // Route based on country/provider
                if (in_array($country, ['KE', 'TZ', 'UG']) || 
                    isset($paymentData['payment_method']['mobile_money']['provider']) &&
                    $paymentData['payment_method']['mobile_money']['provider'] === 'mpesa') {
                    return $this->connectors['mpesa'] ?? null;
                }
                break;
                
            case 'card':
                return $this->connectors['card'] ?? null;
                
            default:
                return null;
        }

        return null;
    }

    /**
     * Handle successful payment
     */
    protected function handleSuccessfulPayment(Charge $charge, array $result)
    {
        // Mark charge as succeeded
        $charge->markAsSucceeded(
            $result['connector_charge_id'] ?? null,
            $result['connector_response'] ?? []
        );

        // Create ledger entries
        $this->ledgerService->recordPayment($charge);

        // Update merchant balance
        $this->updateMerchantBalance($charge);

        Log::info('Payment succeeded', [
            'charge_id' => $charge->charge_id,
            'connector_charge_id' => $result['connector_charge_id'] ?? null,
        ]);
    }

    /**
     * Handle failed payment
     */
    protected function handleFailedPayment(Charge $charge, array $result)
    {
        $charge->markAsFailed(
            $result['failure_code'] ?? 'unknown_error',
            $result['failure_message'] ?? 'Payment failed',
            $result['connector_response'] ?? []
        );

        Log::warning('Payment failed', [
            'charge_id' => $charge->charge_id,
            'failure_code' => $result['failure_code'] ?? 'unknown_error',
            'failure_message' => $result['failure_message'] ?? 'Payment failed',
        ]);
    }

    /**
     * Handle pending payment
     */
    protected function handlePendingPayment(Charge $charge, array $result)
    {
        $charge->update([
            'status' => 'processing',
            'connector_charge_id' => $result['connector_charge_id'] ?? null,
            'connector_response' => $result['connector_response'] ?? [],
        ]);

        Log::info('Payment pending', [
            'charge_id' => $charge->charge_id,
            'connector_charge_id' => $result['connector_charge_id'] ?? null,
        ]);
    }

 
    /**
     * Update merchant balance
     */
    protected function updateMerchantBalance(Charge $charge)
    {
        $merchant = $charge->merchant;
        $balance = $merchant->getBalance($charge->currency);
        
        if (!$balance) {
            $balance = $merchant->balances()->create(['currency' => $charge->currency]);
        }

        // Add to pending (will be moved to available after settlement period)
        $netAmount = $charge->getNetAmount();
        $balance->creditPending($netAmount);
    }

    /**
     * Extract country from payment data
     */
    protected function extractCountryFromPaymentData(array $paymentData)
    {
        if (isset($paymentData['billing_details']['address']['country'])) {
            return $paymentData['billing_details']['address']['country'];
        }

        if (isset($paymentData['payment_method']['mobile_money']['phone'])) {
            $phone = $paymentData['payment_method']['mobile_money']['phone'];
            // Simple country detection based on phone prefix
            if (strpos($phone, '+254') === 0 || strpos($phone, '254') === 0) {
                return 'KE';
            }
        }

        return 'Unknown';
    }

    /**
     * Get available payment methods for country/currency
     */
    public function getAvailablePaymentMethods($countryCode, $currency)
    {
        $methods = [];
        
        foreach ($this->connectors as $name => $connector) {
            if ($connector->supportsCountryAndCurrency($countryCode, $currency)) {
                $methods = array_merge($methods, $connector->getSupportedPaymentMethods());
            }
        }

        return array_unique($methods);
    }
    
}