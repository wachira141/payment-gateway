<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TenantContextMiddleware
{
    /**
     * Handle an incoming request to set tenant context for API key requests
     */
    public function handle(Request $request, Closure $next)
    {
        // Get the API key from AuthenticateApiKey middleware
        $user = $request->user();
        
        if ($user && $user->merchant) {
            // Find the merchant app associated with the API key
            // This should be set by AuthenticateApiKey middleware
            $apiKeyHeader = $request->header('x-api-key') ?? $request->header('Authorization');
            
            if (str_starts_with($apiKeyHeader, 'Bearer ')) {
                $apiKeyHeader = substr($apiKeyHeader, 7);
            }
            
            $apiKey = \App\Models\ApiKey::verifyKey($apiKeyHeader);
            
            if ($apiKey && $apiKey->merchantApp) {
                $request->attributes->set('merchantApp', $apiKey->merchantApp);
                $request->merchantApp = $apiKey->merchantApp;
            }
        }
        
        return $next($request);
    }
}