<?php

namespace App\Models;

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
     * Find payout by ID
     */
    public static function findById(string $payoutId): ?array
    {
        $payout = static::where('payout_id', $payoutId)->first();
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
        $updated = static::where('payout_id', $payoutId)->update($data);
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
        $query = static::where('merchant_id', $merchantId);

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

        return $query->get()->toArray();
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

        return [
            'total_payouts' => $payouts->count(),
            'successful_payouts' => $payouts->whereIn('status', ['in_transit', 'completed'])->count(),
            'failed_payouts' => $payouts->where('status', 'failed')->count(),
            'pending_payouts' => $payouts->where('status', 'pending')->count(),
            'total_payout_amount' => $payouts->whereIn('status', ['in_transit', 'completed'])->sum('amount'),
            'total_fees' => $payouts->whereIn('status', ['in_transit', 'completed'])->sum('fee_amount'),
            'average_payout_amount' => $payouts->whereIn('status', ['in_transit', 'completed'])->avg('amount') ?: 0,
        ];
    }
}
