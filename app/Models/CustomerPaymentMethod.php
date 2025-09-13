<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerPaymentMethod extends BaseModel
{
    protected $fillable = [
        'customer_id',
        'type',
        'token',
        'metadata',
        'is_default',
        'verified_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_default' => 'boolean',
        'verified_at' => 'datetime'
    ];

    /**
     * Get the customer that owns this payment method.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($paymentMethod) {
            if (empty($paymentMethod->token)) {
                $paymentMethod->token = 'pm_' . Str::random(24);
            }
        });

        // When setting a payment method as default, unset others
        static::saving(function ($paymentMethod) {
            if ($paymentMethod->is_default && $paymentMethod->isDirty('is_default')) {
                self::where('customer_id', $paymentMethod->customer_id)
                    ->where('id', '!=', $paymentMethod->id)
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * Create a payment method from payment data.
     */
    public static function createFromPaymentData(string $customerId, array $paymentMethodData): self
    {
        $type = self::determineTypeFromPaymentData($paymentMethodData);
        $metadata = self::extractMetadataFromPaymentData($paymentMethodData, $type);

        return self::create([
            'customer_id' => $customerId,
            'type' => $type,
            'metadata' => $metadata,
            'is_default' => false,
            'verified_at' => now() // Assume verified for now
        ]);
    }

    /**
     * Determine payment method type from payment data.
     */
    private static function determineTypeFromPaymentData(array $paymentMethodData): string
    {
        if (isset($paymentMethodData['card'])) {
            return 'card';
        } elseif (isset($paymentMethodData['mobile_money']) || isset($paymentMethodData['mpesa'])) {
            return 'mobile_money';
        } elseif (isset($paymentMethodData['bank_account'])) {
            return 'bank_account';
        }

        return 'card'; // Default fallback
    }

    /**
     * Extract metadata from payment data (masked for security).
     */
    private static function extractMetadataFromPaymentData(array $paymentMethodData, string $type): array
    {
        switch ($type) {
            case 'card':
                return [
                    'last4' => $paymentMethodData['card']['last4'] ?? '****',
                    'brand' => $paymentMethodData['card']['brand'] ?? 'unknown',
                    'exp_month' => $paymentMethodData['card']['exp_month'] ?? null,
                    'exp_year' => $paymentMethodData['card']['exp_year'] ?? null
                ];

            case 'mobile_money':
                $phone = $paymentMethodData['mobile_money']['phone'] ?? $paymentMethodData['mpesa']['phone'] ?? '';
                return [
                    'phone' => substr($phone, 0, 3) . '****' . substr($phone, -2), // Mask phone
                    'provider' => $paymentMethodData['mobile_money']['provider'] ?? 'mpesa'
                ];

            case 'bank_account':
                return [
                    'last4' => $paymentMethodData['bank_account']['last4'] ?? '****',
                    'bank_name' => $paymentMethodData['bank_account']['bank_name'] ?? 'unknown'
                ];

            default:
                return [];
        }
    }

    /**
     * Check if this payment method is verified.
     */
    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    /**
     * Mark this payment method as verified.
     */
    public function markAsVerified(): self
    {
        $this->update(['verified_at' => now()]);
        return $this;
    }
}
