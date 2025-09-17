<?php

namespace App\Services;

use App\Models\CommissionSetting;
use App\Models\ProviderEarning;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Log;

class CommissionCalculationService
{
    protected $balanceService;
    protected $gatewayPricingService;

    public function __construct(BalanceService $balanceService, GatewayPricingService $gatewayPricingService)
    {
        $this->balanceService = $balanceService;
        $this->gatewayPricingService = $gatewayPricingService;
    }

    /**
     * Calculate and record commission for a payment transaction using gateway-based pricing
     */
    public function processCommission(PaymentTransaction $transaction, $feeCalculation)
    {
        if ($transaction->commission_processed) {
            return $transaction; // Already processed
        }

        // Ensure we have gateway information
        if (!$transaction->gateway_code || !$transaction->payment_method_type) {
            Log::warning('Missing gateway information for commission calculation', [
                'transaction_id' => $transaction->id,
                'gateway_code' => $transaction->gateway_code,
                'payment_method_type' => $transaction->payment_method_type,
            ]);
            
            // Try to infer from payment gateway if possible
            $this->inferGatewayInfo($transaction);
        }
        
        // Update transaction with gateway-based commission
        $transaction = $this->updateTransactionCommission($transaction, $feeCalculation);
       
        return $transaction;
    }

    /**
     * Calculate commission for a merchant and amount (used by ChargeService)
     */
    public function calculateCommission(string $merchantId, float $amount): float
    {
        // You can either use a default service type or determine it based on merchant
        $serviceType = 'service'; // Default service type
        
        $commissionSetting = $this->getCommissionSetting($serviceType);
        return $commissionSetting->calculateCommission($amount);
    }

    /**
     * Get service type from payable model
     */
    private function getServiceTypeFromPayable($payableType)
    {
        $typeMapping = [
            'App\\Models\\GoalRequest' => 'goal_request',
            'App\\Models\\MealPlanRequest' => 'meal_plan_request',
            'App\\Models\\Service' => 'service',
        ];
        
        return $typeMapping[$payableType] ?? 'service';
    }

    /**
     * Get commission setting for service type
     */
    private function getCommissionSetting($serviceType)
    {
        $commissionSetting = CommissionSetting::getForServiceType($serviceType);
        
        if (!$commissionSetting) {
            return $this->getDefaultCommissionSetting($serviceType);
        }
        
        return $commissionSetting;
    }

    /**
     * Get default commission setting
     */
    private function getDefaultCommissionSetting($serviceType)
    {
        return new CommissionSetting([
            'service_type' => $serviceType,
            'commission_rate' => 0.15, // 15% default
            'is_active' => true,
        ]);
    }

    /**
     * Update transaction with gateway-based commission data
     */
    private function updateTransactionCommission(PaymentTransaction $transaction, array $feeCalculation): PaymentTransaction
    {
        $transaction->update([
            'commission_amount' => $feeCalculation['commission_amount'],
            'provider_amount' => $feeCalculation['provider_amount'],
            'commission_processed' => true,
            'commission_breakdown' => $feeCalculation,
        ]);

        return $transaction->fresh();
    }

    /**
     * Infer gateway information from payment gateway relationship
     */
    private function inferGatewayInfo(PaymentTransaction $transaction): void
    {
        if (!$transaction->paymentGateway) {
            $transaction->load('paymentGateway');
        }

        $gateway = $transaction->paymentGateway;
        if (!$gateway) {
            Log::error('No payment gateway found for transaction', [
                'transaction_id' => $transaction->id,
                'payment_gateway_id' => $transaction->payment_gateway_id,
            ]);
            return;
        }

        // Map gateway type to standardized gateway code and payment method
        $gatewayMapping = [
            'stripe' => ['gateway_code' => 'stripe', 'payment_method_type' => 'card'],
            'mpesa' => ['gateway_code' => 'mpesa', 'payment_method_type' => 'mobile_money'],
            'telebirr' => ['gateway_code' => 'telebirr', 'payment_method_type' => 'mobile_money'],
        ];

        $mapping = $gatewayMapping[$gateway->type] ?? ['gateway_code' => $gateway->type, 'payment_method_type' => 'unknown'];

        $transaction->update([
            'gateway_code' => $mapping['gateway_code'],
            'payment_method_type' => $mapping['payment_method_type'],
        ]);
    }

    /**
     * Bulk process unprocessed transactions
     */
    public function processUnprocessedTransactions()
    {
        $transactions = $this->getUnprocessedTransactions();
        
        $processed = [];
        
        foreach ($transactions as $transaction) {
            try {
                $feeCalculation = $this->gatewayPricingService->calculateFeesForTransaction($transaction);

                $earning = $this->processCommission($transaction, $feeCalculation);
                if ($earning) {
                    $processed[] = $earning;
                }
            } catch (\Exception $e) {
                Log::error('Commission processing failed for transaction: ' . $transaction->id, [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $processed;
    }

    /**
     * Get unprocessed transactions
     */
    private function getUnprocessedTransactions()
    {
        return PaymentTransaction::getUnprocessedCommissions();
    }

    /**
     * Calculate commission amount
     */
    public function calculateCommissionAmount($amount, $serviceType)
    {
        $commissionSetting = $this->getCommissionSetting($serviceType);
        return $commissionSetting->calculateCommission($amount);
    }

    /**
     * Get commission statistics
     */
    public function getCommissionStatistics($merchantId = null, $dateFrom = null, $dateTo = null)
    {
        return PaymentTransaction::getCommissionStatistics($merchantId, $dateFrom, $dateTo);
    }
}