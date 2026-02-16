<table class="min-w-full text-sm">
    <thead class="bg-slate-50 text-slate-600">
        @if($role==='RO')
            <tr class="border-b">
                <th class="text-left px-4 py-3">Rank</th>
                <th class="text-left px-4 py-3">RO</th>
                <th class="text-right px-4 py-3">TopUp</th>
                <th class="text-right px-4 py-3">NOA</th>
                <th class="text-right px-4 py-3">Repay %</th>
                <th class="text-right px-4 py-3">DPK %</th>
            </tr>
        @else
            {{-- ✅ Non-RO Growth = Realisasi Disbursement (bukan Prev/Now) --}}
            <tr class="border-b">
                <th class="text-left px-4 py-3">Rank</th>
                <th class="text-left px-4 py-3">{{ $role }}</th>
                <th class="text-right px-4 py-3">OS Disb</th>
                <th class="text-right px-4 py-3">NOA Disb</th>
                <th class="text-right px-4 py-3">RR</th>
            </tr>
        @endif
    </thead>

    <tbody>
        @forelse($rows as $r)
            @php [$uid,$name,$aoCode] = $resolveRow($r); @endphp

            @if($role==='RO')
                <tr class="border-b hover:bg-slate-50">
                    <td class="px-4 py-3 font-bold text-slate-900">{{ $r->rank }}</td>
                    <td class="px-4 py-3">
                        <span class="font-semibold text-slate-900">{{ $name }}</span>
                        <div class="text-xs text-gray-500">RO Code: {{ $aoCode }}</div>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold">
                        Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}
                    </td>
                    <td class="px-4 py-3 text-right">{{ number_format((int)($r->noa_growth ?? 0)) }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format((float)($r->repayment_pct ?? 0),2) }}%</td>
                    <td class="px-4 py-3 text-right">{{ number_format((float)($r->dpk_pct ?? 0),4) }}%</td>
                </tr>
            @else
                <tr class="border-b hover:bg-slate-50">
                    <td class="px-4 py-3 font-bold text-slate-900">{{ $r->rank }}</td>

                    <td class="px-4 py-3">
                        <span class="font-semibold text-slate-900">{{ $name }}</span>
                        <div class="text-xs text-gray-500">{{ $role }} Code: {{ $aoCode }}</div>
                    </td>

                    {{-- ✅ Non-RO Growth = os_growth/noa_growth (disbursement) --}}
                    <td class="px-4 py-3 text-right font-semibold">
                        Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}
                    </td>
                    <td class="px-4 py-3 text-right font-semibold">
                        {{ number_format((int)($r->noa_growth ?? 0)) }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        {{ number_format((float)($r->rr_pct ?? 0),2) }}%
                    </td>
                </tr>
            @endif

        @empty
            <tr>
                <td colspan="10" class="px-4 py-6 text-center text-slate-500">
                    Belum ada data ranking untuk periode ini.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

