<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Contracts\PaymentStatusInterface;
use App\Services\PaymentStatusMapper;
use Exception;

class AirtelMoneyPaymentService implements PaymentStatusInterface
{
    private string $clientId;
    private string $clientSecret;
    private string $environment;
    private string $baseUrl;
    private string $country;
    private string $currency;
    
    // B2C specific configuration
    private string $b2cPin;
    
    // API endpoints
    private const OAUTH_ENDPOINT = '/auth/oauth2/token';
    private const C2B_ENDPOINT = '/merchant/v2/payments/';
    private const B2C_ENDPOINT = '/standard/v2/disbursements/';
    private const STATUS_ENDPOINT = '/standard/v1/payments/';
    
    // Supported countries with their configurations
    private const COUNTRY_CONFIG = [
        'UG' => ['currency' => 'UGX', 'phone_prefix' => '256'],
        'KE' => ['currency' => 'KES', 'phone_prefix' => '254'],
        'TZ' => ['currency' => 'TZS', 'phone_prefix' => '255'],
        'RW' => ['currency' => 'RWF', 'phone_prefix' => '250'],
        'ZM' => ['currency' => 'ZMW', 'phone_prefix' => '260'],
        'MW' => ['currency' => 'MWK', 'phone_prefix' => '265'],
        'NG' => ['currency' => 'NGN', 'phone_prefix' => '234'],
        'CD' => ['currency' => 'CDF', 'phone_prefix' => '243'],
    ];

    public function __construct()
    {
        $this->clientId = config('services.airtel_money.client_id');
        $this->clientSecret = config('services.airtel_money.client_secret');
        $this->environment = config('services.airtel_money.environment', 'sandbox');
        $this->country = config('services.airtel_money.country', 'UG');
        $this->currency = config('services.airtel_money.currency', 'UGX');
        $this->b2cPin = config('services.airtel_money.b2c_pin');
        
        $this->baseUrl = $this->environment === 'production' 
            ? 'https://openapi.airtel.africa' 
            : 'https://openapiuat.airtel.africa';
    }

    /**
     * Get OAuth access token with caching
     */
    private function getAccessToken(): string
    {
        $cacheKey = 'airtel_money_access_token_' . $this->environment;
        
        return Cache::remember($cacheKey, 3500, function () {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => '*/*',
            ])->post($this->baseUrl . self::OAUTH_ENDPOINT, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]);

            if (!$response->successful()) {
                Log::error('Airtel Money OAuth failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new Exception('Failed to get Airtel Money access token: ' . $response->body());
            }

            $data = $response->json();
            return $data['access_token'];
        });
    }

    /**
     * Clear cached access token (useful when token is invalid)
     */
    public function clearAccessToken(): void
    {
        $cacheKey = 'airtel_money_access_token_' . $this->environment;
        Cache::forget($cacheKey);
    }

    /**
     * Format phone number to international format without + prefix
     */
    public function formatPhoneNumber(string $phoneNumber, ?string $countryCode = null): string
    {
        $country = $countryCode ?? $this->country;
        $config = self::COUNTRY_CONFIG[$country] ?? self::COUNTRY_CONFIG['UG'];
        $prefix = $config['phone_prefix'];

        // Remove all non-digit characters
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Handle different formats
        if (str_starts_with($cleaned, $prefix)) {
            return $cleaned; // Already in correct format
        }

        if (str_starts_with($cleaned, '0')) {
            return $prefix . substr($cleaned, 1); // Replace leading 0
        }

        if (str_starts_with($cleaned, '+' . $prefix)) {
            return substr($cleaned, 1); // Remove + prefix
        }

        // Assume it's a local number without prefix
        return $prefix . $cleaned;
    }

    /**
     * Initiate C2B (Customer to Business) payment - Collection
     */
    public function initiatePayment(
        float $amount,
        string $phoneNumber,
        string $transactionReference,
        ?string $countryCode = null,
        ?string $currency = null
    ): array {
        try {
            $country = $countryCode ?? $this->country;
            $currencyCode = $currency ?? $this->getCurrencyForCountry($country);
            $formattedPhone = $this->formatPhoneNumber($phoneNumber, $country);

            $accessToken = $this->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => '*/*',
                'X-Country' => $country,
                'X-Currency' => $currencyCode,
            ])->post($this->baseUrl . self::C2B_ENDPOINT, [
                'reference' => $transactionReference,
                'subscriber' => [
                    'country' => $country,
                    'currency' => $currencyCode,
                    'msisdn' => $formattedPhone,
                ],
                'transaction' => [
                    'amount' => $amount,
                    'country' => $country,
                    'currency' => $currencyCode,
                    'id' => $transactionReference,
                ],
            ]);

            $data = $response->json();

            Log::info('Airtel Money C2B payment initiated', [
                'reference' => $transactionReference,
                'phone' => $formattedPhone,
                'amount' => $amount,
                'response' => $data
            ]);

            if ($response->successful() && isset($data['status']) && $data['status']['success'] === true) {
                return [
                    'success' => true,
                    'transaction_id' => $data['data']['transaction']['id'] ?? $transactionReference,
                    'reference' => $transactionReference,
                    'status' => $data['data']['transaction']['status'] ?? 'pending',
                    'message' => $data['status']['message'] ?? 'Payment initiated successfully',
                    'raw_response' => $data,
                ];
            }

            return [
                'success' => false,
                'error' => $data['status']['message'] ?? 'Failed to initiate payment',
                'error_code' => $data['status']['code'] ?? 'UNKNOWN',
                'raw_response' => $data,
            ];
        } catch (Exception $e) {
            Log::error('Airtel Money C2B payment error', [
                'error' => $e->getMessage(),
                'reference' => $transactionReference
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Initiate B2C (Business to Customer) payment - Disbursement
     */
    public function initiateB2CPayment(
        float $amount,
        string $phoneNumber,
        string $transactionReference,
        ?string $countryCode = null,
        ?string $currency = null
    ): array {
        try {
            $country = $countryCode ?? $this->country;
            $currencyCode = $currency ?? $this->getCurrencyForCountry($country);
            $formattedPhone = $this->formatPhoneNumber($phoneNumber, $country);

            $accessToken = $this->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => '*/*',
                'X-Country' => $country,
                'X-Currency' => $currencyCode,
            ])->post($this->baseUrl . self::B2C_ENDPOINT, [
                'payee' => [
                    'msisdn' => $formattedPhone,
                    'wallet_type' => 'NORMAL', // NORMAL or MERCHANT
                ],
                'reference' => $transactionReference,
                'pin' => $this->b2cPin,
                'transaction' => [
                    'amount' => $amount,
                    'id' => $transactionReference,
                    'type' => 'B2C', // B2C or B2B
                ],
            ]);

            $data = $response->json();

            Log::info('Airtel Money B2C payment initiated', [
                'reference' => $transactionReference,
                'phone' => $formattedPhone,
                'amount' => $amount,
                'response' => $data
            ]);

            if ($response->successful() && isset($data['status']) && $data['status']['success'] === true) {
                return [
                    'success' => true,
                    'transaction_id' => $data['data']['transaction']['id'] ?? $transactionReference,
                    'reference' => $transactionReference,
                    'status' => $data['data']['transaction']['status'] ?? 'pending',
                    'airtel_money_id' => $data['data']['transaction']['airtel_money_id'] ?? null,
                    'message' => $data['status']['message'] ?? 'Disbursement initiated successfully',
                    'raw_response' => $data,
                ];
            }

            return [
                'success' => false,
                'error' => $data['status']['message'] ?? 'Failed to initiate disbursement',
                'error_code' => $data['status']['code'] ?? 'UNKNOWN',
                'raw_response' => $data,
            ];
        } catch (Exception $e) {
            Log::error('Airtel Money B2C payment error', [
                'error' => $e->getMessage(),
                'reference' => $transactionReference
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check transaction status
     */
    public function checkTransactionStatus(string $transactionId, ?string $countryCode = null): array
    {
        try {
            $country = $countryCode ?? $this->country;
            $accessToken = $this->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => '*/*',
                'X-Country' => $country,
                'X-Currency' => $this->getCurrencyForCountry($country),
            ])->get($this->baseUrl . self::STATUS_ENDPOINT . $transactionId);

            $data = $response->json();

            Log::info('Airtel Money transaction status check', [
                'transaction_id' => $transactionId,
                'response' => $data
            ]);

            if ($response->successful() && isset($data['status']) && $data['status']['success'] === true) {
                return [
                    'success' => true,
                    'transaction_id' => $transactionId,
                    'status' => $data['data']['transaction']['status'] ?? 'unknown',
                    'message' => $data['data']['transaction']['message'] ?? null,
                    'airtel_money_id' => $data['data']['transaction']['airtel_money_id'] ?? null,
                    'raw_response' => $data,
                ];
            }

            return [
                'success' => false,
                'error' => $data['status']['message'] ?? 'Failed to check status',
                'error_code' => $data['status']['code'] ?? 'UNKNOWN',
                'raw_response' => $data,
            ];
        } catch (Exception $e) {
            Log::error('Airtel Money status check error', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process incoming webhook callback for C2B
     */
    public function processC2BCallback(array $callbackData): array
    {
        Log::info('Airtel Money C2B callback received', $callbackData);

        try {
            $transaction = $callbackData['transaction'] ?? null;
            
            if (!$transaction) {
                throw new Exception('Invalid callback data structure');
            }

            $result = [
                'transaction_id' => $transaction['id'] ?? null,
                'airtel_money_id' => $transaction['airtel_money_id'] ?? null,
                'status' => strtolower($transaction['status_code'] ?? 'unknown'),
                'status_code' => $transaction['status_code'] ?? null,
                'message' => $transaction['message'] ?? null,
            ];

            // Map Airtel status codes to standard statuses
            $statusMapping = [
                'TS' => 'completed',      // Transaction Successful
                'TF' => 'failed',         // Transaction Failed
                'TA' => 'failed',         // Transaction Ambiguous
                'TIP' => 'pending',       // Transaction In Progress
            ];

            $result['normalized_status'] = $statusMapping[$result['status_code']] ?? 'unknown';

            return [
                'success' => true,
                'data' => $result,
            ];
        } catch (Exception $e) {
            Log::error('Airtel Money C2B callback processing error', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process incoming webhook callback for B2C
     */
    public function processB2CCallback(array $callbackData): array
    {
        Log::info('Airtel Money B2C callback received', $callbackData);

        try {
            $transaction = $callbackData['transaction'] ?? null;
            
            if (!$transaction) {
                throw new Exception('Invalid B2C callback data structure');
            }

            $result = [
                'transaction_id' => $transaction['id'] ?? null,
                'airtel_money_id' => $transaction['airtel_money_id'] ?? null,
                'status' => strtolower($transaction['status_code'] ?? 'unknown'),
                'status_code' => $transaction['status_code'] ?? null,
                'message' => $transaction['message'] ?? null,
            ];

            // Map Airtel status codes to standard statuses
            $statusMapping = [
                'TS' => 'completed',      // Transaction Successful
                'TF' => 'failed',         // Transaction Failed
                'TA' => 'failed',         // Transaction Ambiguous
                'TIP' => 'pending',       // Transaction In Progress
            ];

            $result['normalized_status'] = $statusMapping[$result['status_code']] ?? 'unknown';

            return [
                'success' => true,
                'data' => $result,
            ];
        } catch (Exception $e) {
            Log::error('Airtel Money B2C callback processing error', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get currency for a given country
     */
    public function getCurrencyForCountry(string $countryCode): string
    {
        return self::COUNTRY_CONFIG[$countryCode]['currency'] ?? 'UGX';
    }

    /**
     * Get supported countries
     */
    public function getSupportedCountries(): array
    {
        return array_keys(self::COUNTRY_CONFIG);
    }

    /**
     * Check if country is supported
     */
    public function isCountrySupported(string $countryCode): bool
    {
        return isset(self::COUNTRY_CONFIG[$countryCode]);
    }

    /**
     * Validate payment amount
     */
    public function validatePaymentAmount(float $amount, string $type = 'c2b'): array
    {
        $minAmount = config('services.airtel_money.min_amount', 100);
        $maxAmount = $type === 'c2b' 
            ? config('services.airtel_money.c2b_max_amount', 500000)
            : config('services.airtel_money.b2c_max_amount', 500000);

        if ($amount < $minAmount) {
            return [
                'valid' => false,
                'error' => "Amount must be at least {$minAmount}",
            ];
        }

        if ($amount > $maxAmount) {
            return [
                'valid' => false,
                'error' => "Amount cannot exceed {$maxAmount}",
            ];
        }

        return ['valid' => true];
    }

    /**
     * Get transaction limits
     */
    public function getTransactionLimits(): array
    {
        return [
            'c2b' => [
                'min_amount' => config('services.airtel_money.min_amount', 100),
                'max_amount' => config('services.airtel_money.c2b_max_amount', 500000),
                'daily_limit' => config('services.airtel_money.c2b_daily_limit', 5000000),
            ],
            'b2c' => [
                'min_amount' => config('services.airtel_money.min_amount', 100),
                'max_amount' => config('services.airtel_money.b2c_max_amount', 500000),
                'daily_limit' => config('services.airtel_money.b2c_daily_limit', 5000000),
            ],
        ];
    }

    /**
     * Health check for the service
     */
    public function healthCheck(): bool
    {
        try {
            $this->getAccessToken();
            return true;
        } catch (Exception $e) {
            Log::warning('Airtel Money health check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get payment status with standardized response format (PaymentStatusInterface)
     */
    public function getPaymentStatus(string $transactionId): array
    {
        $response = $this->checkTransactionStatus($transactionId);
        
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

        $standardStatus = $this->mapAirtelStatusToStandard($response['status'] ?? 'unknown');
        $gatewayStatus = $response['status'] ?? 'unknown';
        
        return PaymentStatusMapper::createStandardResponse(
            true,
            $standardStatus,
            $gatewayStatus,
            $transactionId,
            $response['airtel_money_id'] ?? null,
            $standardStatus === PaymentStatusMapper::STATUS_COMPLETED ? now()->toISOString() : null,
            null,
            $response
        );
    }

    /**
     * Map Airtel status to standard status
     */
    private function mapAirtelStatusToStandard(string $airtelStatus): string
    {
        $mapping = [
            'ts' => PaymentStatusMapper::STATUS_COMPLETED,
            'completed' => PaymentStatusMapper::STATUS_COMPLETED,
            'success' => PaymentStatusMapper::STATUS_COMPLETED,
            'tf' => PaymentStatusMapper::STATUS_FAILED,
            'failed' => PaymentStatusMapper::STATUS_FAILED,
            'ta' => PaymentStatusMapper::STATUS_FAILED,
            'tip' => PaymentStatusMapper::STATUS_PENDING,
            'pending' => PaymentStatusMapper::STATUS_PENDING,
            'processing' => PaymentStatusMapper::STATUS_PENDING,
        ];

        return $mapping[strtolower($airtelStatus)] ?? PaymentStatusMapper::STATUS_PENDING;
    }
}
