@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-6">
    <div class="mb-4">
        <a href="{{ route('shm.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">
            ← Kembali
        </a>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
        <h1 class="text-xl font-bold text-slate-900">+ Buat Pengajuan Cek SHM</h1>
        <p class="mt-1 text-sm text-slate-500">Lengkapi data debitur dan upload dokumen yang diperlukan.</p>

        @if(session('status'))
            <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <div class="font-semibold">Ada input yang perlu diperbaiki:</div>
                <ul class="mt-2 list-disc pl-5">
                    @foreach($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="mt-5 space-y-5" method="POST" action="{{ route('shm.store') }}" enctype="multipart/form-data">
            @csrf

            {{-- Debitur --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="text-xs font-semibold text-slate-600">Nama Debitur <span class="text-rose-600">*</span></label>
                    <input type="text" name="debtor_name" value="{{ old('debtor_name') }}"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                           placeholder="Nama sesuai KTP">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-600">No HP Debitur</label>
                    <input type="text" name="debtor_phone" value="{{ old('debtor_phone') }}"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                           placeholder="08xxxxxxxxxx">
                </div>

                <div class="sm:col-span-2">
                    <label class="text-xs font-semibold text-slate-600">Alamat Agunan</label>
                    <input type="text" name="collateral_address" value="{{ old('collateral_address') }}"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                           placeholder="Alamat agunan (opsional)">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-600">No Sertifikat</label>
                    <input type="text" name="certificate_no" value="{{ old('certificate_no') }}"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                           placeholder="Nomor SHM (opsional)">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-600">Nama Notaris</label>
                    <input type="text" name="notary_name" value="{{ old('notary_name') }}"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                           placeholder="Notaris (opsional)">
                </div>

                <div class="sm:col-span-2">
                    <label class="text-xs font-semibold text-slate-600">Catatan</label>
                    <textarea name="notes" rows="4"
                              class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                              placeholder="Keterangan tambahan...">{{ old('notes') }}</textarea>
                </div>
            </div>

            {{-- Upload --}}
            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                <div class="mb-2 text-sm font-bold text-slate-900">📎 Upload Dokumen</div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-slate-600">File KTP <span class="text-rose-600">*</span></label>
                        <input type="file" name="ktp_file" accept=".pdf,.jpg,.jpeg,.png"
                               class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        <div class="mt-1 text-xs text-slate-500">pdf/jpg/png, maks 5MB</div>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-600">File SHM <span class="text-rose-600">*</span></label>
                        <input type="file" name="shm_file" accept=".pdf,.jpg,.jpeg,.png"
                               class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        <div class="mt-1 text-xs text-slate-500">pdf/jpg/png, maks 5MB</div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('shm.index') }}"
                   class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Batal
                </a>

                <button type="submit"
                        class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                    Simpan Pengajuan
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
