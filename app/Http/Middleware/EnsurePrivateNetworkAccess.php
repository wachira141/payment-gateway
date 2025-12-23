<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivateNetworkAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Pass the request to the next middleware/controller
        $response = $next($request);

        // Add the required PNA header to allow access from public contexts to local IPs
        $response->headers->set('Access-Control-Allow-Private-Network', 'true');

        return $response;
    }
}
