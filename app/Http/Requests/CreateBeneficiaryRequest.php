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
        $rules = [
            'name' => 'required|string|max:255',
            'type' => 'required|in:bank_account,mobile_money',
            'currency' => 'required|string|size:3',
            'country' => 'required|string|size:2',
            'is_default' => 'boolean',
            'metadata' => 'array',
        ];

        if ($this->input('type') === 'bank_account') {
            $rules['account_number'] = 'required|string|max:50';
            $rules['bank_code'] = 'required|string|max:20';
            $rules['bank_name'] = 'required|string|max:255';
        } elseif ($this->input('type') === 'mobile_money') {
            $rules['mobile_number'] = 'required|string|max:20';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Beneficiary name is required.',
            'type.required' => 'Beneficiary type is required.',
            'type.in' => 'Beneficiary type must be either bank_account or mobile_money.',
            'currency.required' => 'Currency is required.',
            'currency.size' => 'Currency must be a 3-letter code.',
            'country.required' => 'Country is required.',
            'country.size' => 'Country must be a 2-letter code.',
            'account_number.required' => 'Account number is required for bank accounts.',
            'bank_code.required' => 'Bank code is required for bank accounts.',
            'bank_name.required' => 'Bank name is required for bank accounts.',
            'mobile_number.required' => 'Mobile number is required for mobile money.',
        ];
    }
}