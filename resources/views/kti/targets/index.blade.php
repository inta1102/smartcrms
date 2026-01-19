@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    {{-- HEADER --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
            <div class="min-w-0">
                <div class="text-xs font-bold tracking-wider text-slate-400 uppercase">KTI</div>
                <h1 class="mt-2 text-2xl sm:text-3xl font-extrabold text-slate-900 leading-tight">
                    Input Target Penyelesaian (Final)
                </h1>
                <p class="mt-2 text-sm text-slate-600">
                    Halaman khusus KTI untuk input target final (langsung ACTIVE) sesuai keputusan yang sudah disepakati.
                </p>
            </div>

            <form method="GET" class="w-full md:w-auto">
                <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                    <input
                        name="q"
                        value="{{ $q }}"
                        placeholder="Cari debitur / CIF / No Rek / PIC..."
                        class="w-full sm:w-80 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-200"
                    />
                    <button class="w-full sm:w-auto rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Cari
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- FLASH --}}
    @if(session('success'))
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    {{-- MOBILE: CARD LIST (rapi) --}}
    <div class="mt-4 space-y-3 md:hidden">
        @forelse($rows as $r)
            @php
                $chip = match($r->issue) {
                    'NO_TARGET' => 'bg-amber-50 text-amber-800 border-amber-200',
                    'OVERDUE'   => 'bg-rose-50 text-rose-700 border-rose-200',
                    default     => 'bg-slate-100 text-slate-700 border-slate-200',
                };

                $tDate = $r->target_date ? \Carbon\Carbon::parse($r->target_date)->format('d/m/Y') : '-';
            @endphp

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-4">
                {{-- Header: Kasus + tombol --}}
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-[11px] font-bold tracking-wider text-slate-400 uppercase">Kasus</div>

                        <div class="mt-1 text-lg font-extrabold text-slate-900 leading-snug break-words">
                            {{ $r->debtor_name ?? '-' }}
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-bold {{ $chip }}">
                                {{ $r->issue }}
                            </span>

                            @if($r->issue === 'OVERDUE' && !is_null($r->overdue_days))
                                <span class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-bold text-rose-700">
                                    Telat {{ (int)$r->overdue_days }} hari
                                </span>
                            @endif
                        </div>
                    </div>

                    {{-- Tombol kanan (tetap kecil) --}}
                    <a href="{{ route('kti.targets.show', $r->id) }}"
                    class="shrink-0 inline-flex items-center rounded-xl bg-indigo-600 px-3 py-2 text-xs font-extrabold text-white hover:bg-indigo-700">
                        Input →
                    </a>
                </div>

                {{-- Body: info-grid --}}
                <div class="mt-4 grid grid-cols-2 gap-2 text-sm">
                    {{-- CIF --}}
                    <div class="rounded-xl border border-slate-200 p-3">
                        <div class="text-[11px] text-slate-500">CIF</div>
                        <div class="mt-0.5 font-mono text-[12px] font-bold text-slate-900 leading-snug break-all">
                            {{ $r->cif ?? '-' }}
                        </div>
                    </div>

                    {{-- No Rek --}}
                    <div class="rounded-xl border border-slate-200 p-3">
                        <div class="text-[11px] text-slate-500">No Rek</div>
                        <div class="mt-0.5 font-mono text-[12px] font-bold text-slate-900 leading-snug break-all">
                            {{ $r->account_no ?? '-' }}
                        </div>
                    </div>

                    {{-- PIC --}}
                    <div class="rounded-xl border border-slate-200 p-3 col-span-1">
                        <div class="text-[11px] text-slate-500">PIC</div>
                        <div class="mt-0.5 font-bold text-slate-900 truncate">
                            {{ $r->pic_name ?? '-' }}
                        </div>
                    </div>

                    {{-- Target --}}
                    <div class="rounded-xl border border-slate-200 p-3 col-span-1">
                        <div class="text-[11px] text-slate-500">Target Aktif</div>
                        <div class="mt-0.5 font-bold text-slate-900">
                            {{ $tDate }}
                        </div>
                    </div>
                </div>

                {{-- Footer --}}
                <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                    <div>
                        DPD: <span class="font-semibold text-slate-700">{{ $r->dpd ?? '-' }}</span>
                        <span class="text-slate-300">•</span>
                        Kolek: <span class="font-semibold text-slate-700">{{ $r->kolek ?? '-' }}</span>
                    </div>

                    {{-- tombol alternatif full width (kalau kamu mau, uncomment & hapus tombol kanan di atas) --}}
                    {{-- 
                    <a href="{{ route('kti.targets.show', $r->id) }}"
                    class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50">
                        Input
                    </a> 
                    --}}
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-600">
                Tidak ada data.
            </div>
        @endforelse

        <div class="mt-2">
            {{ $rows->links() }}
        </div>
    </div>

    {{-- DESKTOP: TABLE --}}
    <div class="mt-4 hidden md:block rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <div class="text-sm font-bold text-slate-900">Daftar Kasus</div>
                <div class="text-xs text-slate-500">Klik “Input” untuk membuat/mengganti target final.</div>
            </div>
            <div class="text-xs text-slate-500">
                {{ $rows->total() }} data
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-slate-50 text-left text-xs font-bold tracking-wider text-slate-500 uppercase">
                    <tr>
                        <th class="px-5 py-3">Debitur</th>
                        <th class="px-5 py-3">CIF</th>
                        <th class="px-5 py-3">No Rek</th>
                        <th class="px-5 py-3">PIC</th>
                        <th class="px-5 py-3">Target Aktif</th>
                        <th class="px-5 py-3">Issue</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($rows as $r)
                        @php
                            $chip = match($r->issue) {
                                'NO_TARGET' => 'bg-amber-50 text-amber-800 border-amber-200',
                                'OVERDUE'   => 'bg-rose-50 text-rose-700 border-rose-200',
                                default     => 'bg-slate-100 text-slate-700 border-slate-200',
                            };
                            $tDate = $r->target_date ? \Carbon\Carbon::parse($r->target_date)->format('d/m/Y') : '-';
                        @endphp
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-4">
                                <div class="font-extrabold text-slate-900">
                                    {{ $r->debtor_name }}
                                </div>
                                <div class="text-xs text-slate-500 mt-0.5">
                                    DPD {{ $r->dpd ?? '-' }} • Kolek {{ $r->kolek ?? '-' }}
                                </div>
                            </td>
                            <td class="px-5 py-4 text-sm text-slate-700">{{ $r->cif }}</td>
                            <td class="px-5 py-4 text-sm text-slate-700">{{ $r->account_no }}</td>
                            <td class="px-5 py-4 text-sm text-slate-700">{{ $r->pic_name }}</td>
                            <td class="px-5 py-4 text-sm text-slate-700">{{ $tDate }}</td>
                            <td class="px-5 py-4">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-bold {{ $chip }}">
                                    {{ $r->issue }}
                                </span>
                                @if($r->issue === 'OVERDUE' && !is_null($r->overdue_days))
                                    <div class="text-xs text-rose-600 mt-1 font-semibold">
                                        Telat {{ (int)$r->overdue_days }} hari
                                    </div>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('kti.targets.show', $r->id) }}"
                                   class="inline-flex items-center rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white hover:bg-indigo-700">
                                    Input Target
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-8 text-center text-sm text-slate-600">
                                Tidak ada data.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-4">
            {{ $rows->links() }}
        </div>
    </div>

</div>
@endsection
