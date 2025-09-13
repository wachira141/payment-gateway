<?php

namespace App\Models;

use App\Models\BaseModel;

class MerchantBalance extends BaseModel
{
    protected $fillable = [
        'merchant_id',
        'currency',
        'available_amount',
        'pending_amount',
        'reserved_amount',
        'total_volume',
        'last_transaction_at',
    ];

    protected $casts = [
        'available_amount' => 'decimal:4',
        'pending_amount' => 'decimal:4',
        'reserved_amount' => 'decimal:4',
        'total_volume' => 'decimal:4',
        'last_transaction_at' => 'datetime',
    ];

    /**
     * Get the merchant
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Credit available balance
     */
    public function creditAvailable($amount)
    {
        $this->increment('available_amount', $amount);
        $this->increment('total_volume', $amount);
        $this->update(['last_transaction_at' => now()]);
    }

    /**
     * Debit available balance
     */
    public function debitAvailable($amount)
    {
        if ($this->available_amount < $amount) {
            throw new \Exception('Insufficient available balance');
        }

        $this->decrement('available_amount', $amount);
        $this->update(['last_transaction_at' => now()]);
    }

    /**
     * Credit pending balance
     */
    public function creditPending($amount)
    {
        $this->increment('pending_amount', $amount);
        $this->update(['last_transaction_at' => now()]);
    }

    /**
     * Move from pending to available
     */
    public function movePendingToAvailable($amount)
    {
        if ($this->pending_amount < $amount) {
            throw new \Exception('Insufficient pending balance');
        }

        $this->decrement('pending_amount', $amount);
        $this->increment('available_amount', $amount);
        $this->update(['last_transaction_at' => now()]);
    }

    /**
     * Reserve amount
     */
    public function reserve($amount)
    {
        if ($this->available_amount < $amount) {
            throw new \Exception('Insufficient available balance to reserve');
        }

        $this->decrement('available_amount', $amount);
        $this->increment('reserved_amount', $amount);
        $this->update(['last_transaction_at' => now()]);
    }

    /**
     * Release reserved amount
     */
    public function releaseReserved($amount)
    {
        if ($this->reserved_amount < $amount) {
            throw new \Exception('Insufficient reserved balance');
        }

        $this->decrement('reserved_amount', $amount);
        $this->increment('available_amount', $amount);
        $this->update(['last_transaction_at' => now()]);
    }

    /**
     * Get total balance
     */
    public function getTotalBalance()
    {
        return $this->available_amount + $this->pending_amount + $this->reserved_amount;
    }

    /**
     * Check if has sufficient available balance
     */
    public function hasSufficientAvailable($amount)
    {
        return $this->available_amount >= $amount;
    }

    /**
     * Find by merchant and currency
     */
    public static function findByMerchantAndCurrency($merchantId, $currency)
    {
        return self::where('merchant_id', $merchantId)
            ->where('currency', $currency)
            ->first();
    }
}