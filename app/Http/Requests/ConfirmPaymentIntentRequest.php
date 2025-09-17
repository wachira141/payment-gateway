<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmPaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'array'],
            'payment_method.type' => ['required', Rule::in(['card', 'mobile_money', 'bank_transfer', 'ussd'])],
            'payment_method.card' => ['required_if:payment_method.type,card', 'array'],
            'payment_method.card.number' => ['required_with:payment_method.card', 'string', 'min:13', 'max:19'],
            'payment_method.card.exp_month' => ['required_with:payment_method.card', 'numeric', 'between:1,12'],
            'payment_method.card.exp_year' => ['required_with:payment_method.card', 'numeric', 'min:' . date('Y')],
            'payment_method.card.cvc' => ['required_with:payment_method.card', 'string', 'min:3', 'max:4'],
            'payment_method.mobile_money' => ['required_if:payment_method.type,mobile_money', 'array'],
            'payment_method.mobile_money.phone_number' => ['required_with:payment_method.mobile_money', 'string'],
            'payment_method.mobile_money.provider' => ['required_with:payment_method.mobile_money', 'string'],
            'return_url' => ['nullable', 'url', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method.required' => 'Payment method is required',
            'payment_method.type.required' => 'Payment method type is required',
            'payment_method.type.in' => 'Invalid payment method type',
            'payment_method.card.number.required_with' => 'Card number is required for card payments',
            'payment_method.mobile_money.phone.required_with' => 'Phone number is required for mobile money payments',
        ];
    }
}