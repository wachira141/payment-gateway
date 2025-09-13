<?php

namespace App\Services;

use App\Models\Charge;

interface ConnectorInterface
{
    /**
     * Get connector name
     */
    public function getName(): string;

    /**
     * Check if connector supports country and currency
     */
    public function supportsCountryAndCurrency(string $countryCode, string $currency): bool;

    /**
     * Get supported payment methods
     */
    public function getSupportedPaymentMethods(): array;

    /**
     * Process payment
     */
    public function processPayment(Charge $charge, array $paymentData): array;

    /**
     * Check payment status
     */
    public function checkPaymentStatus(Charge $charge): array;

    /**
     * Process refund
     */
    public function processRefund(Charge $charge, float $amount, array $metadata = []): array;

    /**
     * Validate payment method details
     */
    public function validatePaymentMethod(array $paymentMethodDetails): bool;
}