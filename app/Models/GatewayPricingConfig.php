<?php

namespace App\Models;

class GatewayPricingConfig extends BaseModel
{
    protected $fillable = [
        'merchant_id',
        'gateway_code',
        'payment_method_type',
        'currency',
        'processing_fee_rate',
        'processing_fee_fixed',
        'application_fee_rate',
        'application_fee_fixed',
        'min_fee',
        'max_fee',
        'is_active',
        'effective_from',
        'effective_to',
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
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get merchant relationship
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get active pricing config for merchant and gateway
     */
    public static function getActiveConfig(
        string $merchantId, 
        string $gatewayCode, 
        string $paymentMethodType, 
        string $currency
    ) {
        return static::where([
            'merchant_id' => $merchantId,
            'gateway_code' => $gatewayCode,
            'payment_method_type' => $paymentMethodType,
            'currency' => $currency,
            'is_active' => true
        ])
        ->whereDate('effective_from', '<=', now())
        ->where(function($query) {
            $query->whereNull('effective_to')
                  ->orWhereDate('effective_to', '>=', now());
        })
        ->first();
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
}