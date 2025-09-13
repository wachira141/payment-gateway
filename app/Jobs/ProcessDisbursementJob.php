<?php

namespace App\Jobs;

use App\Models\Disbursement;
use App\Services\AdminDisbursementManagementService;
use App\Notifications\DisbursementProcessingNotification;
use App\Notifications\DisbursementSentNotification;
use App\Notifications\DisbursementFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Events\DisbursementStatusUpdated;

class ProcessDisbursementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;
    public $backoff = [30, 60, 120];

    protected $disbursement;
    protected $oldStatus;

    public function __construct(Disbursement $disbursement)
    {
        $this->disbursement = $disbursement->fresh(); // Ensure fresh instance
        $this->oldStatus = $disbursement->status; // Capture initial status
        $this->onQueue('disbursements');
    }

    public function handle(AdminDisbursementManagementService $disbursementService)
    {
        Log::info('Processing disbursement job', [
            'disbursement_id' => $this->disbursement->id,
            'attempt' => $this->attempts()
        ]);

        try {
            // 1. Broadcast PROCESSING status and send notification
            $this->updateAndBroadcastStatus('processing', [
                'processed_at' => now(),
                'attempt' => $this->attempts()
            ]);

            // Send processing notification
            if ($this->disbursement->user) {
                $this->disbursement->user->notify(
                    new DisbursementProcessingNotification($this->disbursement, 'provider')
                );
            }

            // 2. Process payment
            $result = $disbursementService->processActualPayment($this->disbursement);
            Log::info('Disbursement processing result', [
                'disbursement_id' => $this->disbursement->id,
                'result' => $result
            ]);

            if ($result['success']) {
                // 3. Broadcast GATEWAY_SUBMITTED status and send success notification
                $updateData = [
                    'status' => 'sending',
                    'gateway_response' => $result['data'] ?? null
                ];

                if (isset($result['data']['conversation_id'])) {
                    $updateData['gateway_transaction_id'] = $result['data']['conversation_id'];
                }
                if (isset($result['data']['originator_conversation_id'])) {
                    $updateData['gateway_disbursement_id'] = $result['data']['originator_conversation_id'];
                }

                $this->updateAndBroadcastStatus('sending', $updateData, [
                    'gateway_response' => $result['data'] ?? null,
                    'conversation_id' => $result['data']['conversation_id'] ?? null
                ]);

                // Send success notification
                if ($this->disbursement->user) {
                    $this->disbursement->user->notify(
                        new DisbursementSentNotification($this->disbursement, 'provider')
                    );
                }

                Log::info('Disbursement initiated successfully', [
                    'disbursement_id' => $this->disbursement->id,
                    'conversation_id' => $result['data']['conversation_id'] ?? null
                ]);
            } else {
                $this->handleFailure($result['error'] ?? 'Unknown error');
            }
        } catch (Exception $e) {
            Log::error('Disbursement processing failed', [
                'disbursement_id' => $this->disbursement->id,
                'error' => $e->getMessage()
            ]);
            $this->handleFailure($e->getMessage());
            throw $e;
        }
    }

    public function failed($error)
    {
        Log::error('Disbursement job failed permanently', [
            'disbursement_id' => $this->disbursement->id,
            'attempts' => $this->attempts(),
            'error' => $error
        ]);

        $this->updateAndBroadcastStatus('failed', [
            'failed_at' => now(),
            'failure_reason' => $error,
            'final_attempt' => true
        ]);

        // Send failure notification
        if ($this->disbursement->user) {
            $this->disbursement->user->notify(
                new DisbursementFailedNotification($this->disbursement, $error, 'provider')
            );
        }
    }

    protected function handleFailure(string $error)
    {
        $isFinalAttempt = ($this->attempts() >= $this->tries);

        $this->updateAndBroadcastStatus(
            $isFinalAttempt ? 'failed' : 'processing',
            [
                'failed_at' => $isFinalAttempt ? now() : null,
                'failure_reason' => $error,
                'final_attempt' => $isFinalAttempt
            ],
            [
                'next_attempt_at' => $isFinalAttempt ? null : now()->addSeconds($this->backoff[$this->attempts() - 1] ?? 10)
            ]
        );

        // Send failure notification only on final attempt
        if ($isFinalAttempt && $this->disbursement->user) {
            $this->disbursement->user->notify(
                new DisbursementFailedNotification($this->disbursement, $error, 'provider')
            );
        }

        if ($isFinalAttempt) {
            $this->failed($error);
        }
    }

    protected function updateAndBroadcastStatus(string $newStatus, array $updateData = [], array $metadata = []) {
        $this->disbursement->update(array_merge(
            ['status' => $newStatus],
            $updateData
        ));

        // Refresh to get updated attributes
        $this->disbursement->refresh();

        broadcast(new DisbursementStatusUpdated($this->disbursement, array_merge(
            [
                'old_status' => $this->oldStatus,
                'new_status' => $newStatus,
                'attempt' => $this->attempts()
            ],
            $metadata
        )));

        // Update old status for next broadcast
        $this->oldStatus = $newStatus;
    }
}