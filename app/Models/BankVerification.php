<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class BankVerification extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bank_account_id',
        'email',
        'verification_code',
        'expires_at',
        'verified_at',
        'attempts',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantBankAccount::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    /**
     * Store verification data in cache
     */
    public static function storeInCache(string $userId, string $bankAccountId, array $data): void
    {
        $cacheKey = self::getCacheKey($userId, $bankAccountId);
        Cache::put($cacheKey, $data, now()->addMinutes(15));
    }

    /**
     * Retrieve verification data from cache
     */
    public static function getFromCache(string $userId, string $bankAccountId): ?array
    {
        $cacheKey = self::getCacheKey($userId, $bankAccountId);
        return Cache::get($cacheKey);
    }

    /**
     * Remove verification data from cache
     */
    public static function forgetFromCache(string $userId, string $bankAccountId): void
    {
        $cacheKey = self::getCacheKey($userId, $bankAccountId);
        Cache::forget($cacheKey);
    }

    /**
     * Generate cache key
     */
    private static function getCacheKey(string $userId, string $bankAccountId): string
    {
        return "bank_verification_{$userId}_{$bankAccountId}";
    }

    /**
     * Create a new verification record
     */
    public static function createVerification(array $data): self
    {
        return self::create([
            'user_id' => $data['user_id'],
            'bank_account_id' => $data['bank_account_id'],
            'email' => $data['email'],
            'verification_code' => $data['verification_code'],
            'expires_at' => $data['expires_at'],
            'attempts' => $data['attempts'] ?? 0,
        ]);
    }

    /**
     * Find the latest unverified verification for a user and bank account
     */
    public static function findLatestUnverified(string $userId, string $bankAccountId): ?self
    {
        return self::where('user_id', $userId)
            ->where('bank_account_id', $bankAccountId)
            ->whereNull('verified_at')
            ->latest()
            ->first();
    }

    /**
     * Mark verification as verified
     */
    public function markAsVerified(): bool
    {
        return $this->update(['verified_at' => now()]);
    }

    /**
     * Increment attempt count
     */
    public function incrementAttempts(): bool
    {
        return $this->update(['attempts' => $this->attempts + 1]);
    }

    /**
     * Check if verification has too many attempts
     */
    public function hasTooManyAttempts(): bool
    {
        return $this->attempts >= 3;
    }
}