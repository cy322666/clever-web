<?php

namespace App\Http\Requests\Api\AlfaCRM;

use Illuminate\Foundation\Http\FormRequest;

class OmissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'entity' => 'required|in:Lesson',
            'event'  => 'required|in:update',
        ];
    }
}
