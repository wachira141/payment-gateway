<?php

namespace App\Services;

use App\Models\MerchantBankAccount;
use App\Models\BankVerification;
use App\Models\Merchant;
use App\Services\Email\EmailService;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;

class BankAccountService
{
    protected EmailService $emailService;

    /**
     * 
     * ProviderPayoutService constructor.
     *
     * @param EmailService $emailService
     */
    public function __construct(
        EmailService $emailService
    ) {
        $this->emailService = $emailService;
    }

    /**
     * retrieve bank account by bank id
     * @param string $bankId
     * 
     */
    public function getBankAccountById(string $bankId) : MerchantBankAccount|null
    {
        return MerchantBankAccount::findByAccountId($bankId);
    }

    /**
     * Create bank account for provider
     */
    public function createBankAccount($userId, array $data)
    {
        // Validate routing number
        if (!$this->validateRoutingNumber($data['routing_number'])) {
            throw new \Exception('Invalid routing number');
        }

        $bankAccount = MerchantBankAccount::create([
            'user_id' => $userId,
            'account_type' => $data['account_type'],
            'bank_name' => $data['bank_name'],
            'account_holder_name' => $data['account_holder_name'],
            'account_number' => $data['account_number'],
            'routing_number' => $data['routing_number'],
            'currency' => $data['currency'] ?? 'USD',
            'is_primary' => $data['is_primary'] ?? false,
        ]);

        // If this is set as primary, update others
        if ($data['is_primary'] ?? false) {
            $bankAccount->setAsPrimary();
        }

        Log::info("Created bank account for provider {$userId}");

        return $bankAccount;
    }

    /**
     * Update bank account
     */
    public function updateBankAccount($userId, $accountId, array $data)
    {
        $bankAccount = MerchantBankAccount::where('id', $accountId)
            ->where('user_id', $userId)
            ->first();

        if (!$bankAccount) {
            throw new \Exception('Bank account not found');
        }

        // If routing number is being changed, validate it
        if (isset($data['routing_number']) && !$this->validateRoutingNumber($data['routing_number'])) {
            throw new \Exception('Invalid routing number');
        }

        $bankAccount->update($data);

        // If setting as primary
        if (isset($data['is_primary']) && $data['is_primary']) {
            $bankAccount->setAsPrimary();
        }

        Log::info("Updated bank account {$accountId} for provider {$userId}");

        return $bankAccount;
    }

    /**
     * Delete/deactivate bank account
     */
    public function deleteBankAccount($userId, $accountId)
    {
        $bankAccount = MerchantBankAccount::where('id', $accountId)
            ->where('user_id', $userId)
            ->first();

        if (!$bankAccount) {
            throw new \Exception('Bank account not found');
        }

        // delete the bank account, we are using soft delete
        $bankAccount->delete();

        // If this was primary, set another as primary
        if ($bankAccount->is_primary) {
            $newPrimary = MerchantBankAccount::where('user_id', $userId)
                ->where('id', '!=', $accountId)
                ->active()
                ->first();

            if ($newPrimary) {
                $newPrimary->setAsPrimary();
            }
        }

        Log::info("Deactivated bank account {$accountId} for provider {$userId}");

        return true;
    }

    /**
     * Verify bank account
     */
    public function verifyBankAccount(string $accountId, string $userId, string $status, ?string $notes)
    {
        $bankAccount = MerchantBankAccount::where('id', $accountId)
            ->where('user_id', $userId)
            ->first();

        if (!$bankAccount) {
            throw new \Exception('Bank account not found');
        }

        // In a real implementation, this would integrate with a bank verification service
        // For now, we'll mark as verified
        if ($status === 'failed') {
            $bankAccount->markAsRejected();
            Log::info("Rejected bank account {$accountId} for provider {$userId}: {$notes}");
            return $bankAccount;
        } else {
            $bankAccount->markAsVerified();

            Log::info("Verified bank account {$accountId} for provider {$userId}");
        }

        return $bankAccount;
    }

    /**
     * Activate deactivated bank account
     * Instead of deleting, we deactivate for audit purposes
     */
    public function activateBankAccount(string $accountId, string $status)
    {
        $bankAccount = MerchantBankAccount::where('id', $accountId)
            ->first();

        if (!$bankAccount) {
            throw new \Exception('Bank account not found');
        }

        if ($status) {
            $bankAccount->activate();
        } else {
            $bankAccount->deactivate();
        }
        Log::info("Bank account {$accountId} has been {$status}");
        return $bankAccount; // Return the updated account
        // Reactivate the bank account
    }

    /**
     * Get provider bank accounts
     */
    public function getMerchantBankAccounts(?string $userId, $page, $perPage)
    {
        return MerchantBankAccount::getProviderAccounts($userId, $page, $perPage);
    }

    /**
     * Validate routing number using basic checksum
     */
    private function validateRoutingNumber($routingNumber)
    {
        // Remove any non-digits
        $routingNumber = preg_replace('/\D/', '', $routingNumber);

        // Must be exactly 9 digits
        if (strlen($routingNumber) !== 9) {
            return false;
        }

        // ABA routing number checksum validation
        $checksum = 0;
        for ($i = 0; $i < 9; $i++) {
            $digit = (int) $routingNumber[$i];
            if ($i % 3 === 0) {
                $checksum += $digit * 3;
            } elseif ($i % 3 === 1) {
                $checksum += $digit * 7;
            } else {
                $checksum += $digit;
            }
        }

        return $checksum % 10 === 0;
    }

    /**
     * Initiate micro-deposits for verification
     *
     * @param string $accountId
     * @return MerchantBankAccount
     * @throws BankAccountException
     */
    public function initiateMicroDeposits(string $accountId)
    {
        $account = MerchantBankAccount::find($accountId);

        if (!$account) {
            throw new Exception('Bank account not found');
        }

        if ($account->isVerified()) {
            throw new Exception('Account is already verified');
        }

        // Generate random micro-deposit amounts
        $amount1 = rand(1, 99) / 100; // $0.01 to $0.99
        $amount2 = rand(1, 99) / 100; // $0.01 to $0.99

        // In a real app, you would actually send these to the bank
        // $this->bankApi->sendMicroDeposits($account, $amount1, $amount2);

        // Store expected amounts in metadata
        $account->metadata = array_merge($account->metadata ?? [], [
            'micro_deposit_1' => $amount1,
            'micro_deposit_2' => $amount2,
            'micro_deposits_initiated_at' => now()->toDateTimeString()
        ]);

        $account->save();

        return $account;
    }


    // Add these methods to your existing ProviderPayoutService class

    /**
     * Send bank account verification code to user's email
     */
    public function sendBankVerificationCode(User $user, string $bankAccountId, string $email): array
    {
        // Generate a 4-digit verification code
        $verificationCode = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // Prepare data for cache and database
        $verificationData = [
            'code' => $verificationCode,
            'email' => $email,
            'bank_account_id' => $bankAccountId,
            'attempts' => 0,
            'created_at' => now()->toISOString()
        ];

        // Store verification code in cache with 15-minute expiration
        BankVerification::storeInCache($user->id, $bankAccountId, $verificationData);

        // Store in database for audit trail
        BankVerification::createVerification([
            'user_id' => $user->id,
            'bank_account_id' => $bankAccountId,
            'email' => $email,
            'verification_code' => $verificationCode,
            'expires_at' => now()->addMinutes(15),
            'attempts' => 0,
        ]);

        // Get bank account details for the email
        $bankAccount = MerchantBankAccount::find($bankAccountId);

        if (!$bankAccount || $bankAccount->user_id !== $user->id) {
            return [
                'success' => false,
                'message' => 'Bank account not found or access denied.'
            ];
        }

        // Send email with verification code
        try {
            $success = $this->emailService->sendEmail('bank', [
                'template' => 'bank_verification',
                'recipient' => $email,
                'subject' => 'Bank Account Verification',
                'user_id' => $user->id,
                'variables' => [
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'verification_code' => $verificationCode,
                    'bank_name' => $bankAccount->bank_name,
                    'account_holder_name' => $bankAccount->account_holder_name,
                    'masked_account_number' => $bankAccount->masked_account_number,
                    'expires_in_minutes' => 15,
                    'expires_at' => now()->addMinutes(15)->format('M j, Y \a\t g:i A T'),
                    'app_name' => config('app.name'),
                    'support_email' => config('mail.support_address', config('mail.from.address')),
                    'request_ip' => request()->ip(),
                    'request_time' => now()->format('M j, Y \a\t g:i A T'),
                ],
                'priority' => 'high',
                'queue' => false,
                'tags' => ['bank_verification', 'authentication'],
                'reply_to' => config('mail.support_address'),
            ]);

            if (!$success) {
                throw new \Exception('Email service returned failure');
            }

            return [
                'success' => true,
                'message' => 'Verification code sent to your email address.',
                'expires_at' => now()->addMinutes(15)->toISOString()
            ];
        } catch (\Exception $e) {
            // Log the error
            Log::error('Failed to send bank verification email', [
                'user_id' => $user->id,
                'bank_account_id' => $bankAccountId,
                'email' => $email,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to send verification email. Please try again.'
            ];
        }
    }

    /**
     * Verify bank account verification code
     */
    public function verifyBankCode(User $user, string $bankAccountId, string $verificationCode): array
    {
        $storedData = BankVerification::getFromCache($user->id, $bankAccountId);

        if (!$storedData) {
            return [
                'success' => false,
                'message' => 'Verification code has expired or does not exist. Please request a new code.'
            ];
        }

        // Check for too many attempts (rate limiting)
        if ($storedData['attempts'] >= 3) {
            BankVerification::forgetFromCache($user->id, $bankAccountId);
            return [
                'success' => false,
                'message' => 'Too many failed attempts. Please request a new verification code.'
            ];
        }

        // Verify the code
        if ($storedData['code'] !== $verificationCode) {
            // Increment attempts
            $storedData['attempts']++;
            BankVerification::storeInCache($user->id, $bankAccountId, $storedData);

            return [
                'success' => false,
                'message' => 'Invalid verification code. Please try again.'
            ];
        }

        // Code is correct - mark as verified
        BankVerification::forgetFromCache($user->id, $bankAccountId);

        // Update database record
        $verification = BankVerification::findLatestUnverified($user->id, $bankAccountId);
        if ($verification) {
            $verification->markAsVerified();
        }

        // Mark bank account as verified
        $this->markBankAccountAsVerified($user, $bankAccountId);

        return [
            'success' => true,
            'message' => 'Bank account verified successfully.',
            'verified' => true
        ];
    }

    /**
     * Mark bank account as verified
     */
    private function markBankAccountAsVerified(User $user, string $bankAccountId): void
    {
        $bankAccount = MerchantBankAccount::where('id', $bankAccountId)
            ->where('user_id', $user->id)
            ->first();

        if ($bankAccount) {
            $bankAccount->update([
                'verification_status' => 'verified',
                'verified_at' => now()
            ]);
        }
    }
}
