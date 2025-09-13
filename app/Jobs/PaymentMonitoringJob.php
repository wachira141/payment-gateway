<?php

namespace App\Jobs;

use App\Models\Disbursement;
use App\Services\PaymentMonitoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PaymentMonitoringJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 1; // Don't retry monitoring jobs

    public function __construct()
    {
        $this->onQueue('monitoring');
    }

    public function handle(PaymentMonitoringService $monitoringService)
    {
        Log::info('Running payment monitoring job');

        try {
            // Check for stuck processing payments (older than 30 minutes)
            $stuckPayments = Disbursement::where('status', 'processing')
                ->where('processed_at', '<', Carbon::now()->subMinutes(30))
                ->get();

            foreach ($stuckPayments as $payment) {
                Log::warning('Found stuck payment', [
                    'disbursement_id' => $payment->id,
                    'processed_at' => $payment->processed_at
                ]);

                $monitoringService->handleStuckPayment($payment);
            }

            // Check for pending payments that need status updates
            $pendingPayments = Disbursement::whereIn('status', ['processing', 'pending'])
                ->where('created_at', '>', Carbon::now()->subHours(24))
                ->get();

            foreach ($pendingPayments as $payment) {
                $monitoringService->checkPaymentStatus($payment);
            }

            // Generate monitoring report
            $monitoringService->generateMonitoringReport();

            Log::info('Payment monitoring completed successfully');

        } catch (\Exception $e) {
            Log::error('Payment monitoring job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}