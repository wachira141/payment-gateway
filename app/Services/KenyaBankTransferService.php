<?php
namespace App\Services;

use App\Models\MerchantBankAccount;
use App\Contracts\PaymentStatusInterface;
use App\Models\Merchant;
use App\Services\PaymentStatusMapper;
use Illuminate\Support\Facades\Log;

class KenyaBankTransferService implements PaymentStatusInterface
{
    private $kenyaBanks = [
        'KCB' => ['code' => '01', 'name' => 'Kenya Commercial Bank'],
        'SCB' => ['code' => '02', 'name' => 'Standard Chartered Bank'],
        'BBK' => ['code' => '03', 'name' => 'Barclays Bank of Kenya'],
        'CFC' => ['code' => '31', 'name' => 'CFC Stanbic Bank'],
        'DTB' => ['code' => '49', 'name' => 'Diamond Trust Bank'],
        'EQB' => ['code' => '68', 'name' => 'Equity Bank'],
        'COO' => ['code' => '11', 'name' => 'Co-operative Bank'],
        'FAM' => ['code' => '70', 'name' => 'Family Bank'],
    ];

    /**
     * Initiate bank transfer for Kenya
     */
    public function initiateBankTransfer(MerchantBankAccount $account, $amount, $reference, $description = 'Provider Payout')
    {
        try {
            // Validate account
            if (!$this->validateBankAccount($account)) {
                return [
                    'success' => false,
                    'error' => 'Invalid bank account details',
                ];
            }

            // Determine transfer method based on amount
            $transferMethod = $this->determineTransferMethod($amount);
            
            // For now, we'll simulate the bank transfer
            // In production, integrate with Kenya's payment gateway or banking API
            $result = $this->processTransfer($account, $amount, $transferMethod, $reference, $description);

            return $result;
        } catch (\Exception $e) {
            Log::error('Kenya bank transfer error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate bank account for Kenya
     */
    private function validateBankAccount(MerchantBankAccount $account)
    {
        // Check if account is for Kenya
        if ($account->country_code !== 'KE') {
            return false;
        }

        // Validate bank code
        if (!$account->bank_code || !isset($this->kenyaBanks[$account->bank_code])) {
            return false;
        }

        // Validate account number format (basic validation)
        $accountNumber = $account->account_number;
        if (!$accountNumber || strlen($accountNumber) < 6 || strlen($accountNumber) > 20) {
            return false;
        }

        return true;
    }

    /**
     * Determine transfer method based on amount
     */
    private function determineTransferMethod($amount)
    {
        // EFT for amounts under 1 million KES
        if ($amount < 1000000) {
            return 'eft';
        }
        
        // RTGS for larger amounts
        return 'rtgs';
    }

    /**
     * Process the actual bank transfer
     */
    private function processTransfer($account, $amount, $method, $reference, $description)
    {
        // This would integrate with actual banking APIs
        // For now, simulate success/failure
        
        $bankInfo = $this->kenyaBanks[$account->bank_code];
        
        // Simulate processing time and success rate
        $isSuccess = rand(1, 100) > 5; // 95% success rate simulation
        
        if ($isSuccess) {
            return [
                'success' => true,
                'transfer_id' => 'KE_' . $method . '_' . uniqid(),
                'method' => $method,
                'bank_name' => $bankInfo['name'],
                'bank_code' => $bankInfo['code'],
                'account_number' => substr($account->account_number, -4),
                'amount' => $amount,
                'currency' => 'KES',
                'reference' => $reference,
                'estimated_completion' => $this->getEstimatedCompletion($method),
                'processing_fee' => $this->calculateProcessingFee($amount, $method),
            ];
        }

        return [
            'success' => false,
            'error' => 'Bank transfer failed - please retry or contact support',
            'retry_after' => 300, // 5 minutes
        ];
    }

    /**
     * Get estimated completion time
     */
    private function getEstimatedCompletion($method)
    {
        switch ($method) {
            case 'eft':
                return now()->addHours(2); // EFT usually takes 2-4 hours
            case 'rtgs':
                return now()->addMinutes(30); // RTGS is real-time
            default:
                return now()->addHours(24);
        }
    }

    /**
     * Calculate processing fee
     */
    private function calculateProcessingFee($amount, $method)
    {
        switch ($method) {
            case 'eft':
                return min(max($amount * 0.001, 25), 200); // 0.1% with min 25 KES, max 200 KES
            case 'rtgs':
                return min(max($amount * 0.002, 100), 500); // 0.2% with min 100 KES, max 500 KES
            default:
                return $amount * 0.01; // 1% fallback
        }
    }

    /**
     * Get transfer limits
     */
    public function getTransferLimits()
    {
        return [
            'eft' => [
                'min_amount' => 100,
                'max_amount' => 999999,
                'currency' => 'KES',
                'processing_time' => '2-4 hours',
            ],
            'rtgs' => [
                'min_amount' => 1000000,
                'max_amount' => 50000000,
                'currency' => 'KES',
                'processing_time' => 'Real-time',
            ],
        ];
    }

    /**
     * Get supported Kenya banks
     */
    public function getSupportedBanks()
    {
        return $this->kenyaBanks;
    }

    /**
     * Query transfer status (for future integration)
     */
    public function queryTransferStatus($transferId)
    {
        // This would query the banking API for transfer status
        // For now, simulate response
        return [
            'success' => true,
            'status' => 'completed',
            'transfer_id' => $transferId,
            'completed_at' => now(),
        ];
    }

     /**
     * Get payment status with standardized response format
     */
    public function getPaymentStatus(string $transactionId): array
    {
        $response = $this->queryTransferStatus($transactionId);
        
        if (!$response['success']) {
            return PaymentStatusMapper::createStandardResponse(
                false,
                PaymentStatusMapper::STATUS_FAILED,
                'error',
                $transactionId,
                null,
                null,
                'Transfer query failed',
                $response
            );
        }

        $gatewayStatus = $response['status'] ?? 'unknown';
        $standardStatus = PaymentStatusMapper::mapBankTransferStatus($gatewayStatus);
        
        return PaymentStatusMapper::createStandardResponse(
            true,
            $standardStatus,
            $gatewayStatus,
            $transactionId,
            null,
            $standardStatus === PaymentStatusMapper::STATUS_COMPLETED 
                ? ($response['completed_at'] ?? now()->toISOString()) : null,
            null,
            $response
        );
    }
}
