<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PaymentWebhook extends BaseModel
{
    protected $fillable = [
        'payment_gateway_id',
        'merchant_app_id',
        'webhook_id',
        'event_type',
        'gateway_event_id',
        'payment_intent_id',
        'payment_transaction_id',
        'payload',
        'status',
        'processing_error',
        'retry_count',
        'processed_at',
        'correlation_id',
        'replay_of_webhook_id'
    ];

    protected $casts = [
        'payload' => 'array',
        'retry_count' => 'integer',
        'processed_at' => 'datetime',
    ];

    /**
     * Get the associated payment gateway
     */
    public function paymentGateway()
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    /**
     * Get the payment intent associated with this webhook 
     */
    public function paymentIntent()
    {
        return $this->hasOne(PaymentIntent::class);
    }

    /**
     * Get the payment transaction associated with this webhook via gateway_transaction_id
     */
    public function paymentTransaction()
    {
        return $this->hasOne(PaymentTransaction::class, 'payment_transaction_id', 'payment_transaction_id');
    }


    /**
     * Get the associated merchant app
     */
    public function merchantApp()
    {
        return $this->belongsTo(App::class, 'merchant_app_id', 'id');
    }

    /**
     * Scope to get pending webhooks
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get failed webhooks that can be retried
     */
    public function scopeRetryable($query)
    {
        return $query->where('status', 'failed')
            ->where('retry_count', '<', 3);
    }

    /**
     * Mark webhook as processed
     */
    public function markProcessed()
    {
        return $this->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    /**
     * Mark webhook as failed
     */
    public function markFailed($error)
    {
        return $this->update([
            'status' => 'failed',
            'processing_error' => $error,
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    /**
     * Create a new webhook record
     */
    public static function createWebhook(array $data)
    {
        return static::create($data);
    }

    /**
     * Find webhook by ID
     */
    public static function findByWebhookId($webhookId)
    {
        return static::where('webhook_id', $webhookId)->first();
    }

    /**
     * Get webhooks with filters
     */
    /**
     * Get webhooks with filters
     */
    public static function getWebhooksWithFilters(array $filters = [], $perPage = 20)
    {
        $query = static::with(['paymentGateway', 'merchantApp'])
            ->orderBy('created_at', 'desc');

        if (isset($filters['app_id'])) {
            $query->where('merchant_app_id', $filters['app_id']);
        }

        if (isset($filters['gateway_type'])) {
            $query->whereHas('paymentGateway', function ($q) use ($filters) {
                $q->where('type', $filters['gateway_type']);
            });
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get webhook with payment gateway relationship
     */
    public static function getWebhookWithGateway(string $webhookId)
    {
        return static::with('paymentGateway')
            ->where('webhook_id', $webhookId)
            ->first();
    }


    /**
     * Get statistics by timeframe
     */
    public static function getStatsByTimeframe(Carbon $startDate, ?string $appId = null): array
    {
        $query = static::where('created_at', '>=', $startDate);

        if ($appId) {
            $query->where('merchant_app_id', $appId);
        }

        return [
            'total' => $query->count(),
            'processed' => $query->where('status', 'processed')->count(),
            'failed' => $query->where('status', 'failed')->count(),
            'pending' => $query->where('status', 'pending')->count(),
        ];
    }

    /**
     * Get webhooks that can be retried
     */
    public static function getRetryableWebhooks(array $webhookIds)
    {
        return static::with('paymentGateway')
            ->whereIn('webhook_id', $webhookIds)
            ->where('status', 'failed')
            ->where('retry_count', '<', 3)
            ->get();
    }

    /**
     * Get distinct event types
     */
    public static function getDistinctEventTypes()
    {
        return static::distinct()
            ->pluck('event_type')
            ->filter()
            ->sort()
            ->values()
            ->toArray();
    }


    /**
     * Mark webhook as pending
     */
    public function markPending(): void
    {
        $this->update([
            'status' => 'pending',
            'processed_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ]);
    }

    /**
     * Scope: Only processed webhooks
     */
    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }

    /**
     * Scope: Only failed webhooks
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Find webhook by payment intent ID and merchant app ID
     */
    public static function findByPaymentIntentAndApp($paymentIntentId, $merchantAppId, array $filters = [])
    {
        $query = static::where('payment_intent_id', $paymentIntentId)
            ->where('merchant_app_id', $merchantAppId);

        if (isset($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Find webhook by payment transaction ID and merchant app ID
     */
    public static function findByPaymentTransactionAndApp($paymentTransactionId, $merchantAppId, array $filters = [])
    {
        $query = static::where('payment_transaction_id', $paymentTransactionId)
            ->where('merchant_app_id', $merchantAppId);

        if (isset($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Find webhook by payment intent ID, payment transaction ID, and merchant app ID
     */
    public static function findByIntentTransactionAndApp($paymentIntentId, $paymentTransactionId, $merchantAppId, array $filters = [])
    {
        $query = static::where('payment_intent_id', $paymentIntentId)
            ->where('payment_transaction_id', $paymentTransactionId)
            ->where('merchant_app_id', $merchantAppId);

        if (isset($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Find latest webhook by payment intent ID
     */
    public static function findLatestByPaymentIntent($paymentIntentId, $merchantAppId = null)
    {
        $query = static::where('payment_intent_id', $paymentIntentId);

        if ($merchantAppId) {
            $query->where('merchant_app_id', $merchantAppId);
        }

        return $query->orderBy('created_at', 'desc')->first();
    }

    /**
     * Find latest webhook by payment transaction ID
     */
    public static function findLatestByPaymentTransaction($paymentTransactionId, $merchantAppId = null)
    {
        $query = static::where('payment_transaction_id', $paymentTransactionId);

        if ($merchantAppId) {
            $query->where('merchant_app_id', $merchantAppId);
        }

        return $query->orderBy('created_at', 'desc')->first();
    }

    /**
     * Get webhooks for a payment intent with pagination
     */
    public static function getWebhooksForPaymentIntent($paymentIntentId, $merchantAppId = null, $perPage = 20)
    {
        $query = static::with(['paymentGateway', 'merchantApp'])
            ->where('payment_intent_id', $paymentIntentId);

        if ($merchantAppId) {
            $query->where('merchant_app_id', $merchantAppId);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get webhooks for a payment transaction with pagination
     */
    public static function getWebhooksForPaymentTransaction($paymentTransactionId, $merchantAppId = null, $perPage = 20)
    {
        $query = static::with(['paymentGateway', 'merchantApp'])
            ->where('payment_transaction_id', $paymentTransactionId);

        if ($merchantAppId) {
            $query->where('merchant_app_id', $merchantAppId);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Check if a specific event type exists for payment intent
     */
    public static function hasEventTypeForPaymentIntent($paymentIntentId, $eventType, $merchantAppId = null)
    {
        $query = static::where('payment_intent_id', $paymentIntentId)
            ->where('event_type', $eventType);

        if ($merchantAppId) {
            $query->where('merchant_app_id', $merchantAppId);
        }

        return $query->exists();
    }

    /**
     * Check if a specific event type exists for payment transaction
     */
    public static function hasEventTypeForPaymentTransaction($paymentTransactionId, $eventType, $merchantAppId = null)
    {
        $query = static::where('payment_transaction_id', $paymentTransactionId)
            ->where('event_type', $eventType);

        if ($merchantAppId) {
            $query->where('merchant_app_id', $merchantAppId);
        }

        return $query->exists();
    }

    /**
     * Get statistics for a specific payment intent
     */
    public static function getStatsForPaymentIntent($paymentIntentId, $merchantAppId = null): array
    {
        $query = static::where('payment_intent_id', $paymentIntentId);

        if ($merchantAppId) {
            $query->where('merchant_app_id', $merchantAppId);
        }

        $total = $query->count();

        return [
            'total' => $total,
            'processed' => $query->clone()->where('status', 'processed')->count(),
            'failed' => $query->clone()->where('status', 'failed')->count(),
            'pending' => $query->clone()->where('status', 'pending')->count(),
            'event_types' => $query->clone()->distinct()->pluck('event_type')->toArray(),
        ];
    }

    /**
     * Get statistics for a specific payment transaction
     */
    public static function getStatsForPaymentTransaction($paymentTransactionId, $merchantAppId = null): array
    {
        $query = static::where('payment_transaction_id', $paymentTransactionId);

        if ($merchantAppId) {
            $query->where('merchant_app_id', $merchantAppId);
        }

        $total = $query->count();

        return [
            'total' => $total,
            'processed' => $query->clone()->where('status', 'processed')->count(),
            'failed' => $query->clone()->where('status', 'failed')->count(),
            'pending' => $query->clone()->where('status', 'pending')->count(),
            'event_types' => $query->clone()->distinct()->pluck('event_type')->toArray(),
        ];
    }
}
