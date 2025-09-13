<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BankAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class MerchantBankAccountController extends Controller
{
    protected $bankAccountService;

    public function __construct(BankAccountService $bankAccountService)
    {
        $this->bankAccountService = $bankAccountService;
    }

    /**
     * Get provider bank accounts
     */
    public function index()
    {
        try {

            $bankAccounts = $this->getBankAccounts(Auth::id());
            return response()->json([
                'success' => true,
                'data' => $this->formatAccountData($bankAccounts['data'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bank accounts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get provider bank account for admin
     * @param string $providerId
     * @return \Illuminate\Http\JsonResponse
     * This method retrieves bank accounts for a specific provider.
     * It is intended for use by administrators to view bank accounts associated with a provider.
     * @throws \Exception
     * @internal This method is not intended for general use by providers.
     * It is designed for administrative purposes only.
     */
    public function getProviderBankAccounts(Request $request)
    {
        $providerId = $request->query('user_id', null);
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 50);

        try {
            $bankAccounts = $this->getBankAccounts($providerId, $page, $perPage);

            if (empty($bankAccounts)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No bank accounts found for this provider'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatAccountData($bankAccounts['data']),
                'pagination' => $bankAccounts['pagination'] ?? [
                    'total' => 0,
                    'currentPage' => $page,
                    'perPage' => $perPage,
                    'lastPage' => 1,
                    'from' => 0,
                    'to' => 0
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bank accounts for provider',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getBankAccounts(?string $providerId, int $page = 1, int $perPage = 50)
    {
        return $this->bankAccountService->getMerchantBankAccounts($providerId, $page, $perPage);
    }

    private function formatAccountData($bankAccounts)
    {

        $data =  $bankAccounts->map(function ($account) {
            return [
                'user_name' => $account->user->name ?? 'N/A',
                'user_id' => $account->user->serviceProvider->id,
                'id' => $account->id,
                'account_type' => $account->account_type,
                'bank_name' => $account->bank_name,
                'account_holder_name' => $account->account_holder_name,
                'masked_account_number' => $account->masked_account_number,
                'routing_number' => $account->routing_number,
                'currency' => $account->currency,
                'verification_status' => $account->verification_status,
                'is_primary' => $account->is_primary,
                'is_active' => $account->is_active,
                'verified_at' => $account->verified_at,
                'created_at' => $account->created_at,
            ];
        });
        return $data;
    }

    /**
     * Create bank account
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'account_type' => 'required|in:checking,savings,business',
                'bank_name' => 'required|string|max:255',
                'account_holder_name' => 'required|string|max:255',
                'account_number' => 'required|string|min:4|max:20',
                'routing_number' => 'required|string|size:9',
                'is_primary' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $bankAccount = $this->bankAccountService->createBankAccount(
                Auth::id(),
                $request->all()
            );

            return response()->json([
                'success' => true,
                'message' => 'Bank account created successfully',
                'data' => [
                    'id' => $bankAccount->id,
                    'account_type' => $bankAccount->account_type,
                    'bank_name' => $bankAccount->bank_name,
                    'account_holder_name' => $bankAccount->account_holder_name,
                    'masked_account_number' => $bankAccount->masked_account_number,
                    'routing_number' => $bankAccount->routing_number,
                    'currency' => $bankAccount->currency,
                    'verification_status' => $bankAccount->verification_status,
                    'is_primary' => $bankAccount->is_primary,
                    'is_active' => $bankAccount->is_active,
                    'verified_at' => $bankAccount->verified_at,
                    'created_at' => $bankAccount->created_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create bank account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific bank account
     */
    public function show($id)
    {
        try {
            $bankAccount = \App\Models\MerchantBankAccount::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$bankAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bank account not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $bankAccount->id,
                    'account_type' => $bankAccount->account_type,
                    'bank_name' => $bankAccount->bank_name,
                    'account_holder_name' => $bankAccount->account_holder_name,
                    'masked_account_number' => $bankAccount->masked_account_number,
                    'routing_number' => $bankAccount->routing_number,
                    'currency' => $bankAccount->currency,
                    'verification_status' => $bankAccount->verification_status,
                    'is_primary' => $bankAccount->is_primary,
                    'is_active' => $bankAccount->is_active,
                    'verified_at' => $bankAccount->verified_at,
                    'created_at' => $bankAccount->created_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bank account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update bank account
     */
    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'account_type' => 'sometimes|in:checking,savings,business',
                'bank_name' => 'sometimes|string|max:255',
                'account_holder_name' => 'sometimes|string|max:255',
                'account_number' => 'sometimes|string|min:4|max:20',
                'routing_number' => 'sometimes|string|size:9',
                'is_primary' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $bankAccount = $this->bankAccountService->updateBankAccount(
                Auth::id(),
                $id,
                $request->all()
            );

            return response()->json([
                'success' => true,
                'message' => 'Bank account updated successfully',
                'data' => [
                    'id' => $bankAccount->id,
                    'account_type' => $bankAccount->account_type,
                    'bank_name' => $bankAccount->bank_name,
                    'account_holder_name' => $bankAccount->account_holder_name,
                    'masked_account_number' => $bankAccount->masked_account_number,
                    'routing_number' => $bankAccount->routing_number,
                    'currency' => $bankAccount->currency,
                    'verification_status' => $bankAccount->verification_status,
                    'is_primary' => $bankAccount->is_primary,
                    'is_active' => $bankAccount->is_active,
                    'verified_at' => $bankAccount->verified_at,
                    'created_at' => $bankAccount->created_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update bank account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete bank account
     */
    public function destroy($id)
    {
        try {
            $this->bankAccountService->deleteBankAccount(Auth::id(), $id);

            return response()->json([
                'success' => true,
                'message' => 'Bank account removed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove bank account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify a bank account
     *
     * @param VerifyBankAccountRequest $request
     * @param string $accountId
     * @return JsonResponse
     */
    public function verifyBankAccount(Request $request, string $accountId)
    {

        $data = $request->validate([
            'notes' => 'nullable|string|max:50',
            'status' => 'required|in:verified,failed',
            'user_id' => 'required|exists:users,id',
        ]);

        try {
            $account = $this->bankAccountService->verifyBankAccount(
                $accountId,
                $data['user_id'],
                $data['status'],
                $data['notes'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Bank account verified successfully',
                'data' => $account->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * activate/deactivate bank account
     */
    public function toggleActiveStatus(Request $request, string $accountId)
    {
        $data = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        try {
            $account = $this->bankAccountService->activateBankAccount($accountId, $data['is_active']);

            return response()->json([
                'success' => true,
                'message' => 'Bank account status updated successfully',
                'data' => [
                    'id' => $account->id,
                    'is_active' => $account->is_active
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }
    /**
     * Initiate micro-deposits for verification
     *
     * @param string $accountId
     * @return JsonResponse
     */
    public function initiateMicroDeposits(string $accountId)
    {
        try {
            $account = $this->bankAccountService->initiateMicroDeposits($accountId);

            return response()->json([
                'success' => true,
                'message' => 'Micro-deposits initiated',
                'data' => [
                    'account_id' => $account->id,
                    'verification_method' => 'micro_deposits'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function sendBankVerification(Request $request)
    {
        $validated = $request->validate([
            'bank_account_id' => [
                'required',
                'string',
                'exists:provider_bank_accounts,id'
            ],
        ]);

        $user = Auth::user();

        // Verify that the bank account belongs to the user
        $bankAccount = $this->bankAccountService->getBankAccountById($validated['bank_account_id']);

        if (!$bankAccount || $bankAccount->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found or access denied.'
            ], 403);
        }

        try {
            $result = $this->bankAccountService->sendBankVerificationCode(
                $user,
                $validated['bank_account_id'],
                $user->email
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send verification code. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /provider/bank-accounts/verify-code
    public function verifyBankCode(Request $request)
    {
        $validated = $request->validate([
            'bank_account_id' => [
                'required',
                'string',
                'exists:provider_bank_accounts,id'
            ],
            'verification_code' => [
                'required',
                'string',
                'size:4',
                'regex:/^\d{4}$/' // Exactly 4 digits
            ],
        ]);

        $user = Auth::user();

        // Verify that the bank account belongs to the user
        $bankAccount = $this->bankAccountService->getBankAccountById($validated['bank_account_id']);

        if (!$bankAccount || $bankAccount->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found or access denied.'
            ], 403);
        }

        try {
            $result = $this->bankAccountService->verifyBankCode(
                $user,
                $validated['bank_account_id'],
                $validated['verification_code']
            );

            return response()->json($result, $result['success'] ? 200 : 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Verification failed. Please try again.'
            ], 500);
        }
    }
}
