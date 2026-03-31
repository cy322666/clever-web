<?php

namespace App\Http\Requests\Api\Assistant;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssistantLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:50',
            'endpoint' => 'nullable|string|max:255',
            'tool' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'prompt_version' => 'nullable|string|max:255',
            'latency_ms' => 'nullable|integer|min:0',
            'input_tokens' => 'nullable|integer|min:0',
            'output_tokens' => 'nullable|integer|min:0',
            'total_tokens' => 'nullable|integer|min:0',
            'request_payload' => 'nullable|array',
            'response_payload' => 'nullable|array',
            'error' => 'nullable|string',
        ];
    }
}
