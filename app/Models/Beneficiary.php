<?php

namespace App\Models;

class Beneficiary extends BaseModel
{
    protected $table = 'beneficiaries';

    protected $fillable = [
        'beneficiary_id',
        'merchant_id',
        'type',
        'name',
        'account_number',
        'bank_code',
        'bank_name',
        'mobile_number',
        'currency',
        'country',
        'is_default',
        'is_verified',
        'metadata',
        'status',
        'verified_at',
        'verification_details',
        'verification_failure_reason'
    ];

    protected $casts = [
        'metadata' => 'array',
        'verification_details' => 'array',
        'is_default' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime'
    ];

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
    public static function getForMerchant(string $merchantId, array $filters = []): array
    {
        $query = static::where('merchant_id', $merchantId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $query->orderBy('created_at', 'desc');

        if (!empty($filters['limit'])) {
            $query->limit($filters['limit']);
        }

        if (!empty($filters['offset'])) {
            $query->offset($filters['offset']);
        }

        return $query->get()->toArray();
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
