<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MerchantAuthController
{
    /**
     * Login a merchant user
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $merchantUser = MerchantUser::where('email', $request->email)->first();

        if (!$merchantUser || !Hash::check($request->password, $merchantUser->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($merchantUser->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Your account is not active. Please contact support.'],
            ]);
        }

        // Update last login
        $merchantUser->updateLastLogin();

        // Create token
        $token = $merchantUser->createToken('merchant-dashboard')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'merchant' => $merchantUser->merchant,
                'user' => $merchantUser->only(['id', 'name', 'email', 'role', 'permissions']),
            ]
        ]);
    }

    /**
     * Register a new merchant
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:merchant_users',
            'password' => 'required|string|min:8|confirmed',
            'business_name' => 'required|string|max:255',
            'business_type' => 'required|string|in:sole_proprietor,partnership,corporation,ngo',
            'country' => 'required|string|max:2',
        ]);

        // Create merchant first
        $merchant = Merchant::createWithDefaults([
            'name' => $request->business_name,
            'email' => $request->email,
            'business_name' => $request->business_name,
            'business_type' => $request->business_type,
            'country' => $request->country,
            'currency' => $this->getCurrencyByCountry($request->country),
            'status' => 'active',
            'compliance_status' => 'pending',
        ]);

        // Create merchant user
        $merchantUser = MerchantUser::create([
            'merchant_id' => $merchant->id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'owner',
            'status' => 'active',
        ]);

        // Update last login
        $merchantUser->updateLastLogin();

        // Create token
        $token = $merchantUser->createToken('merchant-dashboard')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'merchant' => $merchant,
                'user' => $merchantUser->only(['id', 'name', 'email', 'role', 'permissions']),
            ]
        ], 201);
    }

    /**
     * Logout the merchant user
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get authenticated merchant user details
     */
    public function me(Request $request)
    {
        $merchantUser = $request->user();
        
        return response()->json([
            'data' => [
                'merchant' => $merchantUser->merchant,
                'user' => $merchantUser->only(['id', 'name', 'email', 'role', 'permissions']),
            ]
        ]);
    }

    /**
     * Get currency by country code
     */
    private function getCurrencyByCountry($countryCode)
    {
        $currencies = [
            'KE' => 'KES',
            'UG' => 'UGX',
            'TZ' => 'TZS',
            'NG' => 'NGN',
            'GH' => 'GHS',
            'ZA' => 'ZAR',
            'US' => 'USD',
            'GB' => 'GBP',
        ];

        return $currencies[$countryCode] ?? 'USD';
    }
}