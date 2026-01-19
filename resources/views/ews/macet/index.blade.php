@extends('layouts.app') 

@php
    $cardClass = function(int $count) {
        return $count > 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white';
    };

    $titleClass = function(int $count) {
        return $count > 0 ? 'text-rose-700' : 'text-slate-500';
    };

    $valueClass = function(int $count) {
        return $count > 0 ? 'text-rose-700' : 'text-slate-900';
    };

    // tombol active
    $qScope  = strtolower(trim((string) request('scope', $scope ?? 'ag6')));
    $qAgunan = strtoupper(trim((string) request('agunan', 'ALL')));

    $activeAgunan = 'ALL';
    if (in_array($qAgunan, ['6','9'], true)) {
        $activeAgunan = $qAgunan;
    } else {
        if ($qScope === 'ag6') $activeAgunan = '6';
        if ($qScope === 'ag9') $activeAgunan = '9';
        if ($qScope === 'all') $activeAgunan = 'ALL';
    }

    $btnBase = 'inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold border transition';
    $btnOn   = 'bg-slate-900 text-white border-slate-900 shadow-sm';
    $btnOff  = 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50';

    // export ikut state tombol
    $exportQuery = array_merge(request()->query(), [
        'scope'  => $qScope,
        'agunan' => ($activeAgunan === 'ALL' ? 'ALL' : (int)$activeAgunan),
    ]);
@endphp

@section('content')
<div class="w-full">

    <div class="mb-4">
        <h1 class="text-2xl font-bold text-slate-900">EWS • Usia Macet (Kolek 5)</h1>
        <p class="text-sm text-slate-500">
            Warning berbasis usia macet dari <code>tgl_kolek</code> sampai hari ini.
            • Posisi: <span class="font-semibold">{{ $filter['position_date'] }}</span>
            • Scope:
            @if($visibleAoCodes === null)
                <span class="font-semibold">ALL</span>
            @elseif(count($visibleAoCodes) === 1)
                <span class="font-semibold">PERSONAL</span>
            @else
                <span class="font-semibold">SUBSET ({{ count($visibleAoCodes) }} AO)</span>
            @endif
        </p>
    </div>

    {{-- KPI --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">Total Macet (Kolek 5 • Semua Usia)</div>
            <div class="mt-1 text-3xl font-extrabold text-slate-900">{{ number_format($meta['total_all_rek']) }}</div>
            <div class="mt-1 text-sm text-slate-600">
                OS: <span class="font-semibold">Rp {{ number_format($meta['total_all_os'],0,',','.') }}</span>
            </div>

            <div class="mt-2 text-xs text-slate-500">
                Warning window (Ag6+Ag9): <span class="font-semibold">{{ number_format($meta['total_warn_rek']) }}</span>
            </div>
        </div>

        <div class="rounded-2xl border p-5 shadow-sm {{ $cardClass($meta['ag6']['rek']) }}">
            <div class="text-sm {{ $titleClass($meta['ag6']['rek']) }}">
                Warning Agunan 6 (20–24 bln)
                @if($meta['ag6']['rek'] > 0)
                    <span class="ml-2 inline-flex items-center rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-semibold text-white">⚠</span>
                @endif
            </div>

            <div class="mt-1 text-3xl font-extrabold {{ $valueClass($meta['ag6']['rek']) }}">
                {{ number_format($meta['ag6']['rek']) }}
            </div>

            <div class="mt-1 text-sm {{ $meta['ag6']['rek'] > 0 ? 'text-rose-600' : 'text-slate-600' }}">
                OS: <span class="font-semibold">Rp {{ number_format($meta['ag6']['os'],0,',','.') }}</span>
            </div>

            @if($meta['ag6']['rek'] > 0)
                <div class="mt-3">
                    <a class="text-sm font-semibold text-rose-700 hover:underline"
                       href="{{ request()->fullUrlWithQuery(['scope'=>'ag6','agunan'=>6]) }}">
                        Lihat Detail →
                    </a>
                </div>
            @endif
        </div>

        <div class="rounded-2xl border p-5 shadow-sm {{ $cardClass($meta['ag9']['rek']) }}">
            <div class="text-sm {{ $titleClass($meta['ag9']['rek']) }}">
                Warning Agunan 9 (9–12 bln)
                @if($meta['ag9']['rek'] > 0)
                    <span class="ml-2 inline-flex items-center rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-semibold text-white">⚠</span>
                @endif
            </div>

            <div class="mt-1 text-3xl font-extrabold {{ $valueClass($meta['ag9']['rek']) }}">
                {{ number_format($meta['ag9']['rek']) }}
            </div>

            <div class="mt-1 text-sm {{ $meta['ag9']['rek'] > 0 ? 'text-rose-600' : 'text-slate-600' }}">
                OS: <span class="font-semibold">Rp {{ number_format($meta['ag9']['os'],0,',','.') }}</span>
            </div>

            @if($meta['ag9']['rek'] > 0)
                <div class="mt-3">
                    <a class="text-sm font-semibold text-rose-700 hover:underline"
                       href="{{ request()->fullUrlWithQuery(['scope'=>'ag9','agunan'=>9]) }}">
                        Lihat Detail →
                    </a>
                </div>
            @endif
        </div>
    </div>

    {{-- Note --}}
    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="text-sm font-semibold text-slate-800 mb-1">📌 Definisi</div>
        <ul class="list-disc pl-5 text-sm text-slate-600 space-y-1">
            <li>Kolek = 5 (macet).</li>
            <li>Usia macet = selisih bulan dari <code>tgl_kolek</code> sampai hari ini (mengacu ke posisi tanggal).</li>
            <li>Warning: agunan 6 (20–24 bln), agunan 9 (9–12 bln).</li>
        </ul>
    </div>

    {{-- Table --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <div class="text-sm font-bold text-slate-900">Detail</div>

                <div class="text-xs text-slate-500 mt-0.5">
                    Filter aktif:
                    <span class="font-semibold">
                        {{ $activeAgunan === 'ALL' ? 'ALL' : 'Agunan '.$activeAgunan }}
                    </span>
                </div>

                <div class="text-xs text-slate-500">
                    Scope: <span class="font-semibold">{{ strtoupper($qScope) }}</span>
                    • Menampilkan max 300 baris terbesar (usia & OS).
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-start md:justify-end gap-2">
                <a href="{{ request()->fullUrlWithQuery(['scope'=>'all','agunan'=>'ALL']) }}"
                   class="{{ $btnBase }} {{ $activeAgunan === 'ALL' ? $btnOn : $btnOff }}"
                   aria-current="{{ $activeAgunan === 'ALL' ? 'page' : 'false' }}">
                    ALL
                </a>

                <a href="{{ request()->fullUrlWithQuery(['scope'=>'ag6','agunan'=>6]) }}"
                   class="{{ $btnBase }} {{ $activeAgunan === '6' ? $btnOn : $btnOff }}"
                   aria-current="{{ $activeAgunan === '6' ? 'page' : 'false' }}">
                    Agunan 6
                </a>

                <a href="{{ request()->fullUrlWithQuery(['scope'=>'ag9','agunan'=>9]) }}"
                   class="{{ $btnBase }} {{ $activeAgunan === '9' ? $btnOn : $btnOff }}"
                   aria-current="{{ $activeAgunan === '9' ? 'page' : 'false' }}">
                    Agunan 9
                </a>

                <a href="{{ route('ews.detail.export', $exportQuery) }}"
                   class="ml-0 md:ml-2 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50">
                    ⬇️ Export Excel
                </a>
            </div>
        </div>

        <div class="w-full overflow-x-auto">
            <table class="min-w-[1100px] w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="text-left px-4 py-3">No Rek</th>
                        <th class="text-left px-4 py-3">Nama</th>
                        <th class="text-left px-4 py-3">AO</th>
                        <th class="text-left px-4 py-3">Agunan</th>
                        <th class="text-left px-4 py-3">Tgl Kolek</th>
                        <th class="text-right px-4 py-3">Usia (bln)</th>
                        <th class="text-right px-4 py-3">OS</th>
                        <th class="text-right px-4 py-3">PPKA</th>
                        <th class="text-right px-4 py-3">Pengurang</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse($rows as $r)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-900">{{ $r->account_no }}</td>
                        <td class="px-4 py-3">{{ $r->customer_name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $r->ao_code ?: $r->collector_code }}</td>
                        <td class="px-4 py-3">
                            <div class="font-semibold">{{ $r->jenis_agunan }}</div>
                            <div class="text-xs text-slate-500">{{ $r->keterangan_sandi }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $r->tgl_kolek }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ (int) $r->usia_macet_bulan }}</td>
                        <td class="px-4 py-3 text-right font-semibold">Rp {{ number_format($r->outstanding,0,',','.') }}</td>
                        <td class="px-4 py-3 text-right font-semibold">Rp {{ number_format($r->cadangan_ppap,0,',','.') }}</td>
                        <td class="px-4 py-3 text-right font-semibold">Rp {{ number_format($r->nilai_agunan_yg_diperhitungkan,0,',','.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-slate-500">Tidak ada data.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

    </div>

</div>
@endsection
