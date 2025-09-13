<?php

namespace App\Models;

use App\Models\BaseModel;

class CommissionSetting extends BaseModel
{
    protected $fillable = [
        'service_type',
        'commission_rate',
        'min_commission',
        'max_commission',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:4',
        'min_commission' => 'decimal:2',
        'max_commission' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Get active commission setting for a service type
     */
    public static function getForServiceType($serviceType)
    {
        return static::where('service_type', $serviceType)
                    ->where('is_active', true)
                    ->first();
    }

    /**
     * Calculate commission for an amount
     */
    public function calculateCommission($amount)
    {
        $commission = $amount * $this->commission_rate;
        
        if ($this->min_commission && $commission < $this->min_commission) {
            $commission = $this->min_commission;
        }
        
        if ($this->max_commission && $commission > $this->max_commission) {
            $commission = $this->max_commission;
        }
        
        return round($commission, 2);
    }

    /**
     * Get all active commission settings
     */
    public static function getActiveSettings()
    {
        return static::where('is_active', true)->get();
    }
}
