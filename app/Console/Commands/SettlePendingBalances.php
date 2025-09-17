<?php

namespace App\Console\Commands;

use App\Jobs\SettlePendingBalancesJob;
use Illuminate\Console\Command;

class SettlePendingBalances extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'balances:settle {--dry-run : Show what would be settled without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Settle pending balances that have passed the clearing period';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting pending balance settlement...');

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes will be made');
            // TODO: Add dry-run logic to show what would be settled
            return;
        }

        try {
            SettlePendingBalancesJob::dispatch();
            $this->info('Balance settlement job dispatched successfully.');
        } catch (\Exception $e) {
            $this->error('Failed to dispatch balance settlement job: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}