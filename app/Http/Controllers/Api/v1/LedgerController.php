<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\LedgerService;
use App\Services\LedgerValidationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LedgerController extends Controller
{
    private LedgerService $ledgerService;
    private LedgerValidationService $validationService;

    public function __construct(LedgerService $ledgerService, LedgerValidationService $validationService)
    {
        $this->ledgerService = $ledgerService;
        $this->validationService = $validationService;
    }

    /**
     * Get financial report for merchant
     */
    public function getFinancialReport(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $startDate = $request->input('start_date', now()->subMonth()->format('Y-m-d'));
            $endDate = $request->input('end_date', now()->format('Y-m-d'));
            $currency = $request->input('currency');

            $report = $this->ledgerService->generateFinancialReport(
                $merchantId,
                $startDate,
                $endDate,
                $currency
            );

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get account balance
     */
    public function getAccountBalance(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $accountType = $request->input('account_type');
            $accountName = $request->input('account_name');
            $currency = $request->input('currency');

            $balance = $this->ledgerService->getAccountBalance(
                $merchantId,
                $accountType,
                $accountName,
                $currency
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'account_type' => $accountType,
                    'account_name' => $accountName,
                    'currency' => $currency,
                    'balance' => $balance,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate ledger integrity
     */
    public function validateLedger(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $validation = $this->validationService->validateTransactionBalance(
                $merchantId,
                $startDate,
                $endDate
            );

            return response()->json([
                'success' => true,
                'data' => $validation,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get account reconciliation report
     */
    public function getReconciliation(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $accountType = $request->input('account_type');
            $currency = $request->input('currency');

            $reconciliation = $this->validationService->getAccountReconciliation(
                $merchantId,
                $accountType,
                $currency
            );

            return response()->json([
                'success' => true,
                'data' => $reconciliation,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get gateway fee analysis
     */
    public function getGatewayFeeAnalysis(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $startDate = $request->input('start_date', now()->subMonth()->format('Y-m-d'));
            $endDate = $request->input('end_date', now()->format('Y-m-d'));

            $analysis = $this->validationService->getGatewayFeeAnalysis(
                $merchantId,
                $startDate,
                $endDate
            );

            return response()->json([
                'success' => true,
                'data' => $analysis,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detect ledger anomalies
     */
    public function detectAnomalies(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $threshold = $request->input('threshold', 10000);

            $anomalies = $this->validationService->detectAnomalies($merchantId, $threshold);

            return response()->json([
                'success' => true,
                'data' => $anomalies,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}