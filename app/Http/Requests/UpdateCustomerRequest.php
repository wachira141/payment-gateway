<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Customer;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $merchantId = $this->user()->merchant_id;
        $customerId = $this->route('customerId');

        return [
            'external_id' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($merchantId, $customerId) {
                    if ($value) {
                        $existing = Customer::findByExternalIdAndMerchant($value, $merchantId);
                        if ($existing && $existing->id != $customerId) {
                            $fail('A customer with this external ID already exists.');
                        }
                    }
                }
            ],
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'nullable',
                'email',
                'max:255',
                function ($attribute, $value, $fail) use ($merchantId, $customerId) {
                    if ($value) {
                        $existing = Customer::findByEmailAndMerchant($value, $merchantId);
                        if ($existing && $existing->id != $customerId) {
                            $fail('A customer with this email already exists.');
                        }
                    }
                }
            ],
            'phone' => [
                'nullable',
                'string',
                'max:50',
                function ($attribute, $value, $fail) use ($merchantId, $customerId) {
                    if ($value) {
                        $existing = Customer::findByPhoneAndMerchant($value, $merchantId);
                        if ($existing && $existing->id != $customerId) {
                            $fail('A customer with this phone number already exists.');
                        }
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