<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBeneficiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'currency' => 'required|string|size:3',
            'country' => 'required|string|size:2',
            'payout_method_id' => 'required|string|exists:supported_payout_methods,id',
            'dynamic_fields' => 'required|array',
            'is_default' => 'sometimes|boolean',
            'metadata' => 'sometimes|array',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Beneficiary name is required.',
            'currency.required' => 'Currency is required.',
            'currency.size' => 'Currency must be a 3-character code.',
            'country.required' => 'Country is required.',
            'country.size' => 'Country must be a 2-character code.',
            'payout_method_id.required' => 'Payout method is required.',
            'payout_method_id.exists' => 'Selected payout method is not supported.',
            'dynamic_fields.required' => 'Beneficiary details are required.',
            'dynamic_fields.array' => 'Beneficiary details must be an array.',
            'is_default.boolean' => 'Default flag must be true or false.',
        ];
    }
}