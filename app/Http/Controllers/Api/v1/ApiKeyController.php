<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\ApiKeyService;
use App\Services\AppService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ApiKeyController extends Controller
{
    private ApiKeyService $apiKeyService;

    public function __construct(
        ApiKeyService $apiKeyService,
        private AppService $appService
        )
    {
        $this->apiKeyService = $apiKeyService;
    }

    /**
     * Get all API keys for the authenticated merchant
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $apiKeys = $this->apiKeyService->getApiKeysForMerchant(
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'data' => $apiKeys
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve API keys',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    

    /**
     * Create a new API key
     */
    public function create(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'scopes' => 'required|array',
                'scopes.*' => 'required|string|in:read,write,admin',
                'app_id' => 'sometimes|string',
                'expires_at' => 'sometimes|date|after:now',
                'description' => 'sometimes|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input',
                    'errors' => $validator->errors()
                ], 422);
            }

            $apiKey = $this->apiKeyService->createApiKey(
                $request->user()->id,
                $validator->validated()
            );

            return response()->json([
                'success' => true,
                'data' => $apiKey,
                'message' => 'API key created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create API key',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific API key by ID
     */
    public function show(Request $request, string $apiKeyId): JsonResponse
    {
        try {
            $apiKey = $this->apiKeyService->getApiKeyById(
                $apiKeyId,
                $request->user()->id
            );

            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'API key not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $apiKey
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve API key',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Regenerate an API key
     */
    public function regenerate(Request $request, string $apiKeyId): JsonResponse
    {
        try {
            $apiKey = $this->apiKeyService->regenerateApiKey(
                $apiKeyId,
                $request->user()->merchant_id
            );

            return response()->json([
                'success' => true,
                'data' => $apiKey,
                'message' => 'API key regenerated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to regenerate API key',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Revoke an API key
     */
    public function revoke(Request $request, string $apiKeyId): JsonResponse
    {
        try {
            $this->apiKeyService->revokeApiKey(
                $apiKeyId,
                $request->user()->merchant_id
            );

            return response()->json([
                'success' => true,
                'message' => 'API key revoked successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to revoke API key',
                'error' => $e->getMessage()
            ], 500);
        }
    }


     /**
     * List API keys for the specified app
     *
     * GET /api/v1/apps/{appId}/api-keys
     */
    public function apiKeys(Request $request, string $appId): JsonResponse
    {
        $app = $this->appService->getAppById(
            $appId,
            $request->user()->merchant_id,
        );

        if (!$app) {
            abort(404, 'App not found');
        }

        $this->authorize('view', $app);

        $keys = $this->apiKeyService->getAppApiKeysForMerchant($appId, $request->user()->merchant_id);

        return response()->json([
            'success' => true,
            'data' => $keys,
        ]);
    }
}