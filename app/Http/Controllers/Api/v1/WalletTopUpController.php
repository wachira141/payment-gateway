<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\InitiateTopUpRequest;
use App\Services\WalletService;
use App\Services\WalletTopUpService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WalletTopUpController extends Controller
{
    protected WalletService $walletService;
    protected WalletTopUpService $topUpService;

    public function __construct(WalletService $walletService, WalletTopUpService $topUpService)
    {
        $this->walletService = $walletService;
        $this->topUpService = $topUpService;
    }

    public function index(Request $request, string $walletId): JsonResponse
    {
        $wallet = $this->walletService->getWallet($walletId);
        if (!$wallet || $wallet->merchant_id !== $request->user()->merchant->id) {
            return response()->json(['error' => ['message' => 'Wallet not found']], 404);
        }

        $topUps = $this->topUpService->getTopUpsForWallet($walletId, $request->all());

        return response()->json([
            'object' => 'list',
            'data' => collect($topUps->items())->map(fn($t) => $this->formatTopUp($t)),
            'has_more' => $topUps->hasMorePages(),
        ]);
    }

    public function store(InitiateTopUpRequest $request, string $walletId): JsonResponse
    {
        $wallet = $this->walletService->getWallet($walletId);
        if (!$wallet || $wallet->merchant_id !== $request->user()->merchant->id) {
            return response()->json(['error' => ['message' => 'Wallet not found']], 404);
        }

        try {
            $topUp = $this->topUpService->initiateTopUp(
                $walletId,
                $request->amount,
                $request->method,
                $request->validated()
            );

            return response()->json($this->formatTopUp($topUp), 201);
        } catch (\Exception $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 400);
        }
    }

    public function show(Request $request, string $walletId, string $topUpId): JsonResponse
    {
        $wallet = $this->walletService->getWallet($walletId);
        if (!$wallet || $wallet->merchant_id !== $request->user()->merchant->id) {
            return response()->json(['error' => ['message' => 'Wallet not found']], 404);
        }

        $topUp = $this->topUpService->getTopUpById($topUpId);
        if (!$topUp || $topUp->wallet_id !== $wallet->id) {
            return response()->json(['error' => ['message' => 'Top-up not found']], 404);
        }

        return response()->json($this->formatTopUp($topUp));
    }

    public function cancel(Request $request, string $walletId, string $topUpId): JsonResponse
    {
        $wallet = $this->walletService->getWallet($walletId);
        if (!$wallet || $wallet->merchant_id !== $request->user()->merchant->id) {
            return response()->json(['error' => ['message' => 'Wallet not found']], 404);
        }

        try {
            $this->topUpService->cancelTopUp($topUpId);
            return response()->json(['success' => true, 'message' => 'Top-up cancelled']);
        } catch (\Exception $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 400);
        }
    }

    protected function formatTopUp($topUp): array
    {
        return [
            'id' => $topUp->top_up_id,
            'object' => 'wallet_top_up',
            'amount' => (float) $topUp->amount,
            'currency' => $topUp->currency,
            'method' => $topUp->method,
            'status' => $topUp->status,
            'payment_instructions' => $topUp->payment_instructions,
            'bank_reference' => $topUp->bank_reference,
            'expires_at' => $topUp->expires_at?->timestamp,
            'completed_at' => $topUp->completed_at?->timestamp,
            'created_at' => $topUp->created_at->timestamp,
        ];
    }
}
