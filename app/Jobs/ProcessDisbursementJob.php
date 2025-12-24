<?php

namespace App\Jobs;

use App\Models\Disbursement;
use App\Services\WalletService;
use App\Services\PaymentGatewayService;
use App\Events\DisbursementStatusUpdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessDisbursementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;
    public $backoff = [30, 60, 120];

    protected Disbursement $disbursement;
    protected string $oldStatus;

    public function __construct(Disbursement $disbursement)
    {
        $this->disbursement = $disbursement->fresh();
        $this->oldStatus = $disbursement->status;
        $this->onQueue('disbursements');
    }

    public function handle(WalletService $walletService, PaymentGatewayService $paymentGatewayService)
    {
        Log::info('Processing disbursement job', [
            'disbursement_id' => $this->disbursement->disbursement_id,
            'attempt' => $this->attempts()
        ]);

        try {
            // 1. Mark as processing
            $this->updateAndBroadcastStatus('processing', [
                'processed_at' => now(),
            ]);

            // 2. Load relationships
            $this->disbursement->load(['wallet', 'beneficiary']);

            // 3. Process actual payment through gateway
            $result = $this->processPayment($paymentGatewayService);

            if ($result['success']) {
                // 4. Mark as sending/gateway submitted
                $updateData = [
                    'status' => 'sending',
                    'gateway_response' => $result['data'] ?? null,
                ];

                if (isset($result['data']['transaction_id'])) {
                    $updateData['gateway_transaction_id'] = $result['data']['transaction_id'];
                }
                if (isset($result['data']['disbursement_id'])) {
                    $updateData['gateway_disbursement_id'] = $result['data']['disbursement_id'];
                }

                $this->updateAndBroadcastStatus('sending', $updateData);

                // 5. Complete the hold (debit from wallet)
                $totalAmount = $this->disbursement->gross_amount + $this->disbursement->fee_amount;
                
                $wallet = $this->disbursement->wallet;
                if ($wallet) {
                    $wallet->completeHold($totalAmount);
                    $wallet->updateWithdrawalUsage($totalAmount);
                }

                // 6. Mark as completed (for now - in production, wait for webhook confirmation)
                $this->updateAndBroadcastStatus('completed', [
                    'completed_at' => now(),
                ]);

                // 7. Update batch status if part of batch
                if ($this->disbursement->disbursementBatch) {
                    $this->disbursement->disbursementBatch->updateStatusFromDisbursements();
                }

                Log::info('Disbursement completed successfully', [
                    'disbursement_id' => $this->disbursement->disbursement_id,
                    'transaction_id' => $result['data']['transaction_id'] ?? null,
                ]);
            } else {
                $this->handleFailure($result['error'] ?? 'Unknown error', $walletService);
            }
        } catch (Exception $e) {
            Log::error('Disbursement processing failed', [
                'disbursement_id' => $this->disbursement->disbursement_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->handleFailure($e->getMessage(), $walletService);
            throw $e;
        }
    }

    /**
     * Process payment through the appropriate gateway
     */
    protected function processPayment(PaymentGatewayService $paymentGatewayService): array
    {
        $beneficiary = $this->disbursement->beneficiary;
        $payoutMethod = $this->disbursement->payout_method;

        // Get beneficiary details
        $beneficiaryData = $beneficiary ? $beneficiary->toArray() : [];

        try {
            // Route to appropriate payment processor based on payout method
            $result = match ($payoutMethod) {
                'mobile_money' => $this->processMobileMoneyPayment($paymentGatewayService, $beneficiaryData),
                'bank_transfer' => $this->processBankTransferPayment($paymentGatewayService, $beneficiaryData),
                'international_wire' => $this->processInternationalWirePayment($paymentGatewayService, $beneficiaryData),
                default => $this->processBankTransferPayment($paymentGatewayService, $beneficiaryData),
            };

            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process mobile money payment (M-Pesa, Airtel Money, etc.)
     */
    protected function processMobileMoneyPayment(PaymentGatewayService $gatewayService, array $beneficiary): array
    {
        // For now, simulate successful payment
        // In production, integrate with M-Pesa B2C or other mobile money APIs
        
        $simulateSuccess = rand(1, 100) > 5; // 95% success rate

        if ($simulateSuccess) {
            return [
                'success' => true,
                'data' => [
                    'transaction_id' => 'mm_' . uniqid(),
                    'disbursement_id' => 'mmdb_' . uniqid(),
                    'provider' => 'mpesa',
                    'processed_at' => now()->toISOString(),
                ],
            ];
        }

        return [
            'success' => false,
            'error' => 'Mobile money transfer failed',
        ];
    }

    /**
     * Process bank transfer payment
     */
    protected function processBankTransferPayment(PaymentGatewayService $gatewayService, array $beneficiary): array
    {
        // For now, simulate successful payment
        // In production, integrate with bank transfer APIs
        
        $simulateSuccess = rand(1, 100) > 5; // 95% success rate

        if ($simulateSuccess) {
            return [
                'success' => true,
                'data' => [
                    'transaction_id' => 'bt_' . uniqid(),
                    'disbursement_id' => 'btdb_' . uniqid(),
                    'provider' => 'bank_transfer',
                    'processed_at' => now()->toISOString(),
                ],
            ];
        }

        return [
            'success' => false,
            'error' => 'Bank transfer failed',
        ];
    }

    /**
     * Process international wire payment
     */
    protected function processInternationalWirePayment(PaymentGatewayService $gatewayService, array $beneficiary): array
    {
        // For now, simulate successful payment
        // In production, integrate with SWIFT or correspondent banking APIs
        
        $simulateSuccess = rand(1, 100) > 10; // 90% success rate for international

        if ($simulateSuccess) {
            return [
                'success' => true,
                'data' => [
                    'transaction_id' => 'iw_' . uniqid(),
                    'disbursement_id' => 'iwdb_' . uniqid(),
                    'provider' => 'international_wire',
                    'processed_at' => now()->toISOString(),
                ],
            ];
        }

        return [
            'success' => false,
            'error' => 'International wire transfer failed',
        ];
    }

    /**
     * Handle disbursement failure
     */
    protected function handleFailure(string $error, WalletService $walletService): void
    {
        $isFinalAttempt = ($this->attempts() >= $this->tries);

        if ($isFinalAttempt) {
            // Release held funds back to wallet
            try {
                $totalAmount = $this->disbursement->gross_amount + $this->disbursement->fee_amount;
                $wallet = $this->disbursement->wallet;
                
                if ($wallet && $wallet->locked_balance >= $totalAmount) {
                    $walletService->releaseFunds(
                        $wallet->wallet_id,
                        $totalAmount,
                        "Failed disbursement {$this->disbursement->disbursement_id}: {$error}",
                        $this->disbursement->disbursement_id
                    );
                }
            } catch (Exception $e) {
                Log::error('Failed to release funds after disbursement failure', [
                    'disbursement_id' => $this->disbursement->disbursement_id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Mark as failed
            $this->updateAndBroadcastStatus('failed', [
                'failed_at' => now(),
                'failure_reason' => $error,
            ]);

            // Update batch status if part of batch
            if ($this->disbursement->disbursementBatch) {
                $this->disbursement->disbursementBatch->updateStatusFromDisbursements();
            }
        } else {
            // Will retry
            Log::info('Disbursement will retry', [
                'disbursement_id' => $this->disbursement->disbursement_id,
                'attempt' => $this->attempts(),
                'next_attempt_in' => $this->backoff[$this->attempts() - 1] ?? 60,
            ]);
        }
    }

    /**
     * Job failed permanently
     */
    public function failed(\Throwable $error): void
    {
        Log::error('Disbursement job failed permanently', [
            'disbursement_id' => $this->disbursement->disbursement_id,
            'attempts' => $this->attempts(),
            'error' => $error->getMessage(),
        ]);

        // Ensure status is marked as failed
        $this->disbursement->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $error->getMessage(),
        ]);
    }

    /**
     * Update status and broadcast event
     */
    protected function updateAndBroadcastStatus(string $newStatus, array $updateData = []): void
    {
        $this->disbursement->update(array_merge(
            ['status' => $newStatus],
            $updateData
        ));

        $this->disbursement->refresh();

        // Broadcast status update
        try {
            broadcast(new DisbursementStatusUpdated($this->disbursement, [
                'old_status' => $this->oldStatus,
                'new_status' => $newStatus,
                'attempt' => $this->attempts(),
            ]));
        } catch (Exception $e) {
            Log::warning('Failed to broadcast disbursement status', [
                'disbursement_id' => $this->disbursement->disbursement_id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->oldStatus = $newStatus;
    }
}
