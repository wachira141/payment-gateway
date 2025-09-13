<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'client_id' => $this->client_id,
            'client_secret' => $this->when(
                $request->routeIs('api.v1.apps.show') && $request->query('include_secret'),
                $this->client_secret
            ),
            'webhook_url' => $this->webhook_url,
            'redirect_urls' => $this->redirect_urls ?? [],
            'logo_url' => $this->logo_url,
            'website_url' => $this->website_url,
            'is_live' => $this->is_live,
            'is_active' => $this->is_active,
            'webhook_events' => $this->webhook_events ?? [],
            'settings' => $this->settings ?? [],
            'api_keys_count' => $this->whenCounted('apiKeys'),
            'active_api_keys_count' => $this->when(
                $this->relationLoaded('apiKeys'),
                function () {
                    return $this->apiKeys->where('is_active', true)->count();
                }
            ),
            'last_used_at' => $this->when(
                $this->relationLoaded('apiKeys'),
                function () {
                    return $this->apiKeys->max('last_used_at')?->toISOString();
                }
            ),
            'secret_regenerated_at' => $this->secret_regenerated_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            
            // Conditional relationships
            'api_keys' => ApiKeyResource::collection($this->whenLoaded('apiKeys')),
            'statistics' => $this->when($request->query('include_stats'), function () {
                return $this->getStatistics();
            }),
        ];
    }

    /**
     * Get additional metadata for the resource.
     */
    public function with(Request $request): array
    {
        return [
            'object' => 'app',
        ];
    }
}