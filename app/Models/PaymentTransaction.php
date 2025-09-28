<?php

namespace App\Models;

use App\Models\BaseModel;

class PaymentTransaction extends BaseModel
{
    protected $fillable = [
        'transaction_id',
        'merchant_id',
        'customer_id',
        'payment_gateway_id',
        'gateway_transaction_id',
        'gateway_payment_intent_id',
        'payable_type',
        'payable_id',
        'amount',
        'currency',
        'type',
        'status',
        'description',
        'metadata',
        'gateway_response',
        'failure_reason',
        'retry_count',
        'next_retry_at',
        'completed_at',
        'failed_at',
        'gateway_code',
        'payment_method_type',
        'commission_amount',
        'provider_amount',
        'commission_processed',
        'commission_breakdown',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'gateway_response' => 'array',
        'retry_count' => 'integer',
        'next_retry_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'commission_amount' => 'decimal:2',
        'provider_amount' => 'decimal:2',
        'commission_processed' => 'boolean',
        'commission_breakdown' => 'array',
    ];

    /**
     * Get the associated payment gateway
     */
    public function paymentGateway()
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    /**
     * Get webhooks associated with this payment transaction
     */
    public function webhooks()
    {
        return $this->hasMany(PaymentWebhook::class);
    }

    /**
     * Get the associated user
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the associated customer
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the payable model (goal request, meal plan request, etc.)
     */
    public function payable()
    {
        return $this->morphTo();
    }

    /**
     * Get refunds for this transaction
     */
    public function refunds()
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /**
     * Scope to get transactions by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get failed transactions that can be retried
     */
    public function scopeRetryable($query)
    {
        return $query->where('status', 'failed')
            ->where('retry_count', '<', 5)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            });
    }

    /**
     * Check if transaction is completed
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * Check if transaction failed
     */
    public function isFailed()
    {
        return $this->status === 'failed';
    }

    /**
     * Check if transaction can be retried
     */
    public function canRetry()
    {
        return $this->isFailed() &&
            $this->retry_count < 5 &&
            (!$this->next_retry_at || $this->next_retry_at <= now());
    }

    /**
     * Create a new payment transaction
     */
    public static function createTransaction(array $data)
    {
        return static::create([
            'transaction_id' => $data['transaction_id'],
            'merchant_id' => $data['merchant_id'],
            'customer_id' => $data['customer_id'] ?? null,
            'payment_gateway_id' => $data['payment_gateway_id'],
            'payable_type' => $data['payable_type'],
            'payable_id' => $data['payable_id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'type' => $data['type'] ?? 'payment',
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'status' => 'pending',
        ]);
    }

    /**
     * Update transaction with gateway response
     */
    public function updateWithGatewayResponse($gatewayResponse, $status = null)
    {
        $updateData = [
            'gateway_response' => $gatewayResponse,
        ];

        if ($status) {
            $updateData['status'] = $status;

            if ($status === 'completed') {
                $updateData['completed_at'] = now();
            } elseif ($status === 'failed') {
                $updateData['failed_at'] = now();
                $updateData['retry_count'] = $this->retry_count + 1;
                $updateData['next_retry_at'] = $this->calculateNextRetryTime();
            }
        }

        return $this->update($updateData);
    }

    /**
     * Mark transaction as failed with reason
     */
    public function markAsFailed($reason)
    {
        return $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'failed_at' => now(),
            'retry_count' => $this->retry_count + 1,
            'next_retry_at' => $this->calculateNextRetryTime(),
        ]);
    }

    /**
     * Mark transaction as completed
     */
    public function markAsCompleted($gatewayTransactionId = null)
    {
        $updateData = [
            'status' => 'completed',
            'completed_at' => now(),
        ];

        if ($gatewayTransactionId) {
            $updateData['gateway_transaction_id'] = $gatewayTransactionId;
        }

        return $this->update($updateData);
    }

    /**
     * Calculate next retry time using exponential backoff
     */
    private function calculateNextRetryTime()
    {
        $retryDelays = [
            1 => 1,    // 1 hour
            2 => 6,    // 6 hours
            3 => 24,   // 24 hours
            4 => 72,   // 72 hours
        ];

        $delay = $retryDelays[$this->retry_count + 1] ?? 72; // Default to 72 hours
        return now()->addHours($delay);
    }

    /**
     * Get transaction by transaction ID and user
     */
    public static function getByTransactionIdAndUser($transactionId, $userId)
    {
        return static::where('transaction_id', $transactionId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Get user's payment history with filters
     */
    public static function getUserPaymentHistory($userId, array $filters = [], $perPage = 15)
    {
        $query = static::where('user_id', $userId)
            ->with(['paymentGateway', 'payable'])
            ->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get financial dashboard metrics
     */
    public static function getFinancialDashboardMetrics($dateFrom = null, $dateTo = null)
    {
        $query = static::query();

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->selectRaw('
            COUNT(*) as total_transactions,
            SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as successful_transactions,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_transactions,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_transactions,
            SUM(CASE WHEN status = "completed" THEN amount ELSE 0 END) as total_revenue,
            SUM(CASE WHEN status = "completed" THEN commission_amount ELSE 0 END) as total_commission,
            SUM(CASE WHEN status = "completed" THEN provider_amount ELSE 0 END) as total_provider_payout,
            AVG(CASE WHEN status = "completed" THEN amount ELSE NULL END) as avg_transaction_amount
        ')->first();
    }

    /**
     * Get daily revenue data
     */
    public static function getDailyRevenueData($days = 30)
    {
        return static::where('created_at', '>=', now()->subDays($days))
            ->where('status', 'completed')
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as transaction_count,
                SUM(amount) as total_revenue,
                SUM(commission_amount) as total_commission,
                SUM(provider_amount) as total_provider_payout
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    /**
     * Get revenue by type
     */
    public static function getRevenueByType($dateFrom = null, $dateTo = null)
    {
        $query = static::where('status', 'completed');

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->selectRaw('
            type,
            COUNT(*) as transaction_count,
            SUM(amount) as total_revenue,
            SUM(commission_amount) as total_commission,
            AVG(amount) as avg_amount
        ')
            ->groupBy('type')
            ->get();
    }

    /**
     * Get transactions for admin with filters
     */
    public static function getAdminTransactions(array $filters = [], $perPage = 15)
    {
        $query = static::with(['user', 'paymentGateway', 'payable'])
            ->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['gateway_id'])) {
            $query->where('payment_gateway_id', $filters['gateway_id']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (isset($filters['amount_min'])) {
            $query->where('amount', '>=', $filters['amount_min']);
        }

        if (isset($filters['amount_max'])) {
            $query->where('amount', '<=', $filters['amount_max']);
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('transaction_id', 'LIKE', '%' . $filters['search'] . '%')
                    ->orWhere('gateway_transaction_id', 'LIKE', '%' . $filters['search'] . '%')
                    ->orWhere('description', 'LIKE', '%' . $filters['search'] . '%');
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Get failed transactions for retry
     */
    public static function getFailedTransactionsForRetry($limit = 100)
    {
        return static::where('status', 'failed')
            ->where('retry_count', '<', 5)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get top payment methods by revenue
     */
    public static function getTopPaymentMethodsByRevenue($dateFrom = null, $dateTo = null, $limit = 10)
    {
        $query = static::join('payment_gateways', 'payment_transactions.payment_gateway_id', '=', 'payment_gateways.id')
            ->where('payment_transactions.status', 'completed');

        if ($dateFrom) {
            $query->where('payment_transactions.created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('payment_transactions.created_at', '<=', $dateTo);
        }

        return $query->selectRaw('
            payment_gateways.name,
            payment_gateways.type,
            COUNT(*) as transaction_count,
            SUM(payment_transactions.amount) as total_revenue
        ')
            ->groupBy('payment_gateways.id', 'payment_gateways.name', 'payment_gateways.type')
            ->orderBy('total_revenue', 'desc')
            ->limit($limit)
            ->get();
    }



    /**
     * Get unprocessed commission transactions
     */
    public static function getUnprocessedCommissions($limit = 100)
    {
        return static::where('commission_processed', false)
            ->where('status', 'completed')
            ->whereNotNull('gateway_code')
            ->whereNotNull('payment_method_type')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get commission statistics for merchant
     */
    public static function getCommissionStatistics($merchantId = null, $dateFrom = null, $dateTo = null)
    {
        $query = static::where('commission_processed', true)
            ->where('status', 'completed');

        if ($merchantId) {
            $query->where('merchant_id', $merchantId);
        }

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query->selectRaw('
            COUNT(*) as total_transactions,
            SUM(amount) as total_amount,
            SUM(commission_amount) as total_commission,
            SUM(provider_amount) as total_provider_amount,
            AVG(commission_amount) as avg_commission,
            gateway_code,
            payment_method_type
        ')
            ->groupBy(['gateway_code', 'payment_method_type'])
            ->get();
    }
}
