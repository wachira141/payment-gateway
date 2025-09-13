<?php
namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\AppWebhook;
use App\Models\App;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AppWebhookController extends Controller
{
    /**
     * Get all webhooks for an app
     */
    public function index(Request $request, string $appId)
    {
        $this->authorize('view', App::findOrFail($appId));
        
        $webhooks = AppWebhook::getByAppId($appId);
        
        return response()->json([
            'success' => true,
            'data' => $webhooks,
        ]);
    }

    /**
     * Create a new webhook
     */
    public function store(Request $request, string $appId)
    {
        $app = App::findOrFail($appId);
        $this->authorize('update', $app);

        $validator = Validator::make($request->all(), [
            'url' => 'required|url|max:1000',
            'events' => 'nullable|array',
            'events.*' => 'string|in:' . implode(',', AppWebhook::getAvailableEventTypes()),
            'is_active' => 'boolean',
            'headers' => 'nullable|array',
            'timeout_seconds' => 'integer|min:1|max:300',
            'retry_attempts' => 'integer|min:0|max:10',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['app_id'] = $appId;
        $data['secret'] = 'whsec_' . Str::random(40);

        $webhook = AppWebhook::create($data);

        return response()->json([
            'success' => true,
            'data' => $webhook,
            'message' => 'Webhook created successfully',
        ], 201);
    }

    /**
     * Get a specific webhook
     */
    public function show(string $appId, string $webhookId)
    {
        $app = App::findOrFail($appId);
        $this->authorize('view', $app);

        $webhook = AppWebhook::where('app_id', $appId)
            ->findOrFail($webhookId);

        return response()->json([
            'success' => true,
            'data' => $webhook,
        ]);
    }

    /**
     * Update a webhook
     */
    public function update(Request $request, string $webhookId)
    {
        
        $webhook = AppWebhook::find($webhookId);

        if (!$webhook) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook not found',
            ], 404);
        }
        
        $app = App::find($webhook->app_id);
        if (!$app) {
            return response()->json([
                'success' => false,
                'message' => 'Associated app not found',
            ], 404);
        }
        $this->authorize('update', $app);
        
        $validator = Validator::make($request->all(), [
            'url' => 'sometimes|required|url|max:1000',
            'events' => 'nullable|array',
            // 'events.*' => 'string|in:' . implode(',', AppWebhook::getAvailableEventTypes()) . ',*',
            'events.*' => 'string|in:' . implode(',', AppWebhook::getAvailableEventTypes()),
            'is_active' => 'boolean',
            'headers' => 'nullable|array',
            'timeout_seconds' => 'integer|min:1|max:300',
            'retry_attempts' => 'integer|min:0|max:10',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $webhook->update($validator->validated());

        return response()->json([
            'success' => true,
            'data' => $webhook,
            'message' => 'Webhook updated successfully',
        ]);
    }

    /**
     * Delete a webhook
     */
    public function destroy(string $webhookId)
    {
        $webhook = AppWebhook::find($webhookId);

        if (!$webhook) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook not found',
            ], 404);
        }
        
        $app = App::find($webhook->app_id);
        if (!$app) {
            return response()->json([
                'success' => false,
                'message' => 'Associated app not found',
            ], 404);
        }

        $this->authorize('update', $app);

        $webhook->delete();

        return response()->json([
            'success' => true,
            'message' => 'Webhook deleted successfully',
        ]);
    }

    /**
     * Rotate webhook secret
     */
    public function rotateSecret(Request $request, string $webhookId)
    {
        $webhook = AppWebhook::find($webhookId);

        if (!$webhook) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook not found',
            ], 404);
        }
        
        $app = App::find($webhook->app_id);
        if (!$app) {
            return response()->json([
                'success' => false,
                'message' => 'Associated app not found',
            ], 404);
        }

        $this->authorize('update', $app);




        $newSecret = $webhook->generateSecret();

        return response()->json([
            'success' => true,
            'data' => [
                'secret' => $newSecret,
            ],
            'message' => 'Webhook secret rotated successfully',
        ]);
    }

    /**
     * Test webhook endpoint
     */
    public function test(Request $request, string $webhookId)
    {
        $webhook = AppWebhook::find($webhookId);

        if (!$webhook) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook not found',
            ], 404);
        }
        
        $app = App::find($webhook->app_id);
        if (!$app) {
            return response()->json([
                'success' => false,
                'message' => 'Associated app not found',
            ], 404);
        }

        $testPayload = [
            'id' => 'test_' . Str::random(20),
            'type' => 'webhook.test',
            'created' => now()->timestamp,
            'data' => [
                'message' => 'This is a test webhook from PGaaS',
                'app_id' => $app->id,
                'webhook_id' => $webhookId,
            ],
        ];

        try {
            $timestamp = now()->timestamp;
            $signature = $this->generateSignature($testPayload, $webhook->secret, $timestamp);

            $headers = array_merge(
                $webhook->headers ?? [],
                [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'PGaaS-Webhooks/1.0',
                    'X-PGaaS-Timestamp' => $timestamp,
                    'X-PGaaS-Signature' => $signature,
                ]
            );

            $response = Http::timeout($webhook->timeout_seconds)
                ->withHeaders($headers)
                ->post($webhook->url, $testPayload);

            if ($response->successful()) {
                $webhook->markSuccess();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Test webhook sent successfully',
                    'response' => [
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'headers' => $response->headers(),
                    ],
                ]);
            } else {
                $error = "HTTP {$response->status()}: {$response->body()}";
                $webhook->markFailure($error);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Test webhook failed',
                    'error' => $error,
                ], 400);
            }
        } catch (\Exception $e) {
            $webhook->markFailure($e->getMessage());
            
            Log::error('Webhook test failed', [
                'webhook_id' => $webhookId,
                'url' => $webhook->url,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Test webhook failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available event types
     */
    public function getEventTypes()
    {
        return response()->json([
            'success' => true,
            'data' => AppWebhook::getAvailableEventTypes(),
        ]);
    }

    /**
     * Generate webhook signature
     */
    private function generateSignature(array $payload, string $secret, int $timestamp): string
    {
        $payloadString = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signedPayload = $timestamp . '.' . $payloadString;
        
        return 'sha256=' . hash_hmac('sha256', $signedPayload, $secret);
    }
}