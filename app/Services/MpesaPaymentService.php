<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Contracts\PaymentStatusInterface;
use App\Services\PaymentStatusMapper;
use Illuminate\Support\Facades\Log;

class MpesaPaymentService implements PaymentStatusInterface
{
    private $consumerKey;
    private $consumerSecret;
    private $environment;
    private $baseUrl;
    private $b2cShortcode;
    private $b2cInitiatorName;
    private $b2cSecurityCredential;

    public function __construct()
    {
        $this->consumerKey = config('services.mpesa.consumer_key');
        $this->consumerSecret = config('services.mpesa.consumer_secret');
        $this->environment = config('services.mpesa.environment', 'sandbox');
        $this->baseUrl = $this->environment === 'production' 
            ? 'https://api.safaricom.co.ke' 
            : 'https://sandbox.safaricom.co.ke';
        
        // B2C specific configuration
        $this->b2cShortcode = config('services.mpesa.b2c_shortcode');
        $this->b2cInitiatorName = config('services.mpesa.b2c_initiator_name');
        $this->b2cSecurityCredential = config('services.mpesa.b2c_security_credential');
    }

    /**
     * Get OAuth access token
     */
    private function getAccessToken()
    {
        $credentials = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $credentials,
            'Content-Type' => 'application/json',
            ])->get($this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials');
        if ($response->successful()) {
            return $response->json()['access_token'];
        }
        throw new \Exception('Failed to get M-Pesa access token: ' . $response->body());
    }

    /**
     * Initiate STK Push payment
     */
    public function initiateSTKPush($amount, $phoneNumber, $accountReference, $transactionDesc)
    {
        //format amount to remove any decimal places
        // $amount = round($amount);
        $amount=5;
        try {
            $accessToken = $this->getAccessToken();
            $timestamp = now()->format('YmdHis');
            $shortcode = config('services.mpesa.shortcode');
            $passkey = config('services.mpesa.passkey');
            $password = base64_encode($shortcode . $passkey . $timestamp);
            $phoneNumber = $this->formatPhoneNumber($phoneNumber);
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => $amount,
                'PartyA' => $phoneNumber,
                'PartyB' => $shortcode,
                'PhoneNumber' => $phoneNumber,
                'CallBackURL' => config('services.mpesa.callback_url'),
                'AccountReference' => $accountReference,
                'TransactionDesc' => $transactionDesc,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'checkout_request_id' => $data['CheckoutRequestID'],
                    'merchant_request_id' => $data['MerchantRequestID'],
                    'response_code' => $data['ResponseCode'],
                    'response_description' => $data['ResponseDescription'],
                ];
            }

            return [
                'success' => false,
                'error' => 'STK Push failed: ' . $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa STK Push error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Query STK Push transaction status
     */
    public function querySTKPushStatus($checkoutRequestId)
    {
        try {
            $accessToken = $this->getAccessToken();
            $timestamp = now()->format('YmdHis');
            $shortcode = config('services.mpesa.shortcode');
            $passkey = config('services.mpesa.passkey');
            $password = base64_encode($shortcode . $passkey . $timestamp);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'response_code' => $data['ResponseCode'],
                    'response_description' => $data['ResponseDescription'],
                    'result_code' => $data['ResultCode'] ?? null,
                    'result_desc' => $data['ResultDesc'] ?? null,
                ];
            }

            return [
                'success' => false,
                'error' => 'Query failed: ' . $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa query error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process M-Pesa callback
     */
    public function processCallback($callbackData)
    {
        Log::info('M-Pesa callback received', $callbackData);

        try {
            $stkCallback = $callbackData['Body']['stkCallback'] ?? null;

            if (!$stkCallback) {
                throw new \Exception('Invalid callback data structure');
            }

            $result = [
                'checkout_request_id' => $stkCallback['CheckoutRequestID'],
                'merchant_request_id' => $stkCallback['MerchantRequestID'],
                'result_code' => $stkCallback['ResultCode'],
                'result_desc' => $stkCallback['ResultDesc'],
            ];

            // If payment was successful, extract additional details
            if ($stkCallback['ResultCode'] == 0) {
                $callbackMetadata = $stkCallback['CallbackMetadata']['Item'] ?? [];

                foreach ($callbackMetadata as $item) {
                    switch ($item['Name']) {
                        case 'Amount':
                            $result['amount'] = $item['Value'];
                            break;
                        case 'MpesaReceiptNumber':
                            $result['mpesa_receipt_number'] = $item['Value'];
                            break;
                        case 'TransactionDate':
                            $result['transaction_date'] = $item['Value'];
                            break;
                        case 'PhoneNumber':
                            $result['phone_number'] = $item['Value'];
                            break;
                    }
                }
            }

            return [
                'success' => true,
                'data' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa callback processing error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

     /**
     * Initiate B2C payment (Business to Customer)
     */
    public function initiateB2CPayment($amount, $phoneNumber, $remarks = 'Payout', $occasion = 'Payment')
    {
        try {
            $accessToken = $this->getAccessToken();
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            $formattedPhone = "254708374149"; // For testing purposes, you can hardcode a phone number
            
            if (!$formattedPhone) {
                return [
                    'success' => false,
                    'error' => 'Invalid phone number format',
                ];
            }

            $QueueTimeOutURL =config('services.mpesa.b2c_timeout_url');
            $ResultURL = config('services.mpesa.b2c_result_url');

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/mpesa/b2c/v1/paymentrequest', [
                'InitiatorName' => $this->b2cInitiatorName,
                'SecurityCredential' => $this->b2cSecurityCredential,
                'CommandID' => 'BusinessPayment',
                'Amount' => $amount,
                'PartyA' => $this->b2cShortcode,
                'PartyB' => $formattedPhone,
                'Remarks' => $remarks,
                'QueueTimeOutURL' => $QueueTimeOutURL,
                'ResultURL' => $ResultURL,
                'Occasion' => $occasion,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'conversation_id' => $data['ConversationID'],
                    'originator_conversation_id' => $data['OriginatorConversationID'],
                    'response_code' => $data['ResponseCode'],
                    'response_description' => $data['ResponseDescription'],
                ];
            }

            return [
                'success' => false,
                'error' => 'B2C Payment failed: ' . $response->body().'***'. $QueueTimeOutURL,
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa B2C Payment error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process B2C callback/result
     */
    public function processB2CCallback($callbackData)
    {
        Log::info('M-Pesa B2C callback received', $callbackData);

        try {
            $result = $callbackData['Result'] ?? null;
            
            if (!$result) {
                throw new \Exception('Invalid B2C callback data structure');
            }

            $response = [
                'conversation_id' => $result['ConversationID'],
                'originator_conversation_id' => $result['OriginatorConversationID'],
                'result_code' => $result['ResultCode'],
                'result_desc' => $result['ResultDesc'],
            ];

            // If payment was successful, extract additional details
            if ($result['ResultCode'] == 0) {
                $resultParameters = $result['ResultParameters']['ResultParameter'] ?? [];
                
                foreach ($resultParameters as $param) {
                    switch ($param['Key']) {
                        case 'TransactionAmount':
                            $response['amount'] = $param['Value'];
                            break;
                        case 'TransactionReceipt':
                            $response['transaction_receipt'] = $param['Value'];
                            break;
                        case 'ReceiverPartyPublicName':
                            $response['receiver_name'] = $param['Value'];
                            break;
                        case 'TransactionCompletedDateTime':
                            $response['completed_at'] = $param['Value'];
                            break;
                        case 'B2CUtilityAccountAvailableFunds':
                            $response['utility_balance'] = $param['Value'];
                            break;
                        case 'B2CWorkingAccountAvailableFunds':
                            $response['working_balance'] = $param['Value'];
                            break;
                    }
                }
            }

            return [
                'success' => true,
                'data' => $response,
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa B2C callback processing error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

     /**
     * Validate payout amount for M-Pesa
     */
    public function validatePayoutAmount($amount)
    {
        $minAmount = config('services.mpesa.b2c_min_amount', 250);
        $maxAmount = config('services.mpesa.b2c_max_amount', 500000);
        
        if ($amount < $minAmount) {if ($amount < $minAmount) {
            return [
                'valid' => false,
                'error' => "Amount must be at least KES {$minAmount}",
            ];
        }
            return [
                'valid' => false,
                'error' => "Amount must be at least KES {$minAmount}",
            ];
        }
        
        if ($amount > $maxAmount) {
            return [
                'valid' => false,
                'error' => "Amount cannot exceed KES {$maxAmount}",
            ];
        }
        
        return ['valid' => true];
    }

    /**
     * Format a Kenyan phone number to 254... format.
     *
     * @param string $phoneNumber
     * @return string
     */
    function formatPhoneNumber($phoneNumber)
    {
        // Remove all non-digit characters
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Check if the number starts with 254
        if (strlen($cleaned) >= 9) { // Minimum length for a Kenyan number (e.g., 712345678)
            if (str_starts_with($cleaned, '254')) {
                return $cleaned; // Already in 254 format
            } elseif (str_starts_with($cleaned, '0')) {
                return '254' . substr($cleaned, 1); // Replace 0 with 254
            } else {
                // If it doesn't start with 0 or 254, assume it's missing 254
                return '254' . $cleaned;
            }
        }

        // Fallback (invalid number)
        return $phoneNumber;
    }

    /**
     * Get M-Pesa B2C transaction limits
     */
    public function getB2CTransactionLimits()
    {
        return [
            'min_amount' => config('services.mpesa.b2c_min_amount', 10),
            'max_amount' => config('services.mpesa.b2c_max_amount', 70000),
            'daily_limit' => config('services.mpesa.b2c_daily_limit', 150000),
            'currency' => 'KES',
        ];
    }

     /**
     * Get payment status with standardized response format
     */
    public function getPaymentStatus(string $transactionId): array
    {
        $response = $this->querySTKPushStatus($transactionId);
        
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

        $standardStatus = PaymentStatusMapper::mapMpesaStatus($response);
        $gatewayStatus = $response['result_desc'] ?? $response['response_description'] ?? 'unknown';
        
        return PaymentStatusMapper::createStandardResponse(
            true,
            $standardStatus,
            $gatewayStatus,
            $transactionId,
            null,
            $standardStatus === PaymentStatusMapper::STATUS_COMPLETED ? now()->toISOString() : null,
            null,
            $response
        );
    }
}
