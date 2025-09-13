<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;
use App\Jobs\ProcessEmailBatchJob;
use App\Services\Email\EmailService;
use Illuminate\Support\Facades\Log;
use Exception;

class EmailQueueService
{
    /**
     * Queue priorities and their configurations
     */
    protected $queueConfig = [
        'high' => [
            'queue' => 'emails-high',
            'delay' => 0,
            'timeout' => 120,
            'tries' => 3
        ],
        'normal' => [
            'queue' => 'emails',
            'delay' => 30,
            'timeout' => 300,
            'tries' => 3
        ],
        'bulk' => [
            'queue' => 'emails-bulk',
            'delay' => 300,
            'timeout' => 600,
            'tries' => 2
        ],
        'low' => [
            'queue' => 'emails-low',
            'delay' => 600,
            'timeout' => 900,
            'tries' => 1
        ]
    ];

    /**
     * Get queue name for priority
     */
    public function getQueueForPriority(string $priority): string
    {
        return $this->queueConfig[$priority]['queue'] ?? $this->queueConfig['normal']['queue'];
    }

    /**
     * Get delay for priority
     */
    public function getDelayForPriority(string $priority): int
    {
        return $this->queueConfig[$priority]['delay'] ?? $this->queueConfig['normal']['delay'];
    }

    /**
     * Get timeout for priority
     */
    public function getTimeoutForPriority(string $priority): int
    {
        return $this->queueConfig[$priority]['timeout'] ?? $this->queueConfig['normal']['timeout'];
    }

    /**
     * Get retry attempts for priority
     */
    public function getTriesForPriority(string $priority): int
    {
        return $this->queueConfig[$priority]['tries'] ?? $this->queueConfig['normal']['tries'];
    }

    /**
     * Schedule bulk email batch
     */
    public function scheduleBulkEmails(array $emails, Carbon $scheduledTime = null): void
    {
        $scheduledTime = $scheduledTime ?? now()->addMinutes(5);
        
        // Split emails into batches to avoid overwhelming the queue
        $batches = array_chunk($emails, 100);
        
        foreach ($batches as $index => $batch) {
            // Stagger batch processing to spread load
            $delay = $scheduledTime->copy()->addMinutes($index * 2);
            
            ProcessEmailBatchJob::dispatch($batch)
                ->onQueue($this->getQueueForPriority('bulk'))
                ->delay($delay);
        }
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats(): array
    {
        $stats = [];
        
        foreach ($this->queueConfig as $priority => $config) {
            $queueName = $config['queue'];
            
            $stats[$priority] = [
                'queue_name' => $queueName,
                'pending' => $this->getQueueSize($queueName),
                'failed' => $this->getFailedJobsCount($queueName),
                'processed_today' => $this->getProcessedToday($queueName)
            ];
        }
        
        return $stats;
    }

    /**
     * Get queue size
     */
    protected function getQueueSize(string $queueName): int
    {
        try {
            return Queue::size($queueName);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get failed jobs count
     */
    protected function getFailedJobsCount(string $queueName): int
    {
        // This would need to be implemented based on your queue driver
        // For database driver, query the failed_jobs table
        return 0;
    }

    /**
     * Get processed jobs today
     */
    protected function getProcessedToday(string $queueName): int
    {
        // This would need to be implemented based on your logging/monitoring
        return 0;
    }

    /**
     * Purge queue
     */
    public function purgeQueue(string $priority): bool
    {
        try {
            $queueName = $this->getQueueForPriority($priority);
            
            // Implementation depends on queue driver
            // For Redis: Redis::del("queues:{$queueName}")
            // For Database: DB::table('jobs')->where('queue', $queueName)->delete()
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Retry failed jobs for queue
     */
    public function retryFailedJobs(string $priority, int $limit = 100): int
    {
        $queueName = $this->getQueueForPriority($priority);
        
        // Implementation depends on queue driver
        // This would query failed_jobs table and re-queue them
        
        return 0;
    }

    /**
     * Get optimal sending time based on recipient timezone
     */
    public function getOptimalSendTime(string $timezone = null, string $priority = 'normal'): Carbon
    {
        $now = now();
        
        if ($priority === 'high') {
            return $now; // Send immediately for high priority
        }
        
        // For normal/low priority, consider optimal sending times
        $currentHour = $now->hour;
        
        // Avoid sending during typical sleep hours (11 PM - 7 AM)
        if ($currentHour >= 23 || $currentHour < 7) {
            return $now->setHour(9)->setMinute(0)->setSecond(0);
        }
        
        return $now->addMinutes($this->getDelayForPriority($priority));
    }

    /**
     * Calculate exponential backoff delay for retries
     */
    public function calculateBackoffDelay(int $attempt): int
    {
        // Exponential backoff: 2^attempt minutes, max 60 minutes
        return min(pow(2, $attempt), 60) * 60; // Convert to seconds
    }
}