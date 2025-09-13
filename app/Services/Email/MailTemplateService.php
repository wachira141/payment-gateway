<?php

namespace App\Services\Email;

use App\Models\EmailTemplate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;
use Exception;

class MailTemplateService
{
    /**
     * Get email template by name
     */
    public function getTemplate(string $name): EmailTemplate
    {
        $template = Cache::remember(
            "email_template_{$name}",
            3600, // 1 hour
            fn() => EmailTemplate::where('name', $name)->where('active', true)->first()
        );

        if (!$template) {
            throw new Exception("Email template '{$name}' not found");
        }

        return $template;
    }

    /**
     * Render template with data
     */
    public function renderTemplate(EmailTemplate $template, array $data = []): array
    {
        try {
            // Render subject
            $subject = $this->renderString($template->subject, $data);
            
            // Render HTML content
            $htmlContent = $this->renderContent($template->html_content, $data, 'html');
            
            // Render plain text content
            $textContent = $template->text_content 
                ? $this->renderContent($template->text_content, $data, 'text')
                : strip_tags($htmlContent);

            return [
                'subject' => $subject,
                'html' => $htmlContent,
                'text' => $textContent
            ];

        } catch (Exception $e) {
            throw new Exception("Failed to render template: " . $e->getMessage());
        }
    }

    /**
     * Create or update email template
     */
    public function createTemplate(array $data): EmailTemplate
    {
        return EmailTemplate::updateOrCreate(
            ['name' => $data['name']],
            [
                'subject' => $data['subject'],
                'html_content' => $data['html_content'],
                'text_content' => $data['text_content'] ?? null,
                'variables' => $data['variables'] ?? [],
                'category' => $data['category'] ?? 'general',
                'active' => $data['active'] ?? true
            ]
        );
    }

    /**
     * Get all available templates
     */
    public function getAllTemplates(): array
    {
        return EmailTemplate::where('active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy('category')
            ->toArray();
    }

    /**
     * Preview template with sample data
     */
    public function previewTemplate(string $name, array $sampleData = []): array
    {
        $template = $this->getTemplate($name);
        $data = array_merge($this->getSampleData($template), $sampleData);
        
        return $this->renderTemplate($template, $data);
    }

    /**
     * Validate template content
     */
    public function validateTemplate(array $templateData): array
    {
        $errors = [];

        // Check required fields
        if (empty($templateData['name'])) {
            $errors[] = 'Template name is required';
        }

        if (empty($templateData['subject'])) {
            $errors[] = 'Subject is required';
        }

        if (empty($templateData['html_content'])) {
            $errors[] = 'HTML content is required';
        }

        // Check for valid template syntax
        try {
            $this->renderString($templateData['subject'], []);
        } catch (Exception $e) {
            $errors[] = 'Invalid syntax in subject: ' . $e->getMessage();
        }

        try {
            $this->renderString($templateData['html_content'], []);
        } catch (Exception $e) {
            $errors[] = 'Invalid syntax in HTML content: ' . $e->getMessage();
        }

        return $errors;
    }

    /**
     * Render string with variables
     */
    protected function renderString(string $content, array $data): string
    {
        // Simple variable replacement for now
        // Can be enhanced with Twig or Blade templating
        foreach ($data as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }

        return $content;
    }

    /**
     * Render content with Blade template
     */
    protected function renderContent(string $content, array $data, string $type = 'html'): string
    {
        // For now, use simple variable replacement
        // In production, you might want to use Blade templates
        $rendered = $this->renderString($content, $data);
        
        // Add tracking pixels if enabled and HTML content
        if ($type === 'html' && config('mail.tracking.enabled')) {
            $rendered = $this->addTrackingPixel($rendered, $data);
        }

        return $rendered;
    }

    /**
     * Add tracking pixel to HTML content
     */
    protected function addTrackingPixel(string $content, array $data): string
    {
        if (!config('mail.tracking.open_tracking')) {
            return $content;
        }

        $trackingUrl = route('email.track.open', ['id' => $data['tracking_id'] ?? '']);
        $trackingPixel = '<img src="' . $trackingUrl . '" width="1" height="1" style="display:none;" />';
        
        // Insert before closing body tag or at the end
        if (strpos($content, '</body>') !== false) {
            $content = str_replace('</body>', $trackingPixel . '</body>', $content);
        } else {
            $content .= $trackingPixel;
        }

        return $content;
    }

    /**
     * Get sample data for template testing
     */
    protected function getSampleData(EmailTemplate $template): array
    {
        return [
            'user_name' => 'John Doe',
            'app_name' => config('app.name'),
            'app_url' => config('app.url'),
            'current_year' => date('Y'),
            'current_date' => date('F j, Y'),
            'verification_url' => config('app.url') . '/verify-email?token=sample',
            'reset_url' => config('app.url') . '/reset-password?token=sample',
            'amount' => '$99.99',
            'service_name' => 'Sample Service',
            'appointment_date' => date('F j, Y'),
            'appointment_time' => '2:00 PM'
        ];
    }

    /**
     * Clear template cache
     */
    public function clearTemplateCache(string $name = null): void
    {
        if ($name) {
            Cache::forget("email_template_{$name}");
        } else {
            // Clear all template cache
            $templates = EmailTemplate::pluck('name');
            foreach ($templates as $templateName) {
                Cache::forget("email_template_{$templateName}");
            }
        }
    }
}