<?php

namespace App\Http\Requests\Lkh;

// class UpdateLkhRequest extends StoreLkhRequest

use Illuminate\Foundation\Http\FormRequest;

class UpdateLkhRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'is_visited' => ['nullable', 'boolean'],

            'hasil_kunjungan' => ['nullable', 'string'],
            'respon_nasabah' => ['nullable', 'string'],
            'tindak_lanjut' => ['nullable', 'string'],

            // kalau kamu pakai upload evidence (Storage), validasi bisa kamu kunci:
            // 'evidence' => ['nullable','file','mimes:jpg,jpeg,png,pdf','max:2048'],
            'evidence_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $isVisited = $this->boolean('is_visited', true);

            $hasil  = trim((string)$this->input('hasil_kunjungan', ''));
            $respon = trim((string)$this->input('respon_nasabah', ''));
            $next   = trim((string)$this->input('tindak_lanjut', ''));

            // kalau tidak visited, minimal isi alasan di hasil_kunjungan
            if ($isVisited === false && $hasil === '') {
                $v->errors()->add('hasil_kunjungan', 'Jika tidak dikunjungi, wajib isi alasan di hasil kunjungan.');
            }

            // kalau visited tapi semuanya kosong, tolak (biar tidak “kosong semua”)
            if ($isVisited === true && $hasil === '' && $respon === '' && $next === '') {
                $v->errors()->add('hasil_kunjungan', 'Minimal isi salah satu: hasil / respon / tindak lanjut.');
            }
        });
    }
}
