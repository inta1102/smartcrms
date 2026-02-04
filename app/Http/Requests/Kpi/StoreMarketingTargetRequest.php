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
            'period'      => ['required', 'date'],
            'branch_code' => ['nullable', 'string', 'max:10'],

            // SO: target pencairan (os_disbursement) & NOA
            'target_os_growth' => ['required', 'numeric', 'min:0', 'max:999999999999999.99'],
            'target_noa'       => ['required', 'integer', 'min:0', 'max:100000'],

            // ✅ tambahan untuk KPI SO
            // RR target default 100 (user boleh isi 95-100 atau 0-100 sesuai kebijakan)
            'target_rr'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Handling komunitas = jumlah kegiatan (integer)
            'target_activity' => ['nullable', 'integer', 'min:0', 'max:100000'],

            // ✅ bobot dikunci default -> jangan divalidasi dari user
            // 'weight_os'  => ['nullable','integer','min:0','max:100'],
            // 'weight_noa' => ['nullable','integer','min:0','max:100'],

            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_os_growth.required' => 'Target OS wajib diisi.',
            'target_noa.required'       => 'Target NOA wajib diisi.',
        ];
    }
}
