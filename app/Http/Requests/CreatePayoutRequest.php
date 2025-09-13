<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'beneficiary_id' => 'required|string|max:36',
            'amount' => 'required|integer|min:1',
            'currency' => 'required|string|size:3',
            'description' => 'sometimes|string|max:500',
            'metadata' => 'sometimes|array',
        ];
    }

    public function messages(): array
    {
        return [
            'beneficiary_id.required' => 'Beneficiary ID is required.',
            'amount.required' => 'Payout amount is required.',
            'amount.integer' => 'Amount must be an integer in smallest currency unit.',
            'amount.min' => 'Amount must be greater than 0.',
            'currency.required' => 'Currency is required.',
            'currency.size' => 'Currency must be a 3-letter code.',
            'description.max' => 'Description cannot exceed 500 characters.',
        ];
    }
}