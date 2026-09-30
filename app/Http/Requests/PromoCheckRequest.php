<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PromoCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'     => ['required', 'string', 'max:50'],
            'program_id' => ['required', 'integer', 'exists:programs,id'],
        ];
    }
}
