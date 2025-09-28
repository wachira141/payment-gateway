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
     * Get financial reports for merchant with multi-currency support
     */
    public function getFinancialReports(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $period = $request->input('period', 'monthly');

            $startDate = $request->input('start_date', now()->subMonth()->format('Y-m-d'));

            $endDate = $request->input('end_date', now()->format('Y-m-d'));
            $currency = $request->input('currency', null); // Allow null for all currencies

            $startDateFormated = $this->endOfDay($startDate);
            $endDateFormated = $this->endOfDay($endDate);

            if ($currency && $currency !== 'all') {
                // Single currency report
                $report = $this->ledgerService->generateFinancialReport(
                    $merchantId,
                    $startDateFormated,
                    $endDateFormated,
                    $currency
                );
                $reports = [$report];
            } else {
                // Multi-currency report
                $reports = $this->ledgerService->generateMultiCurrencyFinancialReport(
                    $merchantId,
                    $startDateFormated,
                    $endDateFormated
                );
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'accounts' => [
                        'assets' => $this->ledgerService->getAssetAccountsSummary($merchantId, $currency),
                        'revenue' => $this->ledgerService->getRevenueAccountsSummary($merchantId, $currency)
                    ],
                    'totals' => [
                        'total_credits' => array_sum(array_column($reports, 'total_volume')),
                        'total_debits' => array_sum(array_column($reports, 'processing_fees')) + array_sum(array_column($reports, 'application_fees'))
                    ],
                    'period' => [
                        'start' => $startDate,
                        'end' => $endDate
                    ],
                    'reports' => $reports
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get account balances with multi-currency support
     */
    /**
     * Get account balances with multi-currency support
     */
    public function getAccountBalances(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $currency = $request->input('currency');

            if ($currency && $currency !== 'all') {
                // Single currency balances - return simple array for backward compatibility
                $balances = $this->ledgerService->getAccountBalancesByCurrency($merchantId, $currency);

                return response()->json([
                    'success' => true,
                    'data' => $balances,
                    'metadata' => [
                        'currency_filter' => $currency,
                        'is_single_currency' => true
                    ]
                ]);
            } else {
                // Multi-currency balances with enhanced structure
                $result = $this->ledgerService->getAllAccountBalances($merchantId);

                return response()->json([
                    'success' => true,
                    'data' => $result['balances'], // Keep simple array for frontend compatibility
                    'metadata' => [
                        'currency_summary' => $result['currency_summary'],
                        'total_currencies' => $result['total_currencies'],
                        'available_currencies' => $result['available_currencies'],
                        'is_multi_currency' => true,
                        'generated_at' => now()->toISOString()
                    ]
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Get merchant balances (only merchant-facing balances)
     */
    public function getMerchantBalances(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $currency = $request->input('currency');

            if ($currency && $currency !== 'all') {
                // Single currency merchant balances
                $result = $this->ledgerService->getMerchantBalancesSummary($merchantId);
                $currencyData = $result['currency_summary'][$currency] ?? null;
                
                if (!$currencyData) {
                    return response()->json([
                        'success' => true,
                        'data' => [],
                        'metadata' => [
                            'currency_filter' => $currency,
                            'is_single_currency' => true,
                            'merchant_net_balance' => 0
                        ]
                    ]);
                }
                
                return response()->json([
                    'success' => true,
                    'data' => $currencyData['accounts'],
                    'metadata' => [
                        'currency_filter' => $currency,
                        'is_single_currency' => true,
                        'merchant_net_balance' => $currencyData['merchant_net_balance'],
                        'available_balance' => $currencyData['available_balance'],
                        'pending_balance' => $currencyData['pending_balance']
                    ]
                ]);
            } else {
                // Multi-currency merchant balances
                $result = $this->ledgerService->getMerchantBalancesSummary($merchantId);
                
                return response()->json([
                    'success' => true,
                    'data' => $result['balances'],
                    'metadata' => [
                        'currency_summary' => $result['currency_summary'],
                        'total_currencies' => $result['total_currencies'],
                        'available_currencies' => $result['available_currencies'],
                        'is_multi_currency' => true,
                        'generated_at' => now()->toISOString()
                    ]
                ]);
            }
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
            $currency = $request->input('currency', 'USD');

            // $startDate = $this->endOfDay($startDate);
            // $endDate = $this->endOfDay($endDate);

            $validation = $this->validationService->validateTransactionBalance(
                $merchantId,
                $startDate,
                $endDate,
                $currency
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
     * Get account reconciliation report with multi-currency support
     */
    public function getReconciliation(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $accountType = $request->input('account_type');
            $currency = $request->input('currency');

            $reconciliation = $this->validationService->getMultiCurrencyReconciliation(
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
     * Get gateway fee analysis with currency filtering
     */
    public function getGatewayFeeAnalysis(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $startDate = $request->input('start_date', now()->subMonth()->format('Y-m-d'));
            $endDate = $request->input('end_date', now()->format('Y-m-d'));
            $currency = $request->input('currency');
            $gatewayCode = $request->input('gateway_code');

            $startDate = $this->endOfDay($startDate);
            $endDate = $this->endOfDay($endDate);

            $analysis = $this->validationService->getGatewayFeeAnalysis(
                $merchantId,
                $startDate,
                $endDate,
                $currency,
                $gatewayCode
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

    /**
     * Get ledger entries with pagination
     */
    public function getLedgerEntries(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->user()->merchant_id;
            $accountType = $request->input('account_type');
            $limit = (int) $request->input('limit', 50);
            $offset = (int) $request->input('offset', 0);
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $query = \App\Models\LedgerEntry::where('merchant_id', $merchantId);

            if ($accountType) {
                $query->where('account_type', $accountType);
            }
            if ($startDate) {
                $query->where('posted_at', '>=', $startDate);
            }
            if ($endDate) {
                $query->where('posted_at', '<=', $endDate);
            }

            $total = $query->count();
            $entries = $query->orderBy('posted_at', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $entries->toArray(),
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
