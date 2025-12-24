<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Str;

class Merchant extends BaseModel
{

    protected $fillable = [
        'merchant_id',
        'legal_name',
        'display_name',
        'business_type',
        'country_code',
        'default_currency',
        'status',
        'compliance_status',
        'website',
        'business_description',
        'business_address',
        'tax_id',
        'registration_number',
        'metadata',
        'approved_at',
        'suspended_at',
    ];

    protected $casts = [
        'business_address' => 'array',
        'metadata' => 'array',
        'approved_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            $model->merchant_id ??= 'merch_' . Str::random(16);
        });
    }
    


    /**
     * Get merchant users
     */
    public function users()
    {
        return $this->hasMany(MerchantUser::class);
    }

    /**
     * Get merchant apps
     */
    public function apps()
    {
        return $this->hasMany(App::class);
    }

    /**
     * Get merchant balances
     */
    public function balances()
    {
        return $this->hasMany(MerchantBalance::class);
    }

    /**
     * Get payment intents
     */
    public function paymentIntents()
    {
        return $this->hasMany(PaymentIntent::class);
    }

    /**
     * Get charges
     */
    public function charges()
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * Get ledger entries
     */
    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Check if merchant is active and approved
     */
    public function isActive()
    {
        return $this->status === 'active' && $this->compliance_status === 'approved';
    }

    /**
     * Get balance for currency
     */
    public function getBalance($currency)
    {
        return $this->balances()->where('currency', $currency)->first();
    }

    /**
     * Get customers
     */
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Create merchant with initial setup
     */
    public static function createWithDefaults($data)
    {
        $merchant = static::create($data);

        // Create default balance for merchant's default currency
        $merchant->balances()->create([
            'currency' => $merchant->default_currency,
        ]);

        return $merchant;
    }


    /**
     * Is live enabled
     */
    public function isLiveEnabled()
    {
        // For simplicity, assume all active merchants have live enabled
        return $this->isActive();
    }
}
