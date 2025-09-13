<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiKeyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->key_id,
            'name' => $this->name,
            'key' => $this->when(
                $request->routeIs('api.v1.apps.api-keys.store'),
                $this->full_key
            ),
            'scopes' => $this->scopes ?? [],
            'is_active' => $this->is_active,
            'last_used_at' => $this->last_used_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'rate_limits' => $this->rate_limits ?? [],
            'usage_count' => $this->when(
                $request->query('include_usage'),
                $this->usage_count
            ),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }

    /**
     * Get additional metadata for the resource.
     */
    public function with(Request $request): array
    {
        return [
            'object' => 'api_key',
        ];
    }
}