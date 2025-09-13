<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AppWebhook extends BaseModel
{
    use HasFactory;
protected $fillable = [
        'app_id',
        'url',
        'url_hash',
        'events',
        'secret',
        'is_active',
        'headers',
        'timeout_seconds',
        'retry_attempts',
        'description',
        'last_success_at',
        'last_failure_at',
        'last_error'
    ];

    protected $casts = [
        'events' => 'array',
        'headers' => 'array',
        'is_active' => 'boolean',
        'timeout_seconds' => 'integer',
        'retry_attempts' => 'integer',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    protected $hidden = [
        'secret',
    ];

    protected static function booted()
    {
        static::creating(function ($webhook) {
            $webhook->url_hash = hash('sha256', $webhook->url);
        });

        static::updating(function ($webhook) {
            if ($webhook->isDirty('url')) {
                $webhook->url_hash = hash('sha256', $webhook->url);
            }
        });
    }

    /**
     * Get the app that owns the webhook
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * Generate a new webhook secret
     */
    public function generateSecret(): string
    {
        $secret = 'whsec_' . Str::random(40);
        $this->update(['secret' => $secret]);
        return $secret;
    }

    /**
     * Check if webhook should receive this event type
     */
    public function shouldReceiveEvent(string $eventType): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if (empty($this->events)) {
            return true; // Subscribe to all events if none specified
        }

        return in_array($eventType, $this->events) || in_array('*', $this->events);
    }

    /**
     * Mark webhook as having successful delivery
     */
    public function markSuccess(): void
    {
        $this->update([
            'last_success_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * Mark webhook as having failed delivery
     */
    public function markFailure(string $error): void
    {
        $this->update([
            'last_failure_at' => now(),
            'last_error' => $error,
        ]);
    }

    /**
     * Get webhooks by app ID
     */
    public static function getByAppId(string $appId)
    {
        return static::where('app_id', $appId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get active webhooks for event
     */
    public static function getActiveForEvent(string $appId, string $eventType)
    {
        return static::where('app_id', $appId)
            ->where('is_active', true)
            ->get()
            ->filter(function ($webhook) use ($eventType) {
                return $webhook->shouldReceiveEvent($eventType);
            });
    }

    /**
     * Get available event types
     */
    public static function getAvailableEventTypes(): array
    {
        return array_keys(config('app.webhook_events', []));
    }
}