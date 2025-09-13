<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Str;

class Charge extends BaseModel
{
    protected $fillable = [
        'charge_id',
        'payment_intent_id',
        'merchant_id',
        'amount',
        'currency',
        'status',
        'payment_method_type',
        'payment_method_details',
        'payment_method_data', // Add for confirm method
        'connector_name',
        'connector_charge_id',
        'connector_response',
        'failure_code',
        'failure_message',
        'fee_amount',
        'receipt_number',
        'risk_score',
        'captured',
        'captured_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'fee_amount' => 'decimal:4',
        'payment_method_details' => 'array',
        'payment_method_data' => 'array', // Add for confirm method
        'connector_response' => 'array',
        'risk_score' => 'array',
        'captured' => 'boolean',
        'captured_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($charge) {
            if (empty($charge->charge_id)) {
                $charge->charge_id = 'ch_' . Str::random(24);
            }
        });
    }

    /**
     * Get the payment intent
     */
    public function paymentIntent()
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    /**
     * Get the merchant
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get refunds
     */
    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Get ledger entries
     */
    public function ledgerEntries()
    {
        return $this->morphMany(LedgerEntry::class, 'related');
    }

    /**
     * Mark charge as succeeded
     */
    public function markAsSucceeded($connectorChargeId = null, $connectorResponse = [])
    {
        $this->update([
            'status' => 'succeeded',
            'connector_charge_id' => $connectorChargeId,
            'connector_response' => $connectorResponse,
            'captured' => $this->paymentIntent->capture_method === 'automatic',
            'captured_at' => $this->paymentIntent->capture_method === 'automatic' ? now() : null,
        ]);

        // Mark payment intent as succeeded
        $this->paymentIntent->markAsSucceeded();
    }

    /**
     * Mark charge as failed
     */
    public function markAsFailed($failureCode, $failureMessage = null, $connectorResponse = [])
    {
        $this->update([
            'status' => 'failed',
            'failure_code' => $failureCode,
            'failure_message' => $failureMessage,
            'connector_response' => $connectorResponse,
        ]);
    }

    /**
     * Capture charge
     */
    public function capture()
    {
        if ($this->status !== 'succeeded') {
            throw new \Exception('Only succeeded charges can be captured');
        }

        if ($this->captured) {
            throw new \Exception('Charge already captured');
        }

        $this->update([
            'captured' => true,
            'captured_at' => now(),
        ]);
    }

    /**
     * Check if charge can be refunded
     */
    public function canBeRefunded()
    {
        return $this->status === 'succeeded' && $this->captured;
    }

    /**
     * Get refunded amount
     */
    public function getRefundedAmount()
    {
        return $this->refunds()->where('status', 'succeeded')->sum('amount');
    }

    /**
     * Get remaining refundable amount
     */
    public function getRemainingRefundableAmount()
    {
        return $this->amount - $this->getRefundedAmount();
    }

    /**
     * Get net amount (amount - fees)
     */
    public function getNetAmount()
    {
        return $this->amount - $this->fee_amount;
    }
}