<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateApiKeyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $appId = $this->route('appId');
        $app = \App\Models\App::findForMerchant($appId, $this->user()->merchant_id);

        return $app && $this->user()->can('createApiKey', $app);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $config = config('app');

        return [
            'name' => 'required|string|max:255',
            'scopes' => 'sometimes|array|min:1',
            'scopes.*' => Rule::in(array_keys($config['scopes'])),
            'expires_at' => 'sometimes|date|after:now',
            'rate_limits' => 'sometimes|array',
            'rate_limits.requests_per_minute' => 'sometimes|integer|min:1|max:1000',
            'rate_limits.burst_limit' => 'sometimes|integer|min:1|max:2000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'scopes.required' => 'At least one scope must be selected.',
            'scopes.*.in' => 'Invalid scope selected.',
            'expires_at.after' => 'Expiration date must be in the future.',
        ];
    }



    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $appId = $this->route('appId');
            $app = \App\Models\App::findForMerchant($appId, $this->user()->merchant_id);

            if (!$app) {
                $validator->errors()->add('app', 'App not found.');
            }

            // Since apps don't have scope restrictions, API keys can have any valid catalog scopes
            // The scopes.* rule already validates against the catalog
        });
    }
}
