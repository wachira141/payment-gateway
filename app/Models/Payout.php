<?php

namespace App\Models;

use Illuminate\Support\Str;

class Payout extends BaseModel
{
    protected $table = 'payouts';

    protected $fillable = [
        'payout_id',
        'merchant_id',
        'beneficiary_id',
        'amount',
        'currency',
        'status',
        'description',
        'metadata',
        'fee_amount',
        'estimated_arrival',
        'processed_at',
        'transaction_id',
        'failure_reason',
        'cancelled_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'estimated_arrival' => 'datetime',
        'processed_at' => 'datetime',
        'cancelled_at' => 'datetime'
    ];

    /**
     * Boot function from Laravel.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payout) {
            if (empty($payout->payout_id)) {
                $payout->payout_id = 'pa_' . Str::random(24);
            }
        });
    }

    /**
     * Get the beneficiary that owns the payout
     */
    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_id', 'beneficiary_id');
    }


    /**
     * Find payout by ID
     */
    public static function findById(string $payoutId): ?array
    {
        $payout = static::where('id', $payoutId)->first();
        return $payout ? $payout->toArray() : null;
    }

    /**
     * Find payout by ID and merchant
     */
    public static function findByIdAndMerchant(string $payoutId, string $merchantId): ?array
    {
        $payout = static::where('payout_id', $payoutId)
            ->where('merchant_id', $merchantId)
            ->first();
        return $payout ? $payout->toArray() : null;
    }

    /**
     * Update payout by ID
     */
    public static function updateById(string $payoutId, array $data): ?array
    {
        $updated = static::where('id', $payoutId)->update($data);
        if ($updated) {
            return static::findById($payoutId);
        }
        return null;
    }

    /**
     * Get payouts for merchant
     */
    public static function getForMerchant(string $merchantId, array $filters = []): array
    {
        $query = static::where('merchant_id', $merchantId)
            ->with('beneficiary:beneficiary_id,name,type,currency');

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

        return $query->get()->map(function ($payout) {
            $payoutArray = $payout->toArray();
            if ($payout->beneficiary) {
                $payoutArray['beneficiary_name'] = $payout->beneficiary->name;
                $payoutArray['beneficiary_type'] = $payout->beneficiary->type;
            }
            return $payoutArray;
        })->toArray();
    }

    /**
     * Get payout statistics for merchant
     */
    public static function getStatsForMerchant(string $merchantId, array $filters = []): array
    {
       
        $query = static::where('merchant_id', $merchantId);

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        $payouts = $query->get();

        // Overall summary (counts only, no currency mixing)
        $summary = [
            'total_payouts' => $payouts->count(),
            'successful_payouts' => $payouts->whereIn('status', ['in_transit', 'completed'])->count(),
            'failed_payouts' => $payouts->where('status', 'failed')->count(),
            'pending_payouts' => $payouts->where('status', 'pending')->count(),
            'cancelled_payouts' => $payouts->where('status', 'cancelled')->count(),
        ];

        // Group by currency for currency-specific calculations
        $currencyBreakdown = [];
        $payoutsByCurrency = $payouts->groupBy('currency');

        foreach ($payoutsByCurrency as $currency => $currencyPayouts) {
            $successfulPayouts = $currencyPayouts->whereIn('status', ['in_transit', 'completed']);
            
            $currencyBreakdown[$currency] = [
                'currency' => $currency,
                'total_payouts' => $currencyPayouts->count(),
                'successful_payouts' => $successfulPayouts->count(),
                'failed_payouts' => $currencyPayouts->where('status', 'failed')->count(),
                'pending_payouts' => $currencyPayouts->where('status', 'pending')->count(),
                'cancelled_payouts' => $currencyPayouts->where('status', 'cancelled')->count(),
                'total_payout_amount' => $successfulPayouts->sum('amount'),
                'total_fees' => $successfulPayouts->sum('fee_amount'),
                'average_payout_amount' => $successfulPayouts->avg('amount') ?: 0,
            ];
        }

        return [
            'summary' => $summary,
            'currency_breakdown' => $currencyBreakdown,
        ];
    }
}
