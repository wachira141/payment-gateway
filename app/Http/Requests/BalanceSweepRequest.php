<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BalanceSweepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'description' => 'sometimes|string|max:500',
            'metadata' => 'sometimes|array',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Sweep amount is required.',
            'amount.numeric' => 'Amount must be a valid number.',
            'amount.min' => 'Sweep amount must be at least 0.01.',
            'currency.required' => 'Currency is required.',
            'currency.size' => 'Currency must be a 3-letter code (e.g., KES, USD).',
            'description.max' => 'Description cannot exceed 500 characters.',
        ];
    }
}
