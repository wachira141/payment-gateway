<?php

namespace App\Models;

class DefaultGatewayPricing extends BaseModel
{
    protected $table = 'default_gateway_pricing';

    protected $fillable = [
        'gateway_code',
        'payment_method_type',
        'currency',
        'transaction_type', // 'collection' or 'disbursement'
        'processing_fee_rate',
        'processing_fee_fixed',
        'actual_gateway_cost_rate', // Real rate charged by gateway provider
        'actual_gateway_cost_fixed', // Real fixed fee charged by gateway provider
        'application_fee_rate',
        'application_fee_fixed',
        'min_fee',
        'max_fee',
        'tier',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'processing_fee_rate' => 'decimal:4',
        'actual_gateway_cost_rate' => 'decimal:4',
        'application_fee_rate' => 'decimal:4',
        'processing_fee_fixed' => 'integer',
        'actual_gateway_cost_fixed' => 'integer',
        'application_fee_fixed' => 'integer',
        'min_fee' => 'integer',
        'max_fee' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Get default pricing for gateway and method
     */
    public static function getDefaultConfig(
        string $gatewayCode,
        string $paymentMethodType,
        string $currency,
        string $tier = 'standard',
        string $transactionType = 'collection'
    ) {
        return static::where([
            'gateway_code' => $gatewayCode,
            'payment_method_type' => $paymentMethodType,
            'currency' => $currency,
            'tier' => $tier,
            'transaction_type' => $transactionType,
            'is_active' => true
        ])->first();
    }

    /**
     * Get default disbursement pricing for gateway and method
     */
    public static function getDefaultDisbursementConfig(
        string $gatewayCode,
        string $paymentMethodType,
        string $currency,
        string $tier = 'standard'
    ) {
        return static::getDefaultConfig(
            $gatewayCode,
            $paymentMethodType,
            $currency,
            $tier,
            'disbursement'
        );
    }

    /**
     * Calculate fees for amount with profitability metrics
     * 
     * @param int $amount Amount in cents
     * @return array Fee calculation with profitability breakdown
     */
    public function calculateFees(int $amount): array
    {
        $processingFee = ($amount * $this->processing_fee_rate) + $this->processing_fee_fixed;
        $applicationFee = ($amount * $this->application_fee_rate) + $this->application_fee_fixed;

        // Apply min/max limits to processing fee
        if ($this->min_fee > 0) {
            $processingFee = max($processingFee, $this->min_fee);
        }
        if ($this->max_fee > 0) {
            $processingFee = min($processingFee, $this->max_fee);
        }

        $totalFees = $processingFee + $applicationFee;

        // Calculate actual gateway cost (what we pay to the gateway)
        $actualGatewayCost = $this->calculateActualGatewayCost($amount);

        // Processing margin = what we charge - what gateway charges us
        $processingMargin = round($processingFee) - $actualGatewayCost;

        // Total platform revenue = explicit application fee + hidden margin in processing
        $totalPlatformRevenue = round($applicationFee) + $processingMargin;

        return [
            'processing_fee' => round($processingFee),
            'application_fee' => round($applicationFee),
            'total_fees' => round($totalFees),
            'gateway_code' => $this->gateway_code,
            'payment_method_type' => $this->payment_method_type,
            'tier' => $this->tier,
            // Profitability metrics
            'actual_gateway_cost' => $actualGatewayCost,
            'processing_margin' => $processingMargin,
            'total_platform_revenue' => $totalPlatformRevenue,
            'breakdown' => [
                'processing_fee_rate' => $this->processing_fee_rate,
                'processing_fee_fixed' => $this->processing_fee_fixed,
                'actual_gateway_cost_rate' => $this->actual_gateway_cost_rate,
                'actual_gateway_cost_fixed' => $this->actual_gateway_cost_fixed,
                'application_fee_rate' => $this->application_fee_rate,
                'application_fee_fixed' => $this->application_fee_fixed,
                'min_fee' => $this->min_fee,
                'max_fee' => $this->max_fee,
            ]
        ];
    }

    /**
     * Calculate actual gateway cost (what we pay to the gateway provider)
     * 
     * @param int $amount Amount in cents
     * @return int Actual gateway cost in cents
     */
    public function calculateActualGatewayCost(int $amount): int
    {
        // Use configured actual gateway costs if available
        if ($this->actual_gateway_cost_rate > 0 || $this->actual_gateway_cost_fixed > 0) {
            $cost = ($amount * $this->actual_gateway_cost_rate) + $this->actual_gateway_cost_fixed;
            return (int) round($cost);
        }
        
        // Fallback: estimate at 70% of processing fee charged
        $processingFee = ($amount * $this->processing_fee_rate) + $this->processing_fee_fixed;
        return (int) round($processingFee * 0.7);
    }

    /**
     * Get all active pricing configs
     */
    public static function getActiveConfigs()
    {
        return static::where('is_active', true)->get();
    }
}
