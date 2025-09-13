<?php

namespace App\Jobs;

use App\Models\ProviderEarning;
use App\Services\ProviderRiskService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MarkEarningsAvailableJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutes
    public $tries = 3;

    /**
     * Execute the job.
     */
    public function handle(ProviderRiskService $providerRiskService): void
    {
        Log::info('Starting earnings availability check');

        try {
            $updatedCount = 0;

            // Get pending earnings that might be ready
            $pendingEarnings = ProviderEarning::where('status', 'pending')
                                            ->where('country_code', 'KE')
                                            ->with('user')
                                            ->get();

            foreach ($pendingEarnings as $earning) {
                if ($this->isEarningAvailable($earning, $providerRiskService)) {
                    $earning->update([
                        'status' => 'available',
                        'available_at' => now()
                    ]);
                    $updatedCount++;
                }
            }

            Log::info('Earnings availability check completed', [
                'checked' => $pendingEarnings->count(),
                'updated' => $updatedCount
            ]);

        } catch (\Exception $e) {
            Log::error('Mark earnings available job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Check if earning is available based on hold period
     */
    protected function isEarningAvailable(ProviderEarning $earning, ProviderRiskService $providerRiskService): bool
    {
        // Check for manual override
        if ($earning->hold_period_override) {
            $availableAt = $earning->created_at->addHours($earning->hold_period_override);
            return now()->greaterThanOrEqualTo($availableAt);
        }

        // Use risk-based hold period
        $holdPeriodHours = $providerRiskService->getRiskBasedHoldPeriod($earning->user, 'KE');
        $availableAt = $earning->created_at->addHours($holdPeriodHours);

        // Skip weekends for bank transfers (optional business rule)
        if ($this->shouldSkipWeekends($earning)) {
            $availableAt = $this->skipWeekends($availableAt);
        }

        return now()->greaterThanOrEqualTo($availableAt);
    }

    /**
     * Check if we should skip weekends for this earning
     */
    protected function shouldSkipWeekends(ProviderEarning $earning): bool
    {
        // Skip weekends for larger amounts that likely go via bank
        return $earning->net_amount >= 500000; // 500,000 KES threshold
    }

    /**
     * Skip weekends and move to next business day
     */
    protected function skipWeekends(Carbon $date): Carbon
    {
        while ($date->isWeekend()) {
            $date->addDay();
        }
        return $date;
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('MarkEarningsAvailableJob failed completely', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}