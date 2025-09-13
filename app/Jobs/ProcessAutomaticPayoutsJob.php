<?php

namespace App\Jobs;

use App\Services\PayoutAutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAutomaticPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;

    /**
     * Execute the job.
     */
    public function handle(PayoutAutomationService $payoutAutomationService): void
    {
        Log::info('Starting automatic payouts processing');

        try {
            $results = $payoutAutomationService->processAutomaticPayouts();
            
            Log::info('Automatic payouts completed', [
                'processed' => $results['processed'],
                'failed' => $results['failed'],
                'total_amount' => $results['total_amount'],
                'errors_count' => count($results['errors'])
            ]);

            // Log individual errors
            foreach ($results['errors'] as $error) {
                Log::error('Automatic payout error', $error);
            }

        } catch (\Exception $e) {
            Log::error('Automatic payouts job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessAutomaticPayoutsJob failed completely', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}