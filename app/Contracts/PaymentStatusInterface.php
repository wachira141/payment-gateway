<?php

namespace App\Contracts;

interface PaymentStatusInterface
{
    /**
     * Get payment status with standardized response format
     * 
     * @param string $transactionId Gateway-specific transaction identifier
     * @return array Standardized payment status response
     */
    public function getPaymentStatus(string $transactionId): array;
}