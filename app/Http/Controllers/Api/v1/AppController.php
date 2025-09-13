<?php
namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAppRequest;
use App\Http\Requests\UpdateAppRequest;
use App\Http\Requests\CreateApiKeyRequest;
use App\Http\Resources\AppResource;
use App\Http\Resources\ApiKeyResource;
use App\Services\AppService;
use App\Models\App;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppController extends Controller
{
    public function __construct(private AppService $appService)
    {
        // $this->authorizeResource(App::class, 'app');
    }

    /**
     * Display a listing of apps for the authenticated merchant
     * 
     * @queryParam page integer The page number for pagination. Example: 1
     * @queryParam per_page integer Number of apps per page (max 100). Example: 15
     * @queryParam is_live boolean Filter by live/test mode. Example: true
     * @queryParam is_active boolean Filter by active status. Example: true
     * @queryParam search string Search apps by name or description. Example: "My App"
     * @queryParam with string[] Include relationships. Available: api_keys. Example: api_keys
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'is_live' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'search' => 'sometimes|string|max:255',
            'with' => 'sometimes|array',
            'with.*' => 'string|in:api_keys,statistics',
        ]);

        $perPage = min($validated['per_page'] ?? 15, 100);
        $filters = collect($validated)->only(['is_live', 'is_active', 'search'])->toArray();
        $with = $this->buildWithRelations($validated['with'] ?? []);

        $apps = $this->appService->getAppsForMerchant(
            $request->user()->merchant_id,
            $filters,
            $perPage,
            $with
        );

        return AppResource::collection($apps);
    }

    /**
     * Store a newly created app
     */
    public function store(CreateAppRequest $request): JsonResponse
    {
        $app = $this->appService->createApp(
            $request->user()->merchant_id,
            $request->validated()
        );

        return (new AppResource($app))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified app
     * 
     * @queryParam include_secret boolean Include client secret in response. Example: true
     * @queryParam include_stats boolean Include app statistics. Example: true
     * @queryParam with string[] Include relationships. Available: api_keys. Example: api_keys
     */
    public function show(Request $request, string $appId): AppResource
    {
        $validated = $request->validate([
            'include_secret' => 'sometimes|boolean',
            'include_stats' => 'sometimes|boolean',
            'with' => 'sometimes|array',
            'with.*' => 'string|in:api_keys',
        ]);

        $with = $this->buildWithRelations($validated['with'] ?? []);
        
        $app = $this->appService->getAppById(
            $appId,
            $request->user()->merchant_id,
            $with
        );

        if (!$app) {
            abort(404, 'App not found');
        }

        $this->authorize('view', $app);

        return new AppResource($app);
    }

    /**
     * Update the specified app
     */
    public function update(UpdateAppRequest $request, string $appId): AppResource
    {
        $app = $this->appService->updateApp(
            $appId,
            $request->user()->merchant_id,
            $request->validated()
        );

        if (!$app) {
            abort(404, 'App not found');
        }

        return new AppResource($app);
    }

    /**
     * Remove the specified app
     */
    public function destroy(Request $request, string $appId): JsonResponse
    {
        $app = App::findForMerchant($appId, $request->user()->merchant_id);
        
        if (!$app) {
            abort(404, 'App not found');
        }

        $this->authorize('delete', $app);

        $this->appService->deleteApp($appId, $request->user()->merchant_id);

        return response()->json([
            'message' => 'App deleted successfully'
        ]);
    }

    /**
     * Create API key for the specified app
     */
    public function createApiKey(CreateApiKeyRequest $request, string $appId): JsonResponse
    {
        $apiKey = $this->appService->createApiKeyForApp(
            $appId,
            $request->user()->merchant_id,
            $request->validated()
        );

        return (new ApiKeyResource($apiKey))
            ->response()
            ->setStatusCode(201);
    }

    

    /**
     * Get app statistics
     * 
     * @queryParam period string Statistics period. Available: 7d, 30d, 90d, 1y. Example: 30d
     */
    public function statistics(Request $request, string $appId): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'sometimes|string|in:7d,30d,90d,1y',
        ]);

        $app = App::findForMerchant($appId, $request->user()->merchant_id);
        
        if (!$app) {
            abort(404, 'App not found');
        }

        $this->authorize('viewStatistics', $app);

        $statistics = $this->appService->getAppStatistics($appId, $request->user()->merchant_id);

        return response()->json([
            'app_id' => $appId,
            'period' => $validated['period'] ?? '30d',
            'statistics' => $statistics,
            'generated_at' => now()->toISOString(),
        ]);
    }

    /**
     * Regenerate app client secret
     */
    public function regenerateSecret(Request $request, string $appId): JsonResponse
    {
        $app = App::findForMerchant($appId, $request->user()->merchant_id);
        
        if (!$app) {
            abort(404, 'App not found');
        }

        $this->authorize('regenerateSecret', $app);

        $updatedApp = $this->appService->regenerateClientSecret($appId, $request->user()->merchant_id);

        return response()->json([
            'app_id' => $appId,
            'client_secret' => $updatedApp->client_secret,
            'secret_regenerated_at' => $updatedApp->secret_regenerated_at->toISOString(),
            'message' => 'Client secret regenerated successfully'
        ]);
    }

    /**
     * Update webhook settings for the app
     */
    public function updateWebhookSettings(Request $request, string $appId): AppResource
    {
        $validated = $request->validate([
            'webhook_url' => 'nullable|url|max:2048',
            'events' => 'nullable|array',
            'events.*' => 'string|in:' . implode(',', array_keys(config('apps.webhook_events'))),
        ]);

        $app = App::findForMerchant($appId, $request->user()->merchant_id);
        
        if (!$app) {
            abort(404, 'App not found');
        }

        $this->authorize('manageWebhooks', $app);

        $updatedApp = $this->appService->updateWebhookSettings(
            $appId,
            $request->user()->merchant_id,
            $validated
        );

        return new AppResource($updatedApp);
    }

    /**
     * Test webhook endpoint for the app
     */
    public function testWebhook(Request $request, string $appId): JsonResponse
    {
        $app = App::findForMerchant($appId, $request->user()->merchant_id);
        
        if (!$app) {
            abort(404, 'App not found');
        }

        $this->authorize('manageWebhooks', $app);

        $result = $this->appService->testWebhook($appId, $request->user()->merchant_id);

        return response()->json([
            'message' => 'Webhook test completed',
            'result' => $result,
        ]);
    }

    /**
     * Get usage summary for merchant apps
     * 
     * @queryParam is_live boolean Filter by live/test mode. Example: true
     */
    public function usageSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_live' => 'sometimes|boolean',
        ]);

        $this->authorize('viewAny', App::class);

        $summary = $this->appService->getAppUsageSummary(
            $request->user()->merchant_id,
            $validated
        );

        return response()->json([
            'merchant_id' => $request->user()->merchant_id,
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ]);
    }

    /**
     * Get available options for app creation and management
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', App::class);
        
        $config = config('app');
        
        return response()->json([
            'scopes' => $config['scopes'],
            'webhook_events' => $config['webhook_events'],
            'defaults' => $config['defaults'],
        ]);
    }

    /**
     * Build relationships array for eager loading
     */
    private function buildWithRelations(array $with): array
    {
        $relations = [];
        
        if (in_array('api_keys', $with)) {
            $relations[] = 'apiKeys:id,app_id,key_id,name,scopes,is_active,last_used_at,created_at';
        }
        
        return $relations;
    }
}