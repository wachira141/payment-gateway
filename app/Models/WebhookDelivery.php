<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends BaseModel
{
    protected $fillable = [
        'app_webhook_id',
        'event_type',
        'payload',
        'http_status_code',
        'response_body',
        'delivery_attempts',
        'status',
        'error_message',
        'next_retry_at',
        'delivered_at'
    ];

    protected $casts = [
        'payload' => 'array',
        'delivery_attempts' => 'integer',
        'http_status_code' => 'integer',
        'next_retry_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * Get the app webhook that this delivery belongs to
     */
    public function appWebhook(): BelongsTo
    {
        return $this->belongsTo(AppWebhook::class);
    }

    /**
     * Mark delivery as successful
     */
    public function markAsDelivered(int $statusCode, string $responseBody = null): void
    {
        $this->update([
            'status' => 'delivered',
            'http_status_code' => $statusCode,
            'response_body' => $responseBody,
            'delivered_at' => now(),
            'error_message' => null,
        ]);
    }

    /**
     * Mark delivery as failed
     */
    public function markAsFailed(string $error, int $statusCode = null, string $responseBody = null): void
    {
        $this->increment('delivery_attempts');
        
        $nextRetryAt = null;
        if ($this->delivery_attempts < 5) { // Max 5 attempts
            $delayMinutes = min(pow(2, $this->delivery_attempts - 1) * 5, 60); // Exponential backoff, max 1 hour
            $nextRetryAt = now()->addMinutes($delayMinutes);
        }

        $this->update([
            'status' => $this->delivery_attempts >= 5 ? 'failed' : 'retrying',
            'http_status_code' => $statusCode,
            'response_body' => $responseBody,
            'error_message' => $error,
            'next_retry_at' => $nextRetryAt,
        ]);
    }

    /**
     * Get deliveries ready for retry
     */
    public static function getReadyForRetry()
    {
        return static::where('status', 'retrying')
            ->where('next_retry_at', '<=', now())
            ->get();
    }

    /**
     * Get delivery statistics for a webhook
     */
    public static function getStatsForWebhook(int $webhookId): array
    {
        $stats = static::where('app_webhook_id', $webhookId)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status IN ("pending", "retrying") THEN 1 ELSE 0 END) as pending
            ')
            ->first();

        return [
            'total' => $stats->total ?? 0,
            'delivered' => $stats->delivered ?? 0,
            'failed' => $stats->failed ?? 0,
            'pending' => $stats->pending ?? 0,
            'success_rate' => $stats->total > 0 ? round(($stats->delivered / $stats->total) * 100, 1) : 0,
        ];
    }
}