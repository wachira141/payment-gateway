<?php

namespace App\Services\Email;

use App\Models\EmailLog;
use App\Models\EmailTracking;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailTrackingService
{
    /**
     * Generate tracking ID for email
     */
    public function generateTrackingId(EmailLog $emailLog): string
    {
        $trackingId = Str::uuid();
        
        EmailTracking::create([
            'email_log_id' => $emailLog->id,
            'tracking_id' => $trackingId,
            'recipient' => $emailLog->recipient
        ]);
        
        return $trackingId;
    }

    /**
     * Track email open
     */
    public function trackOpen(string $trackingId, array $metadata = []): bool
    {
        try {
            $tracking = EmailTracking::where('tracking_id', $trackingId)->first();
            
            if (!$tracking) {
                Log::warning('Email tracking ID not found', ['tracking_id' => $trackingId]);
                return false;
            }

            // Update tracking record
            $tracking->update([
                'opened_at' => now(),
                'open_count' => $tracking->open_count + 1,
                'user_agent' => $metadata['user_agent'] ?? null,
                'ip_address' => $metadata['ip_address'] ?? null
            ]);

            // Update email log
            $tracking->emailLog()->update([
                'opened_at' => $tracking->opened_at ?: now(),
                'status' => 'opened'
            ]);

            Log::info('Email open tracked', [
                'tracking_id' => $trackingId,
                'recipient' => $tracking->recipient
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to track email open', [
                'tracking_id' => $trackingId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Track email click
     */
    public function trackClick(string $trackingId, string $url, array $metadata = []): bool
    {
        try {
            $tracking = EmailTracking::where('tracking_id', $trackingId)->first();
            
            if (!$tracking) {
                Log::warning('Email tracking ID not found for click', ['tracking_id' => $trackingId]);
                return false;
            }

            // Record click
            $tracking->update([
                'clicked_at' => now(),
                'click_count' => $tracking->click_count + 1,
                'last_clicked_url' => $url
            ]);

            // Update email log
            $tracking->emailLog()->update(['status' => 'clicked']);

            Log::info('Email click tracked', [
                'tracking_id' => $trackingId,
                'url' => $url,
                'recipient' => $tracking->recipient
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to track email click', [
                'tracking_id' => $trackingId,
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Track email bounce
     */
    public function trackBounce(string $recipient, string $bounceType, string $reason): bool
    {
        try {
            // Find recent email logs for this recipient
            $emailLogs = EmailLog::where('recipient', $recipient)
                ->where('created_at', '>=', now()->subDays(7))
                ->where('status', '!=', 'bounced')
                ->get();

            foreach ($emailLogs as $emailLog) {
                $emailLog->update([
                    'status' => 'bounced',
                    'bounce_type' => $bounceType,
                    'bounce_reason' => $reason,
                    'bounced_at' => now()
                ]);

                // Update tracking if exists
                $tracking = EmailTracking::where('email_log_id', $emailLog->id)->first();
                if ($tracking) {
                    $tracking->update([
                        'bounced_at' => now(),
                        'bounce_type' => $bounceType,
                        'bounce_reason' => $reason
                    ]);
                }
            }

            Log::info('Email bounce tracked', [
                'recipient' => $recipient,
                'bounce_type' => $bounceType,
                'reason' => $reason
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to track email bounce', [
                'recipient' => $recipient,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Track email complaint/spam report
     */
    public function trackComplaint(string $recipient, string $reason = null): bool
    {
        try {
            // Find recent email logs for this recipient
            $emailLogs = EmailLog::where('recipient', $recipient)
                ->where('created_at', '>=', now()->subDays(30))
                ->get();

            foreach ($emailLogs as $emailLog) {
                $emailLog->update([
                    'status' => 'complained',
                    'complaint_reason' => $reason,
                    'complained_at' => now()
                ]);

                // Update tracking if exists
                $tracking = EmailTracking::where('email_log_id', $emailLog->id)->first();
                if ($tracking) {
                    $tracking->update([
                        'complained_at' => now(),
                        'complaint_reason' => $reason
                    ]);
                }
            }

            Log::warning('Email complaint tracked', [
                'recipient' => $recipient,
                'reason' => $reason
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to track email complaint', [
                'recipient' => $recipient,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get email tracking statistics
     */
    public function getTrackingStats(array $filters = []): array
    {
        $query = EmailLog::query();
        
        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        
        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }
        
        if (!empty($filters['template_id'])) {
            $query->where('template_id', $filters['template_id']);
        }

        $total = $query->count();
        $sent = $query->where('status', 'sent')->count();
        $opened = $query->whereNotNull('opened_at')->count();
        $clicked = $query->where('status', 'clicked')->count();
        $bounced = $query->where('status', 'bounced')->count();
        $complained = $query->where('status', 'complained')->count();

        return [
            'total' => $total,
            'sent' => $sent,
            'opened' => $opened,
            'clicked' => $clicked,
            'bounced' => $bounced,
            'complained' => $complained,
            'open_rate' => $sent > 0 ? round(($opened / $sent) * 100, 2) : 0,
            'click_rate' => $sent > 0 ? round(($clicked / $sent) * 100, 2) : 0,
            'bounce_rate' => $sent > 0 ? round(($bounced / $sent) * 100, 2) : 0,
            'complaint_rate' => $sent > 0 ? round(($complained / $sent) * 100, 2) : 0
        ];
    }

    /**
     * Get top performing templates
     */
    public function getTopPerformingTemplates(int $limit = 10): array
    {
        return EmailLog::select('template_id')
            ->selectRaw('COUNT(*) as total_sent')
            ->selectRaw('SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as total_opened')
            ->selectRaw('SUM(CASE WHEN status = "clicked" THEN 1 ELSE 0 END) as total_clicked')
            ->selectRaw('ROUND((SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as open_rate')
            ->selectRaw('ROUND((SUM(CASE WHEN status = "clicked" THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as click_rate')
            ->with('template:id,name,subject')
            ->groupBy('template_id')
            ->having('total_sent', '>=', 10) // Only templates with significant volume
            ->orderByRaw('(total_opened + total_clicked) DESC')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Clean up old tracking data
     */
    public function cleanupOldTracking(int $daysToKeep = 90): int
    {
        $cutoffDate = now()->subDays($daysToKeep);
        
        $deletedCount = EmailTracking::where('created_at', '<', $cutoffDate)->delete();
        
        Log::info("Cleaned up {$deletedCount} old email tracking records");
        
        return $deletedCount;
    }
}