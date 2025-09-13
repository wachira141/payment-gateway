<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $apiKeyHeader = $request->header('x-api-key') ?? $request->header('Authorization');
        
        if (!$apiKeyHeader) {
            return response()->json([
                'error' => [
                    'type' => 'authentication_error',
                    'message' => 'API key required',
                ]
            ], 401);
        }

        // Extract key from Bearer token format if needed
        if (str_starts_with($apiKeyHeader, 'Bearer ')) {
            $apiKeyHeader = substr($apiKeyHeader, 7);
        }

        $apiKey = ApiKey::verifyKey($apiKeyHeader);
        
        if (!$apiKey || !$apiKey->isValid()) {
            return response()->json([
                'error' => [
                    'type' => 'authentication_error',
                    'message' => 'Invalid API key',
                ]
            ], 401);
        }

        // Set authenticated context
        $request->setUserResolver(function () use ($apiKey) {
            return $apiKey->merchantApp->merchant->users()->where('role', 'owner')->first();
        });
        
        $request->merge(['merchantApp' => $apiKey->merchantApp]);
        $apiKey->markAsUsed();

        return $next($request);
    }
}