<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitiateTopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:bank_transfer,mobile_money,card,balance_sweep',
            'phone_number' => 'required_if:method,mobile_money|string|max:20',
            'provider' => 'nullable|string|in:mtn,airtel,mpesa,telebirr',
            'gateway' => 'nullable|string|in:stripe,paystack,flutterwave',
            'metadata' => 'sometimes|array',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Top-up amount is required.',
            'amount.numeric' => 'Amount must be a valid number.',
            'amount.min' => 'Amount must be greater than 0.',
            'method.required' => 'Top-up method is required.',
            'method.in' => 'Invalid top-up method. Must be: bank_transfer, mobile_money, card, or balance_sweep.',
            'phone_number.required_if' => 'Phone number is required for mobile money top-ups.',
            'phone_number.max' => 'Phone number cannot exceed 20 characters.',
            'provider.in' => 'Invalid mobile money provider.',
            'gateway.in' => 'Invalid card payment gateway.',
        ];
    }
}
