<?php

namespace App\Models;

class Refund extends BaseModel
{
    protected $table = 'refunds';

    protected $fillable = [
        'id',
        'merchant_id',
        'charge_id',
        'payment_intent_id',
        'amount',
        'currency',
        'status',
        'reason',
        'metadata',
        'refund_application_fee',
        'reverse_transfer',
        'processed_at',
        'failure_reason',
        'settlement_id',
        'settled_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'refund_application_fee' => 'boolean',
        'reverse_transfer' => 'boolean',
        'processed_at' => 'datetime',
        'settled_at' => 'datetime'
    ];

    public static function getByChargeId(string $chargeId)
    {
        return static::where('charge_id', $chargeId)->get();
    }

    public static function getUnsettledForMerchant(string $merchantId, string $currency)
    {
        return static::where('merchant_id', $merchantId)
            ->where('currency', $currency)
            ->where('status', 'succeeded')
            ->whereNull('settlement_id')
            ->get();
    }
}
