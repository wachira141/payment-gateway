<?php
namespace App\Services;

use App\Services\Email\EmailService;
use App\Services\ServiceProviderService;
use App\Models\PayoutRoutingRule;
use App\Models\MpesaVerification;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MerchantPayoutService
{
    private EmailService $emailService;
    private ServiceProviderService $serviceProvider;
    /**
     * 
     * ProviderPayoutService constructor.
     *
     * @param EmailService $emailService
     */
    public function __construct(
        EmailService $emailService,
        ServiceProviderService $serviceProvider)
    {
        $this->emailService = $emailService;
        $this->serviceProvider = $serviceProvider;
    }

    public function getPayoutMethodsForUser(User $user): array
    {
        $country = $user->country_code ?: 'KE';

        // Default methods
        $methods = [
            ['code' => 'bank',  'name' => 'Bank Transfer', 'description' => 'Payouts to your bank account'],
        ];

        // Enable M-Pesa for Kenya (or if a rule exists)
        $hasMpesaRule = PayoutRoutingRule::active()
            ->where('country_code', $country)
            ->where('method', 'mpesa')
            ->exists();

        if ($country === 'KE' || $hasMpesaRule) {
            array_unshift($methods, [
                'code' => 'mpesa',
                'name' => 'M-Pesa',
                'description' => 'Mobile money payouts to your M-Pesa wallet',
            ]);
        }

        return ['methods' => $methods];
    }

    public function getPayoutLimitsForUser(User $user): array
    {
        $country  = $user->country_code ?: 'KE';
        $currency = strtoupper($user->currency ?? 'KES');

        // Prefer an explicit MPesa rule where applicable
        $rule = PayoutRoutingRule::active()
            ->where('country_code', $country)
            ->where('currency', $currency)
            ->where('method', 'mpesa')
            ->orderByDesc('priority')
            ->first();

        if ($rule) {
            return [
                'currency'    => $currency,
                'min_amount'  => (float) $rule->min_amount,
                'max_amount'  => (float) $rule->max_amount,
                'daily_limit' => Arr::get($rule->configuration ?? [], 'daily_limit'),
            ];
        }

        // Fallback sensible defaults for KE
        return [
            'currency'    => $currency,
            'min_amount'  => 10.0,
            'max_amount'  => 150000.0,
            'daily_limit' => 300000.0,
        ];
    }

    public function updatePayoutPreferences(User $user, array $data): User
    {
        $updates = [];

        if (array_key_exists('preferred_payout_method', $data)) {
            $updates['preferred_payout_method'] = $data['preferred_payout_method'] ?: 'auto';
        }

        if (array_key_exists('mpesa_phone_number', $data) && filled($data['mpesa_phone_number'])) {
            $normalized = $this->normalizeKenyanMsisdn($data['mpesa_phone_number']);
            if (!$normalized) {
                abort(422, 'Invalid M-Pesa phone number.');
            }
            $updates['mpesa_phone_number'] = $normalized;
        }

        // Enrich and persist preferences JSON
        $prefs = $user->serviceProvider->payout_preferences ?: [];

        $prefs = array_merge($prefs, [
            'country'  => $user->preferences['country'] ?: 'KE',
            'currency' => strtoupper($user->preferences['currency'] ?? 'KES'),
        ]);

        $updates['payout_preferences'] = $prefs;
        $updates['payout_preferences_updated_at'] = now();

        $user->serviceProvider->fill($updates)->save();

        return $user->refresh();
    }

    public function estimate(User $user, array $params): array
    {
        $amount   = (float) $params['amount'];
        $method   = $params['method'];
        $currency = strtoupper($params['currency'] ?? ($user->currency ?? 'KES'));
        $country  = $user->country_code ?: 'KE';

        $rule = PayoutRoutingRule::findBestRule($country, $currency, $method, $amount);

        $feePct   = $rule?->fee_percentage ?? 0.0;
        $feeFixed = $rule?->fee_fixed ?? 0.0;

        $feeTotal = round($amount * (float) $feePct + (float) $feeFixed, 2);
        $net      = round($amount - $feeTotal, 2);

        return [
            'currency'        => $currency,
            'method'          => $method,
            'amount'          => $amount,
            'fee_percentage'  => (float) $feePct,
            'fee_fixed'       => (float) $feeFixed,
            'fee_total'       => $feeTotal,
            'net_amount'      => $net,
            'rule_id'         => $rule?->id,
        ];
    }

    public function testMpesaNumber(User $user, string $rawPhone): array
    {
        $normalized = $this->normalizeKenyanMsisdn($rawPhone);

        if (!$normalized) {
            return [
                'success' => false,
                'message' => 'Invalid M-Pesa phone number. Use formats like 07XXXXXXXX, 01XXXXXXXX, +2547XXXXXXX.',
            ];
        }

        // Here you would trigger a lightweight validation ping (e.g., STK Push or lookup).
        // For now, we simulate success if normalized.
        return [
            'success' => true,
            'message' => "Test successful. We can reach {$normalized}.",
        ];
    }

    private function normalizeKenyanMsisdn(?string $input): ?string
    {
        if (!filled($input)) return null;

        $digits = preg_replace('/\D+/', '', $input ?? '');

        // Strip leading 0 for local formats
        if (Str::startsWith($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // Handle 254 prefix
        if (Str::startsWith($digits, '254')) {
            $digits = substr($digits, 3);
        }

        // Now $digits should start with 7 or 1 and be 9 digits long
        if (!preg_match('/^(7|1)\d{8}$/', $digits)) {
            return null;
        }

        return '+254' . $digits;
    }


    /**
     * Send M-Pesa verification code to user's email
     */
    public function sendMpesaVerificationCode(User $user, string $phoneNumber, string $email): array
    {
        // Generate a 4-digit verification code
        $verificationCode = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // Prepare data for cache and database
        $verificationData = [
            'code' => $verificationCode,
            'email' => $email,
            'phone_number' => $phoneNumber,
            'attempts' => 0,
            'created_at' => now()->toISOString()
        ];

        // Store verification code in cache with 15-minute expiration
        MpesaVerification::storeInCache($user->id, $phoneNumber, $verificationData);

        // Store in database for audit trail
        MpesaVerification::createVerification([
            'user_id' => $user->id,
            'phone_number' => $phoneNumber,
            'email' => $email,
            'verification_code' => $verificationCode, // Consider hashing this
            'expires_at' => now()->addMinutes(15),
            'attempts' => 0,
        ]);

        // Send email with verification code
        try {
            $success = $this->emailService->sendEmail('mpesa', [
                'template' => 'mpesa_verification',
                'recipient' => $email,
                'subject' => 'M-Pesa Phone Number Verification',
                'user_id' => $user->id,
                'variables' => [
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'verification_code' => $verificationCode,
                    'phone_number' => $phoneNumber,
                    'expires_in_minutes' => 15,
                    'expires_at' => now()->addMinutes(15)->format('M j, Y \a\t g:i A T'),
                    'app_name' => config('app.name'),
                    'support_email' => config('mail.support_address', config('mail.from.address')),
                    'request_ip' => request()->ip(),
                    'request_time' => now()->format('M j, Y \a\t g:i A T'),
                ],
                'priority' => 'high',
                'queue' => false, // Send immediately for better user experience
                'tags' => ['mpesa_verification', 'authentication'],
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
            Log::error('Failed to send M-Pesa verification email', [
                'user_id' => $user->id,
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
     * Verify M-Pesa verification code
     */
    public function verifyMpesaCode(User $user, string $phoneNumber, string $verificationCode): array
    {
        $storedData = MpesaVerification::getFromCache($user->id, $phoneNumber);

        if (!$storedData) {
            return [
                'success' => false,
                'message' => 'Verification code has expired or does not exist. Please request a new code.'
            ];
        }

        // Check for too many attempts (rate limiting)
        if ($storedData['attempts'] >= 3) {
            MpesaVerification::forgetFromCache($user->id, $phoneNumber);
            return [
                'success' => false,
                'message' => 'Too many failed attempts. Please request a new verification code.'
            ];
        }

        // Verify the code
        if ($storedData['code'] !== $verificationCode) {
            // Increment attempts
            $storedData['attempts']++;
            MpesaVerification::storeInCache($user->id, $phoneNumber, $storedData);

            return [
                'success' => false,
                'message' => 'Invalid verification code. Please try again.'
            ];
        }

        // Code is correct - mark as verified
        MpesaVerification::forgetFromCache($user->id, $phoneNumber);

        // Update database record
        $verification = MpesaVerification::findLatestUnverified($user->id, $phoneNumber);
        if ($verification) {
            $verification->markAsVerified();
        }

        // Optional: Store the verified phone number status
        $this->markPhoneAsVerified($user, $phoneNumber);

        return [
            'success' => true,
            'message' => 'Phone number verified successfully.',
            'verified' => true
        ];
    }

     /**
     * Mark phone number as verified for the user
     */
    private function markPhoneAsVerified(User $user, string $phoneNumber): void
    {
      $this->serviceProvider->updateMpesaPhoneNumber($user->id, $this->normalizeKenyanMsisdn($phoneNumber));
    }
    
}
