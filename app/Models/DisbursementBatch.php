<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Str;

class DisbursementBatch extends BaseModel
{
    protected $fillable = [
        'batch_id',
        'batch_name',
        'merchant_id',
        'wallet_id',
        'funding_source',
        'total_disbursements',
        'total_amount',
        'total_fees',
        'currency',
        'status',
        'processed_at',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'total_amount' => 'decimal:4',
        'total_fees' => 'decimal:4',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the merchant that owns the batch
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
     * Get disbursements in this batch
     */
    public function disbursements()
    {
        return $this->hasMany(Disbursement::class);
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

    // ==================== CORE OPERATIONS ====================

    /**
     * Create new batch
     */
    public static function createBatch(string $merchantId, string $walletId, string $currency, string $batchName = null): self
    {
        return static::create([
            'batch_id' => 'batch_' . Str::random(16),
            'batch_name' => $batchName ?: 'Batch ' . now()->format('Y-m-d H:i'),
            'merchant_id' => $merchantId,
            'wallet_id' => $walletId,
            'funding_source' => 'wallet',
            'currency' => $currency,
            'status' => 'pending',
            'total_disbursements' => 0,
            'total_amount' => 0,
            'total_fees' => 0,
        ]);
    }

    /**
     * Add disbursement to batch
     */
    public function addDisbursement(Disbursement $disbursement): void
    {
        $disbursement->update(['disbursement_batch_id' => $this->id]);

        $this->increment('total_disbursements');
        $this->increment('total_amount', $disbursement->net_amount);
        $this->increment('total_fees', $disbursement->fee_amount);
    }

    /**
     * Recalculate batch totals
     */
    public function recalculateTotals(): void
    {
        $this->update([
            'total_disbursements' => $this->disbursements()->count(),
            'total_amount' => $this->disbursements()->sum('net_amount'),
            'total_fees' => $this->disbursements()->sum('fee_amount'),
        ]);
    }

    /**
     * Mark batch as processing
     */
    public function markAsProcessing(): bool
    {
        return $this->update([
            'status' => 'processing',
            'processed_at' => now(),
        ]);
    }

    /**
     * Mark batch as completed
     */
    public function markAsCompleted(): bool
    {
        return $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark batch as partially completed
     */
    public function markAsPartiallyCompleted(): bool
    {
        return $this->update([
            'status' => 'partially_completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark batch as failed
     */
    public function markAsFailed(): bool
    {
        return $this->update([
            'status' => 'failed',
        ]);
    }

    /**
     * Check and update status based on disbursements
     */
    public function updateStatusFromDisbursements(): void
    {
        $disbursements = $this->disbursements;
        $total = $disbursements->count();
        
        if ($total === 0) {
            return;
        }

        $completed = $disbursements->where('status', 'completed')->count();
        $failed = $disbursements->where('status', 'failed')->count();
        $pending = $disbursements->whereIn('status', ['pending', 'processing', 'sending'])->count();

        if ($completed === $total) {
            $this->markAsCompleted();
        } elseif ($failed === $total) {
            $this->markAsFailed();
        } elseif ($pending === 0 && $completed > 0 && $failed > 0) {
            $this->markAsPartiallyCompleted();
        }
    }

    // ==================== STATIC QUERY METHODS ====================

    /**
     * Get merchant batches with filters
     */
    public static function getMerchantBatches(string $merchantId, array $filters = [], $perPage = 15)
    {
        $query = static::with(['wallet', 'disbursements'])
            ->forMerchant($merchantId)
            ->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['wallet_id'])) {
            $query->where('wallet_id', $filters['wallet_id']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    // ==================== TRANSFORMATION METHODS ====================

    /**
     * Transform batch for API response
     */
    public function transform(): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'batch_name' => $this->batch_name,
            'merchant_id' => $this->merchant_id,
            'status' => $this->status,
            'funding_source' => $this->funding_source,
            'currency' => $this->currency,
            'totals' => [
                'disbursements' => $this->total_disbursements,
                'amount' => (float) $this->total_amount,
                'fees' => (float) $this->total_fees,
            ],
            'wallet' => $this->wallet ? [
                'id' => $this->wallet->id,
                'wallet_id' => $this->wallet->wallet_id,
                'name' => $this->wallet->name,
            ] : null,
            'timestamps' => [
                'created_at' => $this->created_at?->toISOString(),
                'processed_at' => $this->processed_at?->toISOString(),
                'completed_at' => $this->completed_at?->toISOString(),
            ],
            'disbursements_summary' => $this->getDisbursementsSummary(),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Transform batch with disbursements
     */
    public function transformWithDisbursements(): array
    {
        $data = $this->transform();
        $data['disbursements'] = $this->disbursements->map(fn($d) => $d->transformForList())->toArray();
        return $data;
    }

    /**
     * Get disbursements summary
     */
    protected function getDisbursementsSummary(): array
    {
        if (!$this->relationLoaded('disbursements')) {
            $this->load('disbursements');
        }

        $disbursements = $this->disbursements;

        return [
            'total' => $disbursements->count(),
            'pending' => $disbursements->where('status', 'pending')->count(),
            'processing' => $disbursements->where('status', 'processing')->count(),
            'sending' => $disbursements->where('status', 'sending')->count(),
            'completed' => $disbursements->where('status', 'completed')->count(),
            'failed' => $disbursements->where('status', 'failed')->count(),
            'cancelled' => $disbursements->where('status', 'cancelled')->count(),
        ];
    }
}
