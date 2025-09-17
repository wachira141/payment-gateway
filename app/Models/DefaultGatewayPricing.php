<?php

namespace App\Models;

class DefaultGatewayPricing extends BaseModel
{
    protected $table = 'default_gateway_pricing';

    protected $fillable = [
        'gateway_code',
        'payment_method_type',
        'currency',
        'processing_fee_rate',
        'processing_fee_fixed',
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
        'application_fee_rate' => 'decimal:4',
        'processing_fee_fixed' => 'integer',
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
        string $tier = 'standard'
    ) {
        return static::where([
            'gateway_code' => $gatewayCode,
            'payment_method_type' => $paymentMethodType,
            'currency' => $currency,
            'tier' => $tier,
            'is_active' => true
        ])->first();
    }

    /**
     * Calculate fees for amount
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

        return [
            'processing_fee' => round($processingFee),
            'application_fee' => round($applicationFee),
            'total_fees' => round($totalFees),
            'gateway_code' => $this->gateway_code,
            'payment_method_type' => $this->payment_method_type,
            'tier' => $this->tier,
            'breakdown' => [
                'processing_fee_rate' => $this->processing_fee_rate,
                'processing_fee_fixed' => $this->processing_fee_fixed,
                'application_fee_rate' => $this->application_fee_rate,
                'application_fee_fixed' => $this->application_fee_fixed,
                'min_fee' => $this->min_fee,
                'max_fee' => $this->max_fee,
            ]
        ];
    }

    /**
     * Get all active pricing configs
     */
    public static function getActiveConfigs()
    {
        return static::where('is_active', true)->get();
    }
}