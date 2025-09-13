<?php

namespace App\Services;

class PaymentStatusMapper
{
    /**
     * Standard payment statuses
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_UNKNOWN = 'unknown';

    /**
     * Map M-Pesa status to standard status
     */
    public static function mapMpesaStatus(array $response): string
    {
        if (!$response['success']) {
            return self::STATUS_FAILED;
        }

        $resultCode = $response['result_code'] ?? null;
        
        switch ($resultCode) {
            case 0:
                return self::STATUS_COMPLETED;
            case 1032:
                return self::STATUS_CANCELLED; // User cancelled
            case 1037:
                return self::STATUS_FAILED; // Timeout
            case 1:
                return self::STATUS_FAILED; // Insufficient funds
            case null:
                return self::STATUS_PROCESSING; // Still processing
            default:
                return self::STATUS_FAILED;
        }
    }

    /**
     * Map Stripe status to standard status
     */
    public static function mapStripeStatus(string $stripeStatus): string
    {
        switch ($stripeStatus) {
            case 'requires_payment_method':
            case 'requires_confirmation':
            case 'requires_action':
                return self::STATUS_PENDING;
            case 'processing':
                return self::STATUS_PROCESSING;
            case 'succeeded':
                return self::STATUS_COMPLETED;
            case 'canceled':
                return self::STATUS_CANCELLED;
            case 'requires_capture':
                return self::STATUS_PROCESSING;
            default:
                return self::STATUS_FAILED;
        }
    }

    /**
     * Map Telebirr status to standard status
     */
    public static function mapTelebirrStatus(string $telebirrStatus): string
    {
        switch (strtolower($telebirrStatus)) {
            case 'success':
            case 'completed':
                return self::STATUS_COMPLETED;
            case 'pending':
                return self::STATUS_PENDING;
            case 'processing':
                return self::STATUS_PROCESSING;
            case 'failed':
            case 'error':
                return self::STATUS_FAILED;
            case 'cancelled':
            case 'canceled':
                return self::STATUS_CANCELLED;
            default:
                return self::STATUS_UNKNOWN;
        }
    }

    /**
     * Map Kenya Bank Transfer status to standard status
     */
    public static function mapBankTransferStatus(string $bankStatus): string
    {
        switch (strtolower($bankStatus)) {
            case 'completed':
            case 'success':
            case 'settled':
                return self::STATUS_COMPLETED;
            case 'pending':
            case 'submitted':
                return self::STATUS_PENDING;
            case 'processing':
            case 'in_progress':
                return self::STATUS_PROCESSING;
            case 'failed':
            case 'rejected':
            case 'error':
                return self::STATUS_FAILED;
            case 'cancelled':
            case 'canceled':
                return self::STATUS_CANCELLED;
            default:
                return self::STATUS_UNKNOWN;
        }
    }

    /**
     * Create standardized response format
     */
    public static function createStandardResponse(
        bool $success,
        string $standardStatus,
        string $gatewayStatus,
        string $transactionId,
        ?float $amount = null,
        ?string $completedAt = null,
        ?string $error = null,
        array $gatewayData = []
    ): array {
        return [
            'success' => $success,
            'status' => $standardStatus,
            'gateway_status' => $gatewayStatus,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'completed_at' => $completedAt,
            'error' => $error,
            'gateway_data' => $gatewayData
        ];
    }
}