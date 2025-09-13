<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;


class MerchantController extends Controller
{
    /**
     * Get current merchant information
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $merchant = $user->merchant;

        return response()->json([
            'merchant' => [
                'id' => $merchant->merchant_id,
                'legal_name' => $merchant->legal_name,
                'display_name' => $merchant->display_name,
                'country_code' => $merchant->country_code,
                'default_currency' => $merchant->default_currency,
                'status' => $merchant->status,
                'compliance_status' => $merchant->compliance_status,
                'created' => $merchant->created_at->timestamp,
            ],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'permissions' => $user->permissions ?? [],
            ],
        ]);
    }

    /**
     * Get merchant apps
     */
    public function getApps(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        
        $apps = $merchant->apps()
            ->with(['apiKeys' => function ($query) {
                $query->where('is_active', true)
                    ->select(['id', 'app_id', 'key_id', 'name', 'last_used_at']);
            }])
            ->get();

        $data = $apps->map(function ($app) {
            return [
                'id' => $app->app_id,
                'name' => $app->name,
                'description' => $app->description,
                'environment' => $app->environment,
                'status' => $app->status,
                'scopes' => $app->scopes,
                'api_keys_count' => $app->apiKeys->count(),
                'created' => $app->created_at->timestamp,
            ];
        });

        return response()->json([
            'object' => 'list',
            'data' => $data,
        ]);
    }

    /**
     * Create merchant app
     */
    public function createApp(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'string|max:1000',
            'environment' => 'required|string|in:test,live',
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'string|in:payments:read,payments:write,payouts:read,payouts:write,balances:read,webhooks:manage',
        ]);

        $merchant = $request->user()->merchant;

        // Check if user can manage apps
        if (!$request->user()->canManageApps()) {
            return response()->json([
                'error' => [
                    'type' => 'permission_error',
                    'message' => 'You do not have permission to create apps',
                ]
            ], 403);
        }

        $app = $merchant->apps()->create([
            'name' => $request->name,
            'description' => $request->description,
            'environment' => $request->environment,
            'scopes' => $request->scopes,
        ]);

        return response()->json([
            'id' => $app->app_id,
            'name' => $app->name,
            'description' => $app->description,
            'environment' => $app->environment,
            'status' => $app->status,
            'scopes' => $app->scopes,
            'created' => $app->created_at->timestamp,
        ], 201);
    }

    /**
     * Get app details
     */
    public function getApp(Request $request, string $appId): JsonResponse
    {
        $merchant = $request->user()->merchant;
        
        $app = $merchant->apps()
            ->where('app_id', $appId)
            ->with(['apiKeys' => function ($query) {
                $query->where('is_active', true);
            }])
            ->firstOrFail();

        return response()->json([
            'id' => $app->app_id,
            'name' => $app->name,
            'description' => $app->description,
            'environment' => $app->environment,
            'status' => $app->status,
            'scopes' => $app->scopes,
            'api_keys' => $app->apiKeys->map(function ($key) {
                return [
                    'id' => $key->key_id,
                    'name' => $key->name,
                    'scopes' => $key->scopes,
                    'last_used_at' => $key->last_used_at?->timestamp,
                    'created' => $key->created_at->timestamp,
                ];
            }),
            'created' => $app->created_at->timestamp,
        ]);
    }

    /**
     * Create API key for app
     */
    public function createApiKey(Request $request, string $appId): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'scopes' => 'array',
            'scopes.*' => 'string',
        ]);

        $merchant = $request->user()->merchant;
        
        $app = $merchant->apps()
            ->where('app_id', $appId)
            ->firstOrFail();

        // Check permissions
        if (!$request->user()->canManageApps()) {
            return response()->json([
                'error' => [
                    'type' => 'permission_error',
                    'message' => 'You do not have permission to create API keys',
                ]
            ], 403);
        }

        try {
            $apiKey = $app->createApiKey($request->name, $request->scopes);
            
            return response()->json([
                'id' => $apiKey->key_id,
                'name' => $apiKey->name,
                'scopes' => $apiKey->scopes,
                'created' => $apiKey->created_at->timestamp,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => $e->getMessage(),
                ]
            ], 400);
        }
    }
}