<?php

namespace App\Jobs;

use App\Mail\DynamicEmail;
use App\Models\EmailLog;
use App\Services\Email\EmailTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Exception;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $emailData;
    protected $emailLogId;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(array $emailData, string $emailLogId)
    {
        $this->emailData = $emailData;
        $this->emailLogId = $emailLogId;
    }

    /**
     * Execute the job.
     */
    public function handle(EmailTrackingService $trackingService): void
    {
        try {
            $emailLog = EmailLog::find($this->emailLogId);
            
            if (!$emailLog) {
                throw new Exception("Email log not found: {$this->emailLogId}");
            }

            // Generate tracking ID if tracking is enabled
            $trackingId = null;
            if ($this->emailData['tracking_enabled'] ?? false) {
                $trackingId = $trackingService->generateTrackingId($emailLog);
            }

            // Prepare email content with tracking
            $emailData = $this->prepareEmailWithTracking($this->emailData, $trackingId);

            // Create mailable
            $mailable = new DynamicEmail($emailData);

            // Send email
            Mail::to($emailData['to'])->send($mailable);

            // Mark as sent
            $emailLog->markAsSent();

            Log::info('Email sent successfully', [
                'email_log_id' => $this->emailLogId,
                'recipient' => $emailData['to'],
                'subject' => $emailData['subject']
            ]);

        } catch (Exception $e) {
            $this->handleFailure($e);
        }
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        $this->handleFailure($exception);
    }

    /**
     * Handle email sending failure
     */
    protected function handleFailure(Exception $exception): void
    {
        try {
            $emailLog = EmailLog::find($this->emailLogId);
            
            if ($emailLog) {
                $emailLog->markAsFailed($exception->getMessage());
            }

            Log::error('Failed to send email', [
                'email_log_id' => $this->emailLogId,
                'error' => $exception->getMessage(),
                'attempt' => $this->attempts()
            ]);

        } catch (Exception $e) {
            Log::error('Failed to handle email failure', [
                'email_log_id' => $this->emailLogId,
                'original_error' => $exception->getMessage(),
                'handling_error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Prepare email content with tracking
     */
    protected function prepareEmailWithTracking(array $emailData, ?string $trackingId): array
    {
        if (!$trackingId || !config('mail.tracking.enabled')) {
            return $emailData;
        }

        // Add tracking ID to email data
        $emailData['tracking_id'] = $trackingId;

        // Add tracking links to content if click tracking is enabled
        if (config('mail.tracking.click_tracking')) {
            $emailData['content']['html'] = $this->addClickTracking(
                $emailData['content']['html'],
                $trackingId
            );
        }

        return $emailData;
    }

    /**
     * Add click tracking to HTML content
     */
    protected function addClickTracking(string $html, string $trackingId): string
    {
        // Find all links in the HTML content
        $pattern = '/<a\s+([^>]*?)href="([^"]*?)"([^>]*?)>/i';
        
        return preg_replace_callback($pattern, function ($matches) use ($trackingId) {
            $originalUrl = $matches[2];
            
            // Skip mailto and tel links
            if (str_starts_with($originalUrl, 'mailto:') || str_starts_with($originalUrl, 'tel:')) {
                return $matches[0];
            }
            
            // Create tracking URL
            $trackingUrl = route('email.track.click', [
                'id' => $trackingId,
                'url' => urlencode($originalUrl)
            ]);
            
            return '<a ' . $matches[1] . 'href="' . $trackingUrl . '"' . $matches[3] . '>';
        }, $html);
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        // Exponential backoff: 1 minute, 5 minutes, 15 minutes
        return [60, 300, 900];
    }

    /**
     * Determine if the job should be retried based on the exception.
     */
    public function retryUntil(): \DateTime
    {
        // Stop retrying after 1 hour
        return now()->addHour();
    }
}