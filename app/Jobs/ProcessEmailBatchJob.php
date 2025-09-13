<?php

namespace App\Jobs;

use App\Services\Email\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessEmailBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $emailBatch;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 2;

    /**
     * The maximum number of seconds the job can run.
     */
    public $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(array $emailBatch)
    {
        $this->emailBatch = $emailBatch;
    }

    /**
     * Execute the job.
     */
    public function handle(EmailService $emailService): void
    {
        try {
            Log::info('Processing email batch', [
                'batch_size' => count($this->emailBatch),
                'job_id' => $this->job->getJobId()
            ]);

            $successCount = 0;
            $failureCount = 0;

            foreach ($this->emailBatch as $emailData) {
                try {
                    // Add batch processing flag
                    $emailData['queue'] = true;
                    $emailData['priority'] = 'bulk';

                    $result = $emailService->sendEmail($emailData);
                    
                    if ($result) {
                        $successCount++;
                    } else {
                        $failureCount++;
                    }

                    // Small delay between emails to avoid overwhelming the system
                    usleep(100000); // 0.1 seconds

                } catch (Exception $e) {
                    $failureCount++;
                    Log::error('Failed to process email in batch', [
                        'recipient' => $emailData['recipient'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('Email batch processing completed', [
                'total' => count($this->emailBatch),
                'success' => $successCount,
                'failures' => $failureCount,
                'job_id' => $this->job->getJobId()
            ]);

        } catch (Exception $e) {
            Log::error('Email batch processing failed', [
                'batch_size' => count($this->emailBatch),
                'error' => $e->getMessage(),
                'job_id' => $this->job->getJobId()
            ]);

            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error('Email batch job failed permanently', [
            'batch_size' => count($this->emailBatch),
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        // Progressive backoff for batch jobs: 5 minutes, 30 minutes
        return [300, 1800];
    }
}