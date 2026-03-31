<?php

namespace App\Http\Requests\Api\Assistant;

use Illuminate\Foundation\Http\FormRequest;

class AnalyticsRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => 'nullable|integer|min:1|max:100',
            'days' => 'nullable|integer|min:1|max:90',
        ];
    }
}
