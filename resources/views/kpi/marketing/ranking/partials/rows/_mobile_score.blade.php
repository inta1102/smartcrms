@forelse($rows as $r)
    @php [$uid,$name,$aoCode] = $resolveRow($r); @endphp

    <div class="p-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="font-bold text-slate-900 truncate">
                    #{{ $r->rank }} -
                    @if($uid && !empty($r->target_id))
                        <a href="{{ route('kpi.marketing.targets.achievement', ['target'=>$r->target_id, 'period'=>$period->toDateString()]) }}"
                           class="text-blue-600 hover:underline font-semibold">
                            {{ $name }}
                        </a>
                    @else
                        <span class="font-semibold text-slate-900">{{ $name }}</span>
                        @if($uid && empty($r->target_id) && $role !== 'RO')
                            <div class="text-xs text-red-500">Target belum tersedia (belum dibuat/di-approve)</div>
                        @endif
                    @endif
                </div>
                <div class="text-xs text-slate-500">
                    {{ $role }} Code: <b>{{ $aoCode }}</b>
                </div>
            </div>

            @if($role !== 'RO')
                <span class="shrink-0 inline-flex rounded-full px-3 py-1 text-xs font-semibold
                  {{ ($r->is_final ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                  {{ ($r->is_final ?? false) ? 'FINAL' : 'ESTIMASI' }}
                </span>
            @endif
        </div>

        @if($role === 'RO')
            <div class="mt-3 grid grid-cols-2 gap-2">
                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-[11px] text-slate-500">Total</div>
                    <div class="font-bold text-slate-900">{{ number_format((float)($r->score_total ?? 0),2) }}</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-[11px] text-slate-500">TopUp</div>
                    <div class="font-bold text-slate-900">Rp {{ number_format((int)($r->topup_realisasi ?? 0),0,',','.') }}</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-[11px] text-slate-500">Repayment</div>
                    <div class="font-bold text-slate-900">{{ number_format((float)($r->repayment_pct ?? 0),2) }}%</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-[11px] text-slate-500">DPK</div>
                    <div class="font-bold text-slate-900">{{ number_format((float)($r->dpk_pct ?? 0),4) }}%</div>
                </div>
            </div>
        @else
            <div class="mt-3 grid grid-cols-2 gap-2">
                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-[11px] text-slate-500">OS Growth</div>
                    <div class="font-bold text-slate-900">
                        Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}
                    </div>
                </div>

                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-[11px] text-slate-500">NOA Growth</div>
                    <div class="font-bold text-slate-900">
                        {{ number_format((int)($r->noa_growth ?? 0)) }}
                    </div>
                </div>

                <div class="rounded-xl bg-slate-50 p-2 col-span-2">
                    <div class="text-[11px] text-slate-500">Total</div>
                    <div class="font-bold text-slate-900">
                        {{ number_format((float)($r->score_total ?? 0),2) }}
                    </div>
                </div>
            </div>
        @endif

        @if(!$uid)
            <div class="mt-2 text-xs text-red-500">
                Catatan: akun user belum terdaftar / ao_code belum match.
            </div>
        @endif
    </div>
@empty
    <div class="p-4 text-center text-slate-500">
        Belum ada data ranking untuk periode ini.
    </div>
@endforelse
