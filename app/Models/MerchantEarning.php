<?php
namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class MerchantEarning extends BaseModel
{
    protected $fillable = [
        'user_id',
        'payment_transaction_id',
        'payable_type',
        'payable_id',
        'gross_amount',
        'commission_rate',
        'commission_amount',
        'net_amount',
        'currency',
        'status',
        'available_at',
        'disbursement_id',
        'disbursed_at',
        'metadata',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'commission_rate' => 'decimal:4',
        'commission_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'available_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get the associated user (provider)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the associated payment transaction
     */
    public function paymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /**
     * Disbursement relationship
     */
    public function disbursement()
    {
        return $this->hasOne(Disbursement::class);
    }

    /**
     * Get the payable model (goal request, meal plan request, etc.)
     */
    // public function payable()
    // {
    //     return $this->morphTo();
    // }

    /**
     * Scope for specific provider
     */
    public function scopeForProvider($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for specific status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Get provider's earnings summary
     * 
     * @param string|null $userId If null, returns summary for all providers
     */
    public static function getProviderSummary($userId = null)
    {
        $query = static::with('user');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        // First get the results
        $results = $query->get();

        // Get the first user's preferences (assuming all results have the same user)
        $currency = 'KES';
        if ($results->isNotEmpty() && $results->first()->user) {
            $currency = $results->first()->user->preferences['currency'] ?? 'KES';
        }

        return [
            'currency' => $currency,
            'total_earnings' => $results->sum('net_amount'),
            'pending_earnings' => $results->where('status', 'pending')->sum('net_amount'),
            'available_earnings' => $results->whereIn('status', ['available'])->sum('net_amount'),
            'disbursed_earnings' => $results->where('status', 'disbursed')->sum('net_amount'),
            'total_commission' => $results->sum('commission_amount'),
            'transaction_count' => $results->count(),
        ];
    }

    /**
     * Create earnings record from payment transaction
     */
    public static function createFromPayment($paymentTransaction, $commissionSetting)
    {
        $commissionAmount = $commissionSetting->calculateCommission($paymentTransaction->amount);
        $netAmount = $paymentTransaction->amount - $commissionAmount;

        // Get the payable record (e.g., Service, GoalRequest, etc.)
        $payable = $paymentTransaction->payable_type::find($paymentTransaction->payable_id);

        // Fetch user_id dynamically (fallback to paymentTransaction->user_id if not found)
        $userId = $payable->getUserId() ?? $paymentTransaction->user_id;

        return static::create([
            'user_id' => $userId,
            'payment_transaction_id' => $paymentTransaction->id,
            'payable_type' => $paymentTransaction->payable_type,
            'payable_id' => $paymentTransaction->payable_id,
            'gross_amount' => $paymentTransaction->amount,
            'commission_rate' => $commissionSetting->commission_rate,
            'commission_amount' => $commissionAmount,
            'net_amount' => $netAmount,
            'currency' => $paymentTransaction->currency,
            'status' => 'pending',
            'available_at' => now()->addDays(7), // 7-day hold period
        ]);
    }

    /**
     * Get admin earnings overview
     */
    public static function getAdminEarningsOverview($dateFrom = null, $dateTo = null)
    {
        $query = static::query();

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->selectRaw('
            COUNT(*) as total_earnings,
            SUM(gross_amount) as total_gross_amount,
            SUM(commission_amount) as total_commission_amount,
            SUM(net_amount) as total_net_amount,
            SUM(CASE WHEN status = "pending" THEN net_amount ELSE 0 END) as pending_earnings,
            SUM(CASE WHEN status = "available" THEN net_amount ELSE 0 END) as available_earnings,
            SUM(CASE WHEN status = "disbursed" THEN net_amount ELSE 0 END) as disbursed_earnings
        ')->first();
    }

    /**
     * Get provider earnings with admin filters
     */
    public static function getAdminProviderEarnings(array $filters = [], $perPage = 15)
    {
        $query = static::with(['user', 'paymentTransaction', 'payable'])
            ->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
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
     * Get top earning providers
     */
    public static function getTopEarningProviders($dateFrom = null, $dateTo = null, $limit = 10)
    {
        $query = static::join('users', 'provider_earnings.user_id', '=', 'users.id')
            ->where('provider_earnings.status', '!=', 'cancelled');

        if ($dateFrom) {
            $query->where('provider_earnings.created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('provider_earnings.created_at', '<=', $dateTo);
        }

        return $query->selectRaw('
            users.id,
            users.name,
            users.email,
            COUNT(*) as total_earnings,
            SUM(provider_earnings.net_amount) as total_net_amount,
            SUM(provider_earnings.commission_amount) as total_commission_amount
        ')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderBy('total_net_amount', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get earnings by service type
     */
    public static function getEarningsByServiceType($dateFrom = null, $dateTo = null)
    {
        $query = static::where('status', '!=', 'cancelled');

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->selectRaw('
            payable_type,
            COUNT(*) as total_earnings,
            SUM(gross_amount) as total_gross_amount,
            SUM(commission_amount) as total_commission_amount,
            SUM(net_amount) as total_net_amount,
            AVG(commission_rate) as avg_commission_rate
        ')
            ->groupBy('payable_type')
            ->get();
    }

    /**
     * Get earnings ready for disbursement
     */
    public static function getEarningsReadyForDisbursement($userId = null)
    {
        $query = static::where('status', 'available'); // to be changed to processing

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->with(['user:id,name,email,status', 'user.providerBankAccount', 'paymentTransaction'])
            ->orderBy('available_at', 'asc')
            ->get();
    }

    /**
     * Mark earnings as disbursed
     */
    public function markAsDisbursed($disbursementId = null)
    {
        $updateData = [
            'status' => 'disbursed',
            'disbursed_at' => now(),
            'disbursement_id' => $disbursementId,
        ];

        if ($disbursementId) {
            $updateData['metadata'] = array_merge($this->metadata ?? [], ['disbursement_id' => $disbursementId]);
        }
        return $this->update($updateData);
    }

    /**
     * Get daily earnings data
     */
    public static function getDailyEarningsData($days = 30)
    {
        return static::where('created_at', '>=', now()->subDays($days))
            ->where('status', '!=', 'cancelled')
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as earnings_count,
                SUM(gross_amount) as total_gross_amount,
                SUM(commission_amount) as total_commission_amount,
                SUM(net_amount) as total_net_amount
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    /**
     * Transform provider earning data for list display
     */
    public function transformProviderEarningForList()
    {
        // Dynamically resolve the payable record (if not already loaded)
        $payableTitle = 'Untitled'; // Default fallback

        if ($this->payable_type && $this->payable_id) {
            try {
                // 1. First check if the class exists
                if (!class_exists($this->payable_type)) {
                    Log::warning("Payable class does not exist", [
                        'payable_type' => $this->payable_type,
                        'payable_id' => $this->payable_id,
                    ]);
                }
                // 2. If class exists, try to fetch the record
                else {
                    $payable = $this->payable_type::find($this->payable_id);

                    // 3. Check if the record exists and has getTitle()
                    if ($payable && method_exists($payable, 'getTitle')) {
                        $payableTitle = $payable->getTitle() ?? 'Untitled';
                    } else {
                        Log::warning("Payable record or getTitle() method missing", [
                            'payable_type' => $this->payable_type,
                            'payable_id' => $this->payable_id,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Failed to fetch payable title", [
                    'payable_type' => $this->payable_type,
                    'payable_id' => $this->payable_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'title' => $payableTitle,
            'provider_name' => $this->user?->serviceProvider?->name ?? $this->user?->name ?? 'Unknown Provider',
            'user_name' => $this->user?->name ?? 'Unknown User',
            'payment_transaction_id' => $this->payment_transaction_id,
            'payment_gateway' => $this->paymentTransaction?->paymentGateway?->name ?? 'Unknown Gateway',
            // 'payable_type' => $this->payable_type,
            'payable_id' => $this->payable_id,
            'gross_amount' => $this->gross_amount,
            'commission_rate' => $this->commission_rate,
            'commission_amount' => $this->commission_amount,
            'net_amount' => $this->net_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'available_at' => $this->available_at?->format('Y-m-d H:i:s'),
            'disbursed_at' => $this->disbursed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata,
            // Transaction details
            'transaction_amount' => $this->paymentTransaction?->amount ?? 0,
            'transaction_status' => $this->paymentTransaction?->status ?? 'unknown',
            'transaction_reference' => $this->paymentTransaction?->reference ?? null,
        ];
    }

    public static function transformProvidersReadyForDisbursement(Collection $earnings)
    {
        // First group by user
        $groupedByUser = $earnings->groupBy('user_id');

        return $groupedByUser->map(function ($userEarnings) {
            $user = $userEarnings->first()->user;
            $bankAccounts = $user->providerBankAccount ?? collect();

            return [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'provider_bank_details' => $bankAccounts->map(function ($account) {
                        return [
                            'id' => $account->id,
                            'user_id' => $account->user_id,
                            'account_type' => $account->account_type,
                            'bank_name' => $account->bank_name,
                            'account_holder_name' => $account->account_holder_name,
                            'currency' => $account->currency,
                            'verification_status' => $account->verification_status,
                            'is_primary' => $account->is_primary,
                            'is_active' => $account->is_active,
                            'verified_at' => $account->verified_at,
                            'created_at' => $account->created_at,
                            'masked_account_number' => $account->masked_account_number,
                            'masked_routing_number' => $account->masked_routing_number,
                        ];
                    })->toArray()
                ],
                'total_earnings' => (float) number_format($userEarnings->sum('net_amount'), 2),
                'earnings_count' => $userEarnings->count(),
                'oldest_earning' => optional($userEarnings->min('available_at'))->toDateTimeString(),
                'has_bank_account' => $bankAccounts->isNotEmpty()
            ];
        })->values()->toArray();
    }
}
