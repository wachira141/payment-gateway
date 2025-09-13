<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KenyaCurrencyService
{
    protected $baseCurrency = 'USD';
    protected $targetCurrency = 'KES';
    protected $cacheKey = 'usd_kes_rate';
    protected $cacheDuration = 3600; // 1 hour

    /**
     * Convert USD to KES
     */
    public function convertUSDToKES($amountUSD): float
    {
        $rate = $this->getExchangeRate();
        return round($amountUSD * $rate, 2);
    }

    /**
     * Convert KES to USD
     */
    public function convertKESToUSD($amountKES): float
    {
        $rate = $this->getExchangeRate();
        return round($amountKES / $rate, 2);
    }

    /**
     * Get current USD to KES exchange rate
     */
    public function getExchangeRate(): float
    {
        return Cache::remember($this->cacheKey, $this->cacheDuration, function () {
            try {
                // Try to fetch from external API (you can configure your preferred provider)
                $rate = $this->fetchExchangeRateFromAPI();
                
                if ($rate) {
                    return $rate;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch exchange rate from API', ['error' => $e->getMessage()]);
            }
            
            // Fallback to approximate rate if API fails
            return $this->getFallbackRate();
        });
    }

    /**
     * Calculate Kenya-specific payout fees
     */
    public function calculateKenyaPayoutFees($amount, $method = 'mpesa'): array
    {
        $amountKES = $this->convertUSDToKES($amount);
        
        if ($method === 'mpesa') {
            return $this->calculateMpesaFees($amountKES);
        } else {
            return $this->calculateBankFees($amountKES);
        }
    }

    /**
     * Apply regulatory costs
     */
    public function applyRegulatoryCosts($amount, $method = 'mpesa'): float
    {
        // Kenya has minimal regulatory costs for M-Pesa
        // Banks may have additional regulatory fees
        
        if ($method === 'bank') {
            // Example: 0.1% regulatory fee for bank transfers
            return $amount * 0.001;
        }
        
        return 0; // No additional regulatory costs for M-Pesa
    }

    /**
     * Fetch exchange rate from external API
     */
    protected function fetchExchangeRateFromAPI(): ?float
    {
        try {
            // Example using a free API (you can use your preferred provider)
            $response = Http::timeout(10)->get('https://api.exchangerate-api.com/v4/latest/USD');
            
            if ($response->successful()) {
                $data = $response->json();
                return $data['rates']['KES'] ?? null;
            }
        } catch (\Exception $e) {
            Log::error('Exchange rate API request failed', ['error' => $e->getMessage()]);
        }
        
        return null;
    }

    /**
     * Get fallback exchange rate
     */
    protected function getFallbackRate(): float
    {
        // Approximate USD to KES rate (update this periodically)
        // As of 2024, roughly 1 USD = 130-150 KES
        return 140.0;
    }

    /**
     * Calculate M-Pesa fees (B2C rates in Kenya)
     */
    protected function calculateMpesaFees($amountKES): array
    {
        $fee = 0;
        
        // M-Pesa B2C fee structure (approximate)
        if ($amountKES <= 100) {
            $fee = 11;
        } elseif ($amountKES <= 500) {
            $fee = 23;
        } elseif ($amountKES <= 1000) {
            $fee = 29;
        } elseif ($amountKES <= 1500) {
            $fee = 35;
        } elseif ($amountKES <= 2500) {
            $fee = 58;
        } elseif ($amountKES <= 3500) {
            $fee = 69;
        } elseif ($amountKES <= 5000) {
            $fee = 87;
        } elseif ($amountKES <= 7500) {
            $fee = 115;
        } elseif ($amountKES <= 10000) {
            $fee = 149;
        } elseif ($amountKES <= 15000) {
            $fee = 197;
        } elseif ($amountKES <= 20000) {
            $fee = 253;
        } elseif ($amountKES <= 25000) {
            $fee = 309;
        } elseif ($amountKES <= 30000) {
            $fee = 365;
        } elseif ($amountKES <= 40000) {
            $fee = 421;
        } elseif ($amountKES <= 50000) {
            $fee = 477;
        } elseif ($amountKES <= 70000) {
            $fee = 533;
        } else {
            // Over M-Pesa limit, should use bank
            $fee = 0;
        }
        
        return [
            'fee_amount_kes' => $fee,
            'fee_amount_usd' => $this->convertKESToUSD($fee),
            'fee_percentage' => $amountKES > 0 ? ($fee / $amountKES) * 100 : 0,
            'net_amount_kes' => $amountKES - $fee,
            'net_amount_usd' => $this->convertKESToUSD($amountKES - $fee)
        ];
    }

    /**
     * Calculate bank transfer fees
     */
    protected function calculateBankFees($amountKES): array
    {
        // Kenya bank transfer fees (approximate)
        $fee = 0;
        
        if ($amountKES <= 100000) {
            $fee = 50; // EFT fee
        } else {
            $fee = 200; // RTGS fee
        }
        
        return [
            'fee_amount_kes' => $fee,
            'fee_amount_usd' => $this->convertKESToUSD($fee),
            'fee_percentage' => $amountKES > 0 ? ($fee / $amountKES) * 100 : 0,
            'net_amount_kes' => $amountKES - $fee,
            'net_amount_usd' => $this->convertKESToUSD($amountKES - $fee)
        ];
    }
}