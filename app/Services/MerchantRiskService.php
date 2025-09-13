<?php

namespace App\Services;

use App\Models\User;
use App\Models\PayoutAutomationRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class MerchantRiskService
{
    /**
     * Assess provider risk level
     */
    public function assessProviderRisk(User $provider): string
    {
        $cacheKey = "provider_risk_{$provider->id}";
        $score = $this->calculateRiskScore($provider);
            if ($score >= 80) {
                return 'low';
            } elseif ($score >= 50) {
                return 'medium';
            } else {
                return 'high';
            }

        return Cache::remember($cacheKey, 3600, function () use ($provider) {
            $score = $this->calculateRiskScore($provider);
            if ($score >= 80) {
                return 'low';
            } elseif ($score >= 50) {
                return 'medium';
            } else {
                return 'high';
            }
        });
    }

    /**
     * Update provider risk level
     */
    public function updateRiskLevel(User $provider): string
    {
        $riskLevel = $this->assessProviderRisk($provider);
        
        // Update provider earnings with new risk level
        $provider->providerEarnings()
                ->where('status', 'pending')
                ->update(['risk_level' => $riskLevel]);
        
        // Clear cache
        Cache::forget("provider_risk_{$provider->id}");
        
        return $riskLevel;
    }

    /**
     * Get risk-based hold period
     */
    public function getRiskBasedHoldPeriod(User $provider, $countryCode = 'KE'): int
    {
        $riskLevel = $this->assessProviderRisk($provider);
        
        $rule = PayoutAutomationRule::where('country_code', $countryCode)
                                   ->where('risk_level', $riskLevel)
                                   ->where('is_active', true)
                                   ->first();
        
        return $rule ? $rule->hold_period_hours : 168; // Default 7 days
    }

    /**
     * Calculate risk score (0-100, higher is better)
     */
    protected function calculateRiskScore(User $provider): int
    {
        $score = 0;
        // Account age (max 25 points)
        $accountAge = $provider->created_at->diffInDays(now());
        if ($accountAge >= 365) {
            $score += 25;
        } elseif ($accountAge >= 180) {
            $score += 20;
        } elseif ($accountAge >= 90) {
            $score += 15;
        } elseif ($accountAge >= 30) {
            $score += 10;
        }
        
        // Transaction history (max 25 points)
        $totalEarnings = $provider->providerEarnings()->count();
        if ($totalEarnings >= 100) {
            $score += 25;
        } elseif ($totalEarnings >= 50) {
            $score += 20;
        } elseif ($totalEarnings >= 20) {
            $score += 15;
        } elseif ($totalEarnings >= 10) {
            $score += 10;
        }
        
        // Dispute rate (max 20 points)
        $disputeRate = $this->calculateDisputeRate($provider);
        if ($disputeRate <= 0.01) { // Less than 1%
            $score += 20;
        } elseif ($disputeRate <= 0.05) { // Less than 5%
            $score += 15;
        } elseif ($disputeRate <= 0.10) { // Less than 10%
            $score += 10;
        }
        
        // Verification status (max 15 points)
        if ($provider->email_verified_at && $provider->phone_number) {
            $score += 15;
        } elseif ($provider->email_verified_at) {
            $score += 10;
        }
        
        // Recent activity (max 15 points)
        $recentEarnings = $provider->providerEarnings()
                                 ->where('created_at', '>=', now()->subDays(30))
                                 ->count();
        if ($recentEarnings >= 10) {
            $score += 15;
        } elseif ($recentEarnings >= 5) {
            $score += 10;
        } elseif ($recentEarnings >= 1) {
            $score += 5;
        }
        
        return min(100, $score);
    }

    /**
     * Calculate dispute rate for provider
     */
    protected function calculateDisputeRate(User $provider): float
    {
        // This would connect to your dispute/chargeback system
        // For now, returning 0 as placeholder
        $totalTransactions = $provider->providerEarnings()->count();
        $disputes = 0; // Would query actual dispute records
        
        return $totalTransactions > 0 ? $disputes / $totalTransactions : 0;
    }

    /**
     * Check if provider qualifies for instant payout
     */
    public function qualifiesForInstantPayout(User $provider): bool
    {
        $riskLevel = $this->assessProviderRisk($provider);
        
        $rule = PayoutAutomationRule::where('country_code', 'KE')
                                   ->where('risk_level', $riskLevel)
                                   ->where('is_active', true)
                                   ->first();
        
        return $rule ? $rule->instant_payout_enabled : false;
    }
}