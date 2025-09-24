<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;


class PaymentIntent extends BaseModel
{
    protected $fillable = [
        'intent_id', // Public facing ID
        'merchant_id',
        'merchant_app_id',
        'amount',
        'customer_id',
        'country_code',
        'currency',
        'status',
        'payment_method_types',
        'client_secret',
        'description',
        'metadata',
        'customer_email',
        'customer_name',
        'payment_method_id',
        'confirmation_method',
        'capture_method',
        'setup_future_usage',
        'receipt_email',
        'shipping',
        'billing_details',
        'amount_received',
        'amount_capturable',
        'charges',
        'last_payment_error',
        'processing_at',
        'confirmed_at',
        'cancelled_at',
        'succeeded_at',
        'captured_at',
        'cancellation_reason',

        'gateway_transaction_id',
        'gateway_payment_intent_id',
        'gateway_data',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_received' => 'decimal:2',
        'amount_capturable' => 'decimal:2',
        'payment_method_types' => 'array',
        'metadata' => 'array',
        'shipping' => 'array',
        'billing_details' => 'array',
        'gateway_data' => 'array',
        'charges' => 'array',
        'last_payment_error' => 'array',
        'processing_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'succeeded_at' => 'datetime',
        'captured_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($paymentIntent) {
            if (empty($paymentIntent->intent_id)) {
                $paymentIntent->intent_id = 'pi_' . Str::random(24);
            }

            if (empty($paymentIntent->client_secret)) {
                $paymentIntent->client_secret = $paymentIntent->intent_id . '_secret_' . Str::random(16);
            }
            if (empty($paymentIntent->status)) {
                $paymentIntent->status = 'requires_payment_method';
            }
        });
    }

    /**
     * Get merchant relationship
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Country relationship
     */
    public function country()
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    /**
     * Get merchant app relationship
     */
    public function merchantApp()
    {
        return $this->belongsTo(App::class);
    }

    /**
     * Get charges relationship
     */
    public function charges()
    {
        return $this->hasMany(Charge::class, 'payment_intent_id', 'id');
    }

    /**
     * Get charges relationship (alias for backwards compatibility)
     */
    public function chargeRecords()
    {
        return $this->charges();
    }

    public function paymentTransaction(): ?PaymentTransaction
    {
        if (!$this->gateway_transaction_id) {
            return null;
        }

        return PaymentTransaction::where('transaction_id', $this->gateway_transaction_id)->first();
    }

    /**
     * Get latest charge relationship
     */
    public function latestCharge()
    {
        return $this->hasOne(Charge::class, 'payment_intent_id', 'id')->latest();
    }

    /**
     * Find payment intent by payment_intent_id
     */
    public static function findByPaymentIntentId(string $paymentIntentId)
    {
        return static::where('id', $paymentIntentId)->first();
    }

    /**
     * Find payment intent by id and merchant
     */
    public static function findByIdAndMerchant(string $paymentIntentId, string $merchant)
    {
        return static::where('intent_id', $paymentIntentId)
            ->where('merchant_id', $merchant)
            ->first();
    }

    /**
     * Find payment intents by merchant
     */
    public static function findByMerchant(string $merchantId)
    {
        return static::where('merchant_id', $merchantId)->get();
    }



    /**
     * Get payment intents with filters
     */
    public static function getWithFilters(string $merchantId, array $filters = [])
    {
        $query = static::where('merchant_id', $merchantId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
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

        return $query->orderBy('created_at', 'desc')->get();
    }


    /**
     * Get payment intents for merchant with filters and pagination
     */
    public static function getForMerchant(string $merchantId, array $filters = []): Builder
    {
        $query = static::where('merchant_id', $merchantId);

        // Apply filters
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('intent_id', 'like', "%{$search}%")
                    ->orWhere('intent_id', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (!empty($filters['amount_min'])) {
            $query->where('amount', '>=', $filters['amount_min']);
        }

        if (!empty($filters['amount_max'])) {
            $query->where('amount', '<=', $filters['amount_max']);
        }

        // Handle sorting
        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        $query->orderBy($sortField, $sortOrder);

        return $query;
    }

    /**
     * Get payment intents for merchant with pagination
     */
    public function scopeForMerchant(Builder $query, string $merchantId, array $filters = []): Builder
    {
        return static::getForMerchant($merchantId, $filters);
    }

    /**
     * Update payment intent status
     */
    public function updateStatus($status, array $additionalData = [])
    {
        $updateData = array_merge(['status' => $status], $additionalData);

        if ($status === 'succeeded') {
            $updateData['succeeded_at'] = now();
        } elseif ($status === 'cancelled') {
            $updateData['cancelled_at'] = now();
        } elseif ($status === 'processing') {
            $updateData['processing_at'] = now();
        }

        return $this->updateRecord($updateData);
    }

    /**
     * Check if payment intent can be confirmed
     */
    public function canBeConfirmed()
    {
        return in_array($this->status, ['requires_payment_method', 'requires_confirmation']);
    }

    /**
     * Check if payment can be cancelled
     */
    public function canBeCancelled()
    {
        return in_array($this->status, ['requires_payment_method', 'requires_confirmation', 'requires_action']);
    }

    /**
     * Check if payment intent can be captured
     */
    public function canBeCaptured()
    {
        return $this->status === 'requires_action' && $this->amount_capturable > 0;
    }

    /**
     * Confirm payment intent and create charge
     */
    public function confirm(array $paymentMethod)
    {
        if (!$this->canBeConfirmed()) {
            throw new \Exception('Payment intent cannot be confirmed in current status: ' . $this->status);
        }

        $this->updateStatus('processing', [
            'confirmed_at' => now(),
        ]);

        // Create charge
        $charge = Charge::create([
            'intent_id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method_type' => $paymentMethod['type'],
            'payment_method_data' => $paymentMethod,
            'status' => 'pending',
        ]);

        return $charge;
    }

    /**
     * Update payment intent record
     */
    protected function updateRecord(array $data)
    {
        $this->fill($data);
        $this->save();

        return $this->fresh();
    }

    /**
     * Cancel payment intent
     */
    public function cancel($reason = null)
    {
        if (!$this->canBeCanceled()) {
            throw new \Exception('Payment intent cannot be cancelled in current status: ' . $this->status);
        }

        $updateData = [
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ];

        if ($reason) {
            $updateData['cancellation_reason'] = $reason;
        }

        return $this->updateRecord($updateData);
    }

    /**
     * Capture payment intent
     */
    public function capture($amount = null)
    {
        if (!$this->canBeCapture()) {
            throw new \Exception('Payment intent cannot be captured in current status: ' . $this->status);
        }

        $captureAmount = $amount ?? $this->amount_capturable;

        $updateData = [
            'status' => 'succeeded',
            'amount_received' => $captureAmount,
            'captured_at' => now(),
            'succeeded_at' => now(),
        ];

        return $this->updateRecord($updateData);
    }

    /**
     * Get payment intent statistics for merchant
     */
    public static function getStatistics(string $merchantId, $dateFrom = null, $dateTo = null)
    {
        $query = static::where('merchant_id', $merchantId);

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        return [
            'total_count' => $query->count(),
            'succeeded_count' => $query->where('status', 'succeeded')->count(),
            'total_amount' => $query->sum('amount'),
            'succeeded_amount' => $query->where('status', 'succeeded')->sum('amount'),
            'by_status' => $query->groupBy('status')->selectRaw('status, count(*) as count, sum(amount) as total_amount')->get()->keyBy('status'),
            'by_currency' => $query->groupBy('currency')->selectRaw('currency, count(*) as count, sum(amount) as total_amount')->get()->keyBy('currency'),
        ];
    }
}
