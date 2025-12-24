<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class Disbursement extends BaseModel
{
    protected $fillable = [
        'disbursement_id',
        'merchant_id',
        'wallet_id',
        'beneficiary_id',
        'disbursement_batch_id',
        'funding_source',
        'payout_method',
        'gross_amount',
        'fee_amount',
        'net_amount',
        'currency',
        'status',
        'gateway_disbursement_id',
        'gateway_transaction_id',
        'gateway_response',
        'failure_reason',
        'description',
        'external_reference',
        'estimated_arrival',
        'processed_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:4',
        'fee_amount' => 'decimal:4',
        'net_amount' => 'decimal:4',
        'gateway_response' => 'array',
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'estimated_arrival' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the merchant that owns the disbursement
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the wallet used for funding
     */
    public function wallet()
    {
        return $this->belongsTo(MerchantWallet::class, 'wallet_id');
    }

    /**
     * Get the beneficiary receiving the disbursement
     */
    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_id');
    }

    /**
     * Get the associated batch
     */
    public function disbursementBatch()
    {
        return $this->belongsTo(DisbursementBatch::class);
    }

    // ==================== SCOPES ====================

    /**
     * Scope for merchant
     */
    public function scopeForMerchant($query, $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    /**
     * Scope by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope by funding source
     */
    public function scopeByFundingSource($query, $source)
    {
        return $query->where('funding_source', $source);
    }

    /**
     * Scope by wallet
     */
    public function scopeByWallet($query, $walletId)
    {
        return $query->where('wallet_id', $walletId);
    }

    // ==================== STATUS CHECKS ====================

    /**
     * Check if disbursement is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if disbursement is processing
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if disbursement is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if disbursement failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if disbursement is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Check if disbursement can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    /**
     * Check if disbursement can be retried
     */
    public function canBeRetried(): bool
    {
        return $this->status === 'failed';
    }

    // ==================== CORE OPERATIONS ====================

    /**
     * Create new disbursement
     */
    public static function createDisbursement(array $data): self
    {
        return static::create([
            'disbursement_id' => 'disb_' . Str::random(24),
            'merchant_id' => $data['merchant_id'],
            'wallet_id' => $data['wallet_id'],
            'beneficiary_id' => $data['beneficiary_id'],
            'disbursement_batch_id' => $data['disbursement_batch_id'] ?? null,
            'funding_source' => $data['funding_source'] ?? 'wallet',
            'payout_method' => $data['payout_method'] ?? 'bank_transfer',
            'gross_amount' => $data['gross_amount'],
            'fee_amount' => $data['fee_amount'] ?? 0,
            'net_amount' => $data['gross_amount'] - ($data['fee_amount'] ?? 0),
            'currency' => $data['currency'],
            'status' => 'pending',
            'description' => $data['description'] ?? null,
            'external_reference' => $data['external_reference'] ?? null,
            'estimated_arrival' => $data['estimated_arrival'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ]);
    }

    /**
     * Mark as processing
     */
    public function markAsProcessing($gatewayDisbursementId = null): bool
    {
        $updateData = [
            'status' => 'processing',
            'processed_at' => now(),
        ];

        if ($gatewayDisbursementId) {
            $updateData['gateway_disbursement_id'] = $gatewayDisbursementId;
        }

        return $this->update($updateData);
    }

    /**
     * Mark as sending (gateway submitted)
     */
    public function markAsSending($gatewayResponse = null): bool
    {
        $updateData = [
            'status' => 'sending',
        ];

        if ($gatewayResponse) {
            $updateData['gateway_response'] = $gatewayResponse;
        }

        return $this->update($updateData);
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted($gatewayResponse = null): bool
    {
        $updateData = [
            'status' => 'completed',
            'completed_at' => now(),
        ];

        if ($gatewayResponse) {
            $updateData['gateway_response'] = $gatewayResponse;
        }

        return $this->update($updateData);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $reason, $gatewayResponse = null): bool
    {
        $updateData = [
            'status' => 'failed',
            'failure_reason' => $reason,
            'failed_at' => now(),
        ];

        if ($gatewayResponse) {
            $updateData['gateway_response'] = $gatewayResponse;
        }

        return $this->update($updateData);
    }

    /**
     * Mark as cancelled
     */
    public function markAsCancelled(string $reason = null): bool
    {
        return $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    // ==================== STATIC QUERY METHODS ====================

    /**
     * Get merchant disbursement summary
     */
    public static function getMerchantSummary(string $merchantId): array
    {
        $disbursements = static::forMerchant($merchantId);

        return [
            'total_disbursed' => (float) $disbursements->clone()->where('status', 'completed')->sum('net_amount'),
            'pending_disbursements' => (float) $disbursements->clone()->whereIn('status', ['pending', 'processing', 'sending'])->sum('net_amount'),
            'total_fees' => (float) $disbursements->clone()->where('status', 'completed')->sum('fee_amount'),
            'disbursement_count' => $disbursements->count(),
            'completed_count' => $disbursements->clone()->where('status', 'completed')->count(),
            'pending_count' => $disbursements->clone()->whereIn('status', ['pending', 'processing', 'sending'])->count(),
            'failed_count' => $disbursements->clone()->where('status', 'failed')->count(),
        ];
    }

    /**
     * Get merchant disbursements with filters
     */
    public static function getMerchantDisbursements(string $merchantId, array $filters = [], $perPage = 15)
    {
        $query = static::with(['wallet', 'beneficiary', 'disbursementBatch'])
            ->forMerchant($merchantId)
            ->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['funding_source'])) {
            $query->where('funding_source', $filters['funding_source']);
        }

        if (isset($filters['wallet_id'])) {
            $query->where('wallet_id', $filters['wallet_id']);
        }

        if (isset($filters['beneficiary_id'])) {
            $query->where('beneficiary_id', $filters['beneficiary_id']);
        }

        if (isset($filters['batch_id'])) {
            $query->where('disbursement_batch_id', $filters['batch_id']);
        }

        if (isset($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (isset($filters['amount_min'])) {
            $query->where('net_amount', '>=', $filters['amount_min']);
        }

        if (isset($filters['amount_max'])) {
            $query->where('net_amount', '<=', $filters['amount_max']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get disbursement statistics for merchant
     */
    public static function getMerchantStatistics(string $merchantId, array $filters = []): array
    {
        $query = static::forMerchant($merchantId);

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        $stats = $query->selectRaw('
            COUNT(*) as total_disbursements,
            COALESCE(SUM(gross_amount), 0) as total_gross_amount,
            COALESCE(SUM(fee_amount), 0) as total_fees,
            COALESCE(SUM(net_amount), 0) as total_net_amount,
            COALESCE(SUM(CASE WHEN status = "pending" THEN net_amount ELSE 0 END), 0) as pending_amount,
            COALESCE(SUM(CASE WHEN status = "processing" THEN net_amount ELSE 0 END), 0) as processing_amount,
            COALESCE(SUM(CASE WHEN status = "sending" THEN net_amount ELSE 0 END), 0) as sending_amount,
            COALESCE(SUM(CASE WHEN status = "completed" THEN net_amount ELSE 0 END), 0) as completed_amount,
            COALESCE(SUM(CASE WHEN status = "failed" THEN net_amount ELSE 0 END), 0) as failed_amount,
            COALESCE(SUM(CASE WHEN status = "cancelled" THEN net_amount ELSE 0 END), 0) as cancelled_amount,
            COUNT(CASE WHEN status = "pending" THEN 1 END) as pending_count,
            COUNT(CASE WHEN status = "processing" THEN 1 END) as processing_count,
            COUNT(CASE WHEN status = "sending" THEN 1 END) as sending_count,
            COUNT(CASE WHEN status = "completed" THEN 1 END) as completed_count,
            COUNT(CASE WHEN status = "failed" THEN 1 END) as failed_count,
            COUNT(CASE WHEN status = "cancelled" THEN 1 END) as cancelled_count
        ')->first();

        // Get by currency breakdown
        $byCurrency = static::forMerchant($merchantId)
            ->selectRaw('
                currency,
                COUNT(*) as count,
                COALESCE(SUM(net_amount), 0) as total_amount,
                COALESCE(SUM(CASE WHEN status = "completed" THEN net_amount ELSE 0 END), 0) as completed_amount
            ')
            ->groupBy('currency')
            ->get()
            ->keyBy('currency')
            ->toArray();

        // Get by payout method breakdown
        $byMethod = static::forMerchant($merchantId)
            ->selectRaw('
                payout_method,
                COUNT(*) as count,
                COALESCE(SUM(net_amount), 0) as total_amount
            ')
            ->groupBy('payout_method')
            ->get()
            ->keyBy('payout_method')
            ->toArray();

        return [
            'overview' => $stats ? $stats->toArray() : [],
            'by_currency' => $byCurrency,
            'by_payout_method' => $byMethod,
        ];
    }

    /**
     * Get failed disbursements for retry
     */
    public static function getFailedDisbursementsForRetry(string $merchantId, $limit = 50)
    {
        return static::forMerchant($merchantId)
            ->where('status', 'failed')
            ->where(function ($query) {
                $query->whereNull('processed_at')
                    ->orWhere('processed_at', '<', now()->subHours(24));
            })
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get daily disbursement data
     */
    public static function getDailyDisbursementData(string $merchantId, $days = 30)
    {
        return static::forMerchant($merchantId)
            ->where('created_at', '>=', now()->subDays($days))
            ->where('status', '!=', 'cancelled')
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as disbursement_count,
                SUM(gross_amount) as total_gross_amount,
                SUM(fee_amount) as total_fees,
                SUM(net_amount) as total_net_amount,
                SUM(CASE WHEN status = "completed" THEN net_amount ELSE 0 END) as completed_amount
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    // ==================== TRANSFORMATION METHODS ====================

    /**
     * Transform disbursement for API response
     */
    public function transform(): array
    {
        return [
            'id' => $this->id,
            'disbursement_id' => $this->disbursement_id,
            'merchant_id' => $this->merchant_id,
            'status' => $this->status,
            'funding_source' => $this->funding_source,
            'payout_method' => $this->payout_method,
            'amounts' => [
                'gross' => (float) $this->gross_amount,
                'fee' => (float) $this->fee_amount,
                'net' => (float) $this->net_amount,
                'currency' => $this->currency,
            ],
            'description' => $this->description,
            'external_reference' => $this->external_reference,
            'failure_reason' => $this->failure_reason,
            'wallet' => $this->wallet ? [
                'id' => $this->wallet->id,
                'wallet_id' => $this->wallet->wallet_id,
                'name' => $this->wallet->name,
                'currency' => $this->wallet->currency,
            ] : null,
            'beneficiary' => $this->beneficiary ? [
                'id' => $this->beneficiary->id,
                'beneficiary_id' => $this->beneficiary->beneficiary_id,
                'name' => $this->beneficiary->name,
                'currency' => $this->beneficiary->currency,
                'country' => $this->beneficiary->country,
            ] : null,
            'batch' => $this->disbursementBatch ? [
                'id' => $this->disbursementBatch->id,
                'batch_id' => $this->disbursementBatch->batch_id,
                'name' => $this->disbursementBatch->batch_name,
            ] : null,
            'timestamps' => [
                'created_at' => $this->created_at?->toISOString(),
                'processed_at' => $this->processed_at?->toISOString(),
                'completed_at' => $this->completed_at?->toISOString(),
                'failed_at' => $this->failed_at?->toISOString(),
                'cancelled_at' => $this->cancelled_at?->toISOString(),
                'estimated_arrival' => $this->estimated_arrival?->toISOString(),
            ],
            'gateway' => [
                'disbursement_id' => $this->gateway_disbursement_id,
                'transaction_id' => $this->gateway_transaction_id,
            ],
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Transform for list view (lighter)
     */
    public function transformForList(): array
    {
        return [
            'id' => $this->id,
            'disbursement_id' => $this->disbursement_id,
            'status' => $this->status,
            'amount' => (float) $this->net_amount,
            'currency' => $this->currency,
            'payout_method' => $this->payout_method,
            'beneficiary_name' => $this->beneficiary?->name,
            'wallet_name' => $this->wallet?->name,
            'created_at' => $this->created_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
