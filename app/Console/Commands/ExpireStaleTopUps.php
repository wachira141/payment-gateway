<?php

namespace App\Console\Commands;

use App\Services\WalletTopUpService;
use Illuminate\Console\Command;

class ExpireStaleTopUps extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'wallet:expire-topups {--dry-run : Show what would be expired without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Expire pending wallet top-ups that have passed their expiration time';

    protected WalletTopUpService $topUpService;

    public function __construct(WalletTopUpService $topUpService)
    {
        parent::__construct();
        $this->topUpService = $topUpService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for expired wallet top-ups...');

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes will be made');
            
            $expiredCount = \App\Models\WalletTopUp::getExpiredPending()->count();
            $this->info("Found {$expiredCount} pending top-ups that would be expired.");
            
            return 0;
        }

        try {
            $count = $this->topUpService->expireStaleTopUps();
            $this->info("Expired {$count} stale top-up(s).");
        } catch (\Exception $e) {
            $this->error('Failed to expire stale top-ups: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
