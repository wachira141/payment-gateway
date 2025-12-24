<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Str;

class WalletTopUp extends BaseModel
{
    protected $fillable = [
        'top_up_id',
        'wallet_id',
        'merchant_id',
        'amount',
        'currency',
        'method',
        'status',
        'gateway_type',
        'gateway_reference',
        'gateway_response',
        'bank_reference',
        'payment_instructions',
        'failure_reason',
        'expires_at',
        'completed_at',
        'metadata',
        'payment_transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'gateway_response' => 'array',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($topUp) {
            if (empty($topUp->top_up_id)) {
                $topUp->top_up_id = 'wtu_' . Str::random(24);
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the wallet
     */
    public function wallet()
    {
        return $this->belongsTo(MerchantWallet::class, 'wallet_id');
    }

    /**
     * Get the merchant
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
    /**
     * Get the resulting wallet transaction
     */
    public function walletTransaction()
    {
        return $this->morphOne(WalletTransaction::class, 'source');
    }

    /**
     * Get the payment transaction (from payment gateway)
     */
    public function paymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class);
    }


    // ==================== SCOPES ====================

    /**
     * Scope to pending top-ups
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to completed top-ups
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope by method
     */
    public function scopeByMethod($query, string $method)
    {
        return $query->where('method', $method);
    }

    // ==================== METHODS ====================

    /**
     * Mark as processing
     */
    public function markProcessing(string $gatewayReference = null): bool
    {
        $data = ['status' => 'processing'];

        if ($gatewayReference) {
            $data['gateway_reference'] = $gatewayReference;
        }

        return $this->update($data);
    }

    /**
     * Mark as completed
     */
    public function markCompleted(array $gatewayResponse = null): bool
    {
        $data = [
            'status' => 'completed',
            'completed_at' => now(),
        ];

        if ($gatewayResponse) {
            $data['gateway_response'] = $gatewayResponse;
        }

        return $this->update($data);
    }

    /**
     * Mark as failed
     */
    public function markFailed(string $reason): bool
    {
        return $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Mark as cancelled
     */
    public function markCancelled(): bool
    {
        return $this->update(['status' => 'cancelled']);
    }

    /**
     * Mark as expired
     */
    public function markExpired(): bool
    {
        return $this->update(['status' => 'expired']);
    }

    /**
     * Check if expired
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return now()->greaterThan($this->expires_at);
    }

    /**
     * Check if can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    /**
     * Generate payment instructions based on method
     */
    public function generatePaymentInstructions(): string
    {
        switch ($this->method) {
            case 'bank_transfer':
                return $this->generateBankTransferInstructions();
            case 'mobile_money':
                return $this->generateMobileMoneyInstructions();
            default:
                return "Please complete your payment of {$this->amount} {$this->currency}.";
        }
    }

    /**
     * Generate bank transfer instructions
     */
    protected function generateBankTransferInstructions(): string
    {
        $reference = $this->bank_reference ?? $this->top_up_id;

        return <<<INSTRUCTIONS
Bank Transfer Instructions:
- Amount: {$this->amount} {$this->currency}
- Reference: {$reference}
- Use the reference number exactly as shown above.
- Funds will be credited once the transfer is confirmed.
INSTRUCTIONS;
    }

    /**
     * Generate mobile money instructions
     */
    protected function generateMobileMoneyInstructions(): string
    {
        return <<<INSTRUCTIONS
Mobile Money Top-up:
- Amount: {$this->amount} {$this->currency}
- Follow the prompt on your phone to complete the payment.
INSTRUCTIONS;
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create a top-up
     */
    public static function createTopUp(string $walletId, array $data): self
    {
        $wallet = MerchantWallet::findOrFail($walletId);

        return self::create(array_merge($data, [
            'wallet_id' => $wallet->id,
            'merchant_id' => $wallet->merchant_id,
            'currency' => $wallet->currency,
        ]));
    }

    /**
     * Find by gateway reference
     */
    public static function findByGatewayReference(string $reference): ?self
    {
        return self::where('gateway_reference', $reference)->first();
    }

    /**
     * Find by bank reference
     */
    public static function findByBankReference(string $reference): ?self
    {
        return self::where('bank_reference', $reference)->first();
    }

    /**
     * Find by top_up_id (public ID)
     */
    public static function findByTopUpId(string $topUpId): ?self
    {
        return self::where('top_up_id', $topUpId)->first();
    }

    /**
     * Get pending top-ups that are expired
     */
    public static function getExpiredPending()
    {
        return self::where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();
    }
}
