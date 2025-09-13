<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'sort_by' => 'nullable|string|in:name,email,phone,created_at,updated_at',
            'sort_direction' => 'nullable|string|in:asc,desc',
            'email_filter' => 'nullable|email',
            'phone_filter' => 'nullable|string',
            'external_id_filter' => 'nullable|string',
            'created_from' => 'nullable|date',
            'created_to' => 'nullable|date|after_or_equal:created_from',
        ];
    }

    public function messages(): array
    {
        return [
            'limit.max' => 'Maximum limit is 100 customers per page.',
            'email_filter.email' => 'Please provide a valid email for filtering.',
            'created_to.after_or_equal' => 'End date must be after or equal to start date.',
        ];
    }
}