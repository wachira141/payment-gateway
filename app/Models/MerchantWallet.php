<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class MerchantWallet extends BaseModel
{
    protected $fillable = [
        'wallet_id',
        'merchant_id',
        'currency',
        'type',
        'status',
        'name',
        'available_balance',
        'locked_balance',
        'total_topped_up',
        'total_spent',
        'daily_withdrawal_limit',
        'daily_withdrawal_used',
        'monthly_withdrawal_limit',
        'monthly_withdrawal_used',
        'minimum_balance',
        'auto_sweep_enabled',
        'auto_sweep_config',
        'metadata',
        'last_activity_at',
    ];

    protected $casts = [
        'available_balance' => 'decimal:4',
        'locked_balance' => 'decimal:4',
        'total_topped_up' => 'decimal:4',
        'total_spent' => 'decimal:4',
        'daily_withdrawal_limit' => 'decimal:4',
        'daily_withdrawal_used' => 'decimal:4',
        'monthly_withdrawal_limit' => 'decimal:4',
        'monthly_withdrawal_used' => 'decimal:4',
        'minimum_balance' => 'decimal:4',
        'auto_sweep_enabled' => 'boolean',
        'auto_sweep_config' => 'array',
        'metadata' => 'array',
        'last_activity_at' => 'datetime',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($wallet) {
            if (empty($wallet->wallet_id)) {
                $wallet->wallet_id = 'wal_' . Str::random(24);
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the merchant that owns the wallet
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get all transactions for this wallet
     */
    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }

    /**
     * Get all top-ups for this wallet
     */
    public function topUps()
    {
        return $this->hasMany(WalletTopUp::class, 'wallet_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope to active wallets
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope by wallet type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope by currency
     */
    public function scopeForCurrency($query, string $currency)
    {
        return $query->where('currency', strtoupper($currency));
    }

    // ==================== BALANCE METHODS ====================

    /**
     * Get available balance
     */
    public function getAvailableBalance(): float
    {
        return (float) $this->available_balance;
    }

    /**
     * Get total balance (available + locked)
     */
    public function getTotalBalance(): float
    {
        return (float) $this->available_balance + (float) $this->locked_balance;
    }

    /**
     * Check if can debit amount
     */
    public function canDebit(float $amount): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $availableAfterMinimum = $this->available_balance - $this->minimum_balance;
        return $availableAfterMinimum >= $amount;
    }

    /**
     * Credit the wallet (add funds)
     */
    public function credit(float $amount): void
    {
        $this->increment('available_balance', $amount);
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Debit the wallet (remove funds)
     */
    public function debit(float $amount): void
    {
        if (!$this->canDebit($amount)) {
            throw new \Exception('Insufficient available balance or wallet not active');
        }

        $this->decrement('available_balance', $amount);
        $this->increment('total_spent', $amount);
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Hold funds (move from available to locked)
     */
    public function hold(float $amount): void
    {
        if (!$this->canDebit($amount)) {
            throw new \Exception('Insufficient available balance to hold');
        }

        $this->decrement('available_balance', $amount);
        $this->increment('locked_balance', $amount);
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Release held funds (move from locked to available)
     */
    public function release(float $amount): void
    {
        if ($this->locked_balance < $amount) {
            throw new \Exception('Insufficient locked balance to release');
        }

        $this->decrement('locked_balance', $amount);
        $this->increment('available_balance', $amount);
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Complete a hold (debit from locked balance)
     */
    public function completeHold(float $amount): void
    {
        if ($this->locked_balance < $amount) {
            throw new \Exception('Insufficient locked balance');
        }

        $this->decrement('locked_balance', $amount);
        $this->increment('total_spent', $amount);
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Record top-up
     */
    public function recordTopUp(float $amount): void
    {
        $this->increment('available_balance', $amount);
        $this->increment('total_topped_up', $amount);
        $this->update(['last_activity_at' => now()]);
    }

    // ==================== STATUS METHODS ====================

    /**
     * Check if wallet is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if wallet is frozen
     */
    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }

    /**
     * Freeze the wallet
     */
    public function freeze(string $reason = null): bool
    {
        $metadata = $this->metadata ?? [];
        $metadata['freeze_reason'] = $reason;
        $metadata['frozen_at'] = now()->toISOString();

        return $this->update([
            'status' => 'frozen',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Unfreeze the wallet
     */
    public function unfreeze(): bool
    {
        $metadata = $this->metadata ?? [];
        $metadata['unfrozen_at'] = now()->toISOString();
        unset($metadata['freeze_reason']);

        return $this->update([
            'status' => 'active',
            'metadata' => $metadata,
        ]);
    }

    // ==================== LIMIT METHODS ====================

    /**
     * Check withdrawal limits
     */
    public function checkWithdrawalLimit(float $amount): array
    {
        $result = ['allowed' => true, 'reason' => null];

        // Check daily limit
        if ($this->daily_withdrawal_limit !== null) {
            $remainingDaily = $this->daily_withdrawal_limit - $this->daily_withdrawal_used;
            if ($amount > $remainingDaily) {
                return [
                    'allowed' => false,
                    'reason' => "Daily withdrawal limit exceeded. Remaining: {$remainingDaily} {$this->currency}",
                ];
            }
        }

        // Check monthly limit
        if ($this->monthly_withdrawal_limit !== null) {
            $remainingMonthly = $this->monthly_withdrawal_limit - $this->monthly_withdrawal_used;
            if ($amount > $remainingMonthly) {
                return [
                    'allowed' => false,
                    'reason' => "Monthly withdrawal limit exceeded. Remaining: {$remainingMonthly} {$this->currency}",
                ];
            }
        }

        return $result;
    }

    /**
     * Update withdrawal usage
     */
    public function updateWithdrawalUsage(float $amount): void
    {
        $this->increment('daily_withdrawal_used', $amount);
        $this->increment('monthly_withdrawal_used', $amount);
    }

    /**
     * Reset daily limits
     */
    public function resetDailyLimits(): void
    {
        $this->update(['daily_withdrawal_used' => 0]);
    }

    /**
     * Reset monthly limits
     */
    public function resetMonthlyLimits(): void
    {
        $this->update(['monthly_withdrawal_used' => 0]);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Find wallet by merchant and currency
     */
    public static function findByMerchantAndCurrency(string $merchantId, string $currency, string $type = 'operating'): ?self
    {
        return self::where('merchant_id', $merchantId)
            ->where('currency', strtoupper($currency))
            ->where('type', $type)
            ->first();
    }

    /**
     * Get or create wallet
     */
    public static function getOrCreate(string $merchantId, string $currency, string $type = 'operating', array $options = []): self
    {
        $wallet = self::findByMerchantAndCurrency($merchantId, $currency, $type);

        if (!$wallet) {
            $wallet = self::create(array_merge([
                'merchant_id' => $merchantId,
                'currency' => strtoupper($currency),
                'type' => $type,
                'status' => 'active',
                'available_balance' => 0,
                'locked_balance' => 0,
                'total_topped_up' => 0,
                'total_spent' => 0,
            ], $options));
        }

        return $wallet;
    }

    /**
     * Get all wallets for a merchant
     */
    public static function getForMerchant(string $merchantId): Collection
    {
        return self::where('merchant_id', $merchantId)->get();
    }

    /**
     * Find by wallet_id (public ID)
     */
    public static function findByWalletId(string $walletId): ?self
    {
        return self::where('wallet_id', $walletId)->first();
    }
}
