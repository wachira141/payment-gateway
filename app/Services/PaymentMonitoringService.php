<?php

namespace App\Services;

use App\Models\Disbursement;
use App\Jobs\PaymentRetryJob;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Events\PaymentStuckDetected;
use App\Events\DisbursementStatusUpdated;

class PaymentMonitoringService
{
    protected $stuckPaymentThreshold = 30; // minutes
    protected $pendingPaymentThreshold = 24; // hours

    public function handleStuckPayment(Disbursement $payment): void
    {
        Log::warning('Handling stuck payment', [
            'disbursement_id' => $payment->id,
            'processed_at' => $payment->processed_at,
            'minutes_stuck' => Carbon::parse($payment->processed_at)->diffInMinutes(now())
        ]);

        // Check actual status from gateway
        $actualStatus = $this->checkGatewayStatus($payment);

        broadcast(new PaymentStuckDetected($payment, [
            'minutes_stuck' => Carbon::parse($payment->processed_at)->diffInMinutes(now()),
            'gateway_status_check' => $actualStatus
        ]));


        if ($actualStatus) {
            $this->updatePaymentStatus($payment, $actualStatus);
        } else {
            // If we can't determine status, mark as failed and retry
            $payment->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => 'Payment stuck in processing state'
            ]);

            // Schedule retry
            PaymentRetryJob::dispatch($payment, 'stuck_payment');
        }
    }

    public function checkPaymentStatus(Disbursement $payment): void
    {
        Log::info('Checking payment status', [
            'disbursement_id' => $payment->id,
            'current_status' => $payment->status
        ]);

        $gatewayStatus = $this->checkGatewayStatus($payment);

        if ($gatewayStatus && $gatewayStatus !== $payment->status) {
            $this->updatePaymentStatus($payment, $gatewayStatus);
        }
    }

    public function generateMonitoringReport(): array
    {
        $report = [
            'timestamp' => now()->toISOString(),
            'stuck_payments' => $this->getStuckPaymentsCount(),
            'failed_payments_last_hour' => $this->getFailedPaymentsCount(1),
            'success_rate_last_24h' => $this->getSuccessRate(24),
            'pending_payments' => $this->getPendingPaymentsCount(),
            'total_amount_processing' => $this->getTotalProcessingAmount(),
            'gateway_performance' => $this->getGatewayPerformance()
        ];

        Log::info('Payment monitoring report generated', $report);

        // Store report for historical tracking
        $this->storeMonitoringReport($report);

        return $report;
    }

    /**
     * Check the actual status of a payment from the gateway.
     * This method should be implemented for each gateway to fetch the latest status.
     */
    protected function checkGatewayStatus(Disbursement $payment): ?string
    {
        try {
            $gatewayFactory = app(PaymentGatewayFactory::class);
            
            // Determine which gateway was used based on payment data
            $gateway = $this->determineGateway($payment);
            $paymentService = $gatewayFactory->getGatewayService($gateway);

            if (!$paymentService) {
                Log::warning('No payment service found for gateway', [
                    'disbursement_id' => $payment->id,
                    'gateway' => $gateway
                ]);
                return null;
            }

            $result = $paymentService->getPaymentStatus($payment->gateway_disbursement_id);
            
            Log::info('Gateway status check result', [
                'disbursement_id' => $payment->id,
                'gateway' => $gateway,
                'success' => $result['success'],
                'standard_status' => $result['status'],
                'gateway_status' => $result['gateway_status']
            ]);

            return $result['status'] ?? null;

        } catch (\Exception $e) {
            Log::error('Failed to check gateway status', [
                'disbursement_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    protected function updatePaymentStatus(Disbursement $payment, string $newStatus): void
    {
        $statusMapping = [
            'succeeded' => 'completed',
            'completed' => 'completed',
            'failed' => 'failed',
            'processing' => 'processing',
            'pending' => 'pending'
        ];

        $localStatus = $statusMapping[$newStatus] ?? $newStatus;

        $updateData = ['status' => $localStatus];

        if ($localStatus === 'completed') {
            $updateData['completed_at'] = now();
        } elseif ($localStatus === 'failed') {
            $updateData['failed_at'] = now();
        }

        $payment->update($updateData);
        // Broadcast the status update event
        broadcast(new DisbursementStatusUpdated($payment, [
            'action' => 'status_updated_by_monitoring',
            'old_status' => $payment->getOriginal('status'),
            'new_status' => $localStatus,
            'gateway_status' => $newStatus,
            'source' => 'monitoring_service'
        ]));

        Log::info('Payment status updated from gateway', [
            'disbursement_id' => $payment->id,
            'old_status' => $payment->status,
            'new_status' => $localStatus,
            'gateway_status' => $newStatus
        ]);
    }

    protected function determineGateway(Disbursement $payment): string
    {
        // Logic to determine which gateway was used
        // This could be based on payment method, amount, or stored gateway info
        return 'mpesa'; // Default for now
    }

    protected function getStuckPaymentsCount(): int
    {
        return Disbursement::where('status', 'processing')
            ->where('processed_at', '<', Carbon::now()->subMinutes($this->stuckPaymentThreshold))
            ->count();
    }

    protected function getFailedPaymentsCount(int $hours): int
    {
        return Disbursement::where('status', 'failed')
            ->where('failed_at', '>', Carbon::now()->subHours($hours))
            ->count();
    }

    protected function getSuccessRate(int $hours): float
    {
        $total = Disbursement::where('created_at', '>', Carbon::now()->subHours($hours))->count();
        $successful = Disbursement::where('status', 'completed')
            ->where('completed_at', '>', Carbon::now()->subHours($hours))
            ->count();

        return $total > 0 ? round(($successful / $total) * 100, 2) : 0;
    }

    protected function getPendingPaymentsCount(): int
    {
        return Disbursement::whereIn('status', ['pending', 'processing'])->count();
    }

    protected function getTotalProcessingAmount(): float
    {
        return Disbursement::whereIn('status', ['pending', 'processing'])
            ->sum('net_amount');
    }

    protected function getGatewayPerformance(): array
    {
        // Calculate performance metrics for each gateway
        return [
            'mpesa' => [
                'success_rate' => $this->getGatewaySuccessRate('mpesa'),
                'avg_processing_time' => $this->getGatewayAvgProcessingTime('mpesa')
            ],
            'bank_transfer' => [
                'success_rate' => $this->getGatewaySuccessRate('bank_transfer'),
                'avg_processing_time' => $this->getGatewayAvgProcessingTime('bank_transfer')
            ]
        ];
    }

    protected function getGatewaySuccessRate(string $gateway): float
    {
        // This would need gateway tracking in disbursements table
        return 95.5; // Placeholder
    }

    protected function getGatewayAvgProcessingTime(string $gateway): float
    {
        // Calculate average time from processed_at to completed_at
        return 120; // Placeholder (seconds)
    }

    protected function storeMonitoringReport(array $report): void
    {
        // Could store in a dedicated monitoring_reports table
        // or send to external monitoring service
        Log::info('Monitoring report stored', ['report_id' => uniqid()]);
    }
}