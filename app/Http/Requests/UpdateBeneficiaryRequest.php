<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBeneficiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'payout_method_id' => 'sometimes|integer|exists:supported_payout_methods,id',
            'dynamic_fields' => 'sometimes|array',
            'is_default' => 'sometimes|boolean',
            'metadata' => 'sometimes|array',
            'status' => 'sometimes|in:active,inactive,suspended',
        ];
    }

    public function messages(): array
    {
        return [
            'name.string' => 'Beneficiary name must be a string.',
            'name.max' => 'Beneficiary name cannot exceed 255 characters.',
            'payout_method_id.exists' => 'Selected payout method is not supported.',
            'dynamic_fields.array' => 'Beneficiary details must be an array.',
            'is_default.boolean' => 'Default flag must be true or false.',
            'status.in' => 'Status must be active, inactive, or suspended.',
        ];
    }
}