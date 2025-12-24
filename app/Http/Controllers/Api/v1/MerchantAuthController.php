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


        // Create Passport token
        $tokenResult = $merchantUser->createToken('merchant-dashboard');

        return response()->json([
            'data' => [
                'token' => $tokenResult->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => $tokenResult->token->expires_at,
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
            'legal_name' => 'required|string|max:255',
            'display_name' => 'required|string|max:255',
            'business_type' => 'required|string|in:sole_proprietor,partnership,corporation,ngo',
            'country_code' => 'required|string|max:2',
        ]);

        // Create merchant first
        $merchant = Merchant::createWithDefaults([
            'legal_name' => $request->legal_name,
            'display_name' => $request->display_name,
            'name' => $request->legal_name,
            'email' => $request->email,
            'business_name' => $request->legal_name,
            'business_type' => $request->business_type,
            'country' => $request->country_code,
            'country_code' => $request->country_code,
            'default_currency' => $this->getCurrencyByCountry($request->country_code),
            'currency' => $this->getCurrencyByCountry($request->country_code),
            'status' => 'active',
            'compliance_status' => 'pending',
            'kyc_tier' => 0,
            'kyc_status' => 'pending',
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


        // Create Passport token
        $tokenResult = $merchantUser->createToken('merchant-dashboard');

        return response()->json([
            'data' => [
                'token' => $tokenResult->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => $tokenResult->token->expires_at,
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
        $request->user()->token()->revoke();


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
            'success' => true,
            'data' => [
                'merchant' => $merchantUser->merchant,
                'merchant_user' => $this->formatMerchantUserWithRBAC($merchantUser),
            ],
        ]);
    }

    /**
     * Update merchant profile
     * PUT /api/v1/merchant/profile
     */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'display_name' => 'sometimes|string|max:255',
            'business_type' => 'sometimes|string|in:sole_proprietor,partnership,corporation,ngo',
            'default_currency' => 'sometimes|string|max:3',
            'website' => 'nullable|url|max:255',
            'business_description' => 'nullable|string|max:1000',
        ]);

        $merchant = $request->user()->merchant;

        $updateData = $request->only([
            'display_name',
            'business_type',
            'default_currency',
            'website',
            'business_description',
        ]);

        $merchant->update(array_filter($updateData, fn($value) => $value !== null));

        return response()->json([
            'message' => 'Profile updated successfully',
            'merchant' => [
                'id' => $merchant->merchant_id,
                'legal_name' => $merchant->legal_name,
                'display_name' => $merchant->display_name,
                'country_code' => $merchant->country_code,
                'default_currency' => $merchant->default_currency,
                'business_type' => $merchant->business_type,
                'website' => $merchant->website,
                'business_description' => $merchant->business_description,
                'status' => $merchant->status,
                'compliance_status' => $merchant->compliance_status,
            ],
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


    protected function formatMerchantUserWithRBAC(MerchantUser $user): array
    {
        return [
            'id' => $user->id,
            'merchant_id' => $user->merchant_id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_primary' => $user->is_primary,
            'status' => $user->status,
            'roles' => $user->roles()->with('permissions')->get()->map(fn($role) => [
                'id' => $role->pivot->id ?? null,
                'role_id' => $role->id,
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'is_system' => $role->is_system,
                    'permissions' => $role->permissions->pluck('name'),
                ],
                'assigned_by' => $role->pivot->assigned_by,
                'assigned_at' => $role->pivot->assigned_at,
            ]),
            'direct_permissions' => $user->directPermissions()
                ->wherePivot('expires_at', null)
                ->orWherePivot('expires_at', '>', now())
                ->get()
                ->map(fn($perm) => [
                    'id' => $perm->pivot->id ?? null,
                    'permission_id' => $perm->id,
                    'permission' => [
                        'id' => $perm->id,
                        'name' => $perm->name,
                        'display_name' => $perm->display_name,
                        'module' => $perm->module,
                    ],
                    'granted_by' => $perm->pivot->granted_by,
                    'granted_at' => $perm->pivot->granted_at,
                    'expires_at' => $perm->pivot->expires_at,
                ]),
            'resolved_permissions' => $user->getResolvedPermissions(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
