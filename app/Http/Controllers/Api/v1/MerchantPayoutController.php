<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\MerchantPayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class MerchantPayoutController extends Controller
{
    public function __construct(private MerchantPayoutService $service) {}

    // GET /provider/payouts/methods
    public function getPayoutMethods(Request $request)
    {
        $user = Auth::user();
        return response()->json($this->service->getPayoutMethodsForUser($user));
    }

    // GET /provider/payouts/limits
    public function getPayoutLimits(Request $request)
    {
        $user = Auth::user();
        return response()->json($this->service->getPayoutLimitsForUser($user));
    }

    // PUT /provider/payouts/preferences
    public function updatePayoutPreferences(Request $request)
    {
        $validated = $request->validate([
            'preferred_payout_method' => ['nullable', Rule::in(['mpesa', 'bank', 'auto'])],
            'mpesa_phone_number'      => ['nullable', 'string', 'max:32'],
        ]);

        $user = Auth::user();
        $updated = $this->service->updatePayoutPreferences($user, $validated);

        return response()->json([
            'success' => true,
            'user' => [
                'id'                       => $updated->serviceProvider->id,
                'country_code'             => $updated->preferences['country'],
                'mpesa_phone_number'       => $updated->serviceProvider->mpesa_phone_number,
                'preferred_payout_method'  => $updated->serviceProvider->preferred_payout_method,
                'payout_preferences'       => $updated->serviceProvider->payout_preferences,
                'payout_preferences_updated_at' => $updated->serviceProvider->payout_preferences_updated_at,
            ],
        ]);
    }

    // POST /provider/payouts/estimate
    public function getPayoutEstimation(Request $request)
    {
        $validated = $request->validate([
            'amount'   => ['required', 'numeric', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'method'   => ['required', Rule::in(['mpesa', 'bank'])],
        ]);

        $user = Auth::user();
        $result = $this->service->estimate($user, $validated);

        return response()->json($result);
    }

    // POST /provider/payouts/test-mpesa
    public function testMpesaNumber(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:32'],
        ]);

        $user = Auth::user();
        $res = $this->service->testMpesaNumber($user, $validated['phone_number']);

        return response()->json($res, $res['success'] ? 200 : 422);
    }

    // POST /provider/payouts/mpesa/send-verification
    public function sendMpesaVerification(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => [
                'required', 
                'string', 
                'max:32',
                'regex:/^(\+254|0)(7|1)\d{8}$/' // Kenyan phone number format
            ],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = Auth::user();
        
        // Verify that the email belongs to the authenticated user
        if ($user->email !== $validated['email']) {
            return response()->json([
                'success' => false,
                'message' => 'Email address does not match your account.'
            ], 403);
        }

        try {
            $result = $this->service->sendMpesaVerificationCode(
                $user, 
                $validated['phone_number'], 
                $validated['email']
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

    // POST /provider/payouts/mpesa/verify-code
    public function verifyMpesaCode(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => [
                'required', 
                'string', 
                'max:32',
                'regex:/^(\+254|0)(7|1)\d{8}$/' // Kenyan phone number format
            ],
            'verification_code' => [
                'required', 
                'string', 
                'size:4',
                'regex:/^\d{4}$/' // Exactly 4 digits
            ],
        ]);

        $user = Auth::user();

        try {
            $result = $this->service->verifyMpesaCode(
                $user,
                $validated['phone_number'],
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
