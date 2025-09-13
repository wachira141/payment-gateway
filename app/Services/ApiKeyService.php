<?php

namespace App\Services;

use App\Models\ApiKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class ApiKeyService extends BaseService
{
    /**
     * Get API keys for a merchant
     */
    public function getApiKeysForMerchant(string $merchantId): Collection
    {
        return ApiKey::where('merchant_id', $merchantId)
            ->where('is_revoked', false)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($apiKey) {
                // Hide the actual key value for security
                $data = $apiKey->toArray();
                $data['key'] = $this->maskApiKey($data['key']);
                return $data;
            });
    }

    /**
     * Get an API key by ID for a merchant
     */
    public function getApiKeyById(string $apiKeyId, string $merchantId): ?array
    {
        $apiKey = ApiKey::findByIdAndMerchant($apiKeyId, $merchantId);
        
        if (!$apiKey) {
            return null;
        }

        $data = $apiKey->toArray();
        $data['key'] = $this->maskApiKey($data['key']);
        
        return $data;
    }

    /**
     * Create a new API key
     */
    public function createApiKey(string $merchantId, array $data): array
    {
        // Generate API key
        $keyPrefix = $data['app_id'] ? 'ak_live_' : 'ak_test_';
        $keyValue = $keyPrefix . Str::random(32);
        $keyHash = Hash::make($keyValue);

        // Validate scopes
        $allowedScopes = ['read', 'write', 'admin'];
        $scopes = array_intersect($data['scopes'], $allowedScopes);
        
        if (empty($scopes)) {
            throw new \Exception('At least one valid scope is required');
        }

        $apiKeyData = [
            'id' => 'apk_' . Str::random(24),
            'merchant_id' => $merchantId,
            'app_id' => $data['app_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'key' => $keyValue, // Store actual key for return, will be hashed below
            'key_hash' => $keyHash,
            'scopes' => $scopes,
            'is_live' => !empty($data['app_id']), // Live if associated with app
            'is_revoked' => false,
            'last_used_at' => null,
            'expires_at' => $data['expires_at'] ?? null,
            'usage_count' => 0
        ];

        $apiKey = ApiKey::create($apiKeyData);

        // Return with actual key value (only time it's shown in full)
        $result = $apiKey->toArray();
        $result['key'] = $keyValue;
        
        // Also update the stored record to use hash
        ApiKey::updateById($apiKey['id'], ['key' => $keyHash]);

        return $result;
    }

    /**
     * Regenerate an API key
     */
    public function regenerateApiKey(string $apiKeyId, string $merchantId): array
    {
        $apiKey = ApiKey::findByIdAndMerchant($apiKeyId, $merchantId);
        
        if (!$apiKey) {
            throw new \Exception('API key not found');
        }

        if ($apiKey['is_revoked']) {
            throw new \Exception('Cannot regenerate revoked API key');
        }

        // Generate new key
        $keyPrefix = $apiKey['app_id'] ? 'ak_live_' : 'ak_test_';
        $keyValue = $keyPrefix . Str::random(32);
        $keyHash = Hash::make($keyValue);

        $updatedApiKey = ApiKey::updateById($apiKeyId, [
            'key' => $keyHash,
            'regenerated_at' => now(),
            'last_used_at' => null,
            'usage_count' => 0
        ]);

        // Return with actual key value
        $result = $updatedApiKey->toArray();
        $result['key'] = $keyValue;

        return $result;
    }

    /**
     * Revoke an API key
     */
    public function revokeApiKey(string $apiKeyId, string $merchantId): bool
    {
        $apiKey = ApiKey::findByIdAndMerchant($apiKeyId, $merchantId);
        
        if (!$apiKey) {
            throw new \Exception('API key not found');
        }


        $apiKey->deleteKey();

        return true;
    }

    /**
     * Validate API key and return merchant info
     */
    public function validateApiKey(string $keyValue): ?array
    {
        // Extract key parts
        if (!str_starts_with($keyValue, 'ak_')) {
            return null;
        }

        $apiKey = ApiKey::findByKey($keyValue);
        
        if (!$apiKey) {
            return null;
        }

        if ($apiKey['is_revoked']) {
            return null;
        }

        if ($apiKey['expires_at'] && now() > $apiKey['expires_at']) {
            return null;
        }

        // Update usage statistics
        $this->updateUsageStatistics($apiKey['id']);

        return [
            'api_key_id' => $apiKey['id'],
            'merchant_id' => $apiKey['merchant_id'],
            'app_id' => $apiKey['app_id'],
            'scopes' => $apiKey['scopes'],
            'is_live' => $apiKey['is_live']
        ];
    }

    /**
     * Update API key usage statistics
     */
    public function updateUsageStatistics(string $apiKeyId): void
    {
        ApiKey::updateById($apiKeyId, [
            'last_used_at' => now(),
            'usage_count' => DB::raw('usage_count + 1')
        ]);
    }

    /**
     * Check if API key has required scope
     */
    public function hasScope(array $apiKeyData, string $requiredScope): bool
    {
        if (in_array('admin', $apiKeyData['scopes'])) {
            return true; // Admin scope grants all permissions
        }

        return in_array($requiredScope, $apiKeyData['scopes']);
    }

    /**
     * Get API key usage statistics
     */
    public function getUsageStatistics(string $merchantId, array $filters = []): array
    {
        $query = ApiKey::where('merchant_id', $merchantId);

        if (!empty($filters['app_id'])) {
            $query->where('app_id', $filters['app_id']);
        }

        if (!empty($filters['is_live'])) {
            $query->where('is_live', $filters['is_live']);
        }

        $apiKeys = $query->get();

        return [
            'total_keys' => $apiKeys->count(),
            'active_keys' => $apiKeys->where('is_revoked', false)->count(),
            'revoked_keys' => $apiKeys->where('is_revoked', true)->count(),
            'live_keys' => $apiKeys->where('is_live', true)->where('is_revoked', false)->count(),
            'test_keys' => $apiKeys->where('is_live', false)->where('is_revoked', false)->count(),
            'total_usage' => $apiKeys->sum('usage_count'),
            'most_used_key' => $apiKeys->sortByDesc('usage_count')->first(),
            'recently_used_keys' => $apiKeys->whereNotNull('last_used_at')
                                          ->sortByDesc('last_used_at')
                                          ->take(5)
                                          ->values(),
        ];
    }

    /**
     * Mask API key for display purposes
     */
    private function maskApiKey(string $key): string
    {
        if (strlen($key) <= 12) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 8) . str_repeat('*', strlen($key) - 12) . substr($key, -4);
    }

    /**
     * Get API keys by app ID
     */
    public function getApiKeysByApp(string $appId, string $merchantId): Collection
    {
        return ApiKey::where('merchant_id', $merchantId)
            ->where('app_id', $appId)
            ->where('is_revoked', false)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($apiKey) {
                $data = $apiKey->toArray();
                $data['key'] = $this->maskApiKey($data['key']);
                return $data;
            });
    }

    /**
     * Bulk revoke API keys (e.g., when app is deleted)
     */
    public function bulkRevokeApiKeys(array $apiKeyIds, string $merchantId): int
    {
        $count = 0;
        
        foreach ($apiKeyIds as $apiKeyId) {
            try {
                $this->revokeApiKey($apiKeyId, $merchantId);
                $count++;
            } catch (\Exception $e) {
                // Log error but continue with other keys
                continue;
            }
        }

        return $count;
    }

    /**
     * Clean up expired API keys
     */
    public function cleanupExpiredKeys(): int
    {
        // This would typically be called by a scheduled job
        $expiredKeys = ApiKey::where('expires_at', '<', now())
                            ->where('is_revoked', false)
                            ->get();

        $count = 0;
        foreach ($expiredKeys as $key) {
            ApiKey::updateById($key['id'], [
                'is_revoked' => true,
                'revoked_at' => now(),
                'revocation_reason' => 'expired'
            ]);
            $count++;
        }

        return $count;
    }


     /**
     * Business logic: transform API keys for response while delegating DB interactions to the model.
     */
    public function getAppApiKeysForMerchant(string $appId, string $merchantId): Collection
    {
        $keys = ApiKey::listForAppAndMerchant($appId, $merchantId);

        return $keys->map(function (ApiKey $key) {
            return [
                'id' => $key->id,
                'app_id' => $key->app_id,
                'key_id' => $key->key_id,
                'key_masked' => $key->masked_key, // exposed masked value
                'name' => $key->name,
                'scopes' => $key->scopes ?? [],
                'is_active' => (bool) $key->is_active,
                'last_used_at' => $key->last_used_at,
                'expires_at' => $key->expires_at,
                'created_at' => $key->created_at,
            ];
        });
    }
}