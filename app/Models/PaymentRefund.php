<?php

namespace App\Models;

use App\Models\BaseModel;

class PaymentRefund extends BaseModel
{
    protected $fillable = [
        'refund_id',
        'payment_transaction_id',
        'user_id',
        'gateway_refund_id',
        'amount',
        'currency',
        'status',
        'reason',
        'description',
        'metadata',
        'gateway_response',
        'failure_reason',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'gateway_response' => 'array',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Get the associated payment transaction
     */
    public function paymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /**
     * Get the associated user
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if refund is completed
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * Check if refund failed
     */
    public function isFailed()
    {
        return $this->status === 'failed';
    }
}
