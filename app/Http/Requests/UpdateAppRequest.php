<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $appId = $this->route('appId');
        $app = \App\Models\App::findForMerchant($appId, $this->user()->merchant_id);
        
        return $app && $this->user()->can('update', $app);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $config = config('apps');
        
        return [
            'name' => str_replace('required|', 'sometimes|', $config['validation']['name']),
            'description' => $config['validation']['description'],
            'webhook_url' => $config['validation']['webhook_url'],
            'redirect_urls' => str_replace('required|', 'sometimes|', $config['validation']['redirect_urls']),
            'redirect_urls.*' => $config['validation']['redirect_urls.*'],
            'logo_url' => $config['validation']['logo_url'],
            'website_url' => $config['validation']['website_url'],
            'is_live' => 'sometimes|boolean',
            'scopes' => str_replace('required|', 'sometimes|', $config['validation']['scopes']),
            'scopes.*' => Rule::in(array_keys($config['scopes'])),
            'webhook_events' => $config['validation']['webhook_events'],
            'webhook_events.*' => Rule::in(array_keys($config['webhook_events'])),
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'scopes.*.in' => 'Invalid scope selected.',
            'webhook_events.*.in' => 'Invalid webhook event selected.',
            'redirect_urls.max' => 'Maximum of 10 redirect URLs allowed.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Additional custom validation if needed
            if ($this->is_live && !$this->user()->merchant->isLiveEnabled()) {
                $validator->errors()->add('is_live', 'Live mode is not enabled for your account.');
            }
        });
    }
}