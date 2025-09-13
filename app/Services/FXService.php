<?php

namespace App\Services;

use App\Models\FXQuote;
use App\Models\FXTrade;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FXService extends BaseService
{
    private BalanceService $balanceService;

    // Exchange rate providers configuration
    private array $providers = [
        'exchangerate_api' => [
            'url' => 'https://api.exchangerate-api.com/v4/latest/',
            'key_required' => false,
        ],
        'fixer' => [
            'url' => 'http://data.fixer.io/api/latest',
            'key_required' => true,
        ],
        'currencylayer' => [
            'url' => 'http://api.currencylayer.com/live',
            'key_required' => true,
        ],
        'openexchangerates' => [
            'url' => 'https://openexchangerates.org/api/latest.json',
            'key_required' => true,
        ],
    ];

    public function __construct(BalanceService $balanceService)
    {
        $this->balanceService = $balanceService;
    }

    /**
     * Get FX quote for currency conversion
     */
    public function getQuote(string $merchantId, array $data): array
    {
        $fromCurrency = $data['from_currency'];
        $toCurrency = $data['to_currency'];
        $amount = $data['amount'];

        // Check if merchant has sufficient balance
        $balance = $this->balanceService->getBalanceForCurrency($merchantId, $fromCurrency);
        
        if ($balance['available'] < $amount) {
            throw new \Exception('Insufficient balance for FX conversion');
        }

        // Get current exchange rate from real-time source
        $exchangeRate = $this->getCurrentExchangeRate($fromCurrency, $toCurrency);
        
        if (!$exchangeRate) {
            throw new \Exception('Exchange rate not available for currency pair');
        }

        // Calculate conversion amounts
        $convertedAmount = (int) ($amount * $exchangeRate['rate']);
        $feeAmount = $this->calculateFXFee($amount, $fromCurrency, $toCurrency);
        $netConvertedAmount = $convertedAmount - $feeAmount;

        $quoteData = [
            'id' => 'fxq_' . Str::random(24),
            'merchant_id' => $merchantId,
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'from_amount' => $amount,
            'to_amount' => $convertedAmount,
            'net_to_amount' => $netConvertedAmount,
            'exchange_rate' => $exchangeRate['rate'],
            'fee_amount' => $feeAmount,
            'fee_currency' => $toCurrency,
            'expires_at' => now()->addMinutes(5), // Quote valid for 5 minutes
            'rate_source' => $exchangeRate['source'],
            'rate_timestamp' => $exchangeRate['timestamp'],
            'status' => 'active'
        ];

        return FXQuote::create($quoteData);
    }

    /**
     * Execute FX trade based on quote
     */
    public function executeTrade(string $merchantId, array $data): array
    {
        $quoteId = $data['quote_id'];
        
        $quote = FXQuote::findByIdAndMerchant($quoteId, $merchantId);
        
        if (!$quote) {
            throw new \Exception('FX quote not found');
        }

        if ($quote['status'] !== 'active') {
            throw new \Exception('FX quote is not active');
        }

        if (now() > $quote['expires_at']) {
            throw new \Exception('FX quote has expired');
        }

        // Check balance again (might have changed since quote)
        $balance = $this->balanceService->getBalanceForCurrency($merchantId, $quote['from_currency']);
        
        if ($balance['available'] < $quote['from_amount']) {
            throw new \Exception('Insufficient balance for FX trade');
        }

        // Optionally verify current rate hasn't changed significantly (rate protection)
        $currentRate = $this->getCurrentExchangeRate($quote['from_currency'], $quote['to_currency']);
        if ($currentRate && $this->isRateSignificantlyDifferent($quote['exchange_rate'], $currentRate['rate'])) {
            throw new \Exception('Exchange rate has changed significantly. Please request a new quote.');
        }

        // Execute the trade
        $tradeData = [
            'id' => 'fxt_' . Str::random(24),
            'merchant_id' => $merchantId,
            'quote_id' => $quoteId,
            'from_currency' => $quote['from_currency'],
            'to_currency' => $quote['to_currency'],
            'from_amount' => $quote['from_amount'],
            'to_amount' => $quote['to_amount'],
            'net_to_amount' => $quote['net_to_amount'],
            'exchange_rate' => $quote['exchange_rate'],
            'fee_amount' => $quote['fee_amount'],
            'fee_currency' => $quote['fee_currency'],
            'status' => 'processing',
            'executed_at' => now()
        ];

        $trade = FXTrade::create($tradeData);

        // Process the currency conversion
        return $this->processCurrencyConversion($trade['id']);
    }

    /**
     * Process currency conversion
     */
    private function processCurrencyConversion(string $tradeId): array
    {
        $trade = FXTrade::findById($tradeId);
        
        if (!$trade) {
            throw new \Exception('FX trade not found');
        }

        try {
            // Deduct from source currency
            $this->balanceService->subtractFromBalance(
                $trade['merchant_id'],
                $trade['from_currency'],
                $trade['from_amount'],
                'fx_conversion_debit',
                $tradeId
            );

            // Add to target currency (net amount after fees)
            $this->balanceService->addToBalance(
                $trade['merchant_id'],
                $trade['to_currency'],
                $trade['net_to_amount'],
                'fx_conversion_credit',
                $tradeId
            );

            // Mark quote as used
            FXQuote::updateById($trade['quote_id'], [
                'status' => 'used',
                'used_at' => now()
            ]);

            // Update trade status
            return FXTrade::updateById($tradeId, [
                'status' => 'completed',
                'completed_at' => now()
            ]);
        } catch (\Exception $e) {
            // Mark trade as failed
            FXTrade::updateById($tradeId, [
                'status' => 'failed',
                'failure_reason' => $e->getMessage()
            ]);

            throw new \Exception('FX trade processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Get current exchange rates from real-time providers
     */
    public function getCurrentExchangeRates(string $baseCurrency = 'USD'): array
    {
        $cacheKey = "fx_rates_{$baseCurrency}";
        
        // Try to get cached rates (cache for 60 seconds for real-time updates)
        return Cache::remember($cacheKey, 60, function () use ($baseCurrency) {
            return $this->fetchRatesFromProviders($baseCurrency);
        });
    }

    /**
     * Fetch rates from external providers with fallback
     */
    private function fetchRatesFromProviders(string $baseCurrency): array
    {
        $providers = ['exchangerate_api', 'fixer', 'currencylayer', 'openexchangerates'];
        
        foreach ($providers as $provider) {
            try {
                $rates = $this->fetchFromProvider($provider, $baseCurrency);
                if ($rates) {
                    return $rates;
                }
            } catch (\Exception $e) {
                Log::warning("Failed to fetch rates from {$provider}: " . $e->getMessage());
                continue;
            }
        }

        // Fallback to cached rates if all providers fail
        $fallbackKey = "fx_rates_fallback_{$baseCurrency}";
        $fallbackRates = Cache::get($fallbackKey);
        
        if ($fallbackRates) {
            Log::warning('Using fallback cached rates due to provider failures');
            return $fallbackRates;
        }

        // Last resort: use static rates
        Log::error('All FX providers failed, using static rates');
        return $this->getStaticRates($baseCurrency);
    }

    /**
     * Fetch rates from specific provider
     */
    private function fetchFromProvider(string $provider, string $baseCurrency): ?array
    {
        $config = $this->providers[$provider];
        
        switch ($provider) {
            case 'exchangerate_api':
                return $this->fetchFromExchangeRateApi($baseCurrency);
            
            case 'fixer':
                return $this->fetchFromFixer($baseCurrency);
            
            case 'currencylayer':
                return $this->fetchFromCurrencyLayer($baseCurrency);
            
            case 'openexchangerates':
                return $this->fetchFromOpenExchangeRates($baseCurrency);
            
            default:
                return null;
        }
    }

    /**
     * Fetch from ExchangeRate-API (free tier)
     */
    private function fetchFromExchangeRateApi(string $baseCurrency): ?array
    {
        $response = Http::timeout(5)->get("https://api.exchangerate-api.com/v4/latest/{$baseCurrency}");
        
        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        
        // Cache successful response for fallback
        Cache::put("fx_rates_fallback_{$baseCurrency}", [
            'base_currency' => $baseCurrency,
            'rates' => $data['rates'],
            'timestamp' => now()->toISOString(),
            'source' => 'exchangerate_api'
        ], 3600); // Cache for 1 hour as fallback

        return [
            'base_currency' => $baseCurrency,
            'rates' => $data['rates'],
            'timestamp' => now()->toISOString(),
            'source' => 'exchangerate_api'
        ];
    }

    /**
     * Fetch from Fixer.io
     */
    private function fetchFromFixer(string $baseCurrency): ?array
    {
        $apiKey = config('services.fixer.key');
        if (!$apiKey) {
            return null;
        }

        $response = Http::timeout(5)->get('http://data.fixer.io/api/latest', [
            'access_key' => $apiKey,
            'base' => $baseCurrency,
        ]);
        
        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        
        if (!$data['success']) {
            return null;
        }

        return [
            'base_currency' => $baseCurrency,
            'rates' => $data['rates'],
            'timestamp' => $data['timestamp'],
            'source' => 'fixer'
        ];
    }

    /**
     * Fetch from CurrencyLayer
     */
    private function fetchFromCurrencyLayer(string $baseCurrency): ?array
    {
        $apiKey = config('services.currencylayer.key');
        if (!$apiKey) {
            return null;
        }

        $response = Http::timeout(5)->get('http://api.currencylayer.com/live', [
            'access_key' => $apiKey,
            'source' => $baseCurrency,
        ]);
        
        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        
        if (!$data['success']) {
            return null;
        }

        // CurrencyLayer returns rates with source currency prefix, normalize them
        $rates = [];
        foreach ($data['quotes'] as $pair => $rate) {
            $targetCurrency = substr($pair, 3); // Remove first 3 chars (USD...)
            $rates[$targetCurrency] = $rate;
        }

        return [
            'base_currency' => $baseCurrency,
            'rates' => $rates,
            'timestamp' => date('c', $data['timestamp']),
            'source' => 'currencylayer'
        ];
    }

    /**
     * Fetch from OpenExchangeRates
     */
    private function fetchFromOpenExchangeRates(string $baseCurrency): ?array
    {
        $apiKey = config('services.openexchangerates.key');
        if (!$apiKey) {
            return null;
        }

        $response = Http::timeout(5)->get('https://openexchangerates.org/api/latest.json', [
            'app_id' => $apiKey,
            'base' => $baseCurrency,
        ]);
        
        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();

        return [
            'base_currency' => $baseCurrency,
            'rates' => $data['rates'],
            'timestamp' => date('c', $data['timestamp']),
            'source' => 'openexchangerates'
        ];
    }

    /**
     * Get current exchange rate for specific currency pair
     */
    private function getCurrentExchangeRate(string $fromCurrency, string $toCurrency): ?array
    {
        if ($fromCurrency === $toCurrency) {
            return [
                'rate' => 1.0,
                'source' => 'identity',
                'timestamp' => now()->toISOString()
            ];
        }

        $rates = $this->getCurrentExchangeRates($fromCurrency);
        
        if (!isset($rates['rates'][$toCurrency])) {
            // Try inverse rate
            $inverseRates = $this->getCurrentExchangeRates($toCurrency);
            if (isset($inverseRates['rates'][$fromCurrency])) {
                return [
                    'rate' => round(1 / $inverseRates['rates'][$fromCurrency], 6),
                    'source' => $inverseRates['source'] . '_inverse',
                    'timestamp' => $inverseRates['timestamp']
                ];
            }
            return null;
        }

        return [
            'rate' => $rates['rates'][$toCurrency],
            'source' => $rates['source'],
            'timestamp' => $rates['timestamp']
        ];
    }

    /**
     * Check if rate has changed significantly (rate protection)
     */
    private function isRateSignificantlyDifferent(float $quoteRate, float $currentRate, float $threshold = 0.02): bool
    {
        $difference = abs(($currentRate - $quoteRate) / $quoteRate);
        return $difference > $threshold; // 2% threshold
    }

    /**
     * Static rates fallback (last resort)
     */
    private function getStaticRates(string $baseCurrency): array
    {
        $staticRates = [
            'USD' => [
                'EUR' => 0.85,
                'GBP' => 0.73,
                'KES' => 129.50,
                'UGX' => 3700.00,
                'TZS' => 2300.00,
                'NGN' => 410.00,
                'GHS' => 6.10,
                'ZAR' => 15.20
            ],
            'EUR' => [
                'USD' => 1.18,
                'GBP' => 0.86,
                'KES' => 152.35,
                'UGX' => 4356.00,
                'TZS' => 2710.00,
                'NGN' => 483.80,
                'GHS' => 7.20,
                'ZAR' => 17.90
            ],
        ];

        return [
            'base_currency' => $baseCurrency,
            'rates' => $staticRates[$baseCurrency] ?? [],
            'timestamp' => now()->toISOString(),
            'source' => 'static_fallback'
        ];
    }

    /**
     * Calculate FX conversion fee
     */
    private function calculateFXFee(int $amount, string $fromCurrency, string $toCurrency): int
    {
        // FX fee structure:
        // - Major currencies (USD, EUR, GBP): 0.5%
        // - African currencies: 1.0%
        // - Cross-border African: 1.5%
        
        $majorCurrencies = ['USD', 'EUR', 'GBP'];
        $africanCurrencies = ['KES', 'UGX', 'TZS', 'NGN', 'GHS', 'ZAR'];

        $fromIsMajor = in_array($fromCurrency, $majorCurrencies);
        $toIsMajor = in_array($toCurrency, $majorCurrencies);
        $fromIsAfrican = in_array($fromCurrency, $africanCurrencies);
        $toIsAfrican = in_array($toCurrency, $africanCurrencies);

        if ($fromIsMajor && $toIsMajor) {
            $feePercentage = 0.005; // 0.5%
        } elseif ($fromIsAfrican && $toIsAfrican) {
            $feePercentage = 0.015; // 1.5%
        } else {
            $feePercentage = 0.01; // 1.0%
        }

        $calculatedFee = (int) ($amount * $feePercentage);
        
        // Minimum fee thresholds
        $minimumFee = match ($toCurrency) {
            'USD' => 50,    // $0.50
            'EUR' => 50,    // €0.50
            'GBP' => 50,    // £0.50
            'KES' => 5000,  // KSh 50
            'UGX' => 185000, // UGX 1,850
            'TZS' => 115000, // TZS 1,150
            'NGN' => 20500,  // NGN 205
            'GHS' => 300,    // GHS 3
            'ZAR' => 750,    // ZAR 7.50
            default => 50
        };

        return max($calculatedFee, $minimumFee);
    }

    /**
     * Get FX trade history for merchant
     */
    public function getTradeHistoryForMerchant(string $merchantId, array $filters = []): Collection
    {
        $query = FXTrade::where('merchant_id', $merchantId);

        if (!empty($filters['currency_pair'])) {
            $currencies = explode('_', $filters['currency_pair']);
            if (count($currencies) === 2) {
                $query->where('from_currency', $currencies[0])
                      ->where('to_currency', $currencies[1]);
            }
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['start_date'])) {
            $query->where('executed_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('executed_at', '<=', $filters['end_date']);
        }

        $query->orderBy('executed_at', 'desc');

        if (!empty($filters['limit'])) {
            $query->limit($filters['limit']);
        }

        if (!empty($filters['offset'])) {
            $query->offset($filters['offset']);
        }

        return $query->get();
    }

    /**
     * Get FX statistics for merchant
     */
    public function getFXStatistics(string $merchantId, array $filters = []): array
    {
        $query = FXTrade::where('merchant_id', $merchantId);

        if (!empty($filters['start_date'])) {
            $query->where('executed_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('executed_at', '<=', $filters['end_date']);
        }

        $trades = $query->get();

        return [
            'total_trades' => $trades->count(),
            'completed_trades' => $trades->where('status', 'completed')->count(),
            'failed_trades' => $trades->where('status', 'failed')->count(),
            'total_volume_converted' => $trades->where('status', 'completed')->sum('from_amount'),
            'total_fees_paid' => $trades->where('status', 'completed')->sum('fee_amount'),
            'average_trade_size' => $trades->where('status', 'completed')->avg('from_amount'),
            'most_traded_pairs' => $this->getMostTradedPairs($trades),
        ];
    }

    /**
     * Get most traded currency pairs
     */
    private function getMostTradedPairs(Collection $trades): array
    {
        $pairs = $trades->where('status', 'completed')
                       ->groupBy(function ($trade) {
                           return $trade['from_currency'] . '_' . $trade['to_currency'];
                       })
                       ->map(function ($pairTrades) {
                           return [
                               'count' => $pairTrades->count(),
                               'volume' => $pairTrades->sum('from_amount')
                           ];
                       })
                       ->sortByDesc('count')
                       ->take(5);

        return $pairs->toArray();
    }

    /**
     * Cancel an active quote
     */
    public function cancelQuote(string $quoteId, string $merchantId): array
    {
        $quote = FXQuote::findByIdAndMerchant($quoteId, $merchantId);
        
        if (!$quote) {
            throw new \Exception('FX quote not found');
        }

        if ($quote['status'] !== 'active') {
            throw new \Exception('Only active quotes can be cancelled');
        }

        return FXQuote::updateById($quoteId, [
            'status' => 'cancelled',
            'cancelled_at' => now()
        ]);
    }

    /**
     * Health check for FX rate providers
     */
    public function checkProvidersHealth(): array
    {
        $health = [];
        
        foreach (array_keys($this->providers) as $provider) {
            try {
                $start = microtime(true);
                $rates = $this->fetchFromProvider($provider, 'USD');
                $responseTime = round((microtime(true) - $start) * 1000, 2);
                
                $health[$provider] = [
                    'status' => $rates ? 'healthy' : 'unhealthy',
                    'response_time_ms' => $responseTime,
                    'last_checked' => now()->toISOString()
                ];
            } catch (\Exception $e) {
                $health[$provider] = [
                    'status' => 'unhealthy',
                    'error' => $e->getMessage(),
                    'last_checked' => now()->toISOString()
                ];
            }
        }
        
        return $health;
    }
}