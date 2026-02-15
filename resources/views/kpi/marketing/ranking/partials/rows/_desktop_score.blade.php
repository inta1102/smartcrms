<table class="min-w-full text-sm">
    <thead class="bg-slate-50 text-slate-600">
        @if($role==='RO')
            <tr class="border-b">
                <th class="text-left px-4 py-3">Rank</th>
                <th class="text-left px-4 py-3">RO</th>
                <th class="text-right px-4 py-3">Total</th>
                <th class="text-right px-4 py-3">Repay %</th>
                <th class="text-right px-4 py-3">TopUp</th>
                <th class="text-right px-4 py-3">NOA</th>
                <th class="text-right px-4 py-3">DPK %</th>
                <th class="text-right px-4 py-3">Migrasi</th>
            </tr>
        @else
            <tr class="border-b">
                <th class="text-left px-4 py-3">Rank</th>
                <th class="text-left px-4 py-3">{{ $role }}</th>
                <th class="text-right px-4 py-3">OS Growth</th>
                <th class="text-right px-4 py-3">NOA Growth</th>
                <th class="text-right px-4 py-3">Score OS</th>
                <th class="text-right px-4 py-3">Score NOA</th>
                <th class="text-right px-4 py-3">Total</th>
                <th class="text-center px-4 py-3">Status</th>
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
                    <td class="px-4 py-3 text-right font-bold">{{ number_format((float)($r->score_total ?? 0),2) }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format((float)($r->repayment_pct ?? 0),2) }}%</td>
                    <td class="px-4 py-3 text-right">Rp {{ number_format((int)($r->topup_realisasi ?? 0),0,',','.') }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format((int)($r->noa_realisasi ?? 0)) }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format((float)($r->dpk_pct ?? 0),4) }}%</td>
                    <td class="px-4 py-3 text-right">
                        {{ number_format((int)($r->dpk_migrasi_count ?? 0)) }}
                        <div class="text-[11px] text-slate-500">
                            Rp {{ number_format((int)($r->dpk_migrasi_os ?? 0),0,',','.') }}
                        </div>
                    </td>
                </tr>
            @else
                <tr class="border-b hover:bg-slate-50">
                    <td class="px-4 py-3 font-bold text-slate-900">{{ $r->rank }}</td>

                    <td class="px-4 py-3">
                        @if($uid && !empty($r->target_id))
                            <a href="{{ route('kpi.marketing.targets.achievement', ['target'=>$r->target_id, 'period'=>$period->toDateString()]) }}"
                               class="text-xs px-2 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700">
                                {{ $name }}
                            </a>
                        @else
                            <span class="font-semibold text-slate-900">{{ $name }}</span>
                            @if($uid && empty($r->target_id))
                                <div class="text-xs text-red-500">Target belum tersedia</div>
                            @endif
                        @endif
                        <div class="text-xs text-gray-500">{{ $role }} Code: {{ $aoCode }}</div>
                    </td>

                    <td class="px-4 py-3 text-right">Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format((int)($r->noa_growth ?? 0)) }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format((float)($r->score_os ?? 0),2) }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format((float)($r->score_noa ?? 0),2) }}</td>
                    <td class="px-4 py-3 text-right font-bold text-slate-900">{{ number_format((float)($r->score_total ?? 0),2) }}</td>

                    <td class="px-4 py-3 text-center">
                        @if($r->is_final ?? false)
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-emerald-100 text-emerald-800">FINAL</span>
                        @else
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-amber-100 text-amber-800">ESTIMASI</span>
                        @endif
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
