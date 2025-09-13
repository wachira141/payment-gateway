<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\Beneficiary;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PayoutService extends BaseService
{
    private BalanceService $balanceService;
    private BeneficiaryService $beneficiaryService;

    public function __construct(
        BalanceService $balanceService,
        BeneficiaryService $beneficiaryService
    ) {
        $this->balanceService = $balanceService;
        $this->beneficiaryService = $beneficiaryService;
    }

    /**
     * Get payouts for a merchant with filters
     */
    public function getPayoutsForMerchant(string $merchantId, array $filters = []): Collection
    {
        $query = Payout::where('merchant_id', $merchantId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        $query->orderBy('created_at', 'desc');

        if (!empty($filters['limit'])) {
            $query->limit($filters['limit']);
        }

        if (!empty($filters['offset'])) {
            $query->offset($filters['offset']);
        }

        return $query->get();
    }

    /**
     * Get a payout by ID for a merchant
     */
    public function getPayoutById(string $payoutId, string $merchantId): ?array
    {
        $payout = Payout::findByIdAndMerchant($payoutId, $merchantId);
        return $payout ? $payout->toArray() : null;
    }

    /**
     * Create a new payout
     */
    public function createPayout(string $merchantId, array $data): array
    {
        // Validate beneficiary
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
            'beneficiary_id' => $data['beneficiary_id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'status' => 'pending',
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'fee_amount' => $this->calculatePayoutFee($data['amount'], $data['currency']),
            'estimated_arrival' => $this->calculateEstimatedArrival($beneficiary)
        ];

        $payout = Payout::create($payoutData);

        // Reserve the amount from balance
        $this->balanceService->reserveBalance(
            $merchantId,
            $data['currency'],
            $data['amount'] + $payoutData['fee_amount'],
            'payout_created',
            $payout['id']
        );

        // Process the payout
        return $this->processPayout($payout['id']);
    }

    /**
     * Process a payout
     */
    public function processPayout(string $payoutId): array
    {
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
        // Simulate payout processing logic
        // In real implementation, this would integrate with banking systems
        
        // Simulate 3% failure rate for demonstration
        return rand(1, 100) > 3;
    }

    /**
     * Calculate payout fee based on amount and currency
     */
    private function calculatePayoutFee(int $amount, string $currency): int
    {
        // Simple fee structure - in real implementation this would be more complex
        $feePercentage = 0.01; // 1%
        $minimumFee = match ($currency) {
            'USD' => 100, // $1.00
            'EUR' => 100, // €1.00
            'GBP' => 100, // £1.00
            'KES' => 10000, // KSh 100
            default => 100
        };

        $calculatedFee = (int) ($amount * $feePercentage);
        return max($calculatedFee, $minimumFee);
    }

    /**
     * Calculate estimated arrival time for payout
     */
    private function calculateEstimatedArrival(array $beneficiary): string
    {
        // Simple estimation - in real implementation this would consider
        // banking networks, holidays, country-specific factors, etc.
        
        $businessDays = match ($beneficiary['type']) {
            'bank_account' => rand(1, 3),
            'mobile_money' => 0, // Instant
            default => 1
        };

        return now()->addBusinessDays($businessDays)->format('Y-m-d H:i:s');
    }

    /**
     * Get payout statistics for merchant
     */
    public function getPayoutStatistics(string $merchantId, array $filters = []): array
    {
        $query = Payout::where('merchant_id', $merchantId);

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        $payouts = $query->get();

        return [
            'total_payouts' => $payouts->count(),
            'successful_payouts' => $payouts->whereIn('status', ['in_transit', 'completed'])->count(),
            'failed_payouts' => $payouts->where('status', 'failed')->count(),
            'pending_payouts' => $payouts->where('status', 'pending')->count(),
            'total_payout_amount' => $payouts->whereIn('status', ['in_transit', 'completed'])->sum('amount'),
            'total_fees' => $payouts->whereIn('status', ['in_transit', 'completed'])->sum('fee_amount'),
            'average_payout_amount' => $payouts->whereIn('status', ['in_transit', 'completed'])->avg('amount'),
        ];
    }
}