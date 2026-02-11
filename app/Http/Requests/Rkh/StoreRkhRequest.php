<?php

namespace App\Http\Requests\Rkh;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class StoreRkhRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check(); // nanti bisa kamu kunci hanya RO
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.jam_mulai' => ['required', 'string'],
            'items.*.jam_selesai' => ['required', 'string'],

            'items.*.nasabah_id' => ['nullable', 'integer'],

            // ✅ NEW: account_no opsional (kosong = prospect)
            'items.*.account_no' => ['nullable', 'string', 'max:255'],

            'items.*.nama_nasabah' => ['nullable', 'string', 'max:190'],
            'items.*.kolektibilitas' => ['nullable', Rule::in(['L0','LT'])],

            // master dropdown
            'items.*.jenis_kegiatan' => [
                'required',
                'string',
                Rule::exists('master_jenis_kegiatan', 'code')->where(fn($q) => $q->where('is_active', 1)),
            ],
            'items.*.tujuan_kegiatan' => ['required', 'string'],

            'items.*.area' => ['nullable', 'string', 'max:190'],
            'items.*.catatan' => ['nullable', 'string'],

            // networking payload (opsional; wajib kalau jenis pengembangan_jaringan)
            'items.*.networking' => ['nullable', 'array'],
            'items.*.networking.nama_relasi' => ['nullable', 'string', 'max:190'],
            'items.*.networking.jenis_relasi' => ['nullable', Rule::in(['supplier','komunitas','tokoh','umkm','lainnya'])],
            'items.*.networking.potensi' => ['nullable', 'string'],
            'items.*.networking.follow_up' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $items = (array) $this->input('items', []);

            foreach ($items as $i => $row) {
                $jenis  = (string)($row['jenis_kegiatan'] ?? '');
                $tujuan = (string)($row['tujuan_kegiatan'] ?? '');

                if ($jenis === '' || $tujuan === '') continue;

                // 0) normalize account_no (optional) - kalau spasi doang dianggap kosong
                if (array_key_exists('account_no', $row)) {
                    $acc = trim((string)($row['account_no'] ?? ''));
                    if ($acc === '') {
                        // biarkan kosong (prospect)
                    } else {
                        // OPTIONAL: kalau mau super ketat numeric only, buka komentar di bawah
                        // if (!ctype_digit($acc)) {
                        //     $v->errors()->add("items.$i.account_no", "Account No harus angka (tanpa spasi/tanda).");
                        // }
                    }
                }

                // 1) tujuan harus valid sesuai jenis (master_tujuan_kegiatan)
                $okTujuan = DB::table('master_tujuan_kegiatan')
                    ->where('is_active', 1)
                    ->where('jenis_code', $jenis)
                    ->where('code', $tujuan)
                    ->exists();

                if (!$okTujuan) {
                    $v->errors()->add("items.$i.tujuan_kegiatan", "Tujuan kegiatan tidak valid untuk jenis '$jenis'.");
                }

                // 2) networking wajib untuk pengembangan_jaringan
                if ($jenis === 'pengembangan_jaringan') {
                    $net = (array)($row['networking'] ?? []);
                    $namaRelasi = trim((string)($net['nama_relasi'] ?? ''));

                    if ($namaRelasi === '') {
                        $v->errors()->add("items.$i.networking.nama_relasi", "Nama relasi wajib diisi untuk Pengembangan Jaringan.");
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Minimal 1 kegiatan harus diisi.',
            'items.*.jenis_kegiatan.exists' => 'Jenis kegiatan tidak terdaftar/aktif.',
            'items.*.kolektibilitas.in' => 'Kolektibilitas hanya boleh L0 atau LT.',
        ];
    }
}
