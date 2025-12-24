<?php

namespace App\Models;

use App\Models\BaseModel;

class PaymentGateway extends BaseModel
{
    protected $fillable = [
        'name',
        'code',
        'type',
        'supported_countries',
        'supported_currencies',
        'icon',
        'description',
        'is_active',
        'supports_recurring',
        'supports_refunds',
        'configuration',
        'priority',
    ];

    protected $casts = [
        'supported_countries' => 'array',
        'supported_currencies' => 'array',
        'is_active' => 'boolean',
        'supports_recurring' => 'boolean',
        'supports_refunds' => 'boolean',
        'configuration' => 'array',
        'priority' => 'integer',
    ];

    /**
     * Get payment transactions for this gateway
     */
    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Get payment methods for this gateway
     */
    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /**
     * Get webhooks for this gateway
     */
    public function webhooks()
    {
        return $this->hasMany(PaymentWebhook::class);
    }

    /**
     * Scope to get only active gateways
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get only 
     */
    public function scopeByCode($query, $code)
    {
        return $query->where('code', $code);
    }


    /**
     * Scope to get gateways by country
     */
    public function scopeByCountry($query, $countryCode)
    {
        return $query->whereJsonContains('supported_countries', $countryCode);
    }

    /**
     * Scope to get gateways by currency
     */
    public function scopeByCurrency($query, $currencyCode)
    {
        return $query->whereJsonContains('supported_currencies', $currencyCode);
    }

    /**
     * Check if gateway supports a specific country
     */
    public function supportsCountry($countryCode)
    {
        return in_array($countryCode, $this->supported_countries ?? []);
    }

    /**
     * Check if gateway supports a specific currency
     */
    public function supportsCurrency($currencyCode)
    {
        return in_array($currencyCode, $this->supported_currencies ?? []);
    }

    /**
     * Get available gateways for country and currency
     */
    public static function getAvailableForCountryAndCurrency($countryCode, $currency = null)
    {
        $query = static::active()
            ->byCountry($countryCode)
            ->orderBy('priority', 'desc');

        if ($currency) {
            $query->byCurrency($currency);
        }

        return $query->get();
    }

    /**
     * Get gateway by code
     */
    public static function getByCode($code)
    {
        return static::where('code', $code)->active()->first();
    }

    /**
     * Get gateway by type
     */
    public static function getByType($type)
    {
        return static::where('type', $type)->active()->first();
    }

    /**
     * Check if payment method is supported for country and currency
     */
    public static function isPaymentMethodSupported($countryCode, $gatewayType, $currency = 'USD')
    {
        return static::active()
            ->where('type', $gatewayType)
            ->byCountry($countryCode)
            ->byCurrency($currency)
            ->exists();
    }

    /**
     * Get supported currencies for a country
     */
    public static function getSupportedCurrenciesForCountry($countryCode)
    {
        $gateways = static::getAvailableForCountryAndCurrency($countryCode);

        $currencies = collect();
        foreach ($gateways as $gateway) {
            $currencies = $currencies->merge($gateway->supported_currencies);
        }

        return $currencies->unique()->values()->toArray();
    }

    /**
     * Get gateway performance metrics
     */
    public function getPerformanceMetrics($dateFrom = null, $dateTo = null)
    {
        $query = $this->paymentTransactions();

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        $metrics = $query->selectRaw('
            COUNT(*) as total_transactions,
            SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as successful_transactions,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_transactions,
            SUM(CASE WHEN status = "completed" THEN amount ELSE 0 END) as total_volume,
            AVG(CASE WHEN status = "completed" THEN amount ELSE NULL END) as avg_transaction_amount,
            AVG(CASE WHEN status = "completed" THEN TIMESTAMPDIFF(SECOND, created_at, completed_at) ELSE NULL END) as avg_processing_time
        ')->first();

        $successRate = $metrics->total_transactions > 0
            ? ($metrics->successful_transactions / $metrics->total_transactions) * 100
            : 0;

        return [
            'total_transactions' => $metrics->total_transactions,
            'successful_transactions' => $metrics->successful_transactions,
            'failed_transactions' => $metrics->failed_transactions,
            'success_rate' => round($successRate, 2),
            'total_volume' => $metrics->total_volume,
            'avg_transaction_amount' => $metrics->avg_transaction_amount,
            'avg_processing_time' => $metrics->avg_processing_time,
        ];
    }

    /**
     * Get gateway daily performance
     */
    public function getDailyPerformance($days = 30)
    {
        return $this->paymentTransactions()
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as total_transactions,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as successful_transactions,
                SUM(CASE WHEN status = "completed" THEN amount ELSE 0 END) as total_volume
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    /**
     * Get all gateways performance summary
     */
    public static function getGatewaysPerformanceSummary($dateFrom = null, $dateTo = null)
    {
        $gateways = static::active()->get();

        return $gateways->map(function ($gateway) use ($dateFrom, $dateTo) {
            $metrics = $gateway->getPerformanceMetrics($dateFrom, $dateTo);

            return [
                'gateway' => $gateway->only(['id', 'name', 'code', 'type']),
                'metrics' => $metrics,
            ];
        });
    }

    /**
     * Get gateway revenue breakdown
     */
    public function getRevenueBreakdown($dateFrom = null, $dateTo = null)
    {
        $query = $this->paymentTransactions()->where('status', 'completed');

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->selectRaw('
            type,
            COUNT(*) as transaction_count,
            SUM(amount) as total_revenue,
            AVG(amount) as avg_amount
        ')
            ->groupBy('type')
            ->get();
    }

    /**
     * Get gateway fee analysis
     */
    public function getFeeAnalysis($dateFrom = null, $dateTo = null)
    {
        $query = $this->paymentTransactions()->where('status', 'completed');

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->selectRaw('
            SUM(amount) as gross_revenue,
            SUM(commission_amount) as total_commission,
            SUM(provider_amount) as total_provider_payout,
            COUNT(*) as transaction_count
        ')->first();
    }
}
