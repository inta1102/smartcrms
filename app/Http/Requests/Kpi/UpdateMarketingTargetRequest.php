<?php

namespace App\Http\Requests\Kpi;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMarketingTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) auth()->user();
    }

    public function rules(): array
    {
        return [
            'branch_code' => ['nullable', 'string', 'max:10'],

            'target_os_growth' => ['required', 'numeric', 'min:0', 'max:999999999999999.99'],
            'target_noa'       => ['required', 'integer', 'min:0', 'max:100000'],

            // ✅ tambahan untuk KPI SO
            'target_rr'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'target_activity' => ['nullable', 'integer', 'min:0', 'max:100000'],

            // ✅ bobot dikunci default -> jangan divalidasi dari user
            // 'weight_os'  => ['nullable','integer','min:0','max:100'],
            // 'weight_noa' => ['nullable','integer','min:0','max:100'],

            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
