<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => 'required|string|size:3',
            'type' => 'required|in:operating,reserve,payout',
            'name' => 'sometimes|string|max:255',
            'settings' => 'sometimes|array',
            'settings.auto_sweep' => 'sometimes|boolean',
            'settings.sweep_threshold' => 'sometimes|numeric|min:0',
            'settings.sweep_target_wallet_id' => 'sometimes|string|max:36',
            'settings.notifications' => 'sometimes|array',
            'daily_withdrawal_limit' => 'sometimes|numeric|min:0',
            'monthly_withdrawal_limit' => 'sometimes|numeric|min:0',
            'metadata' => 'sometimes|array',
        ];
    }

    public function messages(): array
    {
        return [
            'currency.required' => 'Currency is required.',
            'currency.size' => 'Currency must be a 3-letter code (e.g., KES, USD).',
            'type.required' => 'Wallet type is required.',
            'type.in' => 'Invalid wallet type. Must be: operating, reserve, or disbursement.',
            'name.max' => 'Wallet name cannot exceed 255 characters.',
            'settings.sweep_threshold.min' => 'Sweep threshold must be a positive number.',
            'daily_withdrawal_limit.min' => 'Daily withdrawal limit must be a positive number.',
            'monthly_withdrawal_limit.min' => 'Monthly withdrawal limit must be a positive number.',
        ];
    }
}