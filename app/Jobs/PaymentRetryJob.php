<?php

namespace App\Jobs;

use App\Models\Disbursement;
use App\Services\PaymentRetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class PaymentRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 1; // Each retry attempt is a separate job

    protected $disbursement;
    protected $retryStrategy;

    public function __construct(Disbursement $disbursement, string $retryStrategy = 'standard')
    {
        $this->disbursement = $disbursement;
        $this->retryStrategy = $retryStrategy;
        $this->onQueue('retries');
    }

    public function handle(PaymentRetryService $retryService)
    {
        Log::info('Processing payment retry', [
            'disbursement_id' => $this->disbursement->id,
            'retry_strategy' => $this->retryStrategy,
            'current_retry_count' => $this->disbursement->retry_count
        ]);

        try {
            $result = $retryService->retryPayment($this->disbursement, $this->retryStrategy);

            if ($result['success']) {
                Log::info('Payment retry successful', [
                    'disbursement_id' => $this->disbursement->id,
                    'gateway_used' => $result['gateway_used'] ?? null
                ]);
            } else {
                Log::warning('Payment retry failed', [
                    'disbursement_id' => $this->disbursement->id,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);

                // Schedule next retry if applicable
                $retryService->scheduleNextRetry($this->disbursement, $result);
            }

        } catch (Exception $e) {
            Log::error('Payment retry job failed', [
                'disbursement_id' => $this->disbursement->id,
                'error' => $e->getMessage()
            ]);

            // Mark retry as failed but don't fail the disbursement yet
            $this->disbursement->increment('retry_count');
            
            throw $e;
        }
    }

    public function failed(Exception $exception)
    {
        Log::error('Payment retry job failed permanently', [
            'disbursement_id' => $this->disbursement->id,
            'retry_strategy' => $this->retryStrategy,
            'error' => $exception->getMessage()
        ]);


        // Don't automatically fail the disbursement - let retry service decide
        $retryService = app(PaymentRetryService::class);
        $retryService->handleRetryFailure($this->disbursement, []);
    }
}