<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Encryption\Encrypter;
use App\Exceptions\BankAccountException;
use Exception;

class MerchantBankAccount extends BaseModel
{
    protected $fillable = [
        'user_id',
        'account_type',
        'bank_name',
        'account_holder_name',
        'account_number',
        'routing_number',
        'currency',
        'verification_status',
        'is_primary',
        'is_active',
        'metadata',
        'verified_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'account_number' => 'encrypted',
        'routing_number' => 'encrypted',
        'verification_status' => 'string',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = [
        'account_number',
        'routing_number',
    ];

    protected $appends = [
        'masked_account_number',
        'masked_routing_number',
    ];

    /**
     * Encryption key for sensitive fields (optional - for extra security)
     */
    protected static function getEncryptionKey()
    {
        return config('app.bank_account_encryption_key') ?: config('app.key');
    }

    /**
     * Soft delete the bank account
     */
    public function softDelete()
    {
        return $this->delete();
    }

    /**
     * Restore a soft-deleted bank account
     */
    public function restoreAccount()
    {
        return $this->restore();
    }

    /**
     * Get the associated user (provider)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get disbursements for this bank account
     */
    public function disbursements()
    {
        return $this->hasMany(Disbursement::class);
    }

    /**
     * Encrypt account number when storing
     */
    public function setAccountNumberAttribute($value)
    {
        try {
            $this->attributes['account_number'] = Crypt::encryptString($value);
        } catch (\Exception $e) {
            Log::error('Failed to encrypt account number', ['error' => $e->getMessage()]);
            throw new Exception('Failed to process bank account information');
        }
    }

    /**
     * Encrypt routing number when storing
     */
    public function setRoutingNumberAttribute($value)
    {
        try {

            $this->attributes['routing_number'] = Crypt::encryptString($value);
        } catch (\Exception $e) {
            Log::error('Failed to encrypt routing number', ['error' => $e->getMessage()]);
            throw new Exception('Failed to process bank account information');
        }
    }

    /**
     * Decrypt account number when retrieving
     */
    // public function getAccountNumberAttribute($value)
    // {
    //     if (empty($value)) {
    //         return null;
    //     }

    //     try {
    //         $encrypter = new Encrypter(self::getEncryptionKey(), config('app.cipher'));
    //         return $encrypter->decryptString($value);
    //     } catch (\Exception $e) {
    //         Log::error('Failed to decrypt account number', ['error' => $e->getMessage()]);
    //         return null;
    //     }
    // }

    public function getAccountNumberAttribute($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            Log::error('Failed to decrypt account number', [
                'account_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Decrypt routing number when retrieving
     */
    public function getRoutingNumberAttribute($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            Log::error('Failed to decrypt routing number', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get masked account number for display
     */
    public function getMaskedAccountNumberAttribute()
    {
        $accountNumber = $this->account_number;
        if (!$accountNumber) return null;

        return '****' . substr($accountNumber, -4);
    }

    /**
     * Get masked routing number for display
     */
    public function getMaskedRoutingNumberAttribute()
    {
        $routingNumber = $this->routing_number;
        if (!$routingNumber) return null;

        return '****' . substr($routingNumber, -4);
    }

    /**
     * Scope for active accounts
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for verified accounts
     */
    public function scopeVerified($query)
    {
        return $query->where('verification_status', 'verified');
    }

    /**
     * Scope for primary accounts
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope for provider
     */
    public function scopeforMerchant($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Check if account is verified
     */
    public function isVerified()
    {
        return $this->verification_status === 'verified';
    }

    /**
     * Set as primary account
     */
    public function setAsPrimary()
    {
        // First, unset all other accounts as primary
        static::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        // Set this one as primary
        return $this->update(['is_primary' => true]);
    }

    /**
     * Mark account as verified
     */
    public function markAsVerified()
    {
        return $this->update([
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);
    }
    /**
     * Mark account as rejected
     */
    public function markAsRejected()
    {
        return $this->update([
            'verification_status' => 'failed',
            'verified_at' => now(),
        ]);
    }

    /**
     * Deactivate the bank account
     * Instead of deleting, we deactivate for audit purposes
     */
    public function deactivate()
    {
        return $this->update(['is_active' => false]);
    }
    /**
     * Activate the bank account
     * This can be used to reactivate an account if needed
     */
    public function activate()
    {
        return $this->update(['is_active' => true]);
    }

    /**
     * Find a bank account by account ID
     */
    public static function findByAccountId($accountId): MerchantBankAccount
    {
        $bankAccount = MerchantBankAccount::find($accountId);

        if (!$bankAccount) {
            throw new \Exception('Bank account not found');
        }

        return $bankAccount;
    }


    // public static function getProviderAccounts($userId = null, array $with = ['user'])
    // {
    //     $query = static::with($with);

    //     // Only filter by user if userId is provided
    //     if ($userId !== null) {
    //         $query->forProvider($userId);
    //     }

    //     return $query->orderBy('is_primary', 'desc')
    //         ->orderBy('created_at', 'desc')
    //         ->get();
    // }

    public static function getMerchantAccounts(
        $userId = null, 
        int $page = 1,
        int $perPage = 50,
        array $with = ['user', 'user.serviceProvider']
    ): array {
        $query = static::with($with);
        // Only filter by user if userId is provided
        if ($userId !== null) {
            $query->forMerchant($userId);
        }
    
        $paginatedResults = $query->orderBy('is_primary', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    
        return [
            'data' => collect($paginatedResults->items()),
            'pagination' => [
                'total' => $paginatedResults->total(),
                'currentPage' => $paginatedResults->currentPage(),
                'perPage' => $paginatedResults->perPage(),
                'lastPage' => $paginatedResults->lastPage(),
                'from' => $paginatedResults->firstItem(),
                'to' => $paginatedResults->lastItem(),
            ]
        ];
    }

    /**
     * Get the primary account for a provider
     */
    public static function getPrimaryAccount($userId)
    {
        return static::forProvider($userId)
            ->active()
            ->primary()
            ->first();
    }

    /**
     * Validate bank account details before saving
     */
    public static function validateAccountDetails(array $data)
    {
        // Implement validation logic based on country/region
        // Example: Validate routing number format for US banks
        if (isset($data['routing_number']) && $data['currency'] === 'USD') {
            if (!preg_match('/^\d{9}$/', $data['routing_number'])) {
                throw new Exception('Invalid routing number format');
            }
        }

        return true;
    }

    /**
     * Create a new bank account with validation
     */
    public static function createAccount(array $data)
    {
        self::validateAccountDetails($data);

        return static::create($data);
    }
}
