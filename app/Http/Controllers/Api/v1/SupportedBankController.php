<?php
namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\BankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportedBankController extends Controller
{
    private BankService $bankService;

    public function __construct(BankService $bankService)
    {
        $this->bankService = $bankService;
    }

    /**
     * Get supported banks for a specific country
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'country_code' => 'required|string|size:2'
        ]);

        try {
            $banks = $this->bankService->getBanksForCountry($request->input('country_code'));

            return response()->json([
                'success' => true,
                'data' => $banks
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch supported banks',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}