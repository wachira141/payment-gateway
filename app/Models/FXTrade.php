<?php

namespace App\Models;

class FXTrade extends BaseModel
{
    protected $table = 'fx_trades';

    protected $fillable = [
        'id', 'merchant_id', 'quote_id', 'from_currency', 'to_currency',
        'from_amount', 'to_amount', 'net_to_amount', 'exchange_rate',
        'fee_amount', 'fee_currency', 'status', 'executed_at', 'completed_at',
        'failure_reason'
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'completed_at' => 'datetime'
    ];
}