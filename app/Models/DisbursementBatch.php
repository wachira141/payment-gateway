<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Str;

class DisbursementBatch extends BaseModel
{
    protected $fillable = [
        'batch_id',
        'batch_name',
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
        'total_amount' => 'decimal:2',
        'total_fees' => 'decimal:2',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get disbursements in this batch
     */
    public function disbursements()
    {
        return $this->hasMany(Disbursement::class);
    }

    /**
     * Create new batch
     */
    public static function createBatch($batchName = null)
    {
        return static::create([
            'batch_id' => 'batch_' . Str::random(12),
            'batch_name' => $batchName ?: 'Batch ' . now()->format('Y-m-d H:i'),
        ]);
    }

    /**
     * Add disbursement to batch
     */
    public function addDisbursement(Disbursement $disbursement)
    {
        $disbursement->update(['disbursement_batch_id' => $this->id]);

        $this->increment('total_disbursements');
        $this->increment('total_amount', $disbursement->net_amount);
        $this->increment('total_fees', $disbursement->fee_amount);
    }

    /**
     * Mark batch as processing
     */
    public function markAsProcessing()
    {
        return $this->update([
            'status' => 'processing',
            'processed_at' => now(),
        ]);
    }

    /**
     * Mark batch as completed
     */
    public function markAsCompleted()
    {
        return $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function transformDisbursementBatches()
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'batch_name' => $this->batch_name,
            'total_disbursements' => $this->disbursements->count(),
            'total_amount' => number_format($this->disbursements->sum('net_amount'), 2),
            'total_fees' => number_format($this->disbursements->sum('fee_amount'), 2),
            'currency' =>  'KES', //$this->currency,
            'status' => $this->status,
            'processed_at' => optional($this->processed_at)->format('Y-m-d H:i:s'),
            'completed_at' => optional($this->completed_at)->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            // Simplified disbursements info - just counts and status distribution
            'disbursements_summary' => [
                'count' => $this->disbursements->count(),
                'statuses' => $this->disbursements->groupBy('status')->map->count(),
                'successful_count' => $this->disbursements->where('status', 'completed')->count(),
                'failed_count' => $this->disbursements->where('status', 'failed')->count(),
                'pending_count' => $this->disbursements->where('status', 'pending')->count(),
                'processing_count' => $this->disbursements->where('status', 'processing')->count(),
                'cancelled_count' => $this->disbursements->where('status', 'cancelled')->count(),
            ],

            // Basic user info (just counts)
            'users_summary' => [
                'total_users' => $this->disbursements->groupBy('user_id')->count(),
                'has_bank_accounts' => $this->disbursements->filter(function ($d) {
                    return !empty($d->providerBankAccount);
                })->count(),
            ],
            // Earnings summary
            'earnings_summary' => [
                'total_earnings' => $this->disbursements->sum(function ($d) {
                    return $d->providerEarnings->count();
                }),
            ]
        ];
    }


    // public function transformDisbursementBatch()
    // {
    //     return [
    //         'id' => $this->id,
    //         'batch_id' => $this->batch_id,
    //         'batch_name' => $this->batch_name,
    //         'total_disbursements' => $this->disbursements->count(),
    //         'total_amount' => number_format($this->disbursements->sum('net_amount'), 2),
    //         'total_fees' => number_format($this->disbursements->sum('fee_amount'), 2),
    //         'currency' => $this->currency,
    //         'status' => $this->status,
    //         'processed_at' => optional($this->processed_at)->format('Y-m-d H:i:s'),
    //         'completed_at' => optional($this->completed_at)->format('Y-m-d H:i:s'),
    //         'created_at' => $this->created_at->format('Y-m-d H:i:s'),
    //         'disbursements' => $this->disbursements->map(function ($disbursement) {
    //             return [
    //                 'id' => $disbursement->id,
    //                 'disbursement_id' => $disbursement->disbursement_id,
    //                 'user' => [
    //                     'id' => $disbursement->user->id,
    //                     'name' => $disbursement->user->name,
    //                     'email' => $disbursement->user->email,
    //                     'status' => $disbursement->user->status,
    //                 ],
    //                 'bank_account' => [
    //                     'bank_name' => $disbursement->providerBankAccount->bank_name,
    //                     'account_holder' => $disbursement->providerBankAccount->account_holder_name,
    //                     'account_number' => $disbursement->providerBankAccount->masked_account_number,
    //                     'is_active' => $disbursement->providerBankAccount->is_active,
    //                     'is_primary' => $disbursement->providerBankAccount->is_primary,
    //                     'is_verified' => $disbursement->providerBankAccount->verification_status,
    //                 ],
    //                 'amount' => number_format($disbursement->net_amount, 2),
    //                 'status' => $disbursement->status,
    //                 'earnings_count' => $disbursement->providerEarnings->count()
    //             ];
    //         })
    //     ];
    // }

    public function transformDisbursementBatch()
    {
        return [
            'batch' => [
                'id' => $this->id,
                'batch_id' => $this->batch_id,
                'batch_name' => $this->batch_name,
                'status' => $this->status,
                'currency' => 'KES', //$this->currency,
                'timestamps' => [
                    'created_at' => $this->created_at->format('Y-m-d H:i:s'),
                    'processed_at' => optional($this->processed_at)->format('Y-m-d H:i:s'),
                    'completed_at' => optional($this->completed_at)->format('Y-m-d H:i:s'),
                ],
                'amounts' => [
                    'total_gross' => number_format($this->disbursements->sum('gross_amount'), 2),
                    'total_fees' => number_format($this->disbursements->sum('fee_amount'), 2),
                    'total_net' => number_format($this->disbursements->sum('net_amount'), 2),
                ],
                'counts' => [
                    'total_disbursements' => $this->disbursements->count(),
                    'users' => $this->disbursements->groupBy('user_id')->count(),
                    'earnings' => $this->disbursements->sum(fn($d) => $d->providerEarnings->count()),
                ],
                'status_summary' => $this->disbursements->groupBy('status')->map->count(),
            ],
            'disbursements' => $this->disbursements->map(function ($disbursement) {
                return [
                    'id' => $disbursement->id,
                    'disbursement_id' => $disbursement->disbursement_id,
                    'status' => $disbursement->status,
                    'timestamps' => [
                        'created_at' => $disbursement->created_at->format('Y-m-d H:i:s'),
                        'processed_at' => optional($disbursement->processed_at)->format('Y-m-d H:i:s'),
                        'completed_at' => optional($disbursement->completed_at)->format('Y-m-d H:i:s'),
                    ],
                    'user' => [
                        'id' => $disbursement->user->id,
                        'name' => $disbursement->user->name,
                        'email' => $disbursement->user->email,
                        'status' => $disbursement->user->status,
                        'currency' => $disbursement->user->preferences['currency'],
                    ],
                    'bank_account' => [
                        'bank_name' => $disbursement->providerBankAccount->bank_name,
                        'account_holder' => $disbursement->providerBankAccount->account_holder_name,
                        'account_number' => $disbursement->providerBankAccount->masked_account_number,
                        'account_type' => $disbursement->providerBankAccount->account_type,
                        'verification_status' => $disbursement->providerBankAccount->verification_status,
                        'is_active' => (bool) $disbursement->providerBankAccount->is_active,
                        'is_primary' => (bool) $disbursement->providerBankAccount->is_primary,
                        'currency' => $disbursement->providerBankAccount->currency,
                    ],
                    'amounts' => [
                        'gross' => number_format($disbursement->gross_amount, 2),
                        'fees' => number_format($disbursement->fee_amount, 2),
                        'net' => number_format($disbursement->net_amount, 2),
                        'currency' => 'KES' //$disbursement->currency,
                    ],
                    'metadata' => $disbursement->metadata ?? null,
                ];
            }),
        ];
    }
}
