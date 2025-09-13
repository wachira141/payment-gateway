<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Customer;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $merchantId = $this->user()->merchant_id;

        return [
            'external_id' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($merchantId) {
                    if ($value && Customer::findByExternalIdAndMerchant($value, $merchantId)) {
                        $fail('A customer with this external ID already exists.');
                    }
                }
            ],
            'name' => 'required|string|max:255',
            'email' => [
                'nullable',
                'email',
                'max:255',
                function ($attribute, $value, $fail) use ($merchantId) {
                    if ($value && Customer::findByEmailAndMerchant($value, $merchantId)) {
                        $fail('A customer with this email already exists.');
                    }
                }
            ],
            'phone' => [
                'nullable',
                'string',
                'max:50',
                function ($attribute, $value, $fail) use ($merchantId) {
                    if ($value && Customer::findByPhoneAndMerchant($value, $merchantId)) {
                        $fail('A customer with this phone number already exists.');
                    }
                }
            ],
            'metadata' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Customer name is required.',
            'email.email' => 'Please provide a valid email address.',
            'phone.max' => 'Phone number must not exceed 50 characters.',
        ];
    }
}