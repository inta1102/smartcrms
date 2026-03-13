@php
    $creditCondition = $creditCondition ?? [
        'rows' => [],
        'totals' => [
            'total_os' => 0, 'total_noa' => 0,
            'restruktur_os' => 0, 'restruktur_noa' => 0,
            'realisasi_os' => 0, 'realisasi_noa' => 0,
            'dpd6_os' => 0, 'dpd6_noa' => 0,
            'dpd12_os' => 0, 'dpd12_noa' => 0,
        ],
        'npl' => [
            'amount' => 0,
            'noa' => 0,
            'restruktur_os' => 0,
            'restruktur_noa' => 0,
            'percent' => 0,
        ],
        'kkr' => [
            'percent' => 0,
        ],
    ];

    $fmtNum = fn($v) => number_format((float) $v, 0, ',', '.');
    $fmtPct = fn($v, $d = 2) => number_format((float) $v, $d, ',', '.') . '%';
@endphp

<div class="rounded-[28px] border border-slate-200 bg-white overflow-hidden shadow-sm">
    <div class="px-6 sm:px-8 py-6 sm:py-7 border-b border-slate-200">
        <h3 class="text-[20px] sm:text-[22px] font-extrabold tracking-tight text-slate-900">
            Rekap Kondisi Kredit
        </h3>
        <p class="mt-2 text-sm sm:text-[15px] text-slate-500">
            Posisi periode {{ $periodLabel }}
        </p>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-[1120px] w-full text-sm text-slate-800">
            <thead>
                <tr class="bg-amber-300/90 text-slate-900">
                    <th rowspan="2" class="px-4 py-4 text-left font-bold border-b border-r border-amber-400">
                        Kol
                    </th>

                    <th colspan="2" class="px-3 py-3 text-center font-bold border-b border-r border-amber-400">
                        Total
                    </th>
                    <th colspan="2" class="px-3 py-3 text-center font-bold border-b border-r border-amber-400">
                        Restrukturisasi
                    </th>
                    <th colspan="2" class="px-3 py-3 text-center font-bold border-b border-r border-amber-400">
                        Realisasi Tahun {{ now()->year }}
                    </th>
                    <th colspan="2" class="px-3 py-3 text-center font-bold border-b border-r border-amber-400">
                        Day Past Due 6 Bulan
                    </th>
                    <th colspan="2" class="px-3 py-3 text-center font-bold border-b border-amber-400">
                        Day Past Due 12 Bulan
                    </th>
                </tr>

                <tr class="bg-amber-100 text-slate-800">
                    <th class="px-4 py-3 text-right font-semibold border-b border-r border-slate-200">O/S</th>
                    <th class="px-4 py-3 text-right font-semibold border-b border-r border-slate-200">NOA</th>

                    <th class="px-4 py-3 text-right font-semibold border-b border-r border-slate-200">O/S</th>
                    <th class="px-4 py-3 text-right font-semibold border-b border-r border-slate-200">NOA</th>

                    <th class="px-4 py-3 text-right font-semibold border-b border-r border-slate-200">O/S</th>
                    <th class="px-4 py-3 text-right font-semibold border-b border-r border-slate-200">NOA</th>

                    <th class="px-4 py-3 text-right font-semibold border-b border-r border-slate-200">O/S</th>
                    <th class="px-4 py-3 text-right font-semibold border-b border-r border-slate-200">NOA</th>

                    <th class="px-4 py-3 text-right font-semibold border-b border-r border-slate-200">O/S</th>
                    <th class="px-4 py-3 text-right font-semibold border-b border-slate-200">NOA</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
                @forelse($creditCondition['rows'] as $kol => $r)
                    <tr class="hover:bg-slate-50/80 transition-colors">
                        <td class="px-4 py-3.5 font-bold text-slate-900 border-r border-slate-100">
                            {{ $kol }}
                        </td>

                        <td class="px-4 py-3.5 text-right tabular-nums">{{ $fmtNum($r['total_os']) }}</td>
                        <td class="px-4 py-3.5 text-right tabular-nums border-r border-slate-100">{{ $fmtNum($r['total_noa']) }}</td>

                        <td class="px-4 py-3.5 text-right tabular-nums">{{ $fmtNum($r['restruktur_os']) }}</td>
                        <td class="px-4 py-3.5 text-right tabular-nums border-r border-slate-100">{{ $fmtNum($r['restruktur_noa']) }}</td>

                        <td class="px-4 py-3.5 text-right tabular-nums">{{ $fmtNum($r['realisasi_os']) }}</td>
                        <td class="px-4 py-3.5 text-right tabular-nums border-r border-slate-100">{{ $fmtNum($r['realisasi_noa']) }}</td>

                        <td class="px-4 py-3.5 text-right tabular-nums">{{ $fmtNum($r['dpd6_os']) }}</td>
                        <td class="px-4 py-3.5 text-right tabular-nums border-r border-slate-100">{{ $fmtNum($r['dpd6_noa']) }}</td>

                        <td class="px-4 py-3.5 text-right tabular-nums">{{ $fmtNum($r['dpd12_os']) }}</td>
                        <td class="px-4 py-3.5 text-right tabular-nums">{{ $fmtNum($r['dpd12_noa']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="px-4 py-8 text-center text-slate-500">
                            Belum ada data rekap kondisi kredit untuk periode ini.
                        </td>
                    </tr>
                @endforelse

                {{-- TOTAL --}}
                <tr class="bg-slate-100 font-extrabold text-slate-900">
                    <td class="px-4 py-4 border-r border-slate-200">TOTAL</td>

                    <td class="px-4 py-4 text-right tabular-nums">{{ $fmtNum($creditCondition['totals']['total_os']) }}</td>
                    <td class="px-4 py-4 text-right tabular-nums border-r border-slate-200">{{ $fmtNum($creditCondition['totals']['total_noa']) }}</td>

                    <td class="px-4 py-4 text-right tabular-nums">{{ $fmtNum($creditCondition['totals']['restruktur_os']) }}</td>
                    <td class="px-4 py-4 text-right tabular-nums border-r border-slate-200">{{ $fmtNum($creditCondition['totals']['restruktur_noa']) }}</td>

                    <td class="px-4 py-4 text-right tabular-nums">{{ $fmtNum($creditCondition['totals']['realisasi_os']) }}</td>
                    <td class="px-4 py-4 text-right tabular-nums border-r border-slate-200">{{ $fmtNum($creditCondition['totals']['realisasi_noa']) }}</td>

                    <td class="px-4 py-4 text-right tabular-nums">{{ $fmtNum($creditCondition['totals']['dpd6_os']) }}</td>
                    <td class="px-4 py-4 text-right tabular-nums border-r border-slate-200">{{ $fmtNum($creditCondition['totals']['dpd6_noa']) }}</td>

                    <td class="px-4 py-4 text-right tabular-nums">{{ $fmtNum($creditCondition['totals']['dpd12_os']) }}</td>
                    <td class="px-4 py-4 text-right tabular-nums">{{ $fmtNum($creditCondition['totals']['dpd12_noa']) }}</td>
                </tr>

                {{-- NPL (Rp) --}}
                <tr class="bg-rose-50/70 font-semibold text-slate-900">
                    <td class="px-4 py-3.5 border-r border-rose-100">NPL (Rp)</td>

                    <td class="px-4 py-3.5 text-right tabular-nums">{{ $fmtNum($creditCondition['npl']['amount']) }}</td>
                    <td class="px-4 py-3.5 text-right tabular-nums border-r border-rose-100">{{ $fmtNum($creditCondition['npl']['noa']) }}</td>

                    <td class="px-4 py-3.5 text-right tabular-nums">{{ $fmtNum($creditCondition['npl']['restruktur_os']) }}</td>
                    <td class="px-4 py-3.5 text-right tabular-nums border-r border-rose-100">{{ $fmtNum($creditCondition['npl']['restruktur_noa']) }}</td>

                    <td colspan="6" class="px-4 py-3.5"></td>
                </tr>

                {{-- NPL (%) --}}
                <tr class="bg-rose-50/40 font-semibold text-slate-900">
                    <td class="px-4 py-3.5 border-r border-rose-100">NPL (%)</td>
                    <td class="px-4 py-3.5 text-right tabular-nums">{{ $fmtPct($creditCondition['npl']['percent']) }}</td>
                    <td colspan="9" class="px-4 py-3.5"></td>
                </tr>

                {{-- KKR (%) --}}
                <tr class="bg-amber-50/70 font-semibold text-slate-900">
                    <td class="px-4 py-3.5 border-r border-amber-100">KKR (%)</td>
                    <td class="px-4 py-3.5 text-right tabular-nums">{{ $fmtPct($creditCondition['kkr']['percent']) }}</td>
                    <td colspan="9" class="px-4 py-3.5"></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>