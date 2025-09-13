<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class Disbursement extends BaseModel
{
    protected $fillable = [
        'disbursement_id',
        'user_id',
        'merchant_bank_account_id',
        'disbursement_batch_id',
        'gross_amount',
        'fee_amount',
        'net_amount',
        'currency',
        'status',
        'gateway_disbursement_id',
        'gateway_transaction_id',
        'gateway_response',
        'failure_reason',
        'processed_at',
        'completed_at',
        'failed_at',
        'metadata',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'gateway_response' => 'array',
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Get the associated user (provider)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the associated bank account
     */
    public function providerBankAccount()
    {
        return $this->belongsTo(MerchantBankAccount::class);
    }

    /**
     * Get the associated batch
     */
    public function disbursementBatch()
    {
        return $this->belongsTo(DisbursementBatch::class);
    }

    /**
     * Get the associated earnings
     */
    // public function providerEarnings()
    // {
    //     return $this->hasMany(ProviderEarning::class);
    // }

    /**
     * Scope for provider
     */
    public function scopeForProvider($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Check if disbursement is completed
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * Check if disbursement failed
     */
    public function isFailed()
    {
        return $this->status === 'failed';
    }

    /**
     * Create new disbursement
     */
    public static function createDisbursement(array $data)
    {
        return static::create([
            'disbursement_id' => 'disb_' . Str::random(16),
            'user_id' => $data['user_id'],
            'provider_bank_account_id' => $data['provider_bank_account_id'],
            'gross_amount' => $data['gross_amount'],
            'fee_amount' => $data['fee_amount'] ?? 0,
            'net_amount' => $data['gross_amount'] - ($data['fee_amount'] ?? 0),
            'currency' => $data['currency'] ?? 'USD',
            'metadata' => $data['metadata'] ?? [],
        ]);
    }

    /**
     * Mark as processing
     */
    public function markAsProcessing($gatewayDisbursementId = null)
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
     * Mark as completed
     */
    public function markAsCompleted($gatewayResponse = null)
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
    public function markAsFailed($reason, $gatewayResponse = null)
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
     * Get provider disbursement summary
     */
    public static function getProviderSummary($userId)
    {
        $disbursements = static::forProvider($userId);

        return [
            'total_disbursed' => $disbursements->where('status', 'completed')->sum('net_amount'),
            'pending_disbursements' => $disbursements->whereIn('status', ['pending', 'processing'])->sum('net_amount'),
            'total_fees' => $disbursements->where('status', 'completed')->sum('fee_amount'),
            'disbursement_count' => $disbursements->count(),
        ];
    }

    /**
     * Get admin disbursement overview
     */
    // public static function getAdminDisbursementOverview($dateFrom = null, $dateTo = null)
    // {
    //     $query = static::query();

    //     if ($dateFrom) {
    //         $query->where('created_at', '>=', $dateFrom);
    //     }
    //     if ($dateTo) {
    //         $query->where('created_at', '<=', $dateTo);
    //     }

    //     return $query->selectRaw('
    //         COUNT(*) as total_disbursements,
    //              COALESCE(SUM(gross_amount), 0) as total_gross_amount,
    //     COALESCE(SUM(fee_amount), 0) as total_fees,
    //     COALESCE(SUM(net_amount), 0) as total_net_amount,
    //         SUM(CASE WHEN status = "pending" THEN net_amount ELSE 0 END) as pending_disbursements,
    //         SUM(CASE WHEN status = "processing" THEN net_amount ELSE 0 END) as processing_disbursements,
    //         SUM(CASE WHEN status = "completed" THEN net_amount ELSE 0 END) as completed_disbursements,
    //         SUM(CASE WHEN status = "failed" THEN net_amount ELSE 0 END) as failed_disbursements
    //     ')->first();
    // }


    public static function getAdminDisbursementOverview($dateFrom = null, $dateTo = null)
    {
        $query = static::query();

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->selectRaw('
        COUNT(*) as total_disbursements,
        COALESCE(SUM(gross_amount), 0) as total_gross_amount,
        COALESCE(SUM(fee_amount), 0) as total_fees,
        COALESCE(SUM(net_amount), 0) as total_net_amount,
        COALESCE(SUM(CASE WHEN status = "pending" THEN net_amount ELSE 0 END), 0) as pending_disbursements,
        COALESCE(SUM(CASE WHEN status = "processing" THEN net_amount ELSE 0 END), 0) as processing_disbursements,
        COALESCE(SUM(CASE WHEN status = "completed" THEN net_amount ELSE 0 END), 0) as completed_disbursements,
        COALESCE(SUM(CASE WHEN status = "failed" THEN net_amount ELSE 0 END), 0) as failed_disbursements,
        COALESCE(SUM(CASE WHEN status = "cancelled" THEN net_amount ELSE 0 END), 0) as cancelled_disbursements
    ')->first();
    }

    /**
     * Get admin disbursements with filters
     */
    public static function getAdminDisbursements(array $filters = [], $perPage = 15)
    {
        $query = static::with(['user:name,email,id', 'providerBankAccount:id,account_number,bank_name', 'disbursementBatch', 'providerEarnings'])
            ->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['batch_id'])) {
            $query->where('disbursement_batch_id', $filters['batch_id']);
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
     * Get failed disbursements for retry
     */
    public static function getFailedDisbursementsForRetry($limit = 50)
    {
        return static::where('status', 'failed')
            ->whereNull('processed_at')
            ->orWhere('processed_at', '<', now()->subHours(24))
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get daily disbursement data
     */
    public static function getDailyDisbursementData($days = 30)
    {
        return static::where('created_at', '>=', now()->subDays($days))
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

    /**
     * Get disbursement statistics by provider
     */
    public static function getDisbursementStatsByProvider($dateFrom = null, $dateTo = null, $limit = 10)
    {
        $query = static::join('users', 'disbursements.user_id', '=', 'users.id')
            ->where('disbursements.status', '!=', 'cancelled');

        if ($dateFrom) {
            $query->where('disbursements.created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('disbursements.created_at', '<=', $dateTo);
        }

        return $query->selectRaw('
            users.id,
            users.name,
            users.email,
            COUNT(*) as total_disbursements,
            SUM(disbursements.net_amount) as total_disbursed,
            SUM(disbursements.fee_amount) as total_fees,
            AVG(disbursements.net_amount) as avg_disbursement_amount
        ')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderBy('total_disbursed', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get pending disbursements grouped by provider
     */
    public static function getPendingDisbursementsByProvider()
    {
        return static::where('disbursements.status', 'pending')
            ->join('users', 'disbursements.user_id', '=', 'users.id')
            ->selectRaw('
                        users.id,
                        users.name,
                        users.email,
                        COUNT(*) as pending_count,
                        SUM(disbursements.net_amount) as total_pending_amount
                    ')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderBy('total_pending_amount', 'desc')
            ->get();
    }


    /**
     * Transform disbursement details into a friendly format
     *
     * @return array
     */
    public function transformDisbursementDetails()
    {
        // Load relationships if not already loaded
        if (!$this->relationLoaded('user')) {
            $this->load('user');
        }
        if (!$this->relationLoaded('providerBankAccount')) {
            $this->load('providerBankAccount');
        }
        if (!$this->relationLoaded('providerEarnings')) {
            $this->load('providerEarnings');
        }

        $earnings = $this->providerEarnings;

        // Calculate totals from earnings
        $totalGross = $earnings->sum('gross_amount');
        $totalCommission = $earnings->sum('commission_amount');
        $totalNet = $earnings->sum('net_amount');
        $earningsCount = $earnings->count();

        // Transform earnings to simpler format
        $transformedEarnings = $earnings->map(function ($earning) {
            return [
                'id' => $earning->id,
                'transaction_id' => $earning->payment_transaction_id,
                'type' => $earning->payable_type,
                'gross_amount' => number_format($earning->gross_amount, 2),
                'commission' => number_format($earning->commission_amount, 2),
                'net_amount' => number_format($earning->net_amount, 2),
                'currency' => $earning->currency,
                'date' => Carbon::parse($earning->created_at)->format('M j, Y'),
                'disbursed_date' => Carbon::parse($earning->disbursed_at)->format('M j, Y'),
            ];
        });

        // Group earnings by type
        $earningsByType = $earnings->groupBy('payable_type')->map(function ($group) {
            return [
                'count' => $group->count(),
                'total_amount' => $group->sum('gross_amount')
            ];
        });

        return [
            'disbursement' => [
                'id' => $this->id,
                'reference' => $this->disbursement_id,
                'status' => ucfirst($this->status),
                'created_at' => Carbon::parse($this->created_at)->format('M j, Y H:i'),
                'gross_amount' => number_format($this->gross_amount, 2),
                'fee_amount' => number_format($this->fee_amount, 2),
                'net_amount' => number_format($this->net_amount, 2),
                'currency' => $this->user->preferences['currency'] ,
                'payout_method' => $this->metadata['payout_method'] ?? 'unknown',
                'earnings_count' => $earningsCount ?? 0,
            ],
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'status' => ucfirst($this->user->status),
                'joined_at' => Carbon::parse($this->user->created_at)->format('M j, Y'),
            ],
            'bank_account' => [
                'bank_name' => $this->providerBankAccount->bank_name,
                'account_holder' => $this->providerBankAccount->account_holder_name,
                'account_type' => ucfirst($this->providerBankAccount->account_type),
                'account_number' => $this->providerBankAccount->masked_account_number,
                'routing_number' => $this->providerBankAccount->masked_routing_number,
                'currency' => $this->providerBankAccount->currency,
                'verification_status' => ucfirst($this->providerBankAccount->verification_status),
                'verified_at' => Carbon::parse($this->providerBankAccount->verified_at)->format('M j, Y'),
            ],
            'earnings_summary' => [
                'total_gross' => number_format($totalGross, 2),
                'total_commission' => number_format($totalCommission, 2),
                'total_net' => number_format($totalNet, 2),
                'by_type' => $earningsByType->toArray(),
            ],
            'earnings' => $transformedEarnings->toArray(),
        ];
    }

    /**
     * Transform disbursement for admin view
     */
    public function transformDisbursements()
    {
        return [
            'id' => $this->id,
            'disbursement_id' => $this->disbursement_id,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'timestamps' => [
                'created_at' => $this->created_at->format('Y-m-d H:i:s'),
                'processed_at' => optional($this->processed_at)->format('Y-m-d H:i:s'),
                'completed_at' => optional($this->completed_at)->format('Y-m-d H:i:s'),
            ],
            'amounts' => [
                'gross' => number_format($this->gross_amount, 2),
                'fees' => number_format($this->fee_amount, 2),
                'net' => number_format($this->net_amount, 2),
                'currency' => $this->user->preferences['currency'] ?? 'KES',
            ],
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            'bank_account' => [
                'bank_name' => $this->providerBankAccount->bank_name,
                'account_number' => $this->providerBankAccount->masked_account_number,
                'routing_number' => $this->providerBankAccount->masked_routing_number,
            ],
            'batch' => $this->disbursementBatch ? [
                'id' => $this->disbursementBatch->id,
                'batch_id' => $this->disbursementBatch->batch_id,
                'name' => $this->disbursementBatch->batch_name,
                'status' => $this->disbursementBatch->status,
            ] : null,
            'earnings' => [
                'count' => $this->providerEarnings->count(),
                'total_amount' => number_format($this->providerEarnings->sum('net_amount'), 2),
            ],
            'metadata' => $this->metadata,
        ];
    }
}
