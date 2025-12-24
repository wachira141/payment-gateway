<?php

namespace App\Services;

use App\Models\PaymentGateway;
use App\Services\MpesaPaymentService;
use App\Services\KenyaBankTransferService;
use App\Services\AirtelMoneyPaymentService;
use App\Services\MTNMobileMoneyPaymentService;
use Illuminate\Support\Facades\Log;
use Exception;

class PaymentGatewayFactory
{
    protected $gateways = [];
    protected $fallbackOrder = ['mpesa', 'mtn_momo', 'airtel_money', 'bank_transfer'];
    public function __construct(
        MpesaPaymentService $mpesaService,
        KenyaBankTransferService $bankTransferService,
        AirtelMoneyPaymentService $airtelMoneyService,
        MTNMobileMoneyPaymentService $mtnMoMoService
    ) {
        $this->gateways = [
            'mpesa' => $mpesaService,
            'bank_transfer' => $bankTransferService,
            'airtel_money' => $airtelMoneyService,
            'mtn_momo' => $mtnMoMoService,
            'telebirr' => new TelebirrPaymentService(),
            'stripe' => new StripePaymentService(),
        ];
    }

    /**
     * Get the best available gateway for a payment
     */
    public function getBestGateway(array $criteria = []): ?object
    {
        $availableGateways = $this->getAvailableGateways($criteria);
        
        if (empty($availableGateways)) {
            return null;
        }

        // Return the highest priority healthy gateway
        return $availableGateways[0]['service'];
    }

    /**
     * Get all available gateways sorted by priority and health
     */
    public function getAvailableGateways(array $criteria = []): array
    {
        $gateways = PaymentGateway::where('is_enabled', true)
            ->orderBy('priority', 'asc')
            ->get();

        $available = [];

        foreach ($gateways as $gateway) {
            if (!isset($this->gateways[$gateway->type])) {
                continue;
            }

            $service = $this->gateways[$gateway->type];
            
            // Check gateway health
            $isHealthy = $this->checkGatewayHealth($gateway->type);
            
            // Apply criteria filters
            if ($this->matchesCriteria($gateway, $criteria)) {
                $available[] = [
                    'gateway' => $gateway,
                    'service' => $service,
                    'type' => $gateway->type,
                    'is_healthy' => $isHealthy,
                    'priority' => $gateway->priority
                ];
            }
        }

        // Sort by health first, then priority
        usort($available, function ($a, $b) {
            if ($a['is_healthy'] !== $b['is_healthy']) {
                return $b['is_healthy'] - $a['is_healthy']; // Healthy first
            }
            return $a['priority'] - $b['priority']; // Lower priority number = higher priority
        });

        return $available;
    }

    /**
     * Check if a gateway is healthy
     */
    public function checkGatewayHealth(string $gatewayType): bool
    {
        if (!isset($this->gateways[$gatewayType])) {
            return false;
        }

        try {
            $service = $this->gateways[$gatewayType];
            
            // Check if service has a health check method
            if (method_exists($service, 'healthCheck')) {
                return $service->healthCheck();
            }

            // Basic availability check
            return true;

        } catch (Exception $e) {
            Log::warning('Gateway health check failed', [
                'gateway_type' => $gatewayType,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Get gateway service by type
     */
    public function getGatewayService(string $type): ?object
    {
        return $this->gateways[$type] ?? null;
    }

    /**
     * Check if gateway matches criteria
     */
    protected function matchesCriteria($gateway, array $criteria): bool
    {
        // Amount range check
        if (isset($criteria['amount'])) {
            $amount = $criteria['amount'];
            if (isset($gateway->min_amount) && $amount < $gateway->min_amount) {
                return false;
            }
            if (isset($gateway->max_amount) && $amount > $gateway->max_amount) {
                return false;
            }
        }

        // Currency check
        if (isset($criteria['currency'])) {
            $supportedCurrencies = $gateway->supported_currencies ?? ['KES'];
            if (!in_array($criteria['currency'], $supportedCurrencies)) {
                return false;
            }
        }

        // Payment method check
        if (isset($criteria['payment_method'])) {
            if ($criteria['payment_method'] === 'mobile_money' && !in_array($gateway->type, ['mpesa', 'mtn_momo', 'airtel_money'])) {
                return false;
            }
            if ($criteria['payment_method'] === 'bank_transfer' && $gateway->type !== 'bank_transfer') {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark gateway as having an issue
     */
    protected function markGatewayIssue(string $gatewayType, string $error): void
    {
        try {
            $gateway = PaymentGateway::where('type', $gatewayType)->first();
            if ($gateway) {
                $gateway->update([
                    'last_error' => $error,
                    'last_error_at' => now(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to mark gateway issue', [
                'gateway_type' => $gatewayType,
                'error' => $e->getMessage()
            ]);
        }
    }
}