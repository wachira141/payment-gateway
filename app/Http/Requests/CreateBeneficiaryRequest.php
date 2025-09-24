<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\SupportedPayoutMethod;
use App\Models\SupportedBank;

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
            'payout_method_id' => 'required|string',
            'currency' => 'required|string|size:3',
            'country' => 'required|string|size:2',
            'is_default' => 'boolean',
            'metadata' => 'array',
        ];

        // Get dynamic validation rules based on method type
        $methodType = $this->input('payout_method_id');
        $country = $this->input('country');
        $currency = $this->input('currency');

        if ($methodType && $country && $currency) {
            // Validate method type exists for country/currency
            $rules['payout_method_id'] = [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($country, $currency) {
                    $method = SupportedPayoutMethod::getMethodByType($value, $country, $currency);
                    if (!$method) {
                        $fail('The selected payout method is not supported for the specified country and currency.');
                    }
                }
            ];
            
            // Add dynamic field validation rules
            $dynamicRules = SupportedPayoutMethod::getValidationRules($methodType, $country, $currency);

            // Add custom validation for specific field types
            foreach ($dynamicRules as $field => $rule) {
                if ($field === 'bank_code' && str_contains($rule, 'required')) {
                    $rules[$field] = [
                        'required',
                        'string',
                        'max:20',
                        function ($attribute, $value, $fail) use ($country) {
                            if (!SupportedBank::validateBankForCountry($value, $country)) {
                                $fail('The selected bank is not supported for the specified country.');
                            }
                        }
                    ];
                } else {
                    $rules[$field] = $rule;
                }
            }
        }



        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Beneficiary name is required.',
            'payout_method_id.required' => 'Payout method is required.',
            'currency.required' => 'Currency is required.',
            'currency.size' => 'Currency must be a 3-letter code.',
            'country.required' => 'Country is required.',
            'country.size' => 'Country must be a 2-letter code.',
            'account_number.required' => 'Account number is required.',
            'bank_code.required' => 'Bank selection is required.',
            'mobile_number.required' => 'Mobile number is required.',
            'email.required' => 'Email address is required.',
            'account_id.required' => 'Account ID is required.',
        ];
    }
}
