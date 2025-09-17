<?php

namespace App\Jobs;

use App\Services\BalanceService;
use App\Models\MerchantBalance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SettlePendingBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     * Settle pending balances that are older than the clearing period (e.g., 2-7 days)
     */
    public function handle(BalanceService $balanceService): void
    {
        try {
            // Get all merchant balances with pending amounts
            $balancesWithPending = MerchantBalance::where('pending_amount', '>', 0)
                ->where('last_transaction_at', '<=', now()->subDays(2)) // 2-day clearing period
                ->get();

            foreach ($balancesWithPending as $balance) {
                try {
                    $balanceService->settlePendingBalance(
                        $balance->merchant_id,
                        $balance->currency,
                        $balance->pending_amount
                    );

                    Log::info('Settled pending balance', [
                        'merchant_id' => $balance->merchant_id,
                        'currency' => $balance->currency,
                        'amount' => $balance->pending_amount,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to settle pending balance', [
                        'merchant_id' => $balance->merchant_id,
                        'currency' => $balance->currency,
                        'amount' => $balance->pending_amount,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Pending balance settlement job completed', [
                'processed_balances' => $balancesWithPending->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Pending balance settlement job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}