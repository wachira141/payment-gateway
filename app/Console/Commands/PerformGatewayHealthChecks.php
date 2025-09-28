<?php

namespace App\Console\Commands;

use App\Models\PaymentGateway;
use App\Services\SystemHealthService;
use Illuminate\Console\Command;

class PerformGatewayHealthChecks extends Command
{
    protected $signature = 'health:check-gateways';
    protected $description = 'Perform health checks on all active payment gateways';

    protected $systemHealthService;

    public function __construct(SystemHealthService $systemHealthService)
    {
        parent::__construct();
        $this->systemHealthService = $systemHealthService;
    }

    public function handle()
    {
        $this->info('Starting gateway health checks...');

        $gateways = PaymentGateway::where('is_active', true)->get();

        foreach ($gateways as $gateway) {
            $this->info("Checking gateway: {$gateway->name}");
            
            try {
                $this->systemHealthService->performGatewayHealthCheck($gateway);
                $this->info("✓ Health check completed for {$gateway->name}");
            } catch (\Exception $e) {
                $this->error("✗ Health check failed for {$gateway->name}: {$e->getMessage()}");
            }
        }

        $this->info('Gateway health checks completed.');
    }
}