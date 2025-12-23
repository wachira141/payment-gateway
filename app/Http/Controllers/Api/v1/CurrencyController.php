<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    /**
     * Get all active currencies
     */
    public function index(Request $request): JsonResponse
    {
        $currencies = Currency::active()
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'symbol', 'decimals']);

        return response()->json([
            'success' => true,
            'data' => $currencies,
        ]);
    }

    /**
     * Get a specific currency by code
     */
    public function show(string $code): JsonResponse
    {
        $currency = Currency::where('code', strtoupper($code))->first();

        if (!$currency) {
            return response()->json([
                'success' => false,
                'message' => 'Currency not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $currency,
        ]);
    }

    /**
     * Get decimals for a currency (utility endpoint)
     */
    public function decimals(string $code): JsonResponse
    {
        $decimals = Currency::getDecimals($code);

        return response()->json([
            'success' => true,
            'data' => [
                'code' => strtoupper($code),
                'decimals' => $decimals,
            ],
        ]);
    }
}