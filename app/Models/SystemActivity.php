<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemActivity extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'type',
        'message',
        'status',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Activity types
     */
    const TYPE_API_KEY = 'api_key';
    const TYPE_WEBHOOK = 'webhook';
    const TYPE_PAYMENT = 'payment';
    const TYPE_ERROR = 'error';
    const TYPE_SECURITY = 'security';
    const TYPE_SYSTEM = 'system';

    /**
     * Activity statuses
     */
    const STATUS_SUCCESS = 'success';
    const STATUS_WARNING = 'warning';
    const STATUS_ERROR = 'error';
    const STATUS_INFO = 'info';

    /**
     * Get the merchant that owns this activity
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Log API key activity
     */
    public static function logApiKeyActivity(string $merchantId, string $action, array $metadata = []): void
    {
        self::create([
            'merchant_id' => $merchantId,
            'type' => self::TYPE_API_KEY,
            'message' => "API key {$action}",
            'status' => self::STATUS_SUCCESS,
            'metadata' => $metadata
        ]);
    }

    /**
     * Log webhook activity
     */
    public static function logWebhookActivity(string $merchantId, string $action, string $status = self::STATUS_SUCCESS, array $metadata = []): void
    {
        self::create([
            'merchant_id' => $merchantId,
            'type' => self::TYPE_WEBHOOK,
            'message' => "Webhook {$action}",
            'status' => $status,
            'metadata' => $metadata
        ]);
    }

    /**
     * Log payment activity
     */
    public static function logPaymentActivity(string $merchantId, string $action, string $status = self::STATUS_SUCCESS, array $metadata = []): void
    {
        self::create([
            'merchant_id' => $merchantId,
            'type' => self::TYPE_PAYMENT,
            'message' => "Payment {$action}",
            'status' => $status,
            'metadata' => $metadata
        ]);
    }

    /**
     * Log security activity
     */
    public static function logSecurityActivity(string $merchantId, string $action, string $status = self::STATUS_WARNING, array $metadata = []): void
    {
        self::create([
            'merchant_id' => $merchantId,
            'type' => self::TYPE_SECURITY,
            'message' => "Security event: {$action}",
            'status' => $status,
            'metadata' => $metadata
        ]);
    }

    /**
     * Log system activity
     */
    public static function logSystemActivity(string $merchantId, string $message, string $status = self::STATUS_INFO, array $metadata = []): void
    {
        self::create([
            'merchant_id' => $merchantId,
            'type' => self::TYPE_SYSTEM,
            'message' => $message,
            'status' => $status,
            'metadata' => $metadata
        ]);
    }

    /**
     * Get recent activities for a merchant
     */
    public static function getRecentActivities(string $merchantId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('merchant_id', $merchantId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get activities by type
     */
    public static function getActivitiesByType(string $merchantId, string $type, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('merchant_id', $merchantId)
            ->where('type', $type)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Clean up old activities (keep last 1000 records per merchant)
     */
    public static function cleanupOldActivities(): void
    {
        $merchants = Merchant::pluck('id');

        foreach ($merchants as $merchantId) {
            $keepIds = self::where('merchant_id', $merchantId)
                ->orderBy('created_at', 'desc')
                ->limit(1000)
                ->pluck('id');

            self::where('merchant_id', $merchantId)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }
    }
}