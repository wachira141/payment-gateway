<?php

namespace App\Console\Commands;

use App\Services\OutboundWebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryFailedWebhooks extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'webhooks:retry {--limit=50 : Maximum number of webhooks to retry}';

    /**
     * The console command description.
     */
    protected $description = 'Retry failed webhook deliveries that are ready for retry';

    protected OutboundWebhookService $webhookService;

    public function __construct(OutboundWebhookService $webhookService)
    {
        parent::__construct();
        $this->webhookService = $webhookService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting webhook retry process...');

        try {
            $this->webhookService->retryFailedDeliveries();
            
            $this->info('Webhook retry process completed successfully.');
            
            Log::info('Webhook retry command executed', [
                'command' => $this->signature,
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to retry webhooks: ' . $e->getMessage());
            
            Log::error('Webhook retry command failed', [
                'error' => $e->getMessage(),
                'command' => $this->signature,
            ]);

            return Command::FAILURE;
        }
    }
}