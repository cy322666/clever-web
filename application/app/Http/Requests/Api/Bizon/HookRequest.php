<?php

namespace App\Http\Requests\Api\Bizon;

use Illuminate\Foundation\Http\FormRequest;

class HookRequest extends FormRequest
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
     * @return array
     */
    public function rules()
    {
        return [
            "event"  => "required|in:webinarEnd",
            "roomid" => "required",
            "webinarId" => "required",
        ];
    }
}
