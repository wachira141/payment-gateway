<?php

namespace App\Services;

use App\Models\Disbursement;
use App\Models\DisbursementBatch;
use App\Models\MerchantWallet;
use App\Models\Beneficiary;
use App\Services\WalletService;
use App\Services\BeneficiaryService;
use App\Services\GatewayPricingService;
use App\Services\PayoutMethodService;
use App\Services\PlatformEarningService;
use App\Jobs\ProcessDisbursementJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

class DisbursementService extends BaseService
{
    protected PlatformEarningService $platformEarningService;
    protected GatewayPricingService $gatewayPricingService;
    protected PayoutMethodService $payoutMethodService;
    protected BeneficiaryService $beneficiaryService;
    protected WalletService $walletService;

    public function __construct(
        WalletService $walletService,
        BeneficiaryService $beneficiaryService,
        GatewayPricingService $gatewayPricingService,
        PayoutMethodService $payoutMethodService,
        PlatformEarningService $platformEarningService
    ) {
        $this->walletService = $walletService;
        $this->beneficiaryService = $beneficiaryService;
        $this->gatewayPricingService = $gatewayPricingService;
        $this->payoutMethodService = $payoutMethodService;
        $this->platformEarningService = $platformEarningService;
    }

    // ==================== DISBURSEMENT CREATION ====================

    /**
     * Create a single disbursement from wallet
     */
    public function createDisbursement(
        string $merchantId,
        string $walletId,
        string $beneficiaryId,
        float $amount,
        array $options = []
    ): Disbursement {
        return DB::transaction(function () use ($merchantId, $walletId, $beneficiaryId, $amount, $options) {
            // Get and validate wallet
            $wallet = $this->walletService->getWalletById($walletId);
            if (!$wallet || $wallet->merchant_id !== $merchantId) {
                throw new \Exception('Wallet not found or does not belong to merchant');
            }

            if (!$wallet->isActive()) {
                throw new \Exception('Wallet is not active');
            }

            // Validate beneficiary
            $beneficiary = $this->beneficiaryService->getBeneficiaryById($beneficiaryId, $merchantId);
            if (!$beneficiary) {
                throw new \Exception('Beneficiary not found');
            }

            // Currency validation
            if ($wallet->currency !== $beneficiary['currency']) {
                throw new \Exception("Currency mismatch: Wallet currency ({$wallet->currency}) differs from beneficiary currency ({$beneficiary['currency']})");
            }

            // Calculate fee with detailed breakdown
            $feeDetails = $this->calculateDisbursementFeeWithDetails($merchantId, $beneficiary, $amount, $wallet->currency);
            $totalDebit = $amount + $feeDetails['total_fees'];

            // Check wallet balance
            $canDebit = $this->walletService->canDebit($walletId, $totalDebit);
            if (!$canDebit['allowed']) {
                throw new \Exception($canDebit['reason']);
            }

            // Determine payout method
            $payoutMethod = $this->determinePayoutMethod($beneficiary);

            // Calculate estimated arrival
            $estimatedArrival = $this->calculateEstimatedArrival($beneficiary, $payoutMethod);

            // Create disbursement
            $disbursement = Disbursement::createDisbursement([
                'merchant_id' => $merchantId,
                'wallet_id' => $wallet->wallet_id,
                'beneficiary_id' => $beneficiary['beneficiary_id'],
                'funding_source' => 'wallet',
                'payout_method' => $payoutMethod,
                'gross_amount' => $amount,
                'fee_amount' => $feeDetails['total_fees'],
                'gateway_processing_fee' => $feeDetails['gateway_processing_fee'],
                'platform_application_fee' => $feeDetails['platform_application_fee'],
                'gateway_code' => $feeDetails['gateway_code'],
                'payout_method_type' => $feeDetails['payout_method_type'],
                'currency' => $wallet->currency,
                'description' => $options['description'] ?? null,
                'external_reference' => $options['external_reference'] ?? null,
                'estimated_arrival' => $estimatedArrival,
                'metadata' => array_merge($options['metadata'] ?? [], [
                    'beneficiary_name' => $beneficiary['name'],
                    'wallet_name' => $wallet->name,
                    'fee_breakdown' => $feeDetails['breakdown'] ?? null,
                ]),
            ]);


            // Record platform earning for the disbursement
            $this->platformEarningService->recordDisbursementEarning(
                $disbursement,
                $feeDetails['fee_calculation'] ?? $feeDetails,
                (int) ($feeDetails['gateway_processing_fee'] * 100) // Convert to cents for gateway cost
            );

            // Hold funds in wallet
            $this->walletService->holdFunds(
                $walletId,
                $totalDebit,
                "Disbursement {$disbursement->disbursement_id}",
                $disbursement->disbursement_id
            );

            // Dispatch processing job
            ProcessDisbursementJob::dispatch($disbursement);

            Log::info("Created disbursement with platform earning", [
                'disbursement_id' => $disbursement->disbursement_id,
                'merchant_id' => $merchantId,
                'amount' => $amount,
                'fee' => $feeDetails['total_fees'],
                'gateway_fee' => $feeDetails['gateway_processing_fee'],
                'platform_fee' => $feeDetails['platform_application_fee'],
            ]);

            return $disbursement->fresh(['wallet', 'beneficiary']);
        });
    }

   
    /**
     * Create batch disbursement with detailed fee tracking and platform earnings
     * 
     * @param string $merchantId Merchant ID
     * @param string $walletId Wallet ID to fund disbursements from
     * @param array $disbursementsData Array of disbursement items
     * @param array $options Optional batch settings
     * @return DisbursementBatch
     */
    public function createBatchDisbursement(
        string $merchantId,
        string $walletId,
        array $disbursementsData,
        array $options = []
    ): DisbursementBatch {
        return DB::transaction(function () use ($merchantId, $walletId, $disbursementsData, $options) {
            // Validate wallet
            $wallet = $this->walletService->getWallet($walletId);
            if (!$wallet || $wallet->merchant_id !== $merchantId) {
                throw new \Exception('Wallet not found or does not belong to merchant');
            }

            if (!$wallet->isActive()) {
                throw new \Exception('Wallet is not active');
            }

            // Calculate total required with detailed fee breakdown
            $totalAmount = 0;
            $totalFees = 0;
            $totalGatewayFees = 0;
            $totalPlatformFees = 0;
            $validatedItems = [];

            foreach ($disbursementsData as $index => $item) {
                // Validate beneficiary
                $beneficiary = $this->beneficiaryService->getBeneficiaryById($item['beneficiary_id'], $merchantId);
                if (!$beneficiary) {
                    throw new \Exception("Beneficiary not found at index {$index}");
                }

                if ($wallet->currency !== $beneficiary['currency']) {
                    throw new \Exception("Currency mismatch at index {$index}: Wallet currency ({$wallet->currency}) differs from beneficiary currency ({$beneficiary['currency']})");
                }

                // Use detailed fee calculation instead of simple fee
                $feeDetails = $this->calculateDisbursementFeeWithDetails($merchantId, $beneficiary, $item['amount'], $wallet->currency);
                
                $totalAmount += $item['amount'];
                $totalFees += $feeDetails['total_fees'];
                $totalGatewayFees += $feeDetails['gateway_processing_fee'];
                $totalPlatformFees += $feeDetails['platform_application_fee'];

                $validatedItems[] = [
                    'beneficiary' => $beneficiary,
                    'amount' => $item['amount'],
                    'fee_details' => $feeDetails, // Store full fee breakdown
                    'description' => $item['description'] ?? null,
                    'external_reference' => $item['external_reference'] ?? null,
                ];
            }

            $totalDebit = $totalAmount + $totalFees;

            // Check wallet balance for entire batch
            $canDebit = $this->walletService->canDebit($walletId, $totalDebit);
            if (!$canDebit['allowed']) {
                throw new \Exception("Insufficient balance for batch: {$canDebit['reason']}");
            }

            // Create batch
            $batch = DisbursementBatch::createBatch(
                $merchantId,
                $wallet->id,
                $wallet->currency,
                $options['batch_name'] ?? null
            );

            // Hold total funds
            $this->walletService->holdFunds(
                $walletId,
                $totalDebit,
                "Batch disbursement {$batch->batch_id}",
                $batch->batch_id
            );

            // Create individual disbursements with detailed fee tracking
            foreach ($validatedItems as $item) {
                $payoutMethod = $this->determinePayoutMethod($item['beneficiary']);
                $estimatedArrival = $this->calculateEstimatedArrival($item['beneficiary'], $payoutMethod);
                $feeDetails = $item['fee_details'];

                // Create disbursement with new fee columns
                $disbursement = Disbursement::createDisbursement([
                    'merchant_id' => $merchantId,
                    'wallet_id' => $wallet->id,
                    'beneficiary_id' => $item['beneficiary']['id'],
                    'disbursement_batch_id' => $batch->id,
                    'funding_source' => 'wallet',
                    'payout_method' => $payoutMethod,
                    'gross_amount' => $item['amount'],
                    'fee_amount' => $feeDetails['total_fees'],
                    'gateway_processing_fee' => $feeDetails['gateway_processing_fee'],
                    'platform_application_fee' => $feeDetails['platform_application_fee'],
                    'gateway_code' => $feeDetails['gateway_code'],
                    'payout_method_type' => $feeDetails['payout_method_type'],
                    'currency' => $wallet->currency,
                    'description' => $item['description'],
                    'external_reference' => $item['external_reference'],
                    'estimated_arrival' => $estimatedArrival,
                    'metadata' => [
                        'batch_id' => $batch->batch_id,
                        'beneficiary_name' => $item['beneficiary']['name'],
                        'fee_breakdown' => $feeDetails['breakdown'] ?? null,
                    ],
                ]);

                // Record platform earning for each disbursement in the batch
                $this->platformEarningService->recordDisbursementEarning(
                    $disbursement,
                    $feeDetails['fee_calculation'] ?? $feeDetails,
                    (int) ($feeDetails['gateway_processing_fee'] * 100) // Convert to cents for gateway cost
                );

                // Dispatch processing job for each disbursement
                ProcessDisbursementJob::dispatch($disbursement);
            }

            // Update batch totals
            $batch->recalculateTotals();
            $batch->markAsProcessing();

            Log::info("Created batch disbursement with platform earnings", [
                'batch_id' => $batch->batch_id,
                'merchant_id' => $merchantId,
                'count' => count($disbursementsData),
                'total_amount' => $totalAmount,
                'total_fees' => $totalFees,
                'total_gateway_fees' => $totalGatewayFees,
                'total_platform_fees' => $totalPlatformFees,
            ]);

            return $batch->fresh(['wallet', 'disbursements']);
        });
    }

    // ==================== DISBURSEMENT MANAGEMENT ====================

    /**
     * Cancel a disbursement
     */
    public function cancelDisbursement(string $disbursementId, string $merchantId, string $reason = null): Disbursement
    {
        return DB::transaction(function () use ($disbursementId, $merchantId, $reason) {
            $disbursement = Disbursement::where('disbursement_id', $disbursementId)
                ->where('merchant_id', $merchantId)
                ->first();

            if (!$disbursement) {
                throw new \Exception('Disbursement not found');
            }

            if (!$disbursement->canBeCancelled()) {
                throw new \Exception("Disbursement cannot be cancelled in '{$disbursement->status}' status");
            }

            // Release held funds
            $totalAmount = $disbursement->gross_amount + $disbursement->fee_amount;
            $this->walletService->releaseFunds(
                $disbursement->wallet->wallet_id,
                $totalAmount,
                "Cancelled disbursement {$disbursement->disbursement_id}",
                $disbursement->disbursement_id
            );

            // Mark as cancelled
            $disbursement->markAsCancelled($reason ?? 'Cancelled by merchant');

            Log::info("Cancelled disbursement", [
                'disbursement_id' => $disbursement->disbursement_id,
                'reason' => $reason,
            ]);

            return $disbursement->fresh();
        });
    }

    /**
     * Retry a failed disbursement
     */
    public function retryDisbursement(string $disbursementId, string $merchantId): Disbursement
    {
        $disbursement = Disbursement::where('disbursement_id', $disbursementId)
            ->where('merchant_id', $merchantId)
            ->first();

        if (!$disbursement) {
            throw new \Exception('Disbursement not found');
        }

        if (!$disbursement->canBeRetried()) {
            throw new \Exception("Disbursement cannot be retried in '{$disbursement->status}' status");
        }

        // Reset status and dispatch job
        $disbursement->update([
            'status' => 'pending',
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        ProcessDisbursementJob::dispatch($disbursement);

        Log::info("Retrying disbursement", [
            'disbursement_id' => $disbursement->disbursement_id,
        ]);

        return $disbursement->fresh();
    }

    // ==================== QUERY METHODS ====================

    /**
     * Get merchant disbursements
     */
    public function getMerchantDisbursements(string $merchantId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Disbursement::getMerchantDisbursements($merchantId, $filters, $perPage);
    }

    /**
     * Get disbursement by ID
     */
    public function getDisbursementById(string $disbursementId, string $merchantId): ?Disbursement
    {
        return Disbursement::with(['wallet', 'beneficiary', 'disbursementBatch'])
            ->where('disbursement_id', $disbursementId)
            ->where('merchant_id', $merchantId)
            ->first();
    }

    /**
     * Get merchant batches
     */
    public function getMerchantBatches(string $merchantId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return DisbursementBatch::getMerchantBatches($merchantId, $filters, $perPage);
    }

    /**
     * Get batch by ID
     */
    public function getBatchById(string $batchId, string $merchantId): ?DisbursementBatch
    {
        return DisbursementBatch::with(['wallet', 'disbursements.beneficiary'])
            ->where('batch_id', $batchId)
            ->where('merchant_id', $merchantId)
            ->first();
    }

    /**
     * Get disbursement statistics
     */
    public function getDisbursementStatistics(string $merchantId, array $filters = []): array
    {
        return Disbursement::getMerchantStatistics($merchantId, $filters);
    }

    /**
     * Get disbursement summary
     */
    public function getDisbursementSummary(string $merchantId): array
    {
        return Disbursement::getMerchantSummary($merchantId);
    }

    // ==================== HELPER METHODS ====================

   /**
     * Calculate disbursement fee
     */
    protected function calculateDisbursementFee(string $merchantId, array $beneficiary, float $amount, string $currency): float
    {
        $feeDetails = $this->calculateDisbursementFeeWithDetails($merchantId, $beneficiary, $amount, $currency);
        return $feeDetails['total_fees'];
    }

    /**
     * Calculate disbursement fee with detailed breakdown
     */
    protected function calculateDisbursementFeeWithDetails(
        string $merchantId,
        array $beneficiary,
        float $amount,
        string $currency
    ): array {
        try {
            $feeCalculation = $this->gatewayPricingService->calculateDisbursementFees(
                $merchantId,
                $beneficiary,
                (int) ($amount * 100), // Convert to cents
                $currency
            );

            return [
                'gateway_processing_fee' => (float) ($feeCalculation['gateway_processing_fee'] / 100),
                'platform_application_fee' => (float) ($feeCalculation['platform_application_fee'] / 100),
                'total_fees' => (float) ($feeCalculation['total_fees'] / 100),
                'gateway_code' => $feeCalculation['gateway_code'],
                'payout_method_type' => $feeCalculation['payment_method_type'],
                'fee_calculation' => $feeCalculation,
                'breakdown' => $feeCalculation['breakdown'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::warning("Fee calculation failed, using default", [
                'error' => $e->getMessage(),
            ]);
            // Default fee calculation
            $defaultFee = $amount * 0.01;
            $gatewayFee = $defaultFee * 0.7; // 70% to gateway
            $platformFee = $defaultFee * 0.3; // 30% platform

            return [
                'gateway_processing_fee' => $gatewayFee,
                'platform_application_fee' => $platformFee,
                'total_fees' => $defaultFee,
                'gateway_code' => 'unknown',
                'payout_method_type' => $beneficiary['type'] ?? 'unknown',
                'fee_calculation' => ['fallback' => true],
                'breakdown' => null,
            ];
        }
    }


    /**
     * Determine payout method from beneficiary
     */
    protected function determinePayoutMethod(array $beneficiary): string
    {
        try {
            return $this->payoutMethodService->getMethodTypeByBeneficiaryType(
                $beneficiary['type'] ?? 'bank_account',
                $beneficiary['country'],
                $beneficiary['currency']
            );
        } catch (\Exception $e) {
            return 'bank_transfer';
        }
    }

    /**
     * Calculate estimated arrival time
     */
    protected function calculateEstimatedArrival(array $beneficiary, string $payoutMethod): Carbon
    {
        try {
            $processingHours = $this->payoutMethodService->getProcessingTimeForMethod(
                $payoutMethod,
                $beneficiary['country'],
                $beneficiary['currency']
            );

            return now()->addHours($processingHours);
        } catch (\Exception $e) {
            // Default processing time based on method
            $defaultHours = match ($payoutMethod) {
                'mobile_money' => 1,
                'bank_transfer' => 24,
                'international_wire' => 72,
                default => 48,
            };

            return now()->addHours($defaultHours);
        }
    }

    /**
     * Get payout estimation for amount
     */
    public function getPayoutEstimation(string $merchantId, string $walletId, float $amount): array
    {
        $wallet = $this->walletService->getWalletById($walletId);
        
        if (!$wallet || $wallet->merchant_id !== $merchantId) {
            throw new \Exception('Wallet not found');
        }

        // Get all beneficiaries for merchant
        $beneficiaries = Beneficiary::where('merchant_id', $merchantId)
            ->where('currency', $wallet->currency)
            ->where('status', 'active')
            ->limit(5)
            ->get();

        $estimations = [];

        foreach ($beneficiaries as $beneficiary) {
            $beneficiaryData = $beneficiary->toArray();
            $fee = $this->calculateDisbursementFee($merchantId, $beneficiaryData, $amount, $wallet->currency);
            $payoutMethod = $this->determinePayoutMethod($beneficiaryData);
            $estimatedArrival = $this->calculateEstimatedArrival($beneficiaryData, $payoutMethod);

            $estimations[] = [
                'beneficiary_id' => $beneficiary->beneficiary_id,
                'beneficiary_name' => $beneficiary->name,
                'payout_method' => $payoutMethod,
                'gross_amount' => $amount,
                'fee' => $fee,
                'net_amount' => $amount - $fee,
                'currency' => $wallet->currency,
                'estimated_arrival' => $estimatedArrival->toISOString(),
            ];
        }

        return [
            'wallet' => [
                'wallet_id' => $wallet->wallet_id,
                'name' => $wallet->name,
                'available_balance' => (float) $wallet->available_balance,
                'currency' => $wallet->currency,
            ],
            'amount' => $amount,
            'fee_amount' => $fee,
            'estimations' => $estimations,
        ];
    }
}
