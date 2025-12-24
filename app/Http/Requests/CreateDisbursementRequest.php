<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateDisbursementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'wallet_id' => 'required|string|exists:merchant_wallets,id',
            'beneficiary_id' => 'required|string|exists:beneficiaries,id',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'external_reference' => 'nullable|string|max:100',
            'metadata' => 'nullable|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'wallet_id.required' => 'A wallet is required for the disbursement',
            'wallet_id.exists' => 'The selected wallet does not exist',
            'beneficiary_id.required' => 'A beneficiary is required for the disbursement',
            'beneficiary_id.exists' => 'The selected beneficiary does not exist',
            'amount.required' => 'Amount is required',
            'amount.min' => 'Amount must be at least 0.01',
        ];
    }
}
