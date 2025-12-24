<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\WalletTransferRequest;
use App\Http\Requests\BalanceSweepRequest;
use App\Services\WalletService;
use App\Services\WalletTransferService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WalletTransferController extends Controller
{
    protected WalletService $walletService;
    protected WalletTransferService $transferService;

    public function __construct(WalletService $walletService, WalletTransferService $transferService)
    {
        $this->walletService = $walletService;
        $this->transferService = $transferService;
    }

   
    public function transfer(WalletTransferRequest $request): JsonResponse
    {
        $merchantId = $request->user()->merchant->id;
        $fromWallet = $this->walletService->getWallet($request->from_wallet_id);
        $toWallet = $this->walletService->getWallet($request->to_wallet_id);

        if (!$fromWallet || $fromWallet->merchant_id !== $merchantId) {
            return response()->json(['error' => ['message' => 'Source wallet not found']], 404);
        }
        if (!$toWallet || $toWallet->merchant_id !== $merchantId) {
            return response()->json(['error' => ['message' => 'Destination wallet not found']], 404);
        }

        try {
            $result = $this->transferService->transferBetweenWallets(
                $request->from_wallet_id,
                $request->to_wallet_id,
                $request->amount,
                $request->description ?? 'Internal transfer'
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 400);
        }
    }

    public function sweepFromBalance(BalanceSweepRequest $request, string $walletId): JsonResponse
    {

        $merchantId = $request->user()->merchant->id;
        $wallet = $this->walletService->getWallet($walletId);

        if (!$wallet || $wallet->merchant_id !== $merchantId) {
            return response()->json(['error' => ['message' => 'Wallet not found']], 404);
        }

        try {
            $result = $this->transferService->transferFromBalance(
                $merchantId,
                $request->currency,
                $request->amount,
                $walletId
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 400);
        }
    }

    public function availableForSweep(Request $request): JsonResponse
    {
        $request->validate(['currency' => 'required|string|size:3']);
        $merchantId = $request->user()->merchant->id;

        $result = $this->transferService->getAvailableForSweep($merchantId, $request->currency);
        return response()->json($result);
    }
}
