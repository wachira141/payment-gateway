<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBatchDisbursementRequest extends FormRequest
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
            'wallet_id' => 'required|string|exists:merchant_wallets,wallet_id',
            'batch_name' => 'nullable|string|max:100',
            'disbursements' => 'required|array|min:1|max:100',
            'disbursements.*.beneficiary_id' => 'required|string|exists:beneficiaries,id',
            'disbursements.*.amount' => 'required|numeric|min:0.01',
            'disbursements.*.description' => 'nullable|string|max:500',
            'disbursements.*.external_reference' => 'nullable|string|max:100',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'wallet_id.required' => 'A wallet is required for the batch disbursement',
            'wallet_id.exists' => 'The selected wallet does not exist',
            'disbursements.required' => 'At least one disbursement is required',
            'disbursements.min' => 'At least one disbursement is required',
            'disbursements.max' => 'Maximum 100 disbursements allowed per batch',
            'disbursements.*.beneficiary_id.required' => 'Each disbursement must have a beneficiary',
            'disbursements.*.beneficiary_id.exists' => 'One or more beneficiaries do not exist',
            'disbursements.*.amount.required' => 'Each disbursement must have an amount',
            'disbursements.*.amount.min' => 'Each disbursement amount must be at least 0.01',
        ];
    }
}