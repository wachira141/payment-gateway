<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WalletTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_wallet_id' => 'required|string|max:36',
            'to_wallet_id' => 'required|string|max:36|different:from_wallet_id',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'sometimes|string|max:500',
            'metadata' => 'sometimes|array',
        ];
    }

    public function messages(): array
    {
        return [
            'from_wallet_id.required' => 'Source wallet ID is required.',
            'to_wallet_id.required' => 'Destination wallet ID is required.',
            'to_wallet_id.different' => 'Cannot transfer to the same wallet.',
            'amount.required' => 'Transfer amount is required.',
            'amount.numeric' => 'Amount must be a valid number.',
            'amount.min' => 'Transfer amount must be at least 0.01.',
            'description.max' => 'Description cannot exceed 500 characters.',
        ];
    }
}