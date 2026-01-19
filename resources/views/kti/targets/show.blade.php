@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    {{-- HEADER --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
            <div class="min-w-0">
                <div class="text-xs font-bold tracking-wider text-slate-400 uppercase">KTI</div>
                <h1 class="mt-2 text-2xl sm:text-3xl font-extrabold text-slate-900 leading-tight">
                    Input Target Final (Langsung Aktif)
                </h1>
                <p class="mt-2 text-sm text-slate-600">
                    Gunakan halaman ini untuk input target final sesuai keputusan yang sudah disepakati (tanpa approval TL/Kasi).
                </p>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('kti.targets.index') }}"
                    class="w-full md:w-auto text-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    ← Kembali
                </a>
            </div>
        </div>
    </div>

    {{-- FLASH --}}
    @if(session('success'))
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <div class="font-bold mb-1">Validasi gagal:</div>
            <ul class="list-disc ml-5 space-y-1">
                @foreach($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- CASE SUMMARY --}}
    <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <div class="text-xs font-bold tracking-wider text-slate-400 uppercase">Kasus</div>
            <div class="mt-2 text-lg sm:text-xl font-extrabold text-slate-900 leading-snug break-words">
                {{ $caseRow->debtor_name ?? '-' }}
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 text-sm">

                {{-- CIF --}}
                <div class="rounded-xl border border-slate-200 p-3 min-w-0">
                    <div class="text-[11px] text-slate-500">CIF</div>
                    <div class="mt-0.5 font-mono text-[12px] font-bold text-slate-900 leading-snug break-all">
                        {{ $caseRow->cif ?? '-' }}
                    </div>
                </div>

                {{-- No Rek (ini yang paling penting) --}}
                <div class="rounded-xl border border-slate-200 p-3 min-w-0">
                    <div class="text-[11px] text-slate-500">No Rek</div>
                    <div class="mt-0.5 font-mono text-[12px] font-bold text-slate-900 leading-snug break-all">
                        {{ $caseRow->account_no ?? '-' }}
                    </div>
                </div>

                {{-- PIC --}}
                <div class="rounded-xl border border-slate-200 p-3 min-w-0">
                    <div class="text-[11px] text-slate-500">PIC</div>
                    <div class="mt-0.5 font-bold text-slate-900 truncate">
                        {{ $caseRow->pic_name ?? '-' }}
                    </div>
                </div>

                {{-- DPD --}}
                <div class="rounded-xl border border-slate-200 p-3 min-w-0">
                    <div class="text-[11px] text-slate-500">DPD</div>
                    <div class="mt-0.5 text-base font-extrabold text-slate-900">
                        {{ $caseRow->dpd ?? '-' }}
                    </div>
                </div>

                {{-- Kolek --}}
                <div class="rounded-xl border border-slate-200 p-3 min-w-0">
                    <div class="text-[11px] text-slate-500">Kolek</div>
                    <div class="mt-0.5 text-base font-extrabold text-slate-900">
                        {{ $caseRow->kolek ?? '-' }}
                    </div>
                </div>

                {{-- OS --}}
                <div class="rounded-xl border border-slate-200 p-3 min-w-0">
                    <div class="text-[11px] text-slate-500">OS</div>
                    <div class="mt-0.5 font-mono text-[12px] font-extrabold text-slate-900 leading-snug break-all">
                        {{ isset($caseRow->outstanding) ? number_format((float)$caseRow->outstanding) : '-' }}
                    </div>
                </div>

            </div>

        </div>

        {{-- ACTIVE TARGET SUMMARY --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <div class="flex items-center justify-between">
                <div class="text-xs font-bold tracking-wider text-slate-400 uppercase">Target Aktif Saat Ini</div>
                @if($activeTarget)
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700">
                        ACTIVE
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                        NONE
                    </span>
                @endif
            </div>

            @if($activeTarget)
                @php
                    $tDate = $activeTarget->target_date ? \Carbon\Carbon::parse($activeTarget->target_date)->format('d/m/Y') : '-';
                    $tAct  = $activeTarget->activated_at ?? $activeTarget->created_at ?? null;
                @endphp
                <div class="mt-3">
                    <div class="text-xs text-slate-500">Target date</div>
                    <div class="text-2xl font-extrabold text-slate-900">{{ $tDate }}</div>
                </div>
                <div class="mt-3 text-sm space-y-2">
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-500">Status</span>
                        <span class="font-bold text-slate-900">{{ strtoupper((string)($activeTarget->status ?? '-')) }}</span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-500">Strategy</span>
                        <span class="font-semibold text-slate-900">{{ $activeTarget->strategy ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-500">Outcome</span>
                        <span class="font-semibold text-slate-900">{{ $activeTarget->target_outcome ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-500">Aktif sejak</span>
                        <span class="font-semibold text-slate-900">
                            {{ $tAct ? \Carbon\Carbon::parse($tAct)->format('d/m/Y H:i') : '-' }}
                        </span>
                    </div>
                </div>
            @else
                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                    Belum ada target aktif pada kasus ini.
                </div>
            @endif
        </div>
    </div>

    {{-- FORM + HISTORY --}}
    <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- FORM --}}
        <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-bold text-slate-900">Form Input Target Final</div>
                    <div class="text-xs text-slate-500">Submit akan mengganti target aktif lama (jika ada) dan mengaktifkan target baru.</div>
                </div>
            </div>

            <form method="POST" action="{{ route('kti.targets.store', $case->id) }}" class="mt-4 space-y-4">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-bold text-slate-600">Target Date</label>
                        <input type="date" name="target_date"
                               value="{{ old('target_date', $activeTarget->target_date ?? '') }}"
                               class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-200">
                    </div>

                    <div>
                        <label class="text-xs font-bold text-slate-600">Target Outcome</label>
                        <select name="target_outcome"
                                class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-200">
                            @php $val = old('target_outcome', $activeTarget->target_outcome ?? 'lancar'); @endphp
                            <option value="lancar" @selected($val==='lancar')>lancar</option>
                            <option value="lunas" @selected($val==='lunas')>lunas</option>
                        </select>
                    </div>
                </div>

                {{-- Strategy --}}
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">
                        Strategy
                    </label>

                    <select name="strategy"
                        class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm
                            focus:border-indigo-500 focus:ring-indigo-500">

                        <option value="">-- Pilih Strategy --</option>

                        <option value="lelang"        @selected(old('strategy')==='lelang')>
                            Proses Lelang
                        </option>
                        <option value="ayda"          @selected(old('strategy')==='ayda')>
                            Proses AYDA
                        </option>
                        <option value="intensif"      @selected(old('strategy')==='intensif')>
                            Proses Penagihan Intensif
                        </option>
                        <option value="rs"            @selected(old('strategy')==='rs')>
                            Proses Restrukturisasi (RS)
                        </option>
                        <option value="jual_jaminan"  @selected(old('strategy')==='jual_jaminan')>
                            Proses Jual Jaminan
                        </option>
                    </select>

                    @error('strategy')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>


                <div>
                    <label class="text-xs font-bold text-slate-600">Catatan/Reason (opsional)</label>
                    <textarea name="reason" rows="3" maxlength="500"
                              placeholder="Dasar keputusan / catatan rapat / nomor memo..."
                              class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ old('reason') }}</textarea>
                    <div class="text-[11px] text-slate-500 mt-1">
                        Tips: isi ringkas agar audit trail jelas bahwa ini input final oleh KTI (awal implementasi).
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-end">
                    <a href="{{ route('kti.targets.index') }}"
                       class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 text-center">
                        Batal
                    </a>
                    <button
                        class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-extrabold text-white hover:bg-indigo-700">
                        Simpan & Aktifkan
                    </button>
                </div>
            </form>
        </div>

        {{-- HISTORY --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <div class="text-sm font-bold text-slate-900">Riwayat Target (10 terakhir)</div>
                <div class="text-xs text-slate-500">Untuk cek perubahan target.</div>
            </div>

            <div class="divide-y divide-slate-100">
                @forelse($history as $t)
                    @php
                        $isActive = (int)($t->is_active ?? 0) === 1;
                        $tDate = $t->target_date ? \Carbon\Carbon::parse($t->target_date)->format('d/m/Y') : '-';
                        $chip = $isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700';
                    @endphp
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-extrabold text-slate-900">{{ $tDate }}</div>
                                <div class="text-xs text-slate-500 mt-0.5">
                                    Status: <span class="font-semibold">{{ strtoupper((string)($t->status ?? '-')) }}</span>
                                </div>
                                <div class="text-xs text-slate-500 mt-0.5 truncate">
                                    {{ $t->strategy ?? '-' }} • {{ $t->target_outcome ?? '-' }}
                                </div>
                            </div>
                            <div class="shrink-0">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold {{ $chip }}">
                                    {{ $isActive ? 'ACTIVE' : 'HISTORY' }}
                                </span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-5 text-sm text-slate-600">
                        Belum ada riwayat target.
                    </div>
                @endforelse
            </div>
        </div>

    </div>

</div>
@endsection
