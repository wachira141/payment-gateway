<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Contracts\PaymentStatusInterface;
use Illuminate\Support\Str;
use Exception;

class MTNMobileMoneyPaymentService implements PaymentStatusInterface
{
    private string $apiUser;
    private string $apiKey;
    private string $subscriptionKeyCollections;
    private string $subscriptionKeyDisbursements;
    private string $environment;
    private string $baseUrl;
    private string $callbackHost;
    private string $country;
    private string $currency;

    // API endpoints
    private const COLLECTION_TOKEN_ENDPOINT = '/collection/token/';
    private const DISBURSEMENT_TOKEN_ENDPOINT = '/disbursement/token/';
    private const REQUEST_TO_PAY_ENDPOINT = '/collection/v1_0/requesttopay';
    private const TRANSFER_ENDPOINT = '/disbursement/v1_0/transfer';
    private const REQUEST_TO_PAY_STATUS_ENDPOINT = '/collection/v1_0/requesttopay/';
    private const TRANSFER_STATUS_ENDPOINT = '/disbursement/v1_0/transfer/';

    // Supported countries with their configurations
    private const COUNTRY_CONFIG = [
        'UG' => ['currency' => 'UGX', 'phone_prefix' => '256'],
        'GH' => ['currency' => 'GHS', 'phone_prefix' => '233'],
        'CI' => ['currency' => 'XOF', 'phone_prefix' => '225'],
        'CM' => ['currency' => 'XAF', 'phone_prefix' => '237'],
        'BJ' => ['currency' => 'XOF', 'phone_prefix' => '229'],
        'CG' => ['currency' => 'XAF', 'phone_prefix' => '242'],
        'ZA' => ['currency' => 'ZAR', 'phone_prefix' => '27'],
        'RW' => ['currency' => 'RWF', 'phone_prefix' => '250'],
        'ZM' => ['currency' => 'ZMW', 'phone_prefix' => '260'],
    ];

    public function __construct()
    {
        $this->apiUser = config('services.mtn_momo.api_user');
        $this->apiKey = config('services.mtn_momo.api_key');
        $this->subscriptionKeyCollections = config('services.mtn_momo.subscription_key_collections');
        $this->subscriptionKeyDisbursements = config('services.mtn_momo.subscription_key_disbursements');
        $this->environment = config('services.mtn_momo.environment', 'sandbox');
        $this->country = config('services.mtn_momo.country', 'UG');
        $this->currency = config('services.mtn_momo.currency', 'UGX');
        $this->callbackHost = config('services.mtn_momo.callback_host', config('app.url'));

        $this->baseUrl = $this->environment === 'production'
            ? 'https://proxy.momoapi.mtn.com'
            : 'https://sandbox.momodeveloper.mtn.com';
    }

    /**
     * Get OAuth access token for Collections API with caching
     */
    private function getCollectionAccessToken(): string
    {
        $cacheKey = 'mtn_momo_collection_token_' . $this->environment;

        return Cache::remember($cacheKey, 3500, function () {
            $response = Http::withBasicAuth($this->apiUser, $this->apiKey)
                ->withHeaders([
                    'Ocp-Apim-Subscription-Key' => $this->subscriptionKeyCollections,
                ])
                ->post($this->baseUrl . self::COLLECTION_TOKEN_ENDPOINT);

            if (!$response->successful()) {
                Log::error('MTN MoMo Collection OAuth failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new Exception('Failed to get MTN MoMo collection access token: ' . $response->body());
            }

            $data = $response->json();
            return $data['access_token'];
        });
    }

    /**
     * Get OAuth access token for Disbursements API with caching
     */
    private function getDisbursementAccessToken(): string
    {
        $cacheKey = 'mtn_momo_disbursement_token_' . $this->environment;

        return Cache::remember($cacheKey, 3500, function () {
            $response = Http::withBasicAuth($this->apiUser, $this->apiKey)
                ->withHeaders([
                    'Ocp-Apim-Subscription-Key' => $this->subscriptionKeyDisbursements,
                ])
                ->post($this->baseUrl . self::DISBURSEMENT_TOKEN_ENDPOINT);

            if (!$response->successful()) {
                Log::error('MTN MoMo Disbursement OAuth failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new Exception('Failed to get MTN MoMo disbursement access token: ' . $response->body());
            }

            $data = $response->json();
            return $data['access_token'];
        });
    }

    /**
     * Clear cached access tokens
     */
    public function clearAccessTokens(): void
    {
        Cache::forget('mtn_momo_collection_token_' . $this->environment);
        Cache::forget('mtn_momo_disbursement_token_' . $this->environment);
    }

    /**
     * Generate UUID v4 for reference ID
     */
    public function generateReferenceId(): string
    {
        return (string) Str::uuid();
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
     * Get target environment header value
     */
    private function getTargetEnvironment(): string
    {
        return $this->environment === 'production' ? 'mtncameroon' : 'sandbox';
    }

    /**
     * Initiate Request-to-Pay (C2B Collection)
     */
    public function requestToPay(
        float $amount,
        string $phoneNumber,
        string $externalId,
        ?string $countryCode = null,
        ?string $currency = null,
        ?string $payerMessage = null,
        ?string $payeeNote = null
    ): array {
        try {
            $country = $countryCode ?? $this->country;
            $currencyCode = $currency ?? $this->getCurrencyForCountry($country);
            $formattedPhone = $this->formatPhoneNumber($phoneNumber, $country);
            $referenceId = $this->generateReferenceId();

            $accessToken = $this->getCollectionAccessToken();

            $callbackUrl = config('services.mtn_momo.collection_callback_url');

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'X-Reference-Id' => $referenceId,
                'X-Target-Environment' => $this->getTargetEnvironment(),
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKeyCollections,
                'Content-Type' => 'application/json',
                'X-Callback-Url' => $callbackUrl,
            ])->post($this->baseUrl . self::REQUEST_TO_PAY_ENDPOINT, [
                'amount' => (string) $amount,
                'currency' => $currencyCode,
                'externalId' => $externalId,
                'payer' => [
                    'partyIdType' => 'MSISDN',
                    'partyId' => $formattedPhone,
                ],
                'payerMessage' => $payerMessage ?? 'Payment request',
                'payeeNote' => $payeeNote ?? 'Payment for services',
            ]);

            Log::info('MTN MoMo Request-to-Pay initiated', [
                'reference_id' => $referenceId,
                'external_id' => $externalId,
                'phone' => $formattedPhone,
                'amount' => $amount,
                'status' => $response->status()
            ]);

            // MTN MoMo returns 202 Accepted for async processing
            if ($response->status() === 202) {
                return [
                    'success' => true,
                    'reference_id' => $referenceId,
                    'external_id' => $externalId,
                    'status' => 'pending',
                    'message' => 'Request-to-Pay initiated successfully. Awaiting customer approval.',
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to initiate Request-to-Pay',
                'error_code' => $response->status(),
                'raw_response' => $response->json(),
            ];
        } catch (Exception $e) {
            Log::error('MTN MoMo Request-to-Pay error', [
                'error' => $e->getMessage(),
                'external_id' => $externalId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Request-to-Pay status
     */
    public function getRequestToPayStatus(string $referenceId): array
    {
        try {
            $accessToken = $this->getCollectionAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'X-Target-Environment' => $this->getTargetEnvironment(),
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKeyCollections,
            ])->get($this->baseUrl . self::REQUEST_TO_PAY_STATUS_ENDPOINT . $referenceId);

            $data = $response->json();

            Log::info('MTN MoMo Request-to-Pay status check', [
                'reference_id' => $referenceId,
                'response' => $data
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'reference_id' => $referenceId,
                    'status' => $data['status'] ?? 'unknown',
                    'financial_transaction_id' => $data['financialTransactionId'] ?? null,
                    'external_id' => $data['externalId'] ?? null,
                    'payer' => $data['payer'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'raw_response' => $data,
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to check status',
                'error_code' => $response->status(),
                'raw_response' => $data,
            ];
        } catch (Exception $e) {
            Log::error('MTN MoMo status check error', [
                'error' => $e->getMessage(),
                'reference_id' => $referenceId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Initiate Transfer (B2C Disbursement)
     */
    public function transfer(
        float $amount,
        string $phoneNumber,
        string $externalId,
        ?string $countryCode = null,
        ?string $currency = null,
        ?string $payerMessage = null,
        ?string $payeeNote = null
    ): array {
        try {
            $country = $countryCode ?? $this->country;
            $currencyCode = $currency ?? $this->getCurrencyForCountry($country);
            $formattedPhone = $this->formatPhoneNumber($phoneNumber, $country);
            $referenceId = $this->generateReferenceId();

            $accessToken = $this->getDisbursementAccessToken();

            $callbackUrl = config('services.mtn_momo.disbursement_callback_url');

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'X-Reference-Id' => $referenceId,
                'X-Target-Environment' => $this->getTargetEnvironment(),
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKeyDisbursements,
                'Content-Type' => 'application/json',
                'X-Callback-Url' => $callbackUrl,
            ])->post($this->baseUrl . self::TRANSFER_ENDPOINT, [
                'amount' => (string) $amount,
                'currency' => $currencyCode,
                'externalId' => $externalId,
                'payee' => [
                    'partyIdType' => 'MSISDN',
                    'partyId' => $formattedPhone,
                ],
                'payerMessage' => $payerMessage ?? 'Transfer',
                'payeeNote' => $payeeNote ?? 'Disbursement payment',
            ]);

            Log::info('MTN MoMo Transfer initiated', [
                'reference_id' => $referenceId,
                'external_id' => $externalId,
                'phone' => $formattedPhone,
                'amount' => $amount,
                'status' => $response->status()
            ]);

            // MTN MoMo returns 202 Accepted for async processing
            if ($response->status() === 202) {
                return [
                    'success' => true,
                    'reference_id' => $referenceId,
                    'external_id' => $externalId,
                    'status' => 'pending',
                    'message' => 'Transfer initiated successfully.',
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to initiate transfer',
                'error_code' => $response->status(),
                'raw_response' => $response->json(),
            ];
        } catch (Exception $e) {
            Log::error('MTN MoMo Transfer error', [
                'error' => $e->getMessage(),
                'external_id' => $externalId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Transfer status
     */
    public function getTransferStatus(string $referenceId): array
    {
        try {
            $accessToken = $this->getDisbursementAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'X-Target-Environment' => $this->getTargetEnvironment(),
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKeyDisbursements,
            ])->get($this->baseUrl . self::TRANSFER_STATUS_ENDPOINT . $referenceId);

            $data = $response->json();

            Log::info('MTN MoMo Transfer status check', [
                'reference_id' => $referenceId,
                'response' => $data
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'reference_id' => $referenceId,
                    'status' => $data['status'] ?? 'unknown',
                    'financial_transaction_id' => $data['financialTransactionId'] ?? null,
                    'external_id' => $data['externalId'] ?? null,
                    'payee' => $data['payee'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'raw_response' => $data,
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to check transfer status',
                'error_code' => $response->status(),
                'raw_response' => $data,
            ];
        } catch (Exception $e) {
            Log::error('MTN MoMo transfer status check error', [
                'error' => $e->getMessage(),
                'reference_id' => $referenceId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process incoming webhook callback for Collection (C2B)
     */
    public function processCollectionCallback(array $callbackData): array
    {
        Log::info('MTN MoMo Collection callback received', $callbackData);

        try {
            $result = [
                'reference_id' => $callbackData['referenceId'] ?? $callbackData['externalId'] ?? null,
                'external_id' => $callbackData['externalId'] ?? null,
                'financial_transaction_id' => $callbackData['financialTransactionId'] ?? null,
                'status' => $callbackData['status'] ?? 'unknown',
                'payer' => $callbackData['payer'] ?? null,
                'reason' => $callbackData['reason'] ?? null,
            ];

            // Map MTN status to normalized status
            $statusMapping = [
                'SUCCESSFUL' => 'completed',
                'FAILED' => 'failed',
                'PENDING' => 'pending',
                'REJECTED' => 'failed',
                'TIMEOUT' => 'failed',
            ];

            $result['normalized_status'] = $statusMapping[$result['status']] ?? 'unknown';

            return [
                'success' => true,
                'data' => $result,
            ];
        } catch (Exception $e) {
            Log::error('MTN MoMo Collection callback processing error', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process incoming webhook callback for Transfer (B2C)
     */
    public function processTransferCallback(array $callbackData): array
    {
        Log::info('MTN MoMo Transfer callback received', $callbackData);

        try {
            $result = [
                'reference_id' => $callbackData['referenceId'] ?? $callbackData['externalId'] ?? null,
                'external_id' => $callbackData['externalId'] ?? null,
                'financial_transaction_id' => $callbackData['financialTransactionId'] ?? null,
                'status' => $callbackData['status'] ?? 'unknown',
                'payee' => $callbackData['payee'] ?? null,
                'reason' => $callbackData['reason'] ?? null,
            ];

            // Map MTN status to normalized status
            $statusMapping = [
                'SUCCESSFUL' => 'completed',
                'FAILED' => 'failed',
                'PENDING' => 'pending',
                'REJECTED' => 'failed',
                'TIMEOUT' => 'failed',
            ];

            $result['normalized_status'] = $statusMapping[$result['status']] ?? 'unknown';

            return [
                'success' => true,
                'data' => $result,
            ];
        } catch (Exception $e) {
            Log::error('MTN MoMo Transfer callback processing error', ['error' => $e->getMessage()]);

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
        $minAmount = config('services.mtn_momo.min_amount', 100);
        $maxAmount = $type === 'c2b'
            ? config('services.mtn_momo.c2b_max_amount', 5000000)
            : config('services.mtn_momo.b2c_max_amount', 5000000);

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
                'min_amount' => config('services.mtn_momo.min_amount', 100),
                'max_amount' => config('services.mtn_momo.c2b_max_amount', 5000000),
                'daily_limit' => config('services.mtn_momo.c2b_daily_limit', 50000000),
            ],
            'b2c' => [
                'min_amount' => config('services.mtn_momo.min_amount', 100),
                'max_amount' => config('services.mtn_momo.b2c_max_amount', 5000000),
                'daily_limit' => config('services.mtn_momo.b2c_daily_limit', 50000000),
            ],
        ];
    }

    /**
     * Perform health check
     */
    public function healthCheck(): bool
    {
        try {
            $this->getCollectionAccessToken();
            return true;
        } catch (Exception $e) {
            Log::warning('MTN MoMo health check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get payment status (implements PaymentStatusInterface)
     */
    public function getPaymentStatus(string $transactionId): array
    {
        return $this->getRequestToPayStatus($transactionId);
    }

    /**
     * Map MTN status to standard status (implements PaymentStatusInterface)
     */
    public function mapToStandardStatus(string $gatewayStatus): string
    {
        $statusMapping = [
            'SUCCESSFUL' => 'completed',
            'FAILED' => 'failed',
            'PENDING' => 'pending',
            'REJECTED' => 'failed',
            'TIMEOUT' => 'failed',
        ];

        return $statusMapping[$gatewayStatus] ?? 'unknown';
    }
}
