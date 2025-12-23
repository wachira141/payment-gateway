<?php

namespace App\Services;

use App\Services\BaseService;
use App\Services\LedgerService;
use App\Models\Refund;
use App\Models\Charge;
use App\Models\PaymentIntent;
use App\Helpers\CurrencyHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RefundService extends BaseService
{
    private BalanceService $balanceService;
    private LedgerService $ledgerService;

    public function __construct(BalanceService $balanceService, LedgerService $ledgerService)
    {
        $this->balanceService = $balanceService;
        $this->ledgerService = $ledgerService;
    }

    /**
     * Get refunds for a merchant with filters
     */
    public function getRefundsForMerchant(string $merchantId, array $filters = [])
    {
        $query = Refund::where('merchant_id', $merchantId);

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

        $perPage = $filters['limit'] ?? 15;
        return $query->paginate($perPage);
    }

    /**
     * Get a refund by ID for a merchant
     */
    public function getRefundById(string $refundId, string $merchantId): ?array
    {
        $refund = Refund::findByIdAndMerchant($refundId, $merchantId);
        return $refund ? $refund->toArray() : null;
    }

    /**
     * Create a new refund
     */
    public function createRefund(string $merchantId, array $data): array
    {
        // Validate charge or payment intent
        $charge = null;
        $paymentIntent = null;

        if (!empty($data['charge_id'])) {
            $charge = Charge::findByIdAndMerchant($data['charge_id'], $merchantId);
            if (!$charge) {
                throw new \Exception('Charge not found');
            }
            if ($charge['status'] !== 'succeeded') {
                throw new \Exception('Cannot refund unsuccessful charge');
            }
        } elseif (!empty($data['payment_intent_id'])) {
            $paymentIntent = PaymentIntent::findByIdAndMerchant($data['payment_intent_id'], $merchantId);
            if (!$paymentIntent) {
                throw new \Exception('Payment intent not found');
            }
            if ($paymentIntent['status'] !== 'succeeded') {
                throw new \Exception('Cannot refund unsuccessful payment intent');
            }

            // Find the associated charge
            $charge = Charge::findByPaymentIntent($data['payment_intent_id']);
            if (!$charge) {
                throw new \Exception('No charge found for payment intent');
            }
        } else {
            throw new \Exception('Either charge_id or payment_intent_id is required');
        }

        // Validate refund amount
        $maxRefundAmount = $charge['amount_captured'] ?? $charge['amount'];
        $existingRefunds = Refund::getByChargeId($charge['id']);
        $totalRefunded = $existingRefunds->where('status', 'succeeded')->sum('amount');
        $availableToRefund = $maxRefundAmount - $totalRefunded;

        if ($data['amount'] > $availableToRefund) {
            throw new \Exception('Refund amount exceeds available amount');
        }

        $refundData = [
            'id' => 're_' . Str::random(24),
            'merchant_id' => $merchantId,
            'charge_id' => $charge['id'],
            'payment_intent_id' => $charge['payment_intent_id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'status' => 'pending',
            'reason' => $data['reason'] ?? 'requested_by_customer',
            'metadata' => $data['metadata'] ?? [],
            'refund_application_fee' => $data['refund_application_fee'] ?? false,
            'reverse_transfer' => $data['reverse_transfer'] ?? false
        ];

        $refund = Refund::create($refundData);

        // Process the refund
        return $this->processRefund($refund['id']);
    }

    /**
     * Process a refund
     */
    public function processRefund(string $refundId): array
    {
        $refund = Refund::findById($refundId);

        if (!$refund) {
            throw new \Exception('Refund not found');
        }

        if ($refund['status'] !== 'pending') {
            throw new \Exception('Refund is not in processable state');
        }

        // Simulate refund processing
        $success = $this->processRefundWithProvider($refund);

        if ($success) {
            $refund = Refund::updateById($refundId, [
                'status' => 'succeeded',
                'processed_at' => now()
            ]);

            // Record refund in ledger system
            $refundModel = Refund::findById($refundId);
            $this->ledgerService->recordRefund($refundModel);

            // Update merchant balance (deduct refund amount)
            $this->balanceService->subtractFromBalance(
                $refund['merchant_id'],
                $refund['currency'],
                $refund['amount'],
                'refund_processed',
                $refundId
            );

            return $refund;
        } else {
            $refund = Refund::updateById($refundId, [
                'status' => 'failed',
                'failure_reason' => 'Refund processing failed'
            ]);

            throw new \Exception('Refund processing failed');
        }
    }

    /**
     * Simulate refund processing with external provider
     */
    private function processRefundWithProvider(array $refund): bool
    {
        // Simulate refund processing logic
        // In real implementation, this would integrate with payment processors

        // Simulate 2% failure rate for demonstration
        return rand(1, 100) > 2;
    }

    /**
     * Get refund statistics for merchant
     */
    public function getRefundStatistics(string $merchantId, array $filters = []): array
    {
        $query = Refund::where('merchant_id', $merchantId);

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        $refunds = $query->get();
        $succeededRefunds = $refunds->where('status', 'succeeded');

        // Get currency for formatting (use first refund's currency or default)
        $currency = $succeededRefunds->first()['currency'] ?? 'USD';
        $totalAmount = $succeededRefunds->sum('amount');
        $avgAmount = $succeededRefunds->avg('amount') ?? 0;

        return [
            'total_refunds' => $refunds->count(),
            'successful_refunds' => $succeededRefunds->count(),
            'failed_refunds' => $refunds->where('status', 'failed')->count(),
            'pending_refunds' => $refunds->where('status', 'pending')->count(),
            'total_refunded_amount' => $totalAmount,
            'total_refunded_formatted' => CurrencyHelper::format($totalAmount, $currency),
            'average_refund_amount' => $avgAmount,
            'average_refund_formatted' => CurrencyHelper::format((int) $avgAmount, $currency),
        ];
    }

    /**
     * Cancel a pending refund
     */
    public function cancelRefund(string $refundId, string $merchantId): array
    {
        $refund = Refund::findByIdAndMerchant($refundId, $merchantId);

        if (!$refund) {
            throw new \Exception('Refund not found');
        }

        if ($refund['status'] !== 'pending') {
            throw new \Exception('Only pending refunds can be cancelled');
        }

        return Refund::updateById($refundId, [
            'status' => 'cancelled',
            'cancelled_at' => now()
        ]);
    }
}
