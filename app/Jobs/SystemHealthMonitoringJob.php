<?php

namespace App\Jobs;

use App\Models\PaymentGateway;
use App\Models\SystemActivity;
use App\Services\SystemHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\GatewayHealthCheck;

class SystemHealthMonitoringJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(SystemHealthService $systemHealthService): void
    {
        try {
            // Perform health checks on all active gateways
            $gateways = PaymentGateway::where('is_active', true)->get();

            foreach ($gateways as $gateway) {
                try {
                    $systemHealthService->performGatewayHealthCheck($gateway);
                    
                    // Log successful health check
                    SystemActivity::logSystemActivity(
                        'system',
                        "Gateway health check completed for {$gateway->name}",
                        'success',
                        ['gateway_id' => $gateway->id, 'gateway_name' => $gateway->name]
                    );
                } catch (\Exception $e) {
                    // Log failed health check
                    SystemActivity::logSystemActivity(
                        'system',
                        "Gateway health check failed for {$gateway->name}: {$e->getMessage()}",
                        'error',
                        ['gateway_id' => $gateway->id, 'gateway_name' => $gateway->name, 'error' => $e->getMessage()]
                    );
                }
            }

            // Clean up old health check records
            GatewayHealthCheck::where('checked_at', '<', now()->subDays(30))->delete();
            
            // Clean up old system activities
            SystemActivity::cleanupOldActivities();

        } catch (\Exception $e) {
            // Log job failure
            Log::error('System health monitoring job failed: ' . $e->getMessage());
            
            SystemActivity::logSystemActivity(
                'system',
                "System health monitoring failed: {$e->getMessage()}",
                'error',
                ['error' => $e->getMessage()]
            );
        }
    }
}