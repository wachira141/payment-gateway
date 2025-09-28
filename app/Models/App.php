<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

class App extends BaseModel
{
    use SoftDeletes;

    protected $table = 'merchant_apps';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'merchant_id',
        'name',
        'description',
        'client_id',
        'client_secret',
        'webhook_url',
        'redirect_urls',
        'logo_url',
        'website_url',
        'is_live',
        'is_active',
        'webhook_events',
        'settings',
        'secret_regenerated_at',
        'scopes',
    ];

    protected $casts = [
        'redirect_urls' => 'array',
        'webhook_events' => 'array',
        'settings' => 'array',
        'is_live' => 'boolean',
        'is_active' => 'boolean',
        'secret_regenerated_at' => 'datetime',
        'scopes' => 'array',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($app) {
            if (empty($app->id)) {
                $app->id = 'app_' . Str::random(16);
            }
            if (empty($app->client_id)) {
                $app->client_id = 'client_' . Str::random(24);
            }
            if (empty($app->client_secret)) {
                $app->client_secret = 'cs_' . Str::random(32);
            }

            // Set default values from config
            $defaults = config('app.defaults');
            $app->webhook_events = $app->webhook_events ?? $defaults['webhook_events'];
            $app->settings = array_merge($defaults['settings'], $app->settings ?? []);
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the merchant
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get API keys
     */
    /**
     * Get API keys
     */
    public function apiKeys()
    {
        return $this->hasMany(ApiKey::class, 'app_id', 'id');
    }

    /**
     * Get payment intents
     */
    public function paymentIntents()
    {
        return $this->hasMany(PaymentIntent::class, 'app_id', 'id');
    }

    /**
     * Get webhooks for this app
     */
    public function webhooks()
    {
        return $this->hasMany(AppWebhook::class, 'app_id', 'id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope to get active apps
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get live apps
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_live', true);
    }

    /**
     * Scope to get test apps
     */
    public function scopeTest(Builder $query): Builder
    {
        return $query->where('is_live', false);
    }

    /**
     * Scope to get apps for merchant
     */
    public function scopeForMerchant(Builder $query, string $merchantId): Builder
    {
        return $query->where('merchant_id', $merchantId);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create app for merchant
     */
    public static function createForMerchant(string $merchantId, array $data): self
    {
        // Generate client secret based on environment
        $isLive = $data['is_live'] ?? false;
        $clientSecret = 'sk_' . ($isLive ? 'live' : 'test') . '_' . Str::random(32);

        return static::create(array_merge($data, [
            'merchant_id' => $merchantId,
            'client_secret' => $clientSecret,
            'is_active' => true,
        ]));
    }

    /**
     * Find app for merchant
     */
    public static function findForMerchant(string $appId, string $merchantId): ?self
    {
        return static::where('id', $appId)
            ->where('merchant_id', $merchantId)
            ->first();
    }

    /**
     * Update app for merchant
     */
    public static function updateForMerchant(string $appId, string $merchantId, array $data): ?self
    {
        $app = static::findForMerchant($appId, $merchantId);

        if ($app) {
            $app->update($data);
            return $app->fresh();
        }

        return null;
    }

    /**
     * Get paginated apps for merchant
     */
    public static function getPaginatedForMerchant(
        string $merchantId,
        array $filters = [],
        int $perPage = 15,
        array $with = []
    ) {
        $query = static::forMerchant($merchantId)
            ->orderBy('created_at', 'desc');

        // Apply filters
        if (isset($filters['is_live'])) {
            $query->where('is_live', $filters['is_live']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (!empty($with)) {
            $query->with($with);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get apps usage summary for merchant
     */
    public static function getUsageSummaryForMerchant(string $merchantId, array $filters = []): array
    {
        $query = static::forMerchant($merchantId)->active();

        if (isset($filters['is_live'])) {
            $query->where('is_live', $filters['is_live']);
        }

        $apps = $query->withCount(['apiKeys', 'apiKeys as active_api_keys_count' => function ($q) {
            $q->where('is_active', true);
        }])->get();

        return [
            'total_apps' => $apps->count(),
            'live_apps' => $apps->where('is_live', true)->count(),
            'test_apps' => $apps->where('is_live', false)->count(),
            'apps_with_webhooks' => $apps->whereNotNull('webhook_url')->count(),
            'total_api_keys' => $apps->sum('api_keys_count'),
            'active_api_keys' => $apps->sum('active_api_keys_count'),
        ];
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Check if app is active and merchant is active
     */
    public function isActive(): bool
    {
        return $this->is_active && $this->merchant->isActive();
    }

    /**
     * Get active API keys
     */
    public function getActiveApiKeys()
    {
        return $this->apiKeys()->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Create API key for this app
     */
    public function createApiKey(string $name, ?array $scopes = null, ?array $options = []): ApiKey
    {
        // Validate scopes are subset of app scopes if provided
        $scopes = $scopes ?? $this->scopes ?? [];
        if (!empty($this->scopes)) {
            $invalidScopes = array_diff($scopes, $this->scopes);
            if (!empty($invalidScopes)) {
                throw new \InvalidArgumentException('Invalid scopes: ' . implode(', ', $invalidScopes));
            }
        }

        // Generate key
        $keyPrefix = 'pk_' . ($this->is_live ? 'live' : 'test') . '_';
        $fullKey = $keyPrefix . Str::random(32);

        $apiKey = $this->apiKeys()->create([
            'key_id' => 'key_' . Str::random(16),
            'key_hash' => hash('sha256', $fullKey),
            'name' => $name,
            'scopes' => $scopes,
            'expires_at' => $options['expires_at'] ?? null,
            'rate_limits' => $options['rate_limits'] ?? null,
        ]);

        // Temporarily store the full key for return
        $apiKey->full_key = $fullKey;

        return $apiKey;
    }

    /**
     * Regenerate client secret
     */
    public function regenerateClientSecret(): self
    {
        $this->update([
            'client_secret' => 'sk_' . ($this->is_live ? 'live' : 'test') . '_' . Str::random(32),
            'secret_regenerated_at' => now(),
        ]);

        return $this->fresh();
    }

    /**
     * Deactivate app with checks
     */
    public function deactivateWithChecks(): bool
    {
        // Check for active API keys
        $activeApiKeysCount = $this->apiKeys()->where('is_active', true)->count();

        if ($activeApiKeysCount > 0) {
            throw new \Exception("Cannot deactivate app with {$activeApiKeysCount} active API keys. Please revoke all API keys first.");
        }

        return $this->update(['is_active' => false]);
    }

    /**
     * Update webhook settings
     */
    public function updateWebhookSettings(array $data): self
    {
        $validEvents = array_keys(config('apps.webhook_events'));
        $webhookEvents = array_intersect($data['events'] ?? [], $validEvents);

        $this->update([
            'webhook_url' => $data['webhook_url'] ?? $this->webhook_url,
            'webhook_events' => $webhookEvents,
        ]);

        return $this->fresh();
    }

    /**
     * Get app statistics
     */
    public function getStatistics(): array
    {
        $apiKeysQuery = $this->apiKeys();

        return [
            'api_keys' => [
                'total' => $apiKeysQuery->count(),
                'active' => $apiKeysQuery->where('is_active', true)->count(),
                'expired' => $apiKeysQuery->where('expires_at', '<=', now())->count(),
            ],
            'usage' => [
                'total_calls' => $apiKeysQuery->sum('usage_count'),
                'last_activity' => $apiKeysQuery->max('last_used_at'),
            ],
            'webhooks' => $this->getWebhookStatistics(),
            'transactions' => $this->getTransactionStatistics(),
        ];
    }

    /**
     * Get webhook statistics
     */
    private function getWebhookStatistics(): array
    {
        $webhooks = $this->webhooks();
        $totalWebhooks = $webhooks->count();
        $activeWebhooks = $webhooks->where('is_active', true)->count();

        return [
            'total_webhooks' => $totalWebhooks,
            'active_webhooks' => $activeWebhooks,
            'last_success' => $webhooks->max('last_success_at'),
            'last_failure' => $webhooks->max('last_failure_at'),
        ];
    }


    /**
     * Get transaction statistics (placeholder for future implementation)
     */
    private function getTransactionStatistics(): array
    {
        // This would integrate with actual transaction tracking
        return [
            'total_transactions' => 0,
            'successful_transactions' => 0,
            'total_volume' => 0,
            'currencies' => [],
        ];
    }

    /**
     * Check if app has scope
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? []);
    }
}
