<?php

namespace App\Services;

use App\Models\MerchantWallet;
use App\Models\MerchantBalance;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletTransferService extends BaseService
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * Transfer between wallets
     */
    public function transferBetweenWallets(string $fromWalletId, string $toWalletId, float $amount, string $description): array
    {
        $fromWallet = $this->walletService->getWallet($fromWalletId);
        $toWallet = $this->walletService->getWallet($toWalletId);

        if (!$fromWallet) {
            throw new \Exception('Source wallet not found');
        }

        if (!$toWallet) {
            throw new \Exception('Destination wallet not found');
        }

        if (!$fromWallet->isActive()) {
            throw new \Exception('Source wallet is not active');
        }

        if (!$toWallet->isActive()) {
            throw new \Exception('Destination wallet is not active');
        }

        if ($fromWallet->currency !== $toWallet->currency) {
            throw new \Exception('Currency mismatch: cannot transfer between wallets with different currencies');
        }

        // Check if can debit
        $canDebit = $this->walletService->canDebit($fromWalletId, $amount);
        if (!$canDebit['allowed']) {
            throw new \Exception($canDebit['reason']);
        }

        return DB::transaction(function () use ($fromWallet, $toWallet, $amount, $description) {
            $reference = 'TRF' . strtoupper(substr(md5(uniqid()), 0, 12));
            $balance_before = $fromWallet->getAvailableBalance();
            $balance_after = $balance_before - $amount;

            // Debit source wallet
            $debitTransaction = WalletTransaction::createTransaction($fromWallet, [
                'type' => 'transfer_out',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $balance_before,
                'balance_after' => $balance_after,
                'source_type' => get_class($toWallet)?? null,
                'source_id' => $toWallet->id,
                'status' => 'completed',
                'reference' => $reference,
                'description' => $description,
                'metadata' => [
                    'to_wallet_id' => $toWallet->wallet_id,
                ],
                'completed_at' => now(),
            ]);

            $fromWallet->debit($amount);
            $debitTransaction->update(['balance_after' => $fromWallet->fresh()->available_balance]);

            $balance_before = $toWallet->getAvailableBalance();
            $balance_after = $balance_before + $amount;
            // Credit destination wallet
            $creditTransaction = WalletTransaction::createTransaction($toWallet, [
                'type' => 'transfer_in',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $balance_before,
                'balance_after' => $balance_after,
                'source_type' => get_class($fromWallet)?? null,
                'source_id' => $fromWallet->id,
                'status' => 'completed',
                'reference' => $reference,
                'description' => $description,
                'metadata' => [
                    'from_wallet_id' => $fromWallet->wallet_id,
                ],
                'completed_at' => now(),
            ]);

            $toWallet->credit($amount);
            $creditTransaction->update(['balance_after' => $toWallet->fresh()->available_balance]);

            $this->logActivity('wallet.transfer', [
                'from_wallet_id' => $fromWallet->wallet_id,
                'to_wallet_id' => $toWallet->wallet_id,
                'amount' => $amount,
                'reference' => $reference,
            ]);

            return [
                'success' => true,
                'reference' => $reference,
                'from_wallet' => [
                    'wallet_id' => $fromWallet->wallet_id,
                    'balance' => $fromWallet->fresh()->available_balance,
                ],
                'to_wallet' => [
                    'wallet_id' => $toWallet->wallet_id,
                    'balance' => $toWallet->fresh()->available_balance,
                ],
                'debit_transaction' => $debitTransaction->transaction_id,
                'credit_transaction' => $creditTransaction->transaction_id,
            ];
        });
    }

    /**
     * Transfer from MerchantBalance to Wallet (sweep)
     */
    public function transferFromBalance(string $merchantId, string $currency, float $amount, string $walletId): array
    {
        $wallet = $this->walletService->getWallet($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        if (!$wallet->isActive()) {
            throw new \Exception('Wallet is not active');
        }

        if ($wallet->merchant_id !== $merchantId) {
            throw new \Exception('Wallet does not belong to this merchant');
        }

        if (strtoupper($currency) !== $wallet->currency) {
            throw new \Exception('Currency mismatch between balance and wallet');
        }

        // Get merchant balance
        $balance = MerchantBalance::findByMerchantAndCurrency($merchantId, $currency);

        if (!$balance) {
            throw new \Exception('Merchant balance not found for currency: ' . $currency);
        }

        if (!$balance->hasSufficientAvailable($amount)) {
            throw new \Exception('Insufficient available balance');
        }

        return DB::transaction(function () use ($balance, $wallet, $amount) {
            $reference = 'SWP' . strtoupper(substr(md5(uniqid()), 0, 12));

            // Debit from MerchantBalance
            $balance->debitAvailable($amount);

            // Credit to Wallet with sweep_in type
            $transaction = WalletTransaction::createTransaction($wallet, [
                'type' => 'sweep_in',
                'direction' => 'credit',
                'amount' => $amount,
                'status' => 'completed',
                'reference' => $reference,
                'description' => 'Sweep from merchant balance',
                'metadata' => [
                    'source' => 'merchant_balance',
                    'balance_currency' => $balance->currency,
                ],
                'completed_at' => now(),
            ]);

            $wallet->credit($amount);
            $transaction->update(['balance_after' => $wallet->fresh()->available_balance]);

            $this->logActivity('wallet.sweep_from_balance', [
                'merchant_id' => $balance->merchant_id,
                'wallet_id' => $wallet->wallet_id,
                'amount' => $amount,
                'reference' => $reference,
            ]);

            return [
                'success' => true,
                'reference' => $reference,
                'source_balance' => [
                    'available' => $balance->fresh()->available_amount,
                    'currency' => $balance->currency,
                ],
                'wallet' => [
                    'wallet_id' => $wallet->wallet_id,
                    'balance' => $wallet->fresh()->available_balance,
                ],
                'transaction_id' => $transaction->transaction_id,
            ];
        });
    }

    /**
     * Get available balance for sweep
     */
    public function getAvailableForSweep(string $merchantId, string $currency): array
    {
        $balance = MerchantBalance::findByMerchantAndCurrency($merchantId, $currency);

        if (!$balance) {
            return [
                'available' => 0,
                'currency' => strtoupper($currency),
                'can_sweep' => false,
            ];
        }

        return [
            'available' => (float) $balance->available_amount,
            'pending' => (float) $balance->pending_amount,
            'currency' => $balance->currency,
            'can_sweep' => $balance->available_amount > 0,
        ];
    }
}
