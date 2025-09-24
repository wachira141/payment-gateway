<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\PayoutMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportedPayoutMethodController extends Controller
{
    private PayoutMethodService $payoutMethodService;

    public function __construct(PayoutMethodService $payoutMethodService)
    {
        $this->payoutMethodService = $payoutMethodService;
    }

    /**
     * Get supported payout methods for a specific country and currency
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'country_code' => 'required|string|size:2',
            'currency' => 'required|string|size:3',
            'amount' => 'nullable|numeric|min:0'
        ]);

        try {
            $methods = $this->payoutMethodService->getMethodsForCountryAndCurrency(
                $request->input('country_code'),
                $request->input('currency'),
                $request->input('amount')
            );

            return response()->json([
                'success' => true,
                'data' => $methods
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch supported payout methods',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}