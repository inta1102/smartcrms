<div class="rounded-3xl border border-slate-200 bg-white overflow-hidden shadow-sm">
    <div class="p-5 sm:p-6 border-b border-slate-200">
        <div class="text-lg font-bold tracking-tight text-slate-900">Rekap Kondisi Kredit</div>
        <div class="mt-1 text-sm text-slate-500">Posisi periode {{ $periodLabel }}.</div>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50">
                <tr class="text-slate-700">
                    <th class="px-4 py-3 text-left whitespace-nowrap font-semibold">Kategori</th>
                    <th class="px-4 py-3 text-right whitespace-nowrap font-semibold">OS</th>
                    <th class="px-4 py-3 text-right whitespace-nowrap font-semibold">% ke Total OS</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @php
                    $totalOs = max((float) $safeRow->total_os, 1);
                    $rows = [
                        ['L', $safeRow->l_os],
                        ['DPK', $safeRow->dpk_os],
                        ['KL', $safeRow->kl_os],
                        ['D', $safeRow->d_os],
                        ['M', $safeRow->m_os],
                    ];
                @endphp

                @foreach($rows as [$label, $os])
                    <tr class="hover:bg-slate-50/80">
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $label }}</td>
                        <td class="px-4 py-3 text-right text-slate-700">{{ $fmtMoney($os) }}</td>
                        <td class="px-4 py-3 text-right text-slate-700">{{ $fmtPct(((float) $os / $totalOs) * 100) }}</td>
                    </tr>
                @endforeach

                <tr class="bg-slate-50 font-bold">
                    <td class="px-4 py-3 text-slate-900">TOTAL</td>
                    <td class="px-4 py-3 text-right text-slate-900">{{ $fmtMoney($safeRow->total_os) }}</td>
                    <td class="px-4 py-3 text-right text-slate-900">100,00%</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>