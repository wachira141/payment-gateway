<?php

namespace App\Services;

use App\Models\Payout;
use App\Services\BalanceService;
use App\Services\BeneficiaryService;
use App\Services\GatewayPricingService;
use App\Services\PayoutMethodService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayoutService extends BaseService
{
    private BalanceService $balanceService;
    private BeneficiaryService $beneficiaryService;
    private GatewayPricingService $gatewayPricingService;
    private PayoutMethodService $payoutMethodService;

    public function __construct(
        BalanceService $balanceService,
        BeneficiaryService $beneficiaryService,
        GatewayPricingService $gatewayPricingService,
        PayoutMethodService $payoutMethodService
    ) {
        $this->balanceService = $balanceService;
        $this->beneficiaryService = $beneficiaryService;
        $this->gatewayPricingService = $gatewayPricingService;
        $this->payoutMethodService = $payoutMethodService;
    }

    /**
     * Get payouts for a merchant with filters
     */
     /**
     * Get payouts for a merchant with filters including beneficiary data
     */
    public function getPayoutsForMerchant(string $merchantId, array $filters = [])
    {
        return Payout::getForMerchant($merchantId, $filters);
    }

    /**
     * Get a payout by ID for a merchant
     */
    public function getPayoutById(string $payoutId, string $merchantId): ?array
    {
        $payout = Payout::findByIdAndMerchant($payoutId, $merchantId);
        return $payout ? $payout : null;
    }

    /**
     * Create a new payout
     */
    public function createPayout(string $merchantId, array $data): array
    {
        // Validate beneficiary
        DB::beginTransaction();
        try {
            //code...
            $beneficiary = $this->beneficiaryService->getBeneficiaryById(
                $data['beneficiary_id'],
                $merchantId
            );

            if (!$beneficiary) {
                throw new \Exception('Beneficiary not found');
            }

            if ($beneficiary['currency'] !== $data['currency']) {
                throw new \Exception('Currency mismatch with beneficiary');
            }

            // Check available balance
            $balance = $this->balanceService->getBalanceForCurrency($merchantId, $data['currency']);

            if ($balance['available'] < $data['amount']) {
                throw new \Exception('Insufficient balance for payout');
            }

            $payoutData = [
                'id' => 'po_' . Str::random(24),
                'merchant_id' => $merchantId,
                'beneficiary_id' => $beneficiary['beneficiary_id'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'status' => 'pending',
                'description' => $data['description'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'fee_amount' => $this->calculatePayoutFee($merchantId, $beneficiary, $data['amount'], $data['currency']),
                'estimated_arrival' => $this->calculateEstimatedArrival($beneficiary)
            ];

            $payout = Payout::create($payoutData);
            // echo json_encode($payoutData);

            // Reserve the amount from balance
            $this->balanceService->reserveBalance(
                $merchantId,
                $data['currency'],
                $data['amount'] + $payoutData['fee_amount'],
                'payout_created',
                $payout['id']
            );

            Db::commit();
            // Process the payout
            return $this->processPayout($payout['id']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to create payout: ' . $e->getMessage());
        }
    }

    /**
     * Process a payout
     */
    public function processPayout(string $payoutId): array
    {

        try {
            $payout = Payout::findById($payoutId);

            if (!$payout) {
                throw new \Exception('Payout not found');
            }

            if ($payout['status'] !== 'pending') {
                throw new \Exception('Payout is not in processable state');
            }

            // Simulate payout processing
            $success = $this->processPayoutWithProvider($payout);
            if ($success) {
                $payout = Payout::updateById($payoutId, [
                    'status' => 'in_transit',
                    'processed_at' => now(),
                    'transaction_id' => 'txn_' . Str::random(16)
                ]);
                // Deduct from balance (convert reserved to actual deduction)
                $this->balanceService->processReservedBalance(
                    $payout['merchant_id'],
                    $payout['currency'],
                    $payout['amount'] + $payout['fee_amount'],
                    'payout_processed',
                    $payoutId
                );

                return $payout;
            } else {
                $payout = Payout::updateById($payoutId, [
                    'status' => 'failed',
                    'failure_reason' => 'Payout processing failed'
                ]);

                // Release reserved balance
                $this->balanceService->releaseReservedBalance(
                    $payout['merchant_id'],
                    $payout['currency'],
                    $payout['amount'] + $payout['fee_amount'],
                    'payout_failed',
                    $payoutId
                );

                throw new \Exception('Payout processing failed');
            }
        } catch (\Exception $e) {
            throw new \Exception('Failed to process payout: ' . $e->getMessage());
        }
    }

    /**
     * Cancel a payout
     */
    public function cancelPayout(string $payoutId, string $merchantId): array
    {
        $payout = Payout::findByIdAndMerchant($payoutId, $merchantId);

        if (!$payout) {
            throw new \Exception('Payout not found');
        }

        if (!in_array($payout['status'], ['pending', 'in_transit'])) {
            throw new \Exception('Payout cannot be cancelled in current state');
        }

        $payout = Payout::updateById($payoutId, [
            'status' => 'cancelled',
            'cancelled_at' => now()
        ]);

        // Release reserved balance
        $this->balanceService->releaseReservedBalance(
            $merchantId,
            $payout['currency'],
            $payout['amount'] + $payout['fee_amount'],
            'payout_cancelled',
            $payoutId
        );

        return $payout;
    }

    /**
     * Simulate payout processing with external provider
     */
    private function processPayoutWithProvider(array $payout): bool
    {
        // Simulate payout processing logic based on beneficiary type and currency
        // In real implementation, this would integrate with banking systems

        $failureRate = match ($payout['currency']) {
            'KES' => 2, // Lower failure for local currency
            'USD', 'EUR', 'GBP' => 5, // Higher for international
            default => 3
        };

        // Additional checks based on beneficiary type
        if (isset($payout['beneficiary_type'])) {
            $failureRate = match ($payout['beneficiary_type']) {
                'mobile_money' => max(1, $failureRate - 1), // Mobile money is more reliable
                'bank_account' => $failureRate,
                'international_wire' => $failureRate + 2, // International wires have higher failure rate
                default => $failureRate
            };
        }

        // Simulate processing time
        usleep(rand(100000, 500000)); // 0.1 to 0.5 seconds delay

        return rand(1, 100) > $failureRate;
    }

    /**
     * Calculate payout fee based on amount and currency
     */
    /**
     * Calculate payout fee using gateway pricing service
     */
    private function calculatePayoutFee(string $merchantId, array $beneficiary, int $amount, string $currency): int
    {
        $feeCalculation = $this->gatewayPricingService->calculatePayoutFees(
            $merchantId,
            $beneficiary,
            $amount,
            $currency
        );

        return (int) $feeCalculation['total_fees'];
    }
    
    /**
     * Calculate estimated arrival time for payout
     */
    private function calculateEstimatedArrival(array $beneficiary): string
    {
        // Use database-driven processing time
        $processingHours = $this->payoutMethodService->getProcessingTimeForMethod(
            $this->mapBeneficiaryToPayoutMethod($beneficiary),
            $beneficiary['country'],
            $beneficiary['currency']
        );

        return now()->addHours($processingHours)->format('Y-m-d H:i:s');
    }


    /**
      * Get multi-currency payout statistics for merchant
     */
    public function getPayoutStatistics(string $merchantId, array $filters = []): array
    {
        return Payout::getStatsForMerchant($merchantId, $filters);
    }

    /**
     * Add business days to a date (excluding weekends and holidays)
     */
    private function addBusinessDays(Carbon $date, int $businessDays, array $holidays = []): Carbon
    {
        if ($businessDays <= 0) {
            return $date->copy();
        }

        $result = $date->copy();
        $daysAdded = 0;

        while ($daysAdded < $businessDays) {
            $result->addDay();

            // Check if it's a business day (Monday to Friday and not a holiday)
            if (!$result->isWeekend() && !in_array($result->format('Y-m-d'), $holidays)) {
                $daysAdded++;
            }
        }

        return $result;
    }

     /**
     * Map beneficiary type to payout method using database configuration
     */
    private function mapBeneficiaryToPayoutMethod(array $beneficiary): string
    {
        // Use database-driven method mapping
        return $this->payoutMethodService->getMethodTypeByBeneficiaryType(
            $beneficiary['type'],
            $beneficiary['country'],
            $beneficiary['currency']
        );
    }

}
