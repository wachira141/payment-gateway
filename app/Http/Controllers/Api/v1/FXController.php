<?php

namespace App\Http\Controllers\Api\v1;
use App\Http\Controllers\Controller;
use App\Services\FXService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class FXController extends Controller
{
    private FXService $fxService;

    public function __construct(FXService $fxService)
    {
        $this->fxService = $fxService;
    }

    /**
     * Get FX quotes
     */
    public function getQuotes(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'from_currency' => 'required|string|size:3',
                'to_currency' => 'required|string|size:3',
                'amount' => 'required|integer|min:1'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input',
                    'errors' => $validator->errors()
                ], 422);
            }

            $quote = $this->fxService->getQuote(
                $request->user()->id,
                $validator->validated()
            );

            return response()->json([
                'success' => true,
                'data' => $quote
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get FX quote',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Execute FX trade
     */
    public function executeTrade(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'quote_id' => 'required|string',
                'confirm' => 'required|boolean|accepted'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input',
                    'errors' => $validator->errors()
                ], 422);
            }

            $trade = $this->fxService->executeTrade(
                $request->user()->id,
                $validator->validated()
            );

            return response()->json([
                'success' => true,
                'data' => $trade,
                'message' => 'FX trade executed successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to execute FX trade',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get FX trade history
     */
    public function getTradeHistory(Request $request): JsonResponse
    {
        try {
            $filters = [
                'currency_pair' => $request->query('currency_pair'),
                'status' => $request->query('status'),
                'limit' => $request->query('limit', 10),
                'offset' => $request->query('offset', 0),
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
            ];

            $trades = $this->fxService->getTradeHistoryForMerchant(
                $request->user()->id,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => $trades
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve FX trade history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current exchange rates
     */
    public function getExchangeRates(Request $request): JsonResponse
    {
        try {
            $baseCurrency = $request->query('base_currency', 'USD');
            $rates = $this->fxService->getCurrentExchangeRates($baseCurrency);

            return response()->json([
                'success' => true,
                'data' => $rates
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve exchange rates',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}