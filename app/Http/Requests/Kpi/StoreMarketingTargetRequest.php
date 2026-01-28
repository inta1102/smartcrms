<?php

namespace App\Http\Requests\Kpi;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarketingTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = auth()->user();
        return $u && $u->hasAnyRole(['AO','RO','SO','FE','BE']);
    }

    public function rules(): array
    {
        return [
            'period' => ['required','date'],
            'branch_code' => ['nullable','string','max:10'],

            'target_os_growth' => ['required','numeric','min:0','max:999999999999999.99'],
            'target_noa'       => ['required','integer','min:0','max:100000'],

            'weight_os'  => ['nullable','integer','min:0','max:100'],
            'weight_noa' => ['nullable','integer','min:0','max:100'],

            'notes' => ['nullable','string','max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_os_growth.required' => 'Target OS Growth wajib diisi.',
            'target_noa.required'       => 'Target NOA wajib diisi.',
        ];
    }
}
