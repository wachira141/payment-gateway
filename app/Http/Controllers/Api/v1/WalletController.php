<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateWalletRequest;
use App\Http\Requests\UpdateWalletRequest;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function index(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        $wallets = $this->walletService->getMerchantWallets($merchant->id);

        return response()->json([
            'object' => 'list',
            'data' => $wallets->map(fn($w) => $this->formatWallet($w)),
        ]);
    }

  
    public function store(CreateWalletRequest $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        $wallet = $this->walletService->createWallet(
            $merchant->id,
            $request->currency,
            $request->type ?? 'operating',
            $request->only(['name', 'daily_withdrawal_limit', 'monthly_withdrawal_limit', 'settings', 'metadata'])
        );

        return response()->json($this->formatWallet($wallet), 201);
    }


    public function show(Request $request, string $walletId): JsonResponse
    {
        $wallet = $this->walletService->getWallet($walletId);
        if (!$wallet || $wallet->merchant_id !== $request->user()->merchant->id) {
            return response()->json(['error' => ['message' => 'Wallet not found']], 404);
        }

        return response()->json($this->formatWallet($wallet));
    }

  
    public function update(UpdateWalletRequest $request, string $walletId): JsonResponse
    {
        $wallet = $this->walletService->getWallet($walletId);
        if (!$wallet || $wallet->merchant_id !== $request->user()->merchant->id) {
            return response()->json(['error' => ['message' => 'Wallet not found']], 404);
        }

        $wallet = $this->walletService->updateWalletSettings($walletId, $request->validated());
        return response()->json($this->formatWallet($wallet));
    }

    public function balance(Request $request, string $walletId): JsonResponse
    {
        $wallet = $this->walletService->getWallet($walletId);
        if (!$wallet || $wallet->merchant_id !== $request->user()->merchant->id) {
            return response()->json(['error' => ['message' => 'Wallet not found']], 404);
        }

        return response()->json($this->walletService->getBalance($walletId));
    }

    public function transactions(Request $request, string $walletId): JsonResponse
    {
        $wallet = $this->walletService->getWallet($walletId);
        if (!$wallet || $wallet->merchant_id !== $request->user()->merchant->id) {
            return response()->json(['error' => ['message' => 'Wallet not found']], 404);
        }

        $transactions = $this->walletService->getTransactions($walletId, $request->all());

        return response()->json([
            'object' => 'list',
            'data' => $transactions->items(),
            'has_more' => $transactions->hasMorePages(),
            'total' => $transactions->total(),
        ]);
    }

    public function freeze(Request $request, string $walletId): JsonResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        $wallet = $this->walletService->getWallet($walletId);
        if (!$wallet || $wallet->merchant_id !== $request->user()->merchant->id) {
            return response()->json(['error' => ['message' => 'Wallet not found']], 404);
        }

        $this->walletService->freezeWallet($walletId, $request->reason);
        return response()->json(['success' => true, 'message' => 'Wallet frozen']);
    }

    public function unfreeze(Request $request, string $walletId): JsonResponse
    {
        $wallet = $this->walletService->getWallet($walletId);
        if (!$wallet || $wallet->merchant_id !== $request->user()->merchant->id) {
            return response()->json(['error' => ['message' => 'Wallet not found']], 404);
        }

        $this->walletService->unfreezeWallet($walletId);
        return response()->json(['success' => true, 'message' => 'Wallet unfrozen']);
    }

    protected function formatWallet($wallet): array
    {
        return [
            'id' => $wallet->id,
            'wallet_id' => $wallet->wallet_id,
            'object' => 'wallet',
            'name' => $wallet->name,
            'currency' => $wallet->currency,
            'type' => $wallet->type,
            'status' => $wallet->status,
            'available_balance' => (float) $wallet->available_balance,
            'locked_balance' => (float) $wallet->locked_balance,
            'total_balance' => $wallet->getTotalBalance(),
            'created_at' => $wallet->created_at->timestamp,
        ];
    }
}
