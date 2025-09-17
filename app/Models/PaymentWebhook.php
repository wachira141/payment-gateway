<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;

class PaymentWebhook extends BaseModel
{
    protected $fillable = [
        'payment_gateway_id',
        'webhook_id',
        'event_type',
        'gateway_event_id',
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
    public static function getWebhooksWithFilters(array $filters = [], $perPage = 20)
    {
        $query = static::with('paymentGateway')
            ->orderBy('created_at', 'desc');

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
    public static function getStatsByTimeframe(Carbon $startDate): array
    {
        $query = static::where('created_at', '>=', $startDate);
        
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
}
