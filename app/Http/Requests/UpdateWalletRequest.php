<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
            'name.max' => 'Wallet name cannot exceed 255 characters.',
            'settings.sweep_threshold.min' => 'Sweep threshold must be a positive number.',
            'daily_withdrawal_limit.min' => 'Daily withdrawal limit must be a positive number.',
            'monthly_withdrawal_limit.min' => 'Monthly withdrawal limit must be a positive number.',
        ];
    }
}