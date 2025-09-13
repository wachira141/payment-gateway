<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Notifications\BookingCreatedNotification;
use App\Notifications\AppointmentReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SendBookingNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $booking;
    public $notificationType;

    /**
     * Create a new job instance.
     *
     * @param Booking $booking
     * @param string $notificationType 'created' or 'reminder'
     */
    public function __construct(Booking $booking, string $notificationType)
    {
        $this->booking = $booking;
        $this->notificationType = $notificationType;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Load relationships if not already loaded
            $this->booking->loadMissing(['patient.user', 'serviceProvider.user', 'service', 'location']);

            switch ($this->notificationType) {
                case 'created':
                    $this->sendBookingCreatedNotifications();
                    break;
                
                case 'reminder':
                    $this->sendAppointmentReminderNotifications();
                    break;
            }

        } catch (\Exception $e) {
            Log::error("Failed to send booking notifications: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send notifications for newly created booking
     */
    protected function sendBookingCreatedNotifications(): void
    {
        // Notify patient
        if ($this->booking->patient && $this->booking->patient->user) {
            $this->booking->patient->user->notify(
                new BookingCreatedNotification($this->booking, 'patient')
            );
        }

        // Notify service provider
        if ($this->booking->serviceProvider && $this->booking->serviceProvider->user) {
            $this->booking->serviceProvider->user->notify(
                new BookingCreatedNotification($this->booking, 'provider')
            );
        }

        Log::info("Booking created notifications sent for booking ID: " . $this->booking->id);
    }

    /**
     * Send reminder notifications for upcoming appointment
     */
    protected function sendAppointmentReminderNotifications(): void
    {
        // Notify patient
        if ($this->booking->patient && $this->booking->patient->user) {
            $this->booking->patient->user->notify(
                new AppointmentReminderNotification($this->booking, 'patient')
            );
        }

        // Notify service provider
        if ($this->booking->serviceProvider && $this->booking->serviceProvider->user) {
            $this->booking->serviceProvider->user->notify(
                new AppointmentReminderNotification($this->booking, 'provider')
            );
        }

        // Mark reminder as sent in the database
        $this->booking->update(['reminder_sent' => true]);

        Log::info("Appointment reminder notifications sent for booking ID: " . $this->booking->id);
    }
}