<?php

namespace App\Services;

use App\Models\PlatformEarning;
use App\Models\Charge;
use App\Models\Disbursement;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Platform Earning Service
 * 
 * Manages platform earnings from payments and disbursements with full profitability tracking.
 * 
 * ================================================================================
 * PROFITABILITY CONCEPTS
 * ================================================================================
 * 
 * EXPLICIT PROFIT (Application Fee):
 *   - Clearly labeled platform commission
 *   - 100% profit, visible to merchants
 * 
 * IMPLICIT PROFIT (Processing Margin):
 *   - Hidden markup in processing fees
 *   - Processing fee charged - actual gateway cost
 * 
 * TOTAL PLATFORM REVENUE:
 *   - True profit = explicit profit + implicit profit
 *   - application_fee + processing_margin
 * 
 * ================================================================================
 */
class PlatformEarningService extends BaseService
{
    // ==================== RECORDING EARNINGS ====================

    /**
     * Record platform earning from a payment
     */
    public function recordPaymentEarning(
        Charge $charge,
        array $feeCalculation,
        int $gatewayCost = 0
    ): PlatformEarning {
        try {
            $earning = PlatformEarning::createPaymentEarning($charge, $feeCalculation, $gatewayCost);
            
            Log::info('Payment platform earning recorded', [
                'earning_id' => $earning->id,
                'charge_id' => $charge->id,
                'gross_amount' => $earning->gross_amount,
                'processing_margin' => $earning->processing_margin,
                'total_platform_revenue' => $earning->total_platform_revenue,
                'currency' => $earning->currency,
            ]);

            return $earning;
        } catch (\Exception $e) {
            Log::error('Failed to record payment platform earning', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Record platform earning from a disbursement
     */
    public function recordDisbursementEarning(
        Disbursement $disbursement,
        array $feeCalculation,
        int $gatewayCost = 0
    ): PlatformEarning {
        try {
            $earning = PlatformEarning::createDisbursementEarning($disbursement, $feeCalculation, $gatewayCost);
            
            // Update disbursement with platform earning reference
            $disbursement->update(['platform_earning_id' => $earning->id]);

            Log::info('Disbursement platform earning recorded', [
                'earning_id' => $earning->id,
                'disbursement_id' => $disbursement->id,
                'gross_amount' => $earning->gross_amount,
                'processing_margin' => $earning->processing_margin,
                'total_platform_revenue' => $earning->total_platform_revenue,
                'currency' => $earning->currency,
            ]);

            return $earning;
        } catch (\Exception $e) {
            Log::error('Failed to record disbursement platform earning', [
                'disbursement_id' => $disbursement->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ==================== REVENUE ANALYTICS ====================

    /**
     * Get platform revenue summary
     */
    public function getRevenueSummary(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $currency = null
    ): array {
        return PlatformEarning::getRevenueSummary($startDate, $endDate, $currency);
    }

    /**
     * Get revenue breakdown by source type
     */
    public function getRevenueBySource(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $currency = null
    ): array {
        $summary = $this->getRevenueSummary($startDate, $endDate, $currency);
        
        return [
            'by_source' => $summary['by_source_type'],
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'currency' => $currency,
            'totals' => [
                'gross' => $summary['total_gross'],
                'gateway_cost' => $summary['total_gateway_cost'],
                'net' => $summary['total_net'],
                'processing_margin' => $summary['total_processing_margin'] ?? 0,
                'total_platform_revenue' => $summary['total_platform_revenue'] ?? $summary['total_net'],
            ],
        ];
    }

    /**
     * Get gateway profitability analysis
     */
    public function getGatewayProfitability(
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        return PlatformEarning::getGatewayProfitability($startDate, $endDate);
    }

    /**
     * Get TRUE profitability report with detailed breakdown
     * 
     * This report distinguishes between:
     * - Explicit commission (application fees)
     * - Implicit profit (processing margins)
     * - Total platform revenue (true profit)
     */
    public function getTrueProfitabilityReport(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $currency = null
    ): array {
        $query = PlatformEarning::query();

        if ($startDate && $endDate) {
            $query->earnedBetween($startDate, $endDate);
        }

        if ($currency) {
            $query->byCurrency($currency);
        }

        $earnings = $query->get();

        // Calculate totals
        $totalExplicitCommission = $earnings->sum('gross_amount');
        $totalProcessingFeeCharged = $earnings->sum('processing_fee_charged');
        $totalActualGatewayCost = $earnings->sum('gateway_cost');
        $totalProcessingMargin = $earnings->sum('processing_margin');
        $totalPlatformRevenue = $earnings->sum('total_platform_revenue') ?: ($totalExplicitCommission + $totalProcessingMargin);

        // Calculate percentages
        $totalFeesCharged = $totalExplicitCommission + $totalProcessingFeeCharged;
        $profitMarginPercentage = $totalFeesCharged > 0 
            ? round(($totalPlatformRevenue / $totalFeesCharged) * 100, 2) 
            : 0;

        // Breakdown by profit source
        $profitSources = [
            'explicit_commission' => [
                'label' => 'Application Fees (Explicit)',
                'description' => 'Platform fees clearly charged to merchants',
                'amount' => $totalExplicitCommission,
                'percentage' => $totalPlatformRevenue > 0 
                    ? round(($totalExplicitCommission / $totalPlatformRevenue) * 100, 2) 
                    : 0,
            ],
            'implicit_profit' => [
                'label' => 'Processing Margins (Implicit)',
                'description' => 'Markup on gateway processing fees',
                'amount' => $totalProcessingMargin,
                'percentage' => $totalPlatformRevenue > 0 
                    ? round(($totalProcessingMargin / $totalPlatformRevenue) * 100, 2) 
                    : 0,
            ],
        ];

        // Breakdown by gateway
        $byGateway = $earnings->groupBy('gateway_code')->map(function ($gatewayEarnings, $gatewayCode) {
            $explicit = $gatewayEarnings->sum('gross_amount');
            $implicit = $gatewayEarnings->sum('processing_margin');
            $total = $gatewayEarnings->sum('total_platform_revenue') ?: ($explicit + $implicit);
            $processingCharged = $gatewayEarnings->sum('processing_fee_charged');
            $gatewayCost = $gatewayEarnings->sum('gateway_cost');

            return [
                'gateway_code' => $gatewayCode,
                'explicit_commission' => $explicit,
                'processing_margin' => $implicit,
                'total_platform_revenue' => $total,
                'processing_fee_charged' => $processingCharged,
                'actual_gateway_cost' => $gatewayCost,
                'margin_rate' => $processingCharged > 0 
                    ? round(($implicit / $processingCharged) * 100, 2) 
                    : 0,
                'transaction_count' => $gatewayEarnings->count(),
            ];
        })->values()->toArray();

        // Breakdown by transaction type
        $byTransactionType = $earnings->groupBy('source_type')->map(function ($typeEarnings, $sourceType) {
            $explicit = $typeEarnings->sum('gross_amount');
            $implicit = $typeEarnings->sum('processing_margin');
            $total = $typeEarnings->sum('total_platform_revenue') ?: ($explicit + $implicit);

            return [
                'source_type' => $sourceType,
                'label' => $sourceType === 'payment' ? 'Collections (C2B)' : 'Disbursements (B2C)',
                'explicit_commission' => $explicit,
                'processing_margin' => $implicit,
                'total_platform_revenue' => $total,
                'transaction_count' => $typeEarnings->count(),
            ];
        })->values()->toArray();

        return [
            // === PERIOD INFO ===
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'currency' => $currency,

            // === TRUE PROFITABILITY SUMMARY ===
            'summary' => [
                'total_explicit_commission' => $totalExplicitCommission,
                'total_processing_margin' => $totalProcessingMargin,
                'total_platform_revenue' => $totalPlatformRevenue,
                'total_fees_charged' => $totalFeesCharged,
                'total_gateway_cost' => $totalActualGatewayCost,
                'profit_margin_percentage' => $profitMarginPercentage,
                'transaction_count' => $earnings->count(),
            ],

            // === PROFIT SOURCES ===
            'profit_sources' => $profitSources,

            // === BREAKDOWNS ===
            'by_gateway' => $byGateway,
            'by_transaction_type' => $byTransactionType,

            // === INSIGHTS ===
            'insights' => $this->generateProfitabilityInsights($earnings, $profitSources, $byGateway),
        ];
    }

    /**
     * Generate profitability insights based on data
     */
    private function generateProfitabilityInsights(
        $earnings, 
        array $profitSources, 
        array $byGateway
    ): array {
        $insights = [];

        // Insight: Profit composition
        if ($profitSources['implicit_profit']['percentage'] > 30) {
            $insights[] = [
                'type' => 'info',
                'title' => 'Significant Processing Margins',
                'message' => sprintf(
                    '%.1f%% of your revenue comes from processing fee margins. Consider if this is sustainable.',
                    $profitSources['implicit_profit']['percentage']
                ),
            ];
        }

        // Insight: Most profitable gateway
        if (!empty($byGateway)) {
            $mostProfitable = collect($byGateway)->sortByDesc('total_platform_revenue')->first();
            $insights[] = [
                'type' => 'success',
                'title' => 'Top Revenue Gateway',
                'message' => sprintf(
                    '%s generates the most platform revenue with %d transactions.',
                    strtoupper($mostProfitable['gateway_code']),
                    $mostProfitable['transaction_count']
                ),
            ];

            // Gateway with highest margin rate
            $highestMargin = collect($byGateway)->sortByDesc('margin_rate')->first();
            if ($highestMargin['margin_rate'] > 0) {
                $insights[] = [
                    'type' => 'info',
                    'title' => 'Highest Processing Margin',
                    'message' => sprintf(
                        '%s has a %.1f%% margin on processing fees.',
                        strtoupper($highestMargin['gateway_code']),
                        $highestMargin['margin_rate']
                    ),
                ];
            }
        }

        return $insights;
    }

    /**
     * Get earnings by merchant
     */
    public function getEarningsByMerchant(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $currency = null
    ): array {
        $query = PlatformEarning::query();

        if ($startDate && $endDate) {
            $query->earnedBetween($startDate, $endDate);
        }

        if ($currency) {
            $query->byCurrency($currency);
        }

        $earnings = $query->get()->groupBy('merchant_id');

        $byMerchant = [];
        foreach ($earnings as $merchantId => $merchantEarnings) {
            $byMerchant[$merchantId] = [
                'merchant_id' => $merchantId,
                'total_gross' => $merchantEarnings->sum('gross_amount'),
                'total_gateway_cost' => $merchantEarnings->sum('gateway_cost'),
                'total_net' => $merchantEarnings->sum('net_amount'),
                'total_processing_margin' => $merchantEarnings->sum('processing_margin'),
                'total_platform_revenue' => $merchantEarnings->sum('total_platform_revenue') 
                    ?: $merchantEarnings->sum('net_amount') + $merchantEarnings->sum('processing_margin'),
                'transaction_count' => $merchantEarnings->count(),
                'by_source_type' => $merchantEarnings->groupBy('source_type')->map(function ($sourceEarnings) {
                    return [
                        'gross' => $sourceEarnings->sum('gross_amount'),
                        'gateway_cost' => $sourceEarnings->sum('gateway_cost'),
                        'net' => $sourceEarnings->sum('net_amount'),
                        'processing_margin' => $sourceEarnings->sum('processing_margin'),
                        'total_platform_revenue' => $sourceEarnings->sum('total_platform_revenue'),
                        'count' => $sourceEarnings->count(),
                    ];
                })->toArray(),
            ];
        }

        return $byMerchant;
    }

    /**
     * Get daily revenue trend with profitability
     */
    public function getDailyRevenueTrend(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $currency = null,
        int $days = 30
    ): array {
        if (!$startDate) {
            $startDate = now()->subDays($days)->startOfDay()->toDateTimeString();
        }
        if (!$endDate) {
            $endDate = now()->endOfDay()->toDateTimeString();
        }

        $query = PlatformEarning::query()
            ->earnedBetween($startDate, $endDate)
            ->selectRaw('DATE(earned_at) as date')
            ->selectRaw('SUM(gross_amount) as gross')
            ->selectRaw('SUM(gateway_cost) as gateway_cost')
            ->selectRaw('SUM(net_amount) as net')
            ->selectRaw('SUM(COALESCE(processing_margin, 0)) as processing_margin')
            ->selectRaw('SUM(COALESCE(total_platform_revenue, net_amount)) as total_platform_revenue')
            ->selectRaw('COUNT(*) as count')
            ->groupByRaw('DATE(earned_at)')
            ->orderBy('date');

        if ($currency) {
            $query->byCurrency($currency);
        }

        return $query->get()->toArray();
    }

    // ==================== SETTLEMENT MANAGEMENT ====================

    /**
     * Settle pending earnings
     */
    public function settleEarnings(array $earningIds): array
    {
        $settled = [];
        $failed = [];

        DB::transaction(function () use ($earningIds, &$settled, &$failed) {
            foreach ($earningIds as $earningId) {
                try {
                    $earning = PlatformEarning::find($earningId);
                    
                    if (!$earning) {
                        $failed[] = ['id' => $earningId, 'reason' => 'Not found'];
                        continue;
                    }

                    if (!$earning->isPending()) {
                        $failed[] = ['id' => $earningId, 'reason' => 'Not in pending status'];
                        continue;
                    }

                    $earning->markAsSettled();
                    $settled[] = $earning->id;
                } catch (\Exception $e) {
                    $failed[] = ['id' => $earningId, 'reason' => $e->getMessage()];
                }
            }
        });

        Log::info('Earnings settlement completed', [
            'settled_count' => count($settled),
            'failed_count' => count($failed),
        ]);

        return [
            'settled' => $settled,
            'failed' => $failed,
            'settled_count' => count($settled),
            'failed_count' => count($failed),
        ];
    }

    /**
     * Settle all pending earnings for a date range
     */
    public function settleAllPendingEarnings(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $currency = null
    ): array {
        $query = PlatformEarning::pending();

        if ($startDate && $endDate) {
            $query->earnedBetween($startDate, $endDate);
        }

        if ($currency) {
            $query->byCurrency($currency);
        }

        $earningIds = $query->pluck('id')->toArray();

        return $this->settleEarnings($earningIds);
    }

    // ==================== REFUND HANDLING ====================

    /**
     * Handle refund for an earning
     */
    public function handleRefund(
        string $sourceType,
        string $sourceId,
        ?float $refundAmount = null
    ): ?PlatformEarning {
        $earning = PlatformEarning::getForSource($sourceType, $sourceId);

        if (!$earning) {
            Log::warning('Platform earning not found for refund', [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);
            return null;
        }

        if ($refundAmount === null || $refundAmount >= $earning->gross_amount) {
            // Full refund
            $earning->markAsRefunded();
        } else {
            // Partial refund - adjust the amounts proportionally
            $refundRatio = $refundAmount / $earning->gross_amount;
            $netRefund = $earning->net_amount * $refundRatio;
            $processingMarginRefund = ($earning->processing_margin ?? 0) * $refundRatio;
            $platformRevenueRefund = ($earning->total_platform_revenue ?? $earning->net_amount) * $refundRatio;

            $earning->update([
                'gross_amount' => $earning->gross_amount - $refundAmount,
                'net_amount' => $earning->net_amount - $netRefund,
                'processing_margin' => ($earning->processing_margin ?? 0) - $processingMarginRefund,
                'total_platform_revenue' => ($earning->total_platform_revenue ?? $earning->net_amount) - $platformRevenueRefund,
                'metadata' => array_merge($earning->metadata ?? [], [
                    'refund_history' => array_merge($earning->metadata['refund_history'] ?? [], [
                        [
                            'amount' => $refundAmount,
                            'net_impact' => $netRefund,
                            'processing_margin_impact' => $processingMarginRefund,
                            'platform_revenue_impact' => $platformRevenueRefund,
                            'refunded_at' => now()->toISOString(),
                        ]
                    ])
                ]),
            ]);
            $earning->markAsPartiallyRefunded();
        }

        Log::info('Platform earning refund processed', [
            'earning_id' => $earning->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'refund_amount' => $refundAmount,
            'new_status' => $earning->status,
        ]);

        return $earning;
    }

    // ==================== RETRIEVAL METHODS ====================

    /**
     * Get paginated earnings list
     */
    public function getEarnings(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PlatformEarning::query()->orderByDesc('earned_at');

        if (!empty($filters['merchant_id'])) {
            $query->byMerchant($filters['merchant_id']);
        }

        if (!empty($filters['source_type'])) {
            $query->bySourceType($filters['source_type']);
        }

        if (!empty($filters['currency'])) {
            $query->byCurrency($filters['currency']);
        }

        if (!empty($filters['gateway_code'])) {
            $query->byGateway($filters['gateway_code']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['fee_type'])) {
            $query->byFeeType($filters['fee_type']);
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->earnedBetween($filters['start_date'], $filters['end_date']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get earning by ID
     */
    public function getEarningById(string $earningId): ?PlatformEarning
    {
        return PlatformEarning::find($earningId);
    }

    /**
     * Get earnings for a specific source
     */
    public function getEarningsForSource(string $sourceType, string $sourceId): ?PlatformEarning
    {
        return PlatformEarning::getForSource($sourceType, $sourceId);
    }
}
