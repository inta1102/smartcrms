@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">
    <div class="flex items-start justify-between gap-4 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">History Achievement KPI Marketing</h1>
            <p class="text-sm text-slate-500">Rekap pencapaian per bulan berdasarkan target yang pernah dibuat.</p>
        </div>

        <a href="{{ route('kpi.marketing.targets.index') }}"
           class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Kembali
        </a>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-4">
        <form method="GET" action="{{ route('kpi.marketing.achievements.index') }}" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="text-sm font-medium">Periode</label>
                <select name="period" class="form-select" onchange="this.form.submit()">
                    <option value="">Semua</option>
                    @foreach($periodOptions as $p)
                        <option value="{{ $p }}" @selected(($period ?? '') === $p)>
                            {{ \Carbon\Carbon::parse($p)->translatedFormat('M Y') }}
                        </option>
                    @endforeach
                </select>
            </div>

            <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Terapkan
            </button>
        </form>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="text-left px-4 py-3">Periode</th>
                    <th class="text-right px-4 py-3">Target OS</th>
                    <th class="text-right px-4 py-3">OS Growth</th>
                    <th class="text-right px-4 py-3">Target NOA</th>
                    <th class="text-right px-4 py-3">NOA Growth</th>
                    <th class="text-left px-4 py-3">Source</th>
                    <th class="text-right px-4 py-3">Score</th>
                    <th class="text-right px-4 py-3">Aksi</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
            @forelse($rows as $t)
                @php
                    $ach = $t->achievement;
                    $sourceNow = $ach?->os_source_now ?? '-';
                    $final = (bool)($ach?->is_final ?? false);

                    $badge = $final
                        ? 'bg-emerald-100 text-emerald-800'
                        : 'bg-slate-100 text-slate-700';

                    $badgeText = $final ? 'FINAL' : 'LIVE';
                @endphp

                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-semibold text-slate-900 whitespace-nowrap">
                        {{ \Carbon\Carbon::parse($t->period)->translatedFormat('M Y') }}
                        <div class="mt-1">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold {{ $badge }}">
                                {{ $badgeText }}
                            </span>
                        </div>
                    </td>

                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        Rp {{ number_format((float)$t->target_os_growth,0,',','.') }}
                    </td>

                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        @if($ach)
                            Rp {{ number_format((float)$ach->os_growth,0,',','.') }}
                        @else
                            <span class="text-slate-400">-</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        {{ number_format((int)$t->target_noa) }}
                    </td>

                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        @if($ach)
                            {{ number_format((int)$ach->noa_growth) }}
                        @else
                            <span class="text-slate-400">-</span>
                        @endif
                    </td>

                    <td class="px-4 py-3">
                        <div class="text-xs text-slate-700">
                            Now: <b>{{ $sourceNow }}</b>
                            @if($ach?->position_date_now)
                                | {{ \Carbon\Carbon::parse($ach->position_date_now)->toDateString() }}
                            @endif
                        </div>
                        <div class="text-xs text-slate-500 mt-1">
                            Prev: <b>{{ $ach?->os_source_prev ?? '-' }}</b>
                            @if($ach?->position_date_prev)
                                | {{ \Carbon\Carbon::parse($ach->position_date_prev)->toDateString() }}
                            @endif
                        </div>
                    </td>

                    <td class="px-4 py-3 text-right font-bold text-slate-900 whitespace-nowrap">
                        {{ $ach ? number_format((float)$ach->score_total, 2) : '0.00' }}
                    </td>

                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('kpi.marketing.targets.achievement', $t) }}"
                           class="inline-flex items-center rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            Detail
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-slate-500">
                        Belum ada histori (buat target dulu).
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $rows->links() }}
    </div>
</div>
@endsection
