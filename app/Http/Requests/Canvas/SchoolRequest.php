<?php

namespace App\Http\Requests\Canvas;

use Illuminate\Foundation\Http\FormRequest;

class SchoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:150'],
            'address'            => ['nullable', 'string', 'max:255'],
            'pic_name'           => ['nullable', 'string', 'max:120'],
            'contact'            => ['nullable', 'string', 'max:30'],   // No WA PIC
            'bank_account'       => ['nullable', 'string', 'max:120'],
            'qris_image'         => ['nullable', 'string', 'max:255'],  // path hasil upload
            'photo'              => ['nullable', 'string', 'max:255'],  // path hasil upload
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pipeline_status'    => ['nullable', 'in:prospek,dalam_proses,sudah_mou,tidak_lanjut'],
            'is_mou'             => ['nullable', 'boolean'],
            "registration_fee" => "nullable:integer,min:0",
            "price_per_cycle"  => "nullable:integer,min:0",
            'latitude'        => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'       => ['nullable', 'numeric', 'between:-180,180'],
            'geofence_radius' => ['nullable', 'integer', 'min:50', 'max:5000'],
        ];
    }
}
