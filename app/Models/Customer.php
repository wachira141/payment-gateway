<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends BaseModel
{
    protected $fillable = [
        'merchant_id',
        'external_id',
        'email',
        'phone',
        'name',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];

    /**
     * Get the merchant that owns the customer.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the payment intents for this customer.
     */
    public function paymentIntents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class);
    }

    /**
     * Get the payment methods for this customer.
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(CustomerPaymentMethod::class);
    }

    /**
     * Get the default payment method for this customer.
     */
    public function defaultPaymentMethod()
    {
        return $this->paymentMethods()->where('is_default', true)->first();
    }

    /**
     * Find a customer by email for a specific merchant.
     */
    public static function findByEmailAndMerchant(string $email, string $merchantId): ?self
    {
        return self::where('email', $email)
                   ->where('merchant_id', $merchantId)
                   ->first();
    }

    /**
     * Find a customer by phone for a specific merchant.
     */
    public static function findByPhoneAndMerchant(string $phone, string $merchantId): ?self
    {
        return self::where('phone', $phone)
                   ->where('merchant_id', $merchantId)
                   ->first();
    }

    /**
     * Find a customer by external ID for a specific merchant.
     */
    public static function findByExternalIdAndMerchant(string $externalId, string $merchantId): ?self
    {
        return self::where('external_id', $externalId)
                   ->where('merchant_id', $merchantId)
                   ->first();
    }

    /**
     * Scope query to specific merchant.
     */
    public function scopeForMerchant($query, string $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    /**
     * Scope query to search by term across multiple fields.
     */
    public function scopeSearchByTerm($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%")
              ->orWhere('phone', 'like', "%{$term}%")
              ->orWhere('external_id', 'like', "%{$term}%");
        });
    }

    /**
     * Scope query to customers with payment methods.
     */
    public function scopeWithPaymentMethods($query)
    {
        return $query->has('paymentMethods');
    }

    /**
     * Scope query to active customers (have payment intents).
     */
    public function scopeActive($query)
    {
        return $query->has('paymentIntents');
    }

    /**
     * Update customer data with proper validation.
     */
    public function updateCustomerData(array $data): bool
    {
        $fillableData = array_intersect_key($data, array_flip($this->fillable));
        return $this->update($fillableData);
    }

    /**
     * Get customer's total spent amount.
     */
    public function getTotalSpentAttribute(): float
    {
        return $this->paymentIntents()
                   ->where('status', 'succeeded')
                   ->sum('amount');
    }

    /**
     * Get customer's payment success rate.
     */
    public function getPaymentSuccessRateAttribute(): float
    {
        $total = $this->paymentIntents()->count();
        if ($total === 0) return 0;
        
        $successful = $this->paymentIntents()->where('status', 'succeeded')->count();
        return round(($successful / $total) * 100, 2);
    }

    /**
     * Create or find customer for merchant.
     */
    public static function findOrCreate(string $merchantId, array $customerData): self
    {
        // Try to find by email first, then phone, then external_id
        $customer = null;
        
        if (!empty($customerData['email'])) {
            $customer = self::findByEmailAndMerchant($customerData['email'], $merchantId);
        }
        
        if (!$customer && !empty($customerData['phone'])) {
            $customer = self::findByPhoneAndMerchant($customerData['phone'], $merchantId);
        }
        
        if (!$customer && !empty($customerData['external_id'])) {
            $customer = self::findByExternalIdAndMerchant($customerData['external_id'], $merchantId);
        }
        
        if (!$customer) {
            $customer = self::create([
                'merchant_id' => $merchantId,
                'external_id' => $customerData['external_id'] ?? null,
                'email' => $customerData['email'] ?? null,
                'phone' => $customerData['phone'] ?? null,
                'name' => $customerData['name'],
                'metadata' => $customerData['metadata'] ?? null
            ]);
        } else {
            // Update customer with any new information
            $customer->updateCustomerData($customerData);
        }
        
        return $customer;
    }
}