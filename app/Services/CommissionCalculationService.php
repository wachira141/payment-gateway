<?php

namespace App\Services;

use App\Models\CommissionSetting;
use App\Models\ProviderEarning;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Log;

class CommissionCalculationService
{
    protected $balanceService;

    public function __construct(BalanceService $balanceService)
    {
        $this->balanceService = $balanceService;
    }

    /**
     * Calculate and record commission for a payment transaction
     */
    public function processCommission(PaymentTransaction $transaction)
    {
        if ($transaction->commission_processed) {
            return null; // Already processed
        }

        // Determine service type from payable
        $serviceType = $this->getServiceTypeFromPayable($transaction->payable_type);
        
        // Get commission setting
        $commissionSetting = $this->getCommissionSetting($serviceType);
        
        // Calculate commission
        $commissionAmount = $commissionSetting->calculateCommission($transaction->amount);
        $providerAmount = $transaction->amount - $commissionAmount;
        
        // Update transaction
        $transaction = $this->updateTransactionCommission($transaction, $commissionAmount, $providerAmount);
       
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
     * Update transaction with commission data
     */
    private function updateTransactionCommission($transaction, $commissionAmount, $providerAmount)
    {
        return $transaction->updateRecord([
            'commission_amount' => $commissionAmount,
            'provider_amount' => $providerAmount,
            'commission_processed' => true,
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
                $earning = $this->processCommission($transaction);
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