<?php

namespace App\Http\Requests\Api\AlfaCRM;

use Illuminate\Foundation\Http\FormRequest;

class RecordRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
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
            'leads' => 'array|required',
        ];
    }
}
