<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class LedgerEntry extends BaseModel
{
    protected $fillable = [
        'entry_id',
        'merchant_id',
        'transaction_id',
        'related_type',
        'related_id',
        'account_type',
        'account_name',
        'entry_type',
        'amount',
        'currency',
        'description',
        'metadata',
        'posted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'metadata' => 'array',
        'posted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($entry) {
            if (empty($entry->entry_id)) {
                $entry->entry_id = 'le_' . Str::random(16);
            }
            if (empty($entry->transaction_id)) {
                $entry->transaction_id = 'txn_' . Str::random(16);
            }
            if (empty($entry->posted_at)) {
                $entry->posted_at = now();
            }
        });
    }

    /**
     * Get the merchant
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the related model
     */
    public function related()
    {
        return $this->morphTo();
    }

    /**
     * Create double-entry transaction
     */
    public static function createTransaction($merchantId, $relatedModel, $entries, $transactionId = null)
    {
        $transactionId = $transactionId ?? 'txn_' . Str::random(16);
        $createdEntries = [];

        DB::transaction(function () use ($merchantId, $relatedModel, $entries, $transactionId, &$createdEntries) {
            $totalDebits = 0;
            $totalCredits = 0;

            foreach ($entries as $entryData) {
                $entry = static::create([
                    'merchant_id' => $merchantId,
                    'transaction_id' => $transactionId,
                    'related_type' => get_class($relatedModel),
                    'related_id' => $relatedModel->id,
                    ...$entryData
                ]);

                $createdEntries[] = $entry;

                if ($entry->entry_type === 'debit') {
                    $totalDebits += $entry->amount;
                } else {
                    $totalCredits += $entry->amount;
                }
            }

            // Ensure double-entry bookkeeping
            if ($totalDebits != $totalCredits) {
                $totals = [
                    'debits' => $totalDebits,
                    'credits' => $totalCredits
                ];
                throw new \Exception('Debits must equal credits in double-entry transaction' . json_encode($totals));
            }
        });
        return $createdEntries;
    }

    /**
     * Get entries by transaction ID
     */
    public static function getByTransactionId($transactionId)
    {
        return static::where('transaction_id', $transactionId)->get();
    }

    /**
     * Get balance for account
     */
    public static function getAccountBalance($merchantId, $accountType, $accountName, $currency = null)
    {
        $query = static::where('merchant_id', $merchantId)
            ->where('account_type', $accountType)
            ->where('account_name', $accountName);

        if ($currency) {
            $query->where('currency', $currency);
        }

        $debits = $query->clone()->where('entry_type', 'debit')->sum('amount');
        $credits = $query->clone()->where('entry_type', 'credit')->sum('amount');

        // For asset and expense accounts, balance = debits - credits
        // For liability, equity, and revenue accounts, balance = credits - debits
        if (in_array($accountType, ['assets', 'fees'])) {
            return $debits - $credits;
        } else {
            return $credits - $debits;
        }
    }
}
