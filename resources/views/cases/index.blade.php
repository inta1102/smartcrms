@extends('layouts.app')

@section('title', 'Daftar Kredit Bermasalah')

@section('content')
    <div class="w-full max-w-6xl space-y-5">

        {{-- Header + Filter --}}
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-slate-800">
                    Daftar Kredit Bermasalah
                </h1>
                <p class="text-xs text-slate-500 mt-1">
                    Data berasal dari import file Excel CBS. Hanya menampilkan rekening yang terdeteksi bermasalah.
                </p>
            </div>

            <form method="GET" action="{{ route('cases.index') }}" class="flex flex-wrap gap-2 items-end">
                <div class="space-y-1">
                    <label class="text-sm font-semibold text-slate-700">Target Penyelesaian</label>
                    <select name="target"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none">
                        <option value="" {{ request('target') === null || request('target') === '' ? 'selected' : '' }}>
                            Semua
                        </option>
                        <option value="missing" {{ request('target') === 'missing' ? 'selected' : '' }}>
                            Belum Ada Target
                        </option>
                        <option value="has" {{ request('target') === 'has' ? 'selected' : '' }}>
                            Sudah Ada Target
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Status Case</label>
                    <select name="status"
                            class="rounded-lg border-slate-300 text-sm px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue">
                        <option value="open"   {{ $status === 'open' ? 'selected' : '' }}>Masih Berjalan</option>
                        <option value="closed" {{ $status === 'closed' ? 'selected' : '' }}>Selesai</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Cari (Rekening / Nama)</label>
                    <input type="text" name="q" value="{{ $search }}"
                           class="rounded-lg border-slate-300 text-sm px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue"
                           placeholder="misal: 1101... / Nama debitur">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Cabang</label>
                    <input type="text" name="branch" value="{{ $branch }}"
                           class="rounded-lg border-slate-300 text-sm px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue"
                           placeholder="kode/nama cabang">
                </div>

                <button type="submit"
                        class="inline-flex items-center px-3 py-2 rounded-lg text-xs font-semibold
                               bg-msa-blue text-white hover:bg-blue-900">
                    Terapkan
                </button>
            </form>
        </div>

        {{-- Tabel (desktop / tablet) --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden hidden md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full text-xs md:text-sm">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600">Rekening</th>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600">Debitur</th>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600 hidden md:table-cell">Collector</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Kolek</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">DPD</th>
                            <th class="px-3 py-2 text-right font-semibold text-slate-600 hidden sm:table-cell">OS</th>
                            <!-- <th class="px-3 py-2 text-center font-semibold text-slate-600 hidden md:table-cell">Prioritas</th> -->
                            <th class="px-3 py-2 text-center font-semibold text-slate-600 hidden md:table-cell">Status</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($cases as $case)
                            @php
                                $loan = $case->loanAccount;
                            @endphp
                            <tr class="border-b last:border-b-0 border-slate-100 hover:bg-slate-50/70">
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <div class="font-mono text-xs md:text-sm text-slate-800">
                                        {{ $loan?->account_no ?? '-' }}
                                    </div>
                                    <div class="text-[10px] text-slate-400">
                                        CIF: {{ $loan?->cif ?? '-' }}
                                    </div>
                                </td>

                                <td class="px-3 py-2">
                                    <div class="text-xs md:text-sm font-medium text-slate-800">
                                        {{ $loan?->customer_name ?? '-' }}
                                    </div>
                                    <div class="text-[10px] text-slate-400">
                                        Alamat: {{ $loan?->alamat ?? '-' }}
                                    </div>
                                </td>

                                <td class="px-3 py-2 text-xs text-slate-700 hidden md:table-cell">
                                    <div>{{ $loan?->ao_name ?? '-' }}</div>
                                    <div class="text-[10px] text-slate-400">
                                        {{ $loan?->ao_code ?? '' }}
                                    </div>
                                </td>

                                <td class="px-3 py-2 text-center text-xs font-semibold">
                                    {{ $loan?->kolek ?? '-' }}
                                </td>

                                <td class="px-3 py-2 text-center text-xs">
                                    {{ $loan?->dpd ?? '-' }}
                                </td>

                                <td class="px-3 py-2 text-right text-xs hidden sm:table-cell">
                                    {{ $loan ? number_format($loan->outstanding, 0, ',', '.') : '-' }}
                                </td>

                                <!-- <td class="px-3 py-2 text-right text-xs hidden sm:table-cell">
                                    @php
                                        $prio = $case->priority ?? 'normal';
                                        $prioClass = match ($prio) {
                                            'critical' => 'bg-red-100 text-red-700',
                                            'high'     => 'bg-amber-100 text-amber-700',
                                            default    => 'bg-emerald-100 text-emerald-700',
                                        };
                                    @endphp
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $prioClass }}">
                                        {{ strtoupper($prio) }}
                                    </span>                                    
                                </td> -->

                                <td class="px-3 py-2 text-right text-xs hidden sm:table-cell">
                                    @if ($case->closed_at)
                                        <span class="inline-flex px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 text-[10px] font-semibold">
                                            Closed
                                        </span>
                                    @else                                       
                                        <div class="flex gap-2 items-center">
                                            <span class="inline-flex px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-semibold">
                                                Open
                                            </span>
                                            @if(!$case->activeResolutionTarget)
                                                <div class="relative inline-flex group">
                                                    <span class="inline-flex items-center gap-2 px-3 py-1 text-xs font-semibold text-amber-700">
                                                        ⏳ Belum Ada Target
                                                    </span>

                                                    <div class="pointer-events-none absolute left-1/2 top-full z-20 mt-2 w-max -translate-x-1/2 opacity-0 transition
                                                                group-hover:opacity-100">
                                                        <div class="rounded-xl bg-slate-900 px-3 py-2 text-xs text-white shadow-lg">
                                                            Belum ada target penyelesaian aktif (belum disetujui/diaktifkan).
                                                        </div>
                                                        <div class="mx-auto -mt-1 h-2 w-2 rotate-45 bg-slate-900"></div>
                                                    </div>
                                                </div>

                                            @else
                                                @php
                                                    $daysLeft = now()->diffInDays($case->activeResolutionTarget->target_date, false);
                                                @endphp

                                                <span class="badge {{ $daysLeft <= 30 ? 'badge-amber' : 'badge-green' }}">
                                                    🎯 {{ $case->activeResolutionTarget->target_date->format('d M Y') }}
                                                </span>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <a href="{{ route('cases.show', $case) }}"
                                    class="inline-flex items-center text-[11px] px-2 py-1 rounded-lg border border-msa-blue text-msa-blue hover:bg-msa-blue hover:text-white">
                                        Detail
                                    </a>
                                </td>
                                <!-- <td class="px-3 py-2 text-center text-[11px] text-slate-600 whitespace-nowrap">
                                    {{ $case->opened_at?->format('d-m-Y') ?? '-' }}
                                </td> -->
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-3 py-6 text-center text-sm text-slate-500">
                                    Belum ada data kredit bermasalah. Silakan lakukan import data dari Excel CBS.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                
            </div>

            @if ($cases->hasPages())
                <div class="px-4 py-3 border-t border-slate-100">
                    {{ $cases->links() }}
                </div>
            @endif
        </div>

        {{-- Card list (mobile) --}}
        <div class="md:hidden space-y-3">
            @forelse ($cases as $case)
                @php
                    $loan = $case->loanAccount;
                @endphp

                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-4 py-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="text-[11px] text-slate-500">Rekening</div>
                            <div class="font-mono text-sm font-semibold text-slate-800">
                                {{ $loan?->account_no ?? '-' }}
                            </div>
                            <div class="text-[10px] text-slate-400">
                                CIF: {{ $loan?->cif ?? '-' }}
                            </div>
                        </div>

                        <div class="text-right text-[11px]">
                            <div class="text-slate-500">Kolek</div>
                            <div class="font-semibold text-slate-800">
                                {{ $loan?->kolek ?? '-' }}
                            </div>
                            <div class="mt-1 text-slate-500">DPD</div>
                            <div class="font-semibold text-slate-800">
                                {{ $loan?->dpd ?? '-' }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-2">
                        <div class="text-[11px] text-slate-500">Debitur</div>
                        <div class="text-sm font-semibold text-slate-800 leading-tight">
                            {{ $loan?->customer_name ?? '-' }}
                        </div>
                        <div class="mt-1 text-[11px] text-slate-500">
                            Cabang: {{ $loan?->branch_name ?? '-' }}
                        </div>
                    </div>

                    <div class="mt-2 flex items-center justify-between text-[11px]">
                        <div>
                            <div class="text-slate-500">OS</div>
                            <div class="font-semibold text-slate-800">
                                {{ $loan ? 'Rp '.number_format($loan->outstanding, 0, ',', '.') : '-' }}
                            </div>
                        </div>

                        <div class="text-right">
                            @php
                                $prio = $case->priority ?? 'normal';
                                $prioClass = match ($prio) {
                                    'critical' => 'bg-red-100 text-red-700',
                                    'high'     => 'bg-amber-100 text-amber-700',
                                    default    => 'bg-emerald-100 text-emerald-700',
                                };
                            @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $prioClass }}">
                                {{ strtoupper($prio) }}
                            </span>

                            <div class="mt-1">
                                @if ($case->closed_at)
                                    <span class="inline-flex px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 text-[10px] font-semibold">
                                        Closed
                                    </span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-semibold">
                                        Open
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 flex justify-between items-center">
                        <div class="text-[10px] text-slate-400">
                            Opened: {{ $case->opened_at?->format('d-m-Y') ?? '-' }}
                        </div>

                        <a href="{{ route('cases.show', $case) }}"
                        class="inline-flex items-center px-3 py-1.5 rounded-lg border border-msa-blue text-[11px] font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                            Detail
                        </a>
                    </div>
                </div>
            @empty
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-4 py-5 text-center text-sm text-slate-500">
                    Belum ada data kredit bermasalah. Silakan lakukan import data dari Excel CBS.
                </div>
            @endforelse

            @if ($cases->hasPages())
                <div class="mt-2">
                    {{ $cases->links() }}
                </div>
            @endif
        </div>

    </div>
@endsection
