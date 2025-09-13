<?php

namespace App\Models;

class FXQuote extends BaseModel
{
    protected $table = 'fx_quotes';

    protected $fillable = [
        'id', 'merchant_id', 'from_currency', 'to_currency', 'from_amount',
        'to_amount', 'net_to_amount', 'exchange_rate', 'fee_amount', 'fee_currency',
        'expires_at', 'rate_source', 'status', 'used_at', 'cancelled_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'cancelled_at' => 'datetime'
    ];
}