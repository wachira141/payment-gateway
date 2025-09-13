<?php

namespace App\Models;

class Settlement extends BaseModel
{
    protected $table = 'settlements';

    protected $fillable = [
        'settlement_id',
        'merchant_id',
        'currency',
        'status',
        'gross_amount',
        'refund_amount',
        'fee_amount',
        'net_amount',
        'transaction_count',
        'settlement_date',
        'transactions',
        'metadata',
        'processed_at',
        'bank_reference',
        'failure_reason'
    ];

    protected $casts = [
        'transactions' => 'array',
        'metadata' => 'array',
        'settlement_date' => 'date',
        'processed_at' => 'datetime'
    ];

    /**
     * Find settlement by ID
     */
    public static function findById(string $settlementId): ?array
    {
        $settlement = static::where('settlement_id', $settlementId)->first();
        return $settlement ? $settlement->toArray() : null;
    }

    /**
     * Find settlement by ID and merchant
     */
    public static function findByIdAndMerchant(string $settlementId, string $merchantId): ?array
    {
        $settlement = static::where('settlement_id', $settlementId)
            ->where('merchant_id', $merchantId)
            ->first();
        return $settlement ? $settlement->toArray() : null;
    }

    /**
     * Get settlements for merchant
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
            $query->where('settlement_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('settlement_date', '<=', $filters['end_date']);
        }

        $query->orderBy('settlement_date', 'desc');

        if (!empty($filters['limit'])) {
            $query->limit($filters['limit']);
        }

        if (!empty($filters['offset'])) {
            $query->offset($filters['offset']);
        }

        return $query->get()->toArray();
    }

    /**
     * Get settlement transactions
     */
    public static function getTransactions(string $settlementId, string $merchantId): array
    {
        $settlement = static::findByIdAndMerchant($settlementId, $merchantId);
        return $settlement ? ($settlement['transactions'] ?? []) : [];
    }

    /**
     * Get settlement statistics for merchant
     */
    public static function getStatsForMerchant(string $merchantId, array $filters = []): array
    {
        $query = static::where('merchant_id', $merchantId);

        if (!empty($filters['start_date'])) {
            $query->where('settlement_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('settlement_date', '<=', $filters['end_date']);
        }

        $settlements = $query->get();

        return [
            'total_settlements' => $settlements->count(),
            'completed_settlements' => $settlements->where('status', 'completed')->count(),
            'pending_settlements' => $settlements->where('status', 'pending')->count(),
            'failed_settlements' => $settlements->where('status', 'failed')->count(),
            'total_gross_amount' => $settlements->where('status', 'completed')->sum('gross_amount'),
            'total_net_amount' => $settlements->where('status', 'completed')->sum('net_amount'),
            'total_fees' => $settlements->where('status', 'completed')->sum('fee_amount'),
            'total_transactions' => $settlements->where('status', 'completed')->sum('transaction_count'),
        ];
    }
}
