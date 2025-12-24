<?php

namespace App\Console\Commands;

use App\Models\MerchantWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResetWalletMonthlyLimits extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'wallet:reset-monthly-limits {--dry-run : Show what would be reset without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Reset monthly withdrawal usage for all wallets on the first of each month';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Resetting monthly withdrawal limits for all wallets...');

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes will be made');
            
            $count = MerchantWallet::where('monthly_withdrawal_used', '>', 0)->count();
            $this->info("Found {$count} wallet(s) with monthly usage that would be reset.");
            
            return 0;
        }

        try {
            $count = MerchantWallet::where('monthly_withdrawal_used', '>', 0)
                ->update(['monthly_withdrawal_used' => 0]);

            Log::info('Monthly wallet withdrawal limits reset', ['count' => $count]);
            $this->info("Reset monthly limits for {$count} wallet(s).");
        } catch (\Exception $e) {
            Log::error('Failed to reset monthly wallet limits', ['error' => $e->getMessage()]);
            $this->error('Failed to reset monthly limits: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
