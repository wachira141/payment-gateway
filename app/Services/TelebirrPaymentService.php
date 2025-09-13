<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Contracts\PaymentStatusInterface;
use App\Services\PaymentStatusMapper;
use Illuminate\Support\Facades\Log;

class TelebirrPaymentService implements PaymentStatusInterface
{
    private $appId;
    private $appKey;
    private $merchantId;
    private $environment;
    private $baseUrl;

    public function __construct()
    {
        $this->appId = config('services.telebirr.app_id');
        $this->appKey = config('services.telebirr.app_key');
        $this->merchantId = config('services.telebirr.merchant_id');
        $this->environment = config('services.telebirr.environment', 'sandbox');
        $this->baseUrl = $this->environment === 'production' 
            ? 'https://api.telebirr.com' 
            : 'https://sandbox.telebirr.com';
    }

    /**
     * Generate authentication token
     */
    private function generateAuthToken($requestData)
    {
        $dataString = json_encode($requestData, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $nonce = uniqid();
        
        $stringToSign = $timestamp . $nonce . $dataString;
        $signature = hash_hmac('sha256', $stringToSign, $this->appKey);
        
        return base64_encode(json_encode([
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature
        ]));
    }

    /**
     * Initiate payment request
     */
    public function initiatePayment($amount, $phoneNumber, $orderId, $description)
    {
        try {
            $requestData = [
                'appId' => $this->appId,
                'merchantId' => $this->merchantId,
                'orderId' => $orderId,
                'amount' => $amount,
                'currency' => 'ETB',
                'phoneNumber' => $phoneNumber,
                'description' => $description,
                'notifyUrl' => config('services.telebirr.notify_url'),
                'returnUrl' => config('services.telebirr.return_url'),
                'timeoutExpress' => '30m',
            ];

            $authToken = $this->generateAuthToken($requestData);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => $authToken,
            ])->post($this->baseUrl . '/payment/v1/token', $requestData);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['code'] === '200') {
                    return [
                        'success' => true,
                        'payment_url' => $data['data']['paymentUrl'],
                        'token' => $data['data']['token'],
                        'order_id' => $orderId,
                    ];
                }
                
                return [
                    'success' => false,
                    'error' => $data['msg'] ?? 'Payment initiation failed',
                ];
            }

            return [
                'success' => false,
                'error' => 'Payment request failed: ' . $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('Telebirr payment initiation error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Query payment status
     */
    public function queryPaymentStatus($orderId)
    {
        try {
            $requestData = [
                'appId' => $this->appId,
                'merchantId' => $this->merchantId,
                'orderId' => $orderId,
            ];

            $authToken = $this->generateAuthToken($requestData);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => $authToken,
            ])->post($this->baseUrl . '/payment/v1/query', $requestData);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'status' => $data['data']['status'] ?? 'unknown',
                    'amount' => $data['data']['amount'] ?? null,
                    'transaction_id' => $data['data']['transactionId'] ?? null,
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'error' => 'Query failed: ' . $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('Telebirr query error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process payment notification callback
     */
    public function processNotification($notificationData)
    {
        Log::info('Telebirr notification received', $notificationData);

        try {
            // Verify notification signature
            if (!$this->verifyNotificationSignature($notificationData)) {
                throw new \Exception('Invalid notification signature');
            }

            $result = [
                'order_id' => $notificationData['orderId'],
                'transaction_id' => $notificationData['transactionId'] ?? null,
                'status' => $notificationData['status'],
                'amount' => $notificationData['amount'] ?? null,
                'currency' => $notificationData['currency'] ?? 'ETB',
                'phone_number' => $notificationData['phoneNumber'] ?? null,
            ];

            return [
                'success' => true,
                'data' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('Telebirr notification processing error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify notification signature
     */
    private function verifyNotificationSignature($notificationData)
    {
        // TODO: Implement signature verification based on Telebirr documentation
        // This is a placeholder implementation
        return true;
    }

    /**
     * Get payment status with standardized response format
     */
    public function getPaymentStatus(string $transactionId): array
    {
        $response = $this->queryPaymentStatus($transactionId);
        
        if (!$response['success']) {
            return PaymentStatusMapper::createStandardResponse(
                false,
                PaymentStatusMapper::STATUS_FAILED,
                'error',
                $transactionId,
                null,
                null,
                $response['error'],
                $response
            );
        }

        $gatewayStatus = $response['status'] ?? 'unknown';
        $standardStatus = PaymentStatusMapper::mapTelebirrStatus($gatewayStatus);
        
        return PaymentStatusMapper::createStandardResponse(
            true,
            $standardStatus,
            $gatewayStatus,
            $transactionId,
            $response['amount'] ?? null,
            $standardStatus === PaymentStatusMapper::STATUS_COMPLETED ? now()->toISOString() : null,
            null,
            $response
        );
    }
}
