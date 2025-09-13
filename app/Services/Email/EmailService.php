<?php

namespace App\Services\Email;

use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Carbon\Carbon;
use App\Models\Service; // Import the Service class
use App\Models\ServiceProvider;
use Exception;

class EmailService
{
    protected $templateService;
    protected $trackingService;
    protected $queueService;
    protected $tokenService;

    public function __construct(
        MailTemplateService $templateService,
        EmailTrackingService $trackingService,
        EmailQueueService $queueService,
        TokenService $tokenService
    ) {
        $this->templateService = $templateService;
        $this->trackingService = $trackingService;
        $this->queueService = $queueService;
        $this->tokenService = $tokenService;
    }

    /**
     * Send email with template and tracking
     */
    // public function sendEmail(array $data): bool
    // {
    //     try {
    //         // Rate limiting check
    //         if (!$this->checkRateLimit($data['recipient'])) {
    //             throw new Exception('Rate limit exceeded for recipient');
    //         }

    //         // Get or create email template
    //         $template = $this->templateService->getTemplate($data['template']);
    //         echo json_encode($template);

    //         // Prepare email data
    //         $emailData = $this->prepareEmailData($data, $template);

    //         // Create email log entry
    //         $emailLog = $this->createEmailLog($emailData);

    //         // Queue or send immediately based on priority
    //         if ($data['queue'] ?? true) {
    //             $this->queueEmail($emailData, $emailLog);
    //         } else {
    //             $this->sendImmediately($emailData, $emailLog);
    //         }

    //         return true;

    //     } catch (Exception $e) {
    //         Log::error('Failed to send email', [
    //             'error' => $e->getMessage(),
    //             'data' => $data
    //         ]);
    //         return false;
    //     }
    // }

    /**
     * Send email with Blade template and tracking
     */
    public function sendEmail(string $templatePath, array $data): bool
    {
        try {
            // Rate limiting check
            if (!$this->checkRateLimit($data['recipient'])) {
                throw new Exception('Rate limit exceeded for recipient');
            }

            // Render Blade template instead of getting from database
            $renderedContent = $this->renderBladeTemplate($templatePath, $data['template'], $data['variables'] ?? []);

            // Prepare email data with rendered content
            $emailData = $this->prepareEmailDataWithBladeTemplate($data, $renderedContent);
            // Create email log entry
            $emailLog = $this->createEmailLog($data);

            // Queue or send immediately based on priority
            if ($data['queue'] ?? true) {
                $this->queueEmail($emailData, $emailLog);
            } else {
                $this->sendImmediately($emailData, $emailLog);
            }

            return true;
        } catch (Exception $e) {
            Log::error('Failed to send email', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return false;
        }
    }

    /**
     * Send welcome email to new user
     */
    /**
     * Send welcome email to new user
     */
    public function sendWelcomeEmail(User $user, array $data = []): bool
    {
        try {
            // Prepare default welcome email data
            $defaultData = [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'login_url' => config('app.frontend_url') . '/login',
                'dashboard_url' => config('app.frontend_url') . '/dashboard',
                'profile_url' => config('app.frontend_url') . '/profile',
                'support_email' => config('mail.support_address', config('mail.from.address')),
                'help_center_url' => config('app.frontend_url') . '/help',
                'getting_started_url' => config('app.frontend_url') . '/getting-started',
                'join_date' => $user->created_at->format('M j, Y'),
                'company_name' => config('app.company_name', config('app.name')),
            ];

            return $this->sendEmail('auth', [
                'template' => 'welcome', // This corresponds to resources/views/emails/welcome.blade.php
                'recipient' => $user->email,
                'subject' => 'Welcome to ' . config('app.name') . '!', // Optional - can be handled by template
                'user_id' => $user->id,
                'variables' => array_merge($defaultData, $data), // Merge custom data with defaults
                'priority' => 'normal', // Welcome emails can be normal priority
                'queue' => true, // Can be queued for better performance
                'tags' => ['welcome', 'onboarding', 'new_user'],

                // Optional: Add reply-to for support
                'reply_to' => config('mail.support_address'),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send welcome email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
                'custom_data' => $data,
                'stack_trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    // Enhanced version with more features
    public function sendEnhancedWelcomeEmail(User $user, array $options = []): bool
    {
        try {
            // Check if user should receive welcome email (avoid duplicates)
            if ($user->welcome_email_sent_at) {
                Log::info('Welcome email already sent to user', ['user_id' => $user->id]);
                return true; // Return true since email was already sent
            }

            $welcomeData = [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'user_first_name' => explode(' ', $user->name)[0], // Get first name only
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'login_url' => config('app.frontend_url') . '/login',
                'dashboard_url' => config('app.frontend_url') . '/dashboard',
                'profile_url' => config('app.frontend_url') . '/profile',
                'settings_url' => config('app.frontend_url') . '/settings',
                'support_email' => config('mail.support_address', config('mail.from.address')),
                'help_center_url' => config('app.frontend_url') . '/help',
                'getting_started_url' => config('app.frontend_url') . '/getting-started',
                'community_url' => config('app.community_url'),
                'blog_url' => config('app.blog_url'),
                'join_date' => $user->created_at->format('F j, Y'),
                'company_name' => config('app.company_name', config('app.name')),
                'social_links' => [
                    'twitter' => config('app.social.twitter'),
                    'facebook' => config('app.social.facebook'),
                    'linkedin' => config('app.social.linkedin'),
                    'instagram' => config('app.social.instagram'),
                ],
                // Welcome bonus or special offer
                'welcome_bonus' => $options['welcome_bonus'] ?? null,
                'promo_code' => $options['promo_code'] ?? null,

                // Onboarding checklist items
                'next_steps' => $options['next_steps'] ?? [
                    'Complete your profile',
                    'Explore the dashboard',
                    'Connect with other users',
                    'Check out our getting started guide'
                ],

                // Feature highlights
                'key_features' => $options['key_features'] ?? [
                    'Easy-to-use dashboard',
                    '24/7 customer support',
                    'Secure and reliable platform',
                    'Regular feature updates'
                ]
            ];

            $result = $this->sendEmail('auth', [
                'template' => 'welcome',
                'recipient' => $user->email,
                'subject' => $options['subject'] ?? "Welcome to " . config('app.name') . ", {$welcomeData['user_first_name']}!",
                'variables' => array_merge($welcomeData, $options['extra_data'] ?? []),
                'priority' => $options['priority'] ?? 'normal',
                'queue' => $options['queue'] ?? true,
                'tags' => array_merge(['welcome', 'onboarding'], $options['tags'] ?? []),
                'reply_to' => config('mail.support_address'),
            ]);

            // Mark welcome email as sent
            if ($result) {
                $user->update(['welcome_email_sent_at' => now()]);
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Failed to send enhanced welcome email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
                'options' => $options
            ]);
            return false;
        }
    }


    /**
     * Send email verification
     */
    // public function sendEmailVerification(User $user): bool
    // {
    //     try {
    //         // Generate verification token
    //         $token = $this->tokenService->generateEmailVerificationToken($user->email);
    //         $verificationUrl = config('app.frontend_url') . '/verify-email?token=' . $token . '&email=' . urlencode($user->email);

    //         return $this->sendEmail(
    //             'auth',
    //             [
    //             'template' => 'email_verification',
    //             'recipient' => $user->email,
    //             'recipient_name' => $user->name,
    //             'subject' => 'Verify Your Email Address',
    //             'data' => [
    //                 'user_name' => $user->name,
    //                 'verification_url' => $verificationUrl,
    //                 'app_name' => config('app.name')
    //             ],
    //             'priority' => 'high',
    //             'user_id' => $user->id
    //         ]);
    //     } catch (Exception $e) {
    //         Log::error('Failed to send email verification', [
    //             'user_id' => $user->id,
    //             'email' => $user->email,
    //             'error' => $e->getMessage()
    //         ]);
    //         return false;
    //     }
    // }

    public function sendEmailVerification(User $user): bool
    {
        try {
            // Generate verification token
            $token = $this->tokenService->generateEmailVerificationToken($user->email);
            $verificationUrl = config('app.frontend_url') . '/verify-email?token=' . $token . '&email=' . urlencode($user->email);

            // Get expiration time from config (if applicable)
            $expiresInHours = config('auth.verification.expire', 24); // Default 24 hours
            $expiresAt = now()->addHours($expiresInHours);

            return $this->sendEmail('auth', [
                'template' => 'email_verification', // This corresponds to resources/views/emails/email_verification.blade.php
                'recipient' => $user->email,
                'subject' => 'Verify Your Email Address', // Optional - can be handled by template
                'user_id' => $user->id,
                'variables' => [ // Changed from 'data' to 'variables'
                    'user_name' => $user->name,
                    'user_email' => $user->email, // Useful to show which email needs verification
                    'verification_link' => $verificationUrl, // Changed from 'verification_url' to match template convention
                    'expires_in_hours' => $expiresInHours,
                    'expires_at' => $expiresAt->format('M j, Y \a\t g:i A T'),
                    'app_name' => config('app.name'),
                    'login_url' => config('app.frontend_url') . '/login',
                    'support_email' => config('mail.support_address', config('mail.from.address')),
                    'request_ip' => request()->ip(), // Optional: for security tracking
                    'request_time' => now()->format('M j, Y \a\t g:i A T'),
                ],
                'priority' => 'high',
                'queue' => false, // Send immediately for better user experience
                'tags' => ['email_verification', 'authentication'],

                // Optional: Add reply-to for support
                'reply_to' => config('mail.support_address'),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send email verification', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
                'token_generated' => isset($token),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Send password reset email
     */
    // public function sendPasswordReset(User $user): bool
    // {
    //     try {
    //         // Generate password reset token
    //         $token = $this->tokenService->generatePasswordResetToken($user->email);
    //         $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
    //         return $this->sendEmail([
    //             'template' => 'password_reset',
    //             'recipient' => $user->email,
    //             'recipient_name' => $user->name,
    //             'subject' => 'Reset Your Password',
    //             'data' => [
    //                 'user_name' => $user->name,
    //                 'reset_url' => $resetUrl,
    //                 'app_name' => config('app.name')
    //             ],
    //             'priority' => 'high',
    //             'user_id' => $user->id
    //         ]);
    //     } catch (Exception $e) {
    //         Log::error('Failed to send password reset email', [
    //             'user_id' => $user->id,
    //             'email' => $user->email,
    //             'error' => $e->getMessage()
    //         ]);
    //         return false;
    //     }
    // }

    public function sendPasswordReset(User $user): bool
    {
        try {
            // Generate password reset token
            $token = $this->tokenService->generatePasswordResetToken($user->email);
            $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);

            // Get expiration time from config
            $expiresInMinutes = config('auth.passwords.users.expire', 60);
            $expiresAt = now()->addMinutes($expiresInMinutes);

            return $this->sendEmail(
                'auth',
                [
                    'template' => 'password_reset',
                    'recipient' => $user->email,
                    'subject' => 'Reset Your Password - ' . config('app.name'), // More specific subject
                    'user_id' => $user->id,
                    'variables' => [
                        'user_name' => $user->name,
                        'user_email' => $user->email, // In case template needs to show email
                        'reset_link' => $resetUrl,
                        'expires_in' => $expiresInMinutes,
                        'expires_at' => $expiresAt->format('M j, Y \a\t g:i A T'), // Formatted expiry time
                        'app_name' => config('app.name'),
                        'support_email' => config('mail.support_address', config('mail.from.address')),
                        'login_url' => config('app.frontend_url') . '/login', // Link back to login
                        'request_ip' => request()->ip(), // Optional: show IP for security
                        'request_time' => now()->format('M j, Y \a\t g:i A T'), // When request was made
                    ],
                    'priority' => 'high',
                    'queue' => false, // Send immediately
                    'tags' => ['password_reset', 'security'],

                    // Optional: Add reply-to for support
                    'reply_to' => config('mail.support_address'),

                    // Optional: BCC security team for monitoring
                    // 'bcc' => [config('mail.security_address')],
                ]
            );
        } catch (Exception $e) {
            Log::error('Failed to send password reset email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
                'token_generated' => isset($token),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Send appointment reminder
     */
    public function sendAppointmentReminder(User $user, array $appointmentData): bool
    {
        return $this->sendEmail('', [
            'template' => 'appointment_reminder',
            'recipient' => $user->email,
            'recipient_name' => $user->name,
            'subject' => 'Appointment Reminder',
            'data' => array_merge([
                'user_name' => $user->name,
                'app_name' => config('app.name')
            ], $appointmentData),
            'priority' => 'normal',
            'user_id' => $user->id
        ]);
    }

    /**
     * Send payment confirmation
     */
    public function sendPaymentConfirmation(User $user, array $paymentData): bool
    {
        return $this->sendEmail('payment', [
            'template' => 'payment_confirmation',
            'recipient' => $user->email,
            'recipient_name' => $user->name,
            'subject' => 'Payment Confirmation',
            'data' => array_merge([
                'user_name' => $user->name,
                'app_name' => config('app.name')
            ], $paymentData),
            'priority' => 'high',
            'user_id' => $user->id
        ]);
    }

    /**
 * Send service provider notification about new purchase
 */
public function sendServiceProviderNotification(
    ServiceProvider $provider,
    User $customer,
    Service $service,
    ?array $bookingData = null
): bool {
    try {
        $defaultData = [
            'provider_name' => $provider->user->name,
            'user_name' => $customer->name,
            'user_email' => $customer->email,
            'app_name' => config('app.name'),
            'app_url' => config('app.url'),
            'dashboard_url' => config('app.frontend_url') . '/provider/dashboard',
            'support_email' => config('mail.support_address', config('mail.from.address')),
            'company_name' => config('app.company_name', config('app.name')),
            
            // Service details
            'service_name' => $service->name,
            'service_description' => $service->description,
            'total_amount' => $service->price,
            'currency' => $service->price_currency ?? 'KES',
            'has_sessions' => $service->has_sessions,
            'total_sessions' => $service->total_sessions,
            'completed_sessions' => 0,
            'purchase_date' => now()->format('M j, Y \a\t g:i A'),
            
            // Booking details (if applicable)
            'has_booking' => !is_null($bookingData),
            'booking_date' => $bookingData['date'] ?? null,
            'booking_location' => $bookingData['location'] ?? null,
            'booking_instructions' => $bookingData['instructions'] ?? null,
        ];

        $subject = $bookingData ? 
            'New Booking: ' . $service->name : 
            'New Purchase: ' . $service->name;

        return $this->sendEmail('services', [
            'template' => 'service_provider_notification',
            'recipient' => $provider->user->email,
            'subject' => $subject,
            'user_id' => $provider->user->id,
            'variables' => $defaultData,
            'priority' => 'high',
            'queue' => false,
            'tags' => ['provider', 'notification', $bookingData ? 'booking' : 'purchase'],
        ]);
        
    } catch (Exception $e) {
        Log::error('Failed to send service provider notification', [
            'provider_id' => $provider->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}


    /**
     * Send service purchase confirmation email
     */
    public function sendServicePurchaseConfirmation(User $user, array $purchasedServiceData): bool
    {
        try {
            // Prepare default email data
            $defaultData = [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'dashboard_url' => config('app.frontend_url') . '/dashboard',
                'profile_url' => config('app.frontend_url') . '/profile',
                'support_email' => config('mail.support_address', config('mail.from.address')),
                'help_center_url' => config('app.frontend_url') . '/help',
                'company_name' => config('app.company_name', config('app.name')),

                // Service specific data
                'service_name' => $purchasedServiceData['service_name'],
                'service_description' => $purchasedServiceData['service_description'] ?? '',
                'purchase_date' => $purchasedServiceData['purchase_date']->format('M j, Y \a\t g:i A'),
                'total_amount' => $purchasedServiceData['total_amount'],
                'currency' => $purchasedServiceData['currency'] ?? config('app.currency', 'USD'),
                'payment_method' => $purchasedServiceData['payment_method'],
                'transaction_id' => $purchasedServiceData['transaction_id'] ?? null,
                'receipt_url' => $purchasedServiceData['receipt_url'] ?? null,

                // Booking details if applicable
                'has_booking' => $purchasedServiceData['has_booking'] ?? false,
                'booking_date' => isset($purchasedServiceData['next_appointment'])
                    ? Carbon::parse($purchasedServiceData['next_appointment'])->format('M j, Y \a\t g:i A')
                    : null,
                'booking_location' => $purchasedServiceData['booking_location'] ?? null,
                'booking_instructions' => $purchasedServiceData['booking_instructions'] ?? null,
                'reschedule_url' => $purchasedServiceData['reschedule_url'] ?? null,
                'cancel_url' => $purchasedServiceData['cancel_url'] ?? null,

                // Session details if applicable
                'has_sessions' => $purchasedServiceData['has_sessions'] ?? false,
                'total_sessions' => $purchasedServiceData['total_sessions'] ?? null,
                'completed_sessions' => $purchasedServiceData['completed_sessions'] ?? 0,
                'sessions_remaining' => $purchasedServiceData['total_sessions'] - ($purchasedServiceData['completed_sessions'] ?? 0),
            ];

            // Determine template based on whether it's a booked service
            $template = $purchasedServiceData['has_booking'] ? 'service_purchase_booked' : 'service_purchase';

            return $this->sendEmail('services', [
                'template' => $template,
                'recipient' => $user->email,
                'subject' => $purchasedServiceData['has_booking']
                    ? 'Your Booking Confirmation for ' . $purchasedServiceData['service_name']
                    : 'Your Purchase Confirmation for ' . $purchasedServiceData['service_name'],
                'user_id' => $user->id,
                'variables' => array_merge($defaultData, $purchasedServiceData['email_variables'] ?? []),
                'priority' => 'high',
                'queue' => false, // Send immediately for purchase confirmations
                'tags' => ['purchase', 'service', $purchasedServiceData['has_booking'] ? 'booking' : 'product'],
                'reply_to' => config('mail.support_address'),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send service purchase confirmation email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'service_data' => $purchasedServiceData,
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Send bulk emails
     */
    public function sendBulkEmails(array $recipients, array $emailData): array
    {
        $results = [];

        foreach ($recipients as $recipient) {
            $data = array_merge($emailData, [
                'recipient' => $recipient['email'],
                'recipient_name' => $recipient['name'] ?? '',
                'queue' => true,
                'priority' => 'bulk'
            ]);

            $results[] = [
                'recipient' => $recipient['email'],
                'success' => $this->sendEmail('', $data)
            ];
        }

        return $results;
    }

    /**
     * Prepare email data for sending
     */
    protected function prepareEmailData(array $data, EmailTemplate $template): array
    {
        $content = $this->templateService->renderTemplate($template, $data['data'] ?? []);

        return [
            'recipient' => $data['recipient'],
            'recipient_name' => $data['recipient_name'] ?? '',
            'subject' => $data['subject'] ?? $template->subject,
            'content' => $content,
            'template_id' => $template->id,
            'priority' => $data['priority'] ?? 'normal',
            'user_id' => $data['user_id'] ?? null,
            'tracking_enabled' => config('mail.tracking.enabled', true)
        ];
    }

    /**
     * Create email log entry
     */
    protected function createEmailLog(array $emailData): EmailLog
    {
        try {
            return EmailLog::create([
                'recipient' => $emailData['recipient'],
                'subject' => $emailData['subject'],
                'template_id' => $emailData['template_id'] ?? null,
                'user_id' => $emailData['user_id'],
                'status' => 'pending',
                'priority' => $emailData['priority'],
                'scheduled_at' => now()
            ]);
        } catch (\Exception $th) {
            echo $th->getMessage();
            throw $th->getMessage();
        }
    }

    /**
     * Queue email for sending
     */
    protected function queueEmail(array $emailData, EmailLog $emailLog): void
    {
        $queue = $this->queueService->getQueueForPriority($emailData['priority']);

        SendEmailJob::dispatch($emailData, $emailLog->id)
            ->onQueue($queue)
            ->delay($this->queueService->getDelayForPriority($emailData['priority']));
    }

    /**
     * Send email immediately
     */
    protected function sendImmediately(array $emailData, EmailLog $emailLog): void
    {
        SendEmailJob::dispatchSync($emailData, $emailLog->id);
    }

    /**
     * Check rate limiting
     */
    protected function checkRateLimit(string $recipient): bool
    {
        if (!config('mail.rate_limiting.enabled', true)) {
            return true;
        }

        $key = 'email_rate_limit:' . $recipient;
        $maxPerMinute = config('mail.rate_limiting.max_per_minute', 60);

        return RateLimiter::attempt(
            $key,
            $maxPerMinute,
            function () {
                // Allow the email to be sent
            },
            60 // 1 minute window
        );
    }

    /**
     * Get email statistics
     */
    public function getEmailStats(array $filters = []): array
    {
        $query = EmailLog::query();

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return [
            'total' => $query->count(),
            'sent' => $query->where('status', 'sent')->count(),
            'failed' => $query->where('status', 'failed')->count(),
            'pending' => $query->where('status', 'pending')->count(),
            'by_priority' => $query->groupBy('priority')
                ->selectRaw('priority, count(*) as count')
                ->pluck('count', 'priority')
                ->toArray()
        ];
    }

    /**
     * Render Blade template with variables
     */
    protected function renderBladeTemplate(
        string $templatePath,
        string $templateName,
        array $variables = []
    ): array {
        try {
            $fullTemplatePath = "emails.{$templatePath}.{$templateName}";

            // Check if template exists
            if (!View::exists($fullTemplatePath)) {
                throw new Exception("Email template '{$fullTemplatePath}' not found");
            }

            // Render the template
            $htmlContent = View::make($fullTemplatePath, $variables)->render();

            // Optionally render a text version if it exists
            $textContent = null;
            $textTemplatePath = "emails.text.{$templateName}";

            if (View::exists($textTemplatePath)) {
                // $textContent = View::make($textTemplatePath, $variables)->render();
            }

            return [
                'html' => $htmlContent,
                'text' => $textContent,
                'subject' => $this->extractSubjectFromTemplate($templateName, $variables),
                'template_name' => $templateName,
                'template_path' => $templatePath
            ];
        } catch (Exception $e) {
            Log::error('Failed to render Blade template', [
                'template' => $fullTemplatePath ?? $templateName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    /**
     * Prepare email data with rendered Blade template
     */
    protected function prepareEmailDataWithBladeTemplate(array $data, array $renderedContent): array
    {
       
        return [
            'to' => $data['recipient'],
            'from' => $data['from'] ?? config('mail.from.address'),
            'from_name' => $data['from_name'] ?? config('mail.from.name'),
            'subject' => $data['subject'] ?? $renderedContent['subject'],
            'html_content' => $renderedContent['html'],
            'text_content' => $renderedContent['text'],
            'template_name' => $renderedContent['template_name'],
            'variables' => $data['variables'] ?? [],
            'attachments' => $data['attachments'] ?? [],
            'reply_to' => $data['reply_to'] ?? null,
            'cc' => $data['cc'] ?? [],
            'bcc' => $data['bcc'] ?? [],
            'priority' => $data['priority'] ?? 'normal',
            'tags' => $data['tags'] ?? [],
        ];
       
    }

    /**
     * Extract subject from template or use default
     */
    protected function extractSubjectFromTemplate(string $templateName, array $variables): string
    {
        // Try to get subject from a dedicated subject template
        if (View::exists("emails.subjects.{$templateName}")) {
            return trim(View::make("emails.subjects.{$templateName}", $variables)->render());
        }

        // Or use a subject variable if provided
        if (isset($variables['subject'])) {
            return $variables['subject'];
        }

        // Default subject based on template name
        return ucwords(str_replace(['_', '-'], ' ', $templateName));
    }
}
