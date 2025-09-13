<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Builder;

class ApiKey extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'app_id',
        'key_id',
        'key_hash',
        'name',
        'scopes',
        'is_active',
        'last_used_at',
        'expires_at',
        'rate_limits',
        'metadata',
        'usage_count',
    ];

    protected $casts = [
        'scopes' => 'array',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'rate_limits' => 'array',
        'metadata' => 'array',
        'usage_count' => 'integer',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($apiKey) {
            if (empty($apiKey->key_id)) {
                $apiKey->key_id = 'key_' . Str::random(16);
            }
            $apiKey->usage_count = 0;
            $apiKey->is_active = true;
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the app this API key belongs to
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id', 'id');
    }

    /**
     * Get the merchant through the app
     */
    public function merchant()
    {
        return $this->hasOneThrough(
            Merchant::class,
            App::class,
            'id',        // Foreign key on apps table
            'id',        // Foreign key on merchants table
            'app_id',    // Local key on api_keys table
            'merchant_id' // Local key on apps table
        );
    }

    // ==================== SCOPES ====================

    /**
     * Scope for active API keys
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope for expired API keys
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope for keys by app
     */
    public function scopeForApp(Builder $query, string $appId): Builder
    {
        return $query->where('app_id', $appId);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create API key with hash generation
     */
    public static function createWithKey(array $data, string $fullKey): self
    {
        return static::create(array_merge($data, [
            'key_hash' => hash('sha256', $fullKey),
        ]));
    }

    /**
     * Verify API key by hash
     */
    public static function verifyKey(string $providedKey): ?self
    {
        $hash = hash('sha256', $providedKey);

        return static::where('key_hash', $hash)
            ->active()
            ->first();
    }

    /**
     * Find by key_id and app_id
     */
    public static function findByKeyId(string $keyId, string $appId): ?self
    {
        return static::where('key_id', $keyId)
            ->where('app_id', $appId)
            ->first();
    }


    /**
     * Update API key by ID
     */
    public static function updateById(string $apiKeyId, array $data): ?self
    {
        $apiKey = static::find($apiKeyId);

        if (!$apiKey) {
            return null;
        }

        // Map 'key' to 'key_hash' if provided
        if (isset($data['key'])) {
            $data['key_hash'] = $data['key'];
            unset($data['key']);
        }

        $apiKey->update($data);

        return $apiKey->fresh();
    }

    /**
     * Get active keys for app
     */
    public static function getActiveForApp(string $appId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forApp($appId)->active()->get();
    }

    /**
     * Get paginated keys for app
     */
    public static function getPaginatedForApp(
        string $appId,
        array $filters = [],
        int $perPage = 15
    ) {
        $query = static::forApp($appId)->orderBy('created_at', 'desc');

        // Apply filters
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['search'])) {
            $query->where('name', 'like', "%{$filters['search']}%");
        }

        if (isset($filters['expires_soon'])) {
            $query->where('expires_at', '<=', now()->addDays(30));
        }

        return $query->paginate($perPage);
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Check if API key has specific scope
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? []) || in_array('admin', $this->scopes ?? []);
    }

    /**
     * Check if API key is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if API key is test mode (based on app)
     */
    public function isTestMode(): bool
    {
        return !$this->app->is_live;
    }

    /**
     * Check if API key is live mode (based on app)
     */
    public function isLiveMode(): bool
    {
        return $this->app->is_live;
    }

    /**
     * Revoke API key
     */
    public function revoke(): bool
    {
        return $this->update(['is_active' => false]);
    }

    /**
     * Delete API key (soft delete)
     */
    public function deleteKey(): bool
    {
        return $this->delete();
    }

    /**
     * Activate API key
     */
    public function activate(): bool
    {
        return $this->update(['is_active' => true]);
    }

    /**
     * Update scopes
     */
    public function updateScopes(array $scopes): bool
    {
        // Validate scopes against app scopes
        if ($this->app && !empty($this->app->scopes)) {
            $invalidScopes = array_diff($scopes, $this->app->scopes);
            if (!empty($invalidScopes)) {
                throw new \InvalidArgumentException('Invalid scopes: ' . implode(', ', $invalidScopes));
            }
        }

        return $this->update(['scopes' => $scopes]);
    }

    /**
     * Set expiration date
     */
    public function setExpiration(?\Carbon\Carbon $expiresAt): bool
    {
        return $this->update(['expires_at' => $expiresAt]);
    }

    /**
     * Update usage statistics
     */
    public function updateUsageStats(): bool
    {
        return $this->update([
            'usage_count' => $this->usage_count + 1,
            'last_used_at' => now(),
        ]);
    }

    /**
     * Check rate limit
     */
    public function checkRateLimit(): bool
    {
        if (!$this->rate_limits || !isset($this->rate_limits['requests_per_minute'])) {
            return true;
        }

        $limit = $this->rate_limits['requests_per_minute'];
        $currentMinute = now()->format('Y-m-d H:i');
        $cacheKey = "api_rate_limit:key:{$this->key_id}:{$currentMinute}";

        $currentCount = cache()->get($cacheKey, 0);

        if ($currentCount >= $limit) {
            return false;
        }

        cache()->put($cacheKey, $currentCount + 1, 60);

        return true;
    }

    /**
     * Get masked key for display
     */
    public function getMaskedKeyAttribute(): string
    {
        if (!$this->key_id) {
            return '****';
        }

        return substr($this->key_id, 0, 8) . str_repeat('*', 8);
    }

    /**
     * Get usage statistics
     */
    public function getUsageStatistics(int $days = 30): array
    {
        // This would integrate with actual usage tracking
        // For now, return basic stats
        return [
            'usage_count' => $this->usage_count,
            'last_used_at' => $this->last_used_at,
            'daily_average' => $this->usage_count > 0 ? round($this->usage_count / max($this->created_at->diffInDays(now()), 1), 2) : 0,
            'rate_limit_hits' => 0, // Would track from cache/logs
        ];
    }


    /**
     * Find app for merchant
     */
    /**
     * Find API key by ID for a specific merchant
     */
    public static function findByIdAndMerchant(string $apiKeyId, string $merchantId): ?self
    {
        return static::where('id', $apiKeyId)
            ->whereHas('app', function ($q) use ($merchantId) {
                $q->where('merchant_id', $merchantId);
            })
            ->first();
    }

    /**
     * List API keys for a given app, restricted to the merchant that owns the app.
     * Only performs database querying; no business logic here.
     */
    public static function listForAppAndMerchant(string $appId, string $merchantId)
    {
        return static::query()
            ->forApp($appId)
            ->whereHas('app', function ($q) use ($merchantId) {
                $q->where('merchant_id', $merchantId);
            })
            ->orderBy('created_at', 'desc')
            ->get([
                'id',
                'app_id',
                'key_id',
                'name',
                'scopes',
                'is_active',
                'last_used_at',
                'expires_at',
                'created_at',
                'deleted_at',
            ]);
    }
}
