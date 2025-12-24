<?php

namespace App\Console\Commands;

use App\Models\MerchantWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResetWalletDailyLimits extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'wallet:reset-daily-limits {--dry-run : Show what would be reset without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Reset daily withdrawal usage for all wallets at midnight';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Resetting daily withdrawal limits for all wallets...');

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes will be made');
            
            $count = MerchantWallet::where('daily_withdrawal_used', '>', 0)->count();
            $this->info("Found {$count} wallet(s) with daily usage that would be reset.");
            
            return 0;
        }

        try {
            $count = MerchantWallet::where('daily_withdrawal_used', '>', 0)
                ->update(['daily_withdrawal_used' => 0]);

            Log::info('Daily wallet withdrawal limits reset', ['count' => $count]);
            $this->info("Reset daily limits for {$count} wallet(s).");
        } catch (\Exception $e) {
            Log::error('Failed to reset daily wallet limits', ['error' => $e->getMessage()]);
            $this->error('Failed to reset daily limits: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
