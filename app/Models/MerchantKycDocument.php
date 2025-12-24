<?php

namespace App\Models;

use App\Models\BaseModel;

class MerchantKycDocument extends BaseModel
{
    protected $table = 'merchant_kyc_documents';

    protected $fillable = [
        'merchant_id',
        'document_type',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'side',
        'status',
        'verification_notes',
        'verification_data',
        'extracted_data',
        'verified_by',
        'verified_at',
        'expires_at',
        'uploaded_by',
    ];

    protected $casts = [
        'verification_data' => 'array',
        'extracted_data' => 'array',
        'file_size' => 'integer',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    /**
     * Get merchant relationship
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get verifier relationship
     */
    public function verifier()
    {
        return $this->belongsTo(MerchantUser::class, 'verified_by');
    }

    /**
     * Get uploader relationship
     */
    public function uploader()
    {
        return $this->belongsTo(MerchantUser::class, 'uploaded_by');
    }

    /**
     * Get document type configuration
     */
    public function getDocumentTypeConfig(): ?KycDocumentType
    {
        return KycDocumentType::getByKey(
            $this->merchant->country_code,
            $this->document_type
        );
    }

    /**
     * Check if document is verified
     */
    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    /**
     * Check if document is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if document is rejected
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if document is expired
     */
    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return true;
        }

        return false;
    }

    /**
     * Mark document as verified
     */
    public function markVerified(string $verifiedBy, ?string $notes = null, ?array $verificationData = null): bool
    {
        return $this->update([
            'status' => self::STATUS_VERIFIED,
            'verified_by' => $verifiedBy,
            'verified_at' => now(),
            'verification_notes' => $notes,
            'verification_data' => $verificationData,
        ]);
    }

    /**
     * Mark document as rejected
     */
    public function markRejected(string $verifiedBy, string $reason): bool
    {
        return $this->update([
            'status' => self::STATUS_REJECTED,
            'verified_by' => $verifiedBy,
            'verified_at' => now(),
            'verification_notes' => $reason,
        ]);
    }

    /**
     * Mark document as expired
     */
    public function markExpired(): bool
    {
        return $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);
    }

    /**
     * Get documents for a merchant
     */
    public static function getForMerchant(string $merchantId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('merchant_id', $merchantId)
            ->orderBy('document_type')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get latest document of type for merchant
     */
    public static function getLatestForMerchant(string $merchantId, string $documentType, string $side = 'front'): ?self
    {
        return static::where('merchant_id', $merchantId)
            ->where('document_type', $documentType)
            ->where('side', $side)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Get verified documents for merchant
     */
    public static function getVerifiedForMerchant(string $merchantId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('merchant_id', $merchantId)
            ->where('status', self::STATUS_VERIFIED)
            ->get();
    }

    /**
     * Scope for pending documents
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for verified documents
     */
    public function scopeVerified($query)
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }
}
