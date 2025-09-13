<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\PaymentIntent;
use App\Models\Merchant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ChargeService extends BaseService
{
    private BalanceService $balanceService;
    private CommissionCalculationService $commissionService;

    public function __construct(
        BalanceService $balanceService,
        CommissionCalculationService $commissionService
    ) {
        $this->balanceService = $balanceService;
        $this->commissionService = $commissionService;
    }

    /**
     * Get charges for a merchant with filters
     */
    public function getChargesForMerchant(string $merchantId, array $filters = []): Collection
    {
        $query = Charge::where('merchant_id', $merchantId);

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
     * Get a charge by ID for a merchant
     */
    public function getChargeById(string $chargeId, string $merchantId): ?array
    {
        $charge = Charge::findByIdAndMerchant($chargeId, $merchantId);
        return $charge ? $charge->toArray() : null;
    }

    /**
     * Create a new charge from payment intent
     */
    public function createChargeFromPaymentIntent(string $paymentIntentId, array $data = []): array
    {
        $paymentIntent = PaymentIntent::findById($paymentIntentId);
        
        if (!$paymentIntent) {
            throw new \Exception('Payment intent not found');
        }

        if ($paymentIntent['status'] !== 'requires_capture') {
            throw new \Exception('Payment intent is not in capturable state');
        }

        $chargeData = [
            'id' => 'ch_' . Str::random(24),
            'merchant_id' => $paymentIntent['merchant_id'],
            'payment_intent_id' => $paymentIntentId,
            'amount' => $data['amount'] ?? $paymentIntent['amount'],
            'currency' => $paymentIntent['currency'],
            'status' => 'pending',
            'payment_method_id' => $paymentIntent['payment_method_id'],
            'description' => $data['description'] ?? $paymentIntent['description'],
            'metadata' => array_merge($paymentIntent['metadata'] ?? [], $data['metadata'] ?? []),
            'application_fee_amount' => $this->commissionService->calculateCommission(
                $paymentIntent['merchant_id'],
                $data['amount'] ?? $paymentIntent['amount']
            )
        ];

        return Charge::create($chargeData);
    }

    /**
     * Capture a charge
     */
    public function captureCharge(string $chargeId, string $merchantId, array $data = []): array
    {
        $charge = Charge::findByIdAndMerchant($chargeId, $merchantId);
        
        if (!$charge) {
            throw new \Exception('Charge not found');
        }

        if ($charge['status'] !== 'pending') {
            throw new \Exception('Charge cannot be captured in current state');
        }

        // Simulate payment processing
        $success = $this->processPaymentWithProvider($charge, $data);
        
        if ($success) {
            $updateData = [
                'status' => 'succeeded',
                'captured_at' => now(),
                'outcome' => [
                    'network_status' => 'approved_by_network',
                    'reason' => null,
                    'risk_level' => 'normal',
                    'type' => 'authorized'
                ]
            ];

            if (!empty($data['amount'])) {
                $updateData['amount_captured'] = $data['amount'];
            } else {
                $updateData['amount_captured'] = $charge['amount'];
            }

            $charge = Charge::updateById($chargeId, $updateData);

            // Update merchant balance
            $this->balanceService->addToBalance(
                $merchantId,
                $charge['currency'],
                $charge['amount_captured'] - $charge['application_fee_amount'],
                'charge_succeeded',
                $chargeId
            );

            return $charge;
        } else {
            $charge = Charge::updateById($chargeId, [
                'status' => 'failed',
                'failure_code' => 'card_declined',
                'failure_message' => 'Your card was declined.',
                'outcome' => [
                    'network_status' => 'declined_by_network',
                    'reason' => 'generic_decline',
                    'risk_level' => 'normal',
                    'type' => 'issuer_declined'
                ]
            ]);

            throw new \Exception('Charge capture failed: ' . $charge['failure_message']);
        }
    }

    /**
     * Simulate payment processing with external provider
     */
    private function processPaymentWithProvider(array $charge, array $options = []): bool
    {
        // Simulate payment processing logic
        // In real implementation, this would integrate with payment processors
        
        // Simulate 5% failure rate for demonstration
        return rand(1, 100) > 5;
    }

    /**
     * Get charge statistics for merchant
     */
    public function getChargeStatistics(string $merchantId, array $filters = []): array
    {
        $query = Charge::where('merchant_id', $merchantId);

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        $charges = $query->get();

        return [
            'total_charges' => $charges->count(),
            'successful_charges' => $charges->where('status', 'succeeded')->count(),
            'failed_charges' => $charges->where('status', 'failed')->count(),
            'pending_charges' => $charges->where('status', 'pending')->count(),
            'total_volume' => $charges->where('status', 'succeeded')->sum('amount_captured'),
            'average_charge_amount' => $charges->where('status', 'succeeded')->avg('amount_captured'),
        ];
    }
}