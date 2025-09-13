<?php

namespace App\Models;

use App\Models\BaseModel;

class PaymentMethod extends BaseModel
{
    protected $fillable = [
        'user_id',
        'payment_gateway_id',
        'gateway_payment_method_id',
        'type',
        'provider',
        'last_four',
        'expiry_month',
        'expiry_year',
        'brand',
        'country',
        'is_default',
        'is_active',
        'metadata',
        'expires_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the associated user
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the associated payment gateway
     */
    public function paymentGateway()
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    /**
     * Scope to get active payment methods
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get default payment method
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Check if payment method is expired
     */
    public function isExpired()
    {
        if (!$this->expires_at) {
            return false;
        }
        
        return $this->expires_at < now();
    }

    /**
     * Get display name for payment method
     */
    public function getDisplayNameAttribute()
    {
        switch ($this->type) {
            case 'card':
                return "{$this->brand} •••• {$this->last_four}";
            case 'mobile_money':
                return "{$this->provider} {$this->last_four}";
            default:
                return $this->provider ?: $this->type;
        }
    }

    /**
     * Get user's payment methods
     */
    public static function getUserPaymentMethods($userId)
    {
        return static::where('user_id', $userId)
                    ->active()
                    ->with('paymentGateway')
                    ->orderBy('is_default', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->get();
    }

    /**
     * Create a new payment method
     */
    public static function createPaymentMethod(array $data)
    {
        return static::create($data);
    }

    /**
     * Set payment method as default
     */
    public function setAsDefault()
    {
        // First, unset all other payment methods as default
        static::where('user_id', $this->user_id)
              ->where('id', '!=', $this->id)
              ->update(['is_default' => false]);

        // Set this one as default
        return $this->update(['is_default' => true]);
    }

    /**
     * Deactivate payment method
     */
    public function deactivate()
    {
        $wasDefault = $this->is_default;
        $this->update(['is_active' => false]);

        // If this was the default, set another one as default
        if ($wasDefault) {
            $newDefault = static::where('user_id', $this->user_id)
                               ->where('id', '!=', $this->id)
                               ->active()
                               ->first();

            if ($newDefault) {
                $newDefault->setAsDefault();
            }
        }

        return true;
    }

    /**
     * Find payment method by user and ID
     */
    public static function findByUserAndId($userId, $id)
    {
        return static::where('id', $id)
                    ->where('user_id', $userId)
                    ->first();
    }
}
