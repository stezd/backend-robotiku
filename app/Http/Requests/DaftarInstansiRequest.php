<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DaftarInstansiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:120'],
            'birth_date'       => ['required', 'date'],
            'gender'           => ['required', 'in:L,P'],
            'shirt_size'       => ['nullable', 'string', 'max:10'],
            'school_grade'     => ['nullable', 'string', 'max:20'],
            'allergy_notes'    => ['nullable', 'string', 'max:500'],
            'photo_permission' => ['required', 'boolean'],
            // Data orang tua OPSIONAL: jalur instansi sering tidak punya nomor HP
            // (import CSV bahkan tidak punya kolomnya). Kalau salah satu diisi,
            // keduanya harus ada — parents.name & parents.phone NOT NULL.
            'parent_name'      => ['nullable', 'string', 'max:120', 'required_with:phone'],
            'phone'            => ['nullable', 'string', 'max:20'],
            'class_id'         => ['required', 'integer', 'exists:classes,id'],
        ];
    }
}
