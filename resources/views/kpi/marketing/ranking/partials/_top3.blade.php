<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
    @for($i=0; $i<3; $i++)
        @php $r = $top[$i] ?? null; @endphp

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs text-slate-500">{{ $labels[$i] }}</div>

            @if($r)
                @php [$uid,$name,$aoCode] = $resolveRow($r); @endphp

                <div class="mt-1 text-lg font-bold text-slate-900">
                    {{ $name }}
                </div>
                <div class="text-xs text-slate-500">
                    {{ $role }} Code: <b>{{ $aoCode }}</b>
                </div>

                @if($tab==='score')
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <div class="rounded-xl bg-slate-50 p-2">
                            <div class="text-[11px] text-slate-500">Total Score</div>
                            <div class="font-bold">{{ number_format((float)($r->score_total ?? 0),2) }}</div>
                        </div>

                        <div class="rounded-xl bg-slate-50 p-2">
                            @if($role==='RO')
                                <div class="text-[11px] text-slate-500">TopUp</div>
                                <div class="font-bold">Rp {{ number_format((int)($r->topup_realisasi ?? 0),0,',','.') }}</div>
                            @else
                                <div class="text-[11px] text-slate-500">OS Growth</div>
                                <div class="font-bold">Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}</div>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <div class="rounded-xl bg-slate-50 p-2">
                            <div class="text-[11px] text-slate-500">
                                {{ $role==='RO' ? 'TopUp' : 'OS Growth' }}
                            </div>
                            <div class="font-bold">
                                Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-2">
                            <div class="text-[11px] text-slate-500">
                                {{ $role==='RO' ? 'NOA Realisasi' : 'NOA Growth' }}
                            </div>
                            <div class="font-bold">{{ number_format((int)($r->noa_growth ?? 0)) }}</div>
                        </div>
                    </div>
                @endif
            @else
                <div class="mt-2 text-sm text-slate-500">Belum ada data.</div>
            @endif
        </div>
    @endfor
</div>
