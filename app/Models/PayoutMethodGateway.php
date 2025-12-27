<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutMethodGateway extends BaseModel
{
    protected $fillable = [
        'payout_method_id',
        'gateway_code',
        'gateway_method_code',
        'country_code',
        'currency',
        'priority',
        'is_active',
        'gateway_config',
        'min_amount',
        'max_amount',
        'processing_time_minutes',
        'metadata',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'gateway_config' => 'array',
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'processing_time_minutes' => 'integer',
        'metadata' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the payout method relationship
     */
    public function payoutMethod(): BelongsTo
    {
        return $this->belongsTo(SupportedPayoutMethod::class, 'payout_method_id');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCountry($query, string $countryCode)
    {
        return $query->where('country_code', strtoupper($countryCode));
    }

    public function scopeForCurrency($query, string $currency)
    {
        return $query->where('currency', strtoupper($currency));
    }

    public function scopeForGateway($query, string $gatewayCode)
    {
        return $query->where('gateway_code', $gatewayCode);
    }

    public function scopeForPayoutMethod($query, string $payoutMethodId)
    {
        return $query->where('payout_method_id', $payoutMethodId);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    public function scopeForAmount($query, int $amount)
    {
        return $query->where(function ($q) use ($amount) {
            $q->whereNull('min_amount')->orWhere('min_amount', '<=', $amount);
        })->where(function ($q) use ($amount) {
            $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount);
        });
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get the best gateway for a payout method, country, and currency
     * Returns the highest priority (lowest number) active gateway
     */
    public static function getBestGateway(
        string $payoutMethodId,
        string $countryCode,
        string $currency,
        ?int $amount = null
    ): ?self {
        $query = static::query()
            ->active()
            ->forPayoutMethod($payoutMethodId)
            ->forCountry($countryCode)
            ->forCurrency($currency)
            ->byPriority();

        if ($amount !== null) {
            $query->forAmount($amount);
        }

        return $query->first();
    }

    /**
     * Get all active gateways for a payout method
     */
    public static function getGatewaysForPayoutMethod(
        string $payoutMethodId,
        ?string $countryCode = null,
        ?string $currency = null
    ): array {
        $query = static::query()
            ->active()
            ->forPayoutMethod($payoutMethodId)
            ->byPriority();

        if ($countryCode) {
            $query->forCountry($countryCode);
        }

        if ($currency) {
            $query->forCurrency($currency);
        }

        return $query->get()->toArray();
    }

    /**
     * Get gateway by code for a specific country/currency combination
     */
    public static function getGatewayByCode(
        string $gatewayCode,
        string $countryCode,
        string $currency
    ): ?self {
        return static::query()
            ->active()
            ->forGateway($gatewayCode)
            ->forCountry($countryCode)
            ->forCurrency($currency)
            ->first();
    }

    /**
     * Get all available gateways for country/currency
     */
    public static function getAvailableGateways(
        string $countryCode,
        string $currency,
        ?int $amount = null
    ): array {
        $query = static::query()
            ->active()
            ->forCountry($countryCode)
            ->forCurrency($currency)
            ->byPriority();

        if ($amount !== null) {
            $query->forAmount($amount);
        }

        return $query->with('payoutMethod')->get()->toArray();
    }

    /**
     * Resolve gateway code from payout method and beneficiary details
     */
    public static function resolveGatewayCode(
        string $payoutMethodId,
        string $countryCode,
        string $currency,
        ?int $amount = null
    ): ?string {
        $gateway = static::getBestGateway($payoutMethodId, $countryCode, $currency, $amount);
        
        return $gateway?->gateway_code;
    }

    /**
     * Check if a specific gateway is available for country/currency
     */
    public static function isGatewayAvailable(
        string $gatewayCode,
        string $countryCode,
        string $currency
    ): bool {
        return static::query()
            ->active()
            ->forGateway($gatewayCode)
            ->forCountry($countryCode)
            ->forCurrency($currency)
            ->exists();
    }

    /**
     * Get processing time for gateway
     */
    public function getProcessingTimeHours(): ?float
    {
        if ($this->processing_time_minutes === null) {
            return null;
        }

        return round($this->processing_time_minutes / 60, 2);
    }

    /**
     * Check if amount is within limits
     */
    public function isAmountWithinLimits(int $amount): bool
    {
        if ($this->min_amount !== null && $amount < $this->min_amount) {
            return false;
        }

        if ($this->max_amount !== null && $amount > $this->max_amount) {
            return false;
        }

        return true;
    }

    /**
     * Transform for API response
     */
    public function transform(): array
    {
        return [
            'id' => $this->id,
            'payout_method_id' => $this->payout_method_id,
            'gateway_code' => $this->gateway_code,
            'gateway_method_code' => $this->gateway_method_code,
            'country_code' => $this->country_code,
            'currency' => $this->currency,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'processing_time_minutes' => $this->processing_time_minutes,
            'processing_time_hours' => $this->getProcessingTimeHours(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
