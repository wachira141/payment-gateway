<?php

namespace App\Jobs;

use App\Models\GoalRequest;
use App\Notifications\GoalRequestCreatedNotification;
use App\Notifications\GoalRequestUpdatedNotification;
use App\Notifications\GoalRequestPriceSetNotification;
use App\Notifications\GoalRequestPaymentProcessedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendGoalRequestNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $goalRequest;
    public $notificationType;
    public $updatedData;

    /**
     * Create a new job instance.
     *
     * @param GoalRequest $goalRequest
     * @param string $notificationType 'created' or 'updated'
     * @param array $updatedData Only for update notifications
     */
    public function __construct(GoalRequest $goalRequest, string $notificationType, array $updatedData = [])
    {
        $this->goalRequest = $goalRequest;
        $this->notificationType = $notificationType;
        $this->updatedData = $updatedData;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Load relationships if not already loaded
            $this->goalRequest->loadMissing(['user', 'provider', 'consent']);

            switch ($this->notificationType) {
                case 'created':
                    $this->sendGoalRequestCreatedNotifications();
                    break;

                case 'updated':
                    $this->sendGoalRequestUpdatedNotifications();
                    break;

                case 'price_set':
                    $this->sendPriceSetNotifications();
                    break;

                case 'payment_processed':
                    $this->sendPaymentProcessedNotifications();
                    break;
            }
        } catch (\Exception $e) {
            Log::error("Failed to send goal request notifications: " . $e->getMessage());
            throw $e;
        }
    }

    // In the SendGoalRequestNotifications job, update the notification methods:

    protected function sendGoalRequestCreatedNotifications(): void
    {
        if ($this->goalRequest->user) {
            $this->goalRequest->user->notify(
                new GoalRequestCreatedNotification($this->goalRequest, 'patient')
            );
        }

        if ($this->goalRequest->provider) {
            $this->goalRequest->provider->notify(
                new GoalRequestCreatedNotification($this->goalRequest, 'provider')
            );
        }
    }

    protected function sendGoalRequestUpdatedNotifications(): void
    {
        if ($this->goalRequest->user) {
            $this->goalRequest->user->notify(
                new GoalRequestUpdatedNotification($this->goalRequest, 'patient', $this->updatedData)
            );
        }

        if ($this->goalRequest->provider) {
            $this->goalRequest->provider->notify(
                new GoalRequestUpdatedNotification($this->goalRequest, 'provider', $this->updatedData)
            );
        }
    }

    protected function sendPriceSetNotifications(): void
    {
        if ($this->goalRequest->user) {
            $this->goalRequest->user->notify(
                new GoalRequestPriceSetNotification($this->goalRequest)
            );
        }
    }

    protected function sendPaymentProcessedNotifications(): void
    {
        if ($this->goalRequest->provider) {
            $this->goalRequest->provider->notify(
                new GoalRequestPaymentProcessedNotification($this->goalRequest, 'provider')
            );
        }

        if ($this->goalRequest->user) {
            $this->goalRequest->user->notify(
                new GoalRequestPaymentProcessedNotification($this->goalRequest, 'patient')
            );
        }
    }
}
