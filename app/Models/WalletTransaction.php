<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class WalletTransaction extends BaseModel
{
    protected $fillable = [
        'transaction_id',
        'wallet_id',
        'merchant_id',
        'type',
        'direction',
        'amount',
        'currency',
        'balance_before',
        'balance_after',
        'status',
        'reference',
        'source_type',
        'source_id',
        'description',
        'metadata',
        'failure_reason',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'balance_before' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'metadata' => 'array',
        'completed_at' => 'datetime',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (empty($transaction->transaction_id)) {
                $transaction->transaction_id = 'wtxn_' . Str::random(24);
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the wallet
     */
    public function wallet()
    {
        return $this->belongsTo(MerchantWallet::class, 'wallet_id');
    }

    /**
     * Get the merchant
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the source model (polymorphic)
     */
    public function source()
    {
        return $this->morphTo();
    }

    // ==================== SCOPES ====================

    /**
     * Scope to completed transactions
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to pending transactions
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope by type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to credits only
     */
    public function scopeCredits($query)
    {
        return $query->where('direction', 'credit');
    }

    /**
     * Scope to debits only
     */
    public function scopeDebits($query)
    {
        return $query->where('direction', 'debit');
    }

    // ==================== METHODS ====================

    /**
     * Mark transaction as completed
     */
    public function markCompleted(): bool
    {
        return $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark transaction as failed
     */
    public function markFailed(string $reason): bool
    {
        return $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Create a reversal transaction
     */
    public function reverse(string $reason): self
    {
        $wallet = $this->wallet;

        // Create reversal transaction
        $reversalData = [
            'wallet_id' => $this->wallet_id,
            'merchant_id' => $this->merchant_id,
            'type' => 'reversal',
            'direction' => $this->direction === 'credit' ? 'debit' : 'credit',
            'amount' => $this->amount,
            'currency' => $this->currency,
            'balance_before' => $wallet->available_balance,
            'status' => 'completed',
            'reference' => $this->transaction_id,
            'description' => "Reversal: {$reason}",
            'metadata' => [
                'original_transaction_id' => $this->transaction_id,
                'reversal_reason' => $reason,
            ],
            'completed_at' => now(),
        ];

        // Reverse the balance change
        if ($this->direction === 'credit') {
            $wallet->decrement('available_balance', $this->amount);
        } else {
            $wallet->increment('available_balance', $this->amount);
        }

        $reversalData['balance_after'] = $wallet->fresh()->available_balance;

        // Mark original as reversed
        $this->update(['status' => 'reversed']);

        return self::create($reversalData);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create a new transaction
     */
    public static function createTransaction(MerchantWallet $wallet, array $data): self
    {
        $data['wallet_id'] = $wallet->id;
        $data['merchant_id'] = $wallet->merchant_id;
        $data['currency'] = $wallet->currency;
        $data['balance_before'] = $wallet->available_balance;

        return self::create($data);
    }

    /**
     * Get transactions for a wallet with filters
     */
    public static function getForWallet(string $walletId, array $filters = []): Collection
    {
        $query = self::where('wallet_id', $walletId);

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Find by transaction_id (public ID)
     */
    public static function findByTransactionId(string $transactionId): ?self
    {
        return self::where('transaction_id', $transactionId)->first();
    }
}
