<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Platform Earning Model
 * 
 * Tracks platform revenue from payments and disbursements with full profitability breakdown.
 * 
 * ================================================================================
 * FEE FIELDS DOCUMENTATION
 * ================================================================================
 * 
 * gross_amount:
 *   - The total fee collected from the merchant
 *   - Includes: platform_application_fee + gateway_processing_fee (if tracked separately)
 *   - For backward compatibility, this typically equals the application_fee
 * 
 * gateway_cost:
 *   - The actual/estimated amount paid to the payment gateway
 *   - Used to calculate true profitability
 * 
 * net_amount:
 *   - Platform's net profit after gateway costs
 *   - Formula: gross_amount - gateway_cost
 * 
 * processing_fee_charged:
 *   - The gateway processing fee charged to merchant
 *   - May include a margin above actual gateway cost
 * 
 * processing_margin:
 *   - Hidden profit in processing fee
 *   - Formula: processing_fee_charged - actual_gateway_cost
 * 
 * total_platform_revenue:
 *   - True total profit from this transaction
 *   - Formula: application_fee + processing_margin
 * 
 * ================================================================================
 */

class PlatformEarning extends BaseModel
{
    protected $fillable = [
        'source_type',
        'source_id',
        'fee_type',
        'gross_amount',
        'gateway_cost',
        'net_amount',
        'currency',
        'merchant_id',
        'gateway_code',
        'payment_method_type',
        'transaction_id',
        'status',
        'earned_at',
        'settled_at',
        'fee_breakdown',
        'metadata',
        'processing_fee_charged',
        'processing_margin',
        'total_platform_revenue',
    ];

    protected $casts = [
        'gross_amount' => 'integer',
        'gateway_cost' => 'integer',
        'net_amount' => 'integer',
        'processing_fee_charged' => 'integer',
        'processing_margin' => 'integer',
        'total_platform_revenue' => 'integer',
        'earned_at' => 'datetime',
        'settled_at' => 'datetime',
        'fee_breakdown' => 'array',
        'metadata' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the merchant relationship
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the source (polymorphic relationship)
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeSettled($query)
    {
        return $query->where('status', 'settled');
    }

    public function scopeRefunded($query)
    {
        return $query->whereIn('status', ['refunded', 'partially_refunded']);
    }

    public function scopeBySourceType($query, string $sourceType)
    {
        return $query->where('source_type', $sourceType);
    }

    public function scopeByCurrency($query, string $currency)
    {
        return $query->where('currency', strtoupper($currency));
    }

    public function scopeByGateway($query, string $gatewayCode)
    {
        return $query->where('gateway_code', $gatewayCode);
    }

    public function scopeByMerchant($query, string $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeEarnedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('earned_at', [$startDate, $endDate]);
    }

    public function scopeByFeeType($query, string $feeType)
    {
        return $query->where('fee_type', $feeType);
    }

    // ==================== STATUS METHODS ====================

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSettled(): bool
    {
        return $this->status === 'settled';
    }

    public function isRefunded(): bool
    {
        return in_array($this->status, ['refunded', 'partially_refunded']);
    }

    public function markAsSettled(): self
    {
        $this->update([
            'status' => 'settled',
            'settled_at' => now(),
        ]);

        return $this;
    }

    public function markAsRefunded(): self
    {
        $this->update([
            'status' => 'refunded',
        ]);

        return $this;
    }

    public function markAsPartiallyRefunded(): self
    {
        $this->update([
            'status' => 'partially_refunded',
        ]);

        return $this;
    }

    // ==================== STATIC CREATION METHODS ====================

    /**
     * Create earning record for a payment
     * 
     * Enhanced to capture full profitability breakdown:
     * - gross_amount: Platform application fee (explicit commission)
     * - gateway_cost: Estimated/actual cost paid to gateway
     * - processing_fee_charged: Gateway processing fee charged to merchant
     * - processing_margin: Hidden profit in processing fee
     * - total_platform_revenue: True profit (application_fee + processing_margin)
     */
    public static function createPaymentEarning(
        Charge $charge,
        array $feeCalculation,
        int $gatewayCost = 0
    ): self {
        // Application fee is the explicit platform commission
        $applicationFee = $feeCalculation['application_fee'] ?? $feeCalculation['platform_application_fee'] ?? 0;

        // Convert from decimal to cents if needed
        if ($applicationFee > 0 && $applicationFee < 100) {
            $applicationFee = (int) round($applicationFee * 100);
        }

        // Get processing fee charged (for tracking margin)
        $processingFeeCharged = $feeCalculation['processing_fee'] ?? $feeCalculation['gateway_processing_fee'] ?? 0;
        if ($processingFeeCharged > 0 && $processingFeeCharged < 100) {
            $processingFeeCharged = (int) round($processingFeeCharged * 100);
        }

        // Get estimated gateway cost
        $estimatedGatewayCost = $feeCalculation['estimated_gateway_cost'] ?? 0;
        if ($estimatedGatewayCost > 0 && $estimatedGatewayCost < 100) {
            $estimatedGatewayCost = (int) round($estimatedGatewayCost * 100);
        }
        // Use provided gateway cost if available, otherwise use estimated
        $actualGatewayCost = $gatewayCost > 0 ? $gatewayCost : $estimatedGatewayCost;

        // Calculate processing margin (hidden profit in processing fee)
        $processingMargin = max(0, $processingFeeCharged - $actualGatewayCost);

        // Total platform revenue = application fee + processing margin
        $totalPlatformRevenue = $applicationFee + $processingMargin;

        return static::create([
            'source_type' => 'payment',
            'source_id' => $charge->id,
            'fee_type' => 'application_fee',
            'gross_amount' => $applicationFee,
            'gateway_cost' => $actualGatewayCost,
            'net_amount' => $applicationFee - $actualGatewayCost,
            'processing_fee_charged' => $processingFeeCharged,
            'processing_margin' => $processingMargin,
            'total_platform_revenue' => $totalPlatformRevenue,
            'currency' => $charge->currency,
            'merchant_id' => $charge->merchant_id,
            'gateway_code' => $charge->gateway_code,
            'payment_method_type' => $charge->payment_method_type,
            'transaction_id' => $charge->charge_id,
            'status' => 'pending',
            'earned_at' => now(),
            'fee_breakdown' => $feeCalculation,
        ]);
    }

  /**
     * Create earning record for a disbursement
     * 
     * Enhanced to capture full profitability breakdown
     */
    public static function createDisbursementEarning(
        Disbursement $disbursement,
        array $feeCalculation,
        int $gatewayCost = 0
    ): self {
        // Application fee is the explicit platform commission
        $applicationFee = $feeCalculation['application_fee'] ?? $feeCalculation['platform_application_fee'] ?? 0;
        
        // Handle both cents and decimal formats
        if ($applicationFee > 0 && $applicationFee < 100) {
            $applicationFee = (int) round($applicationFee * 100);
        }

        // Get processing fee charged
        $processingFeeCharged = $feeCalculation['processing_fee'] ?? $feeCalculation['gateway_processing_fee'] ?? 0;
        if ($processingFeeCharged > 0 && $processingFeeCharged < 100) {
            $processingFeeCharged = (int) round($processingFeeCharged * 100);
        }

        // Get estimated gateway cost
        $estimatedGatewayCost = $feeCalculation['estimated_gateway_cost'] ?? 0;
        if ($estimatedGatewayCost > 0 && $estimatedGatewayCost < 100) {
            $estimatedGatewayCost = (int) round($estimatedGatewayCost * 100);
        }
        $actualGatewayCost = $gatewayCost > 0 ? $gatewayCost : $estimatedGatewayCost;

        // Calculate processing margin
        $processingMargin = max(0, $processingFeeCharged - $actualGatewayCost);

        // Total platform revenue
        $totalPlatformRevenue = $applicationFee + $processingMargin;

        return static::create([
            'source_type' => 'disbursement',
            'source_id' => $disbursement->id,
            'fee_type' => 'application_fee',
            'gross_amount' => $applicationFee,
            'gateway_cost' => $actualGatewayCost,
            'net_amount' => $applicationFee - $actualGatewayCost,
            'processing_fee_charged' => $processingFeeCharged,
            'processing_margin' => $processingMargin,
            'total_platform_revenue' => $totalPlatformRevenue,
            'currency' => $disbursement->currency,
            'merchant_id' => $disbursement->merchant_id,
            'gateway_code' => $disbursement->gateway_code,
            'payment_method_type' => $disbursement->payout_method_type,
            'transaction_id' => $disbursement->disbursement_id,
            'status' => 'pending',
            'earned_at' => now(),
            'fee_breakdown' => $feeCalculation,
        ]);
    }

    // ==================== QUERY METHODS ====================

    /**
     * Get platform revenue summary with profitability breakdown
     */
    public static function getRevenueSummary(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $currency = null
    ): array {
        $query = static::query();

        if ($startDate && $endDate) {
            $query->earnedBetween($startDate, $endDate);
        }

        if ($currency) {
            $query->byCurrency($currency);
        }

        $earnings = $query->get();

        $summary = [
            // === EXISTING METRICS ===
            'total_gross' => 0,
            'total_gateway_cost' => 0,
            'total_net' => 0,
            'pending_amount' => 0,
            'settled_amount' => 0,
            'refunded_amount' => 0,
            'transaction_count' => $earnings->count(),
            
            // === NEW PROFITABILITY METRICS ===
            'total_processing_fee_charged' => 0,
            'total_processing_margin' => 0,
            'total_platform_revenue' => 0,
            'explicit_commission' => 0,  // Application fees only
            'implicit_profit' => 0,      // Processing margins only
            
            // === GROUPINGS ===
            'by_currency' => [],
            'by_source_type' => [],
            'by_gateway' => [],
        ];

        foreach ($earnings as $earning) {
            $summary['total_gross'] += $earning->gross_amount;
            $summary['total_gateway_cost'] += $earning->gateway_cost;
            $summary['total_net'] += $earning->net_amount;
            $summary['total_processing_fee_charged'] += $earning->processing_fee_charged ?? 0;
            $summary['total_processing_margin'] += $earning->processing_margin ?? 0;
            $summary['total_platform_revenue'] += $earning->total_platform_revenue ?? $earning->net_amount;
            $summary['explicit_commission'] += $earning->gross_amount;
            $summary['implicit_profit'] += $earning->processing_margin ?? 0;

            if ($earning->isPending()) {
                $summary['pending_amount'] += $earning->total_platform_revenue ?? $earning->net_amount;
            } elseif ($earning->isSettled()) {
                $summary['settled_amount'] += $earning->total_platform_revenue ?? $earning->net_amount;
            } elseif ($earning->isRefunded()) {
                $summary['refunded_amount'] += $earning->total_platform_revenue ?? $earning->net_amount;
            }

            // Group by currency
            $curr = $earning->currency;
            if (!isset($summary['by_currency'][$curr])) {
                $summary['by_currency'][$curr] = [
                    'gross' => 0, 
                    'gateway_cost' => 0, 
                    'net' => 0, 
                    'processing_margin' => 0,
                    'total_platform_revenue' => 0,
                    'count' => 0
                ];
            }
            $summary['by_currency'][$curr]['gross'] += $earning->gross_amount;
            $summary['by_currency'][$curr]['gateway_cost'] += $earning->gateway_cost;
            $summary['by_currency'][$curr]['net'] += $earning->net_amount;
            $summary['by_currency'][$curr]['processing_margin'] += $earning->processing_margin ?? 0;
            $summary['by_currency'][$curr]['total_platform_revenue'] += $earning->total_platform_revenue ?? $earning->net_amount;
            $summary['by_currency'][$curr]['count']++;

            // Group by source type
            $sourceType = $earning->source_type;
            if (!isset($summary['by_source_type'][$sourceType])) {
                $summary['by_source_type'][$sourceType] = [
                    'gross' => 0, 
                    'gateway_cost' => 0, 
                    'net' => 0,
                    'processing_margin' => 0,
                    'total_platform_revenue' => 0,
                    'count' => 0
                ];
            }
            $summary['by_source_type'][$sourceType]['gross'] += $earning->gross_amount;
            $summary['by_source_type'][$sourceType]['gateway_cost'] += $earning->gateway_cost;
            $summary['by_source_type'][$sourceType]['net'] += $earning->net_amount;
            $summary['by_source_type'][$sourceType]['processing_margin'] += $earning->processing_margin ?? 0;
            $summary['by_source_type'][$sourceType]['total_platform_revenue'] += $earning->total_platform_revenue ?? $earning->net_amount;
            $summary['by_source_type'][$sourceType]['count']++;

            // Group by gateway
            $gateway = $earning->gateway_code ?? 'unknown';
            if (!isset($summary['by_gateway'][$gateway])) {
                $summary['by_gateway'][$gateway] = [
                    'gross' => 0, 
                    'gateway_cost' => 0, 
                    'net' => 0,
                    'processing_margin' => 0,
                    'total_platform_revenue' => 0,
                    'count' => 0
                ];
            }
            $summary['by_gateway'][$gateway]['gross'] += $earning->gross_amount;
            $summary['by_gateway'][$gateway]['gateway_cost'] += $earning->gateway_cost;
            $summary['by_gateway'][$gateway]['net'] += $earning->net_amount;
            $summary['by_gateway'][$gateway]['processing_margin'] += $earning->processing_margin ?? 0;
            $summary['by_gateway'][$gateway]['total_platform_revenue'] += $earning->total_platform_revenue ?? $earning->net_amount;
            $summary['by_gateway'][$gateway]['count']++;
        }

        return $summary;
    }

    /**
     * Get gateway profitability analysis with detailed breakdown
     */
    public static function getGatewayProfitability(
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $query = static::query()->whereNotNull('gateway_code');

        if ($startDate && $endDate) {
            $query->earnedBetween($startDate, $endDate);
        }

        $earnings = $query->get()->groupBy('gateway_code');

        $analysis = [];
        foreach ($earnings as $gatewayCode => $gatewayEarnings) {
            $totalGross = $gatewayEarnings->sum('gross_amount');
            $totalGatewayCost = $gatewayEarnings->sum('gateway_cost');
            $totalNet = $gatewayEarnings->sum('net_amount');
            $totalProcessingFeeCharged = $gatewayEarnings->sum('processing_fee_charged');
            $totalProcessingMargin = $gatewayEarnings->sum('processing_margin');
            $totalPlatformRevenue = $gatewayEarnings->sum('total_platform_revenue') ?: $totalNet;

            $analysis[$gatewayCode] = [
                'gateway_code' => $gatewayCode,
                
                // === EXISTING METRICS ===
                'total_gross' => $totalGross,
                'total_gateway_cost' => $totalGatewayCost,
                'total_net_profit' => $totalNet,
                
                // === NEW PROFITABILITY METRICS ===
                'total_processing_fee_charged' => $totalProcessingFeeCharged,
                'total_processing_margin' => $totalProcessingMargin,
                'total_platform_revenue' => $totalPlatformRevenue,
                'explicit_commission' => $totalGross,  // Application fees
                'implicit_profit' => $totalProcessingMargin,  // Processing margins
                
                // === PERCENTAGES ===
                'profit_margin' => $totalGross > 0 ? round(($totalNet / $totalGross) * 100, 2) : 0,
                'processing_margin_rate' => $totalProcessingFeeCharged > 0 
                    ? round(($totalProcessingMargin / $totalProcessingFeeCharged) * 100, 2) 
                    : 0,
                'true_profit_margin' => $totalGross + $totalProcessingFeeCharged > 0
                    ? round(($totalPlatformRevenue / ($totalGross + $totalProcessingFeeCharged)) * 100, 2)
                    : 0,
                
                // === TRANSACTION STATS ===
                'transaction_count' => $gatewayEarnings->count(),
                'average_earning' => $gatewayEarnings->count() > 0 ? round($totalNet / $gatewayEarnings->count(), 2) : 0,
                'average_platform_revenue' => $gatewayEarnings->count() > 0 
                    ? round($totalPlatformRevenue / $gatewayEarnings->count(), 2) 
                    : 0,
                
                // === CURRENCY BREAKDOWN ===
                'by_currency' => $gatewayEarnings->groupBy('currency')->map(function ($currencyEarnings) {
                    return [
                        'gross' => $currencyEarnings->sum('gross_amount'),
                        'gateway_cost' => $currencyEarnings->sum('gateway_cost'),
                        'net' => $currencyEarnings->sum('net_amount'),
                        'processing_margin' => $currencyEarnings->sum('processing_margin'),
                        'total_platform_revenue' => $currencyEarnings->sum('total_platform_revenue') ?: $currencyEarnings->sum('net_amount'),
                        'count' => $currencyEarnings->count(),
                    ];
                })->toArray(),
            ];
        }

        return $analysis;
    }

    /**
     * Get earnings for a specific source
     */
    public static function getForSource(string $sourceType, string $sourceId): ?self
    {
        return static::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * Transform for API response with profitability breakdown
     */
    public function transform(): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'fee_type' => $this->fee_type,
            
            // === FEE AMOUNTS ===
            'gross_amount' => $this->gross_amount,
            'gateway_cost' => $this->gateway_cost,
            'net_amount' => $this->net_amount,
            
            // === PROFITABILITY ===
            'processing_fee_charged' => $this->processing_fee_charged,
            'processing_margin' => $this->processing_margin,
            'total_platform_revenue' => $this->total_platform_revenue ?? $this->net_amount,
            
            // === TRANSACTION DETAILS ===
            'currency' => $this->currency,
            'merchant_id' => $this->merchant_id,
            'gateway_code' => $this->gateway_code,
            'payment_method_type' => $this->payment_method_type,
            'transaction_id' => $this->transaction_id,
            'status' => $this->status,
            
            // === TIMESTAMPS ===
            'earned_at' => $this->earned_at?->toISOString(),
            'settled_at' => $this->settled_at?->toISOString(),
            
            // === METADATA ===
            'fee_breakdown' => $this->fee_breakdown,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
