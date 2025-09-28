<?php

namespace App\Models;

class Beneficiary extends BaseModel
{
    protected $table = 'beneficiaries';

    protected $fillable = [
        'beneficiary_id',
        'merchant_id',
        'payout_method_id',
        'name',
        'currency',
        'country',
        'is_default',
        'is_verified',
        'dynamic_fields',
        'metadata',
        'status',
        'verified_at',
        'verification_details',
        'verification_failure_reason'
    ];

    protected $casts = [
        'dynamic_fields' => 'array',
        'metadata' => 'array',
        'verification_details' => 'array',
        'is_default' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime'
    ];

    /**
     * Relationship to supported payout method
     */
    public function payoutMethod()
    {
        return $this->belongsTo(SupportedPayoutMethod::class, 'payout_method_id');
    }

    /**
     * Get dynamic field value
     */
    public function getDynamicField(string $fieldName): mixed
    {
        return $this->dynamic_fields[$fieldName] ?? null;
    }

    /**
     * Set dynamic field value
     */
    public function setDynamicField(string $fieldName, mixed $value): void
    {
        $dynamicFields = $this->dynamic_fields ?? [];
        $dynamicFields[$fieldName] = $value;
        $this->dynamic_fields = $dynamicFields;
    }

    /**
     * Get all dynamic fields as array
     */
    public function getAllDynamicFields(): array
    {
        return $this->dynamic_fields ?? [];
    }

    /**
     * Find beneficiary by ID
     */
    public static function findById(string $beneficiaryId): ?array
    {
        $beneficiary = static::where('id', $beneficiaryId)->first();
        return $beneficiary ? $beneficiary->toArray() : null;
    }

    /**
     * Find beneficiary by ID and merchant
     */
    public static function findByIdAndMerchant(string $beneficiaryId, string $merchantId): ?array
    {
        $beneficiary = static::where('id', $beneficiaryId)
            ->where('merchant_id', $merchantId)
            ->first();
        return $beneficiary ? $beneficiary->toArray() : null;
    }

    /**
     * Update beneficiary by ID
     */
    public static function updateById(string $beneficiaryId, array $data): ?array
    {
        $updated = static::where('id', $beneficiaryId)->update($data);
        if ($updated) {
            return static::findById($beneficiaryId);
        }
        return null;
    }

    /**
     * Delete beneficiary by ID and merchant
     */
    public static function deleteByIdAndMerchant(string $beneficiaryId, string $merchantId): bool
    {
        return static::where('id', $beneficiaryId)
            ->where('merchant_id', $merchantId)
            ->delete() > 0;
    }

    /**
     * Get beneficiaries for merchant
     */
    public static function getForMerchant(string $merchantId, array $filters = [])
    {
        $query = static::where('merchant_id', $merchantId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        if (!empty($filters['type'])) {
            $query->whereHas('payoutMethod', function ($q) use ($filters) {
                $q->where('type', $filters['type']);
            });
        }

        $query->orderBy('created_at', 'desc');

        $perPage = $filters['limit'] ?? 15;
        return $query->paginate($perPage);
    }

    /**
     * Set as default beneficiary for merchant and currency
     */
    public static function setAsDefault(string $beneficiaryId, string $merchantId): bool
    {
        $beneficiary = static::where('beneficiary_id', $beneficiaryId)
            ->where('merchant_id', $merchantId)
            ->first();

        if (!$beneficiary) {
            return false;
        }

        // Remove default from other beneficiaries with same currency
        static::where('merchant_id', $merchantId)
            ->where('currency', $beneficiary->currency)
            ->where('beneficiary_id', '!=', $beneficiaryId)
            ->update(['is_default' => false]);

        // Set this one as default
        return static::where('beneficiary_id', $beneficiaryId)->update(['is_default' => true]) > 0;
    }
}
