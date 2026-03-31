<?php

namespace App\Http\Requests\Api\Assistant;

use Illuminate\Foundation\Http\FormRequest;

class ManagerSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'manager_id' => 'required|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }
}
