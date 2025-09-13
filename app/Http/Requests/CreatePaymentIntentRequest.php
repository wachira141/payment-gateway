<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
            'capture_method' => ['sometimes', Rule::in(['automatic', 'manual'])],
            'payment_method_types' => ['sometimes', 'array'],
            'payment_method_types.*' => ['string', Rule::in(['card', 'mobile_money', 'bank_transfer', 'ussd'])],
            'client_reference_id' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'receipt_email' => ['nullable', 'email', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'shipping' => ['nullable', 'array'],
            'shipping.name' => ['required_with:shipping', 'string', 'max:255'],
            'shipping.address' => ['required_with:shipping', 'array'],
            'shipping.address.line1' => ['required_with:shipping.address', 'string', 'max:255'],
            'shipping.address.city' => ['required_with:shipping.address', 'string', 'max:100'],
            'shipping.address.country' => ['required_with:shipping.address', 'string', 'size:2'],
            'billing_details' => ['nullable', 'array'],
            'billing_details.name' => ['required_with:billing_details', 'string', 'max:255'],
            'billing_details.email' => ['required_with:billing_details', 'email', 'max:255'],

             // Customer data (optional)
             'customer' => ['nullable', 'array'],
             'customer.external_id' => ['nullable', 'string', 'max:255'],
             'customer.name' => ['required_with:customer', 'string', 'max:255'],
             'customer.email' => ['nullable', 'email', 'max:255'],
             'customer.phone' => ['nullable', 'string', 'max:50'],
             'customer.metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Payment amount is required',
            'amount.min' => 'Payment amount must be greater than 0',
            'currency.required' => 'Currency is required',
            'currency.size' => 'Currency must be a 3-letter code',
            'receipt_email.email' => 'Please provide a valid email address',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->currency),
            ]);
        }

        // Set default values
        $this->merge([
            'capture_method' => $this->capture_method ?? 'automatic',
            'payment_method_types' => $this->payment_method_types ?? ['card'],
            'metadata' => $this->metadata ?? [],
        ]);
    }
}
