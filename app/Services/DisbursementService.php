<?php
namespace App\Services;

use App\Models\Disbursement;
use App\Models\DisbursementBatch;
use App\Models\MerchantBankAccount;
use App\Models\MerchantEarning;
use App\Models\User;
use App\Services\MpesaPaymentService;
use App\Services\KenyaBankTransferService;
use App\Services\PaymentGatewayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DisbursementService
{
    protected $mpesaService;
    protected $kenyaBankService;
    protected $merchantRiskService;
    protected $kenyaCurrencyService;
    protected $paymentGatewayService;

    public function __construct(
        MpesaPaymentService $mpesaService,
        KenyaBankTransferService $kenyaBankService,
        MerchantRiskService $merchantRiskService,
        KenyaCurrencyService $kenyaCurrencyService,
        PaymentGatewayService $paymentGatewayService
    ) {
        $this->mpesaService = $mpesaService;
        $this->kenyaBankService = $kenyaBankService;
        $this->merchantRiskService = $merchantRiskService;
        $this->kenyaCurrencyService = $kenyaCurrencyService;
        $this->paymentGatewayService = $paymentGatewayService;
    }

    /**
     * Create disbursement for provider earnings with Kenya-specific routing
     */
    public function createDisbursement(
        string $userId,
        ?string $bankAccountId,
        ?string $mpesaPhoneNumber,
        array $earningsIds,
        array $metadata = []
    ) {
        return DB::transaction(function () use ($userId, $bankAccountId, $mpesaPhoneNumber, $earningsIds, $metadata) {
            $user = User::findOrFail($userId);

            $paymentPreference = $user->serviceProvider->preferred_payout_method ?? 'mpesa';

            // string to lower
            $paymentPreference = strtolower($paymentPreference);
            // Validate bank account or M-Pesa phone number
            $paymentAccount = null;

            if ($paymentPreference && $paymentPreference === 'bank' && $bankAccountId) {
                $providerBank = MerchantBankAccount::where('id', $bankAccountId)
                    ->where('user_id', $userId)
                    ->verified()
                    ->active()
                    ->first();
                if (!$providerBank) {
                    throw new \Exception('Invalid or unverified bank account');
                }
                $paymentAccount = $providerBank->id;
            } else {
                $paymentAccount = $user->serviceProvider->mpesa_phone_number ?? $mpesaPhoneNumber;
            }

            // Get available earnings
            $earnings = MerchantEarning::where('user_id', $userId)
                ->where('status', 'available')
                ->whereIn('id', $earningsIds)
                ->whereNull('disbursement_id')
                ->get();

            if ($earnings->isEmpty()) {
                throw new \Exception('No available earnings found');
            }

            $totalAmount = $earnings->sum('net_amount');

            // Determine best payout method for Kenya
            $feeAmount = $this->paymentGatewayService->calculateGatewayDisbursementFee($totalAmount, $paymentPreference);

            // Create disbursement
            $disbursement = Disbursement::createDisbursement([
                'user_id' => $userId,
                'provider_bank_account_id' => $paymentAccount,
                'gross_amount' => $totalAmount,
                'fee_amount' => $feeAmount,
                'currency' => 'KES', // Default to KES for Kenya
                'metadata' => array_merge($metadata, [
                    'earnings_count' => $earnings->count(),
                    'earnings_ids' => $earnings->pluck('id')->toArray(),
                    'payout_method' => $paymentPreference,
                    'country' => 'KE',
                ]),
            ]);

            // Link earnings to disbursement
            $earnings->each(function ($earning) use ($disbursement) {
                $earning->update([
                    'disbursement_id' => $disbursement->id,
                    'status' => 'disbursed',
                    'disbursed_at' => now(),
                ]);
            });

            Log::info("Created disbursement {$disbursement->disbursement_id} for provider {$userId} using {$paymentPreference}");

            return $disbursement;
        });
    }



    /**
     * Get payout estimation for user
     */
    public function getPayoutEstimation($userId, $amount)
    {
        $user = User::findOrFail($userId);
        $bankAccount = MerchantBankAccount::forProvider($userId)->verified()->active()->first();

        $estimations = [];
        $payoutMethod = $bankAccount->account_type;

        // M-Pesa estimation
        if ($payoutMethod == 'mobile_money') {
            $validation = $this->mpesaService->validatePayoutAmount($amount);
            if ($validation['valid']) {
                $fee = $this->paymentGatewayService->calculateGatewayDisbursementFee($amount, 'mpesa');
                $estimations['mpesa'] = [
                    'method' => 'mpesa',
                    'gross_amount' => $amount,
                    'fee' => $fee,
                    'net_amount' => $amount - $fee,
                    'processing_time' => 'Immediate',
                    'phone_number' => $this->maskPhoneNumber($user->mpesa_phone_number),
                ];
            }
        }

        // Bank transfer estimation
        if ($bankAccount) {
            $fee = $this->paymentGatewayService->calculateGatewayDisbursementFee($amount, 'bank');
            $estimations['bank'] = [
                'method' => 'bank',
                'gross_amount' => $amount,
                'fee' => $fee,
                'net_amount' => $amount - $fee,
                'processing_time' => $amount >= 1000000 ? 'Real-time (RTGS)' : '2-4 hours (EFT)',
                'bank_name' => $bankAccount->bank_name,
                'account_number' => $bankAccount->masked_account_number,
            ];
        }

        return $estimations;
    }

    /**
     * Mask phone number for display
     */
    private function maskPhoneNumber($phoneNumber)
    {
        return substr($phoneNumber, 0, 6) . '****' . substr($phoneNumber, -2);
    }

    /**
     * Get provider disbursements with pagination
     */
    public function getProviderDisbursements($userId, array $filters = [], $perPage = 15)
    {
        $query = Disbursement::forProvider($userId)
            ->with(['MerchantBankAccount', 'disbursementBatch'])
            ->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->paginate($perPage);
    }
}
