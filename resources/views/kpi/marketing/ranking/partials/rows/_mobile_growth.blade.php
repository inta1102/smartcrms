@forelse($rows as $r)
    @php [$uid,$name,$aoCode] = $resolveRow($r); @endphp

    <div class="p-4">
        <div class="font-bold text-slate-900 truncate">
            #{{ $r->rank }} - {{ $name }}
        </div>
        <div class="text-xs text-slate-500">
            {{ $role }} Code: <b>{{ $aoCode }}</b>
        </div>

        @if($role==='RO')
            <div class="mt-3 grid grid-cols-2 gap-2">
                <div class="rounded-xl bg-slate-50 p-2 col-span-2">
                    <div class="text-[11px] text-slate-500">TopUp</div>
                    <div class="font-bold text-slate-900">
                        Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}
                    </div>
                </div>
                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-[11px] text-slate-500">NOA Realisasi</div>
                    <div class="font-bold text-slate-900">{{ number_format((int)($r->noa_growth ?? 0)) }}</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-[11px] text-slate-500">DPK</div>
                    <div class="font-bold text-slate-900">{{ number_format((float)($r->dpk_pct ?? 0),4) }}%</div>
                </div>
            </div>
        @else
            {{-- ✅ Mobile Non-RO Growth = Realisasi Disbursement --}}
            <div class="mt-3 grid grid-cols-2 gap-2">
                <div class="rounded-xl bg-slate-50 p-2 col-span-2">
                    <div class="text-[11px] text-slate-500">OS Disb</div>
                    <div class="font-bold text-slate-900">
                        Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}
                    </div>
                </div>

                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-[11px] text-slate-500">NOA Disb</div>
                    <div class="font-bold text-slate-900">
                        {{ number_format((int)($r->noa_growth ?? 0)) }}
                    </div>
                </div>

                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-[11px] text-slate-500">RR</div>
                    <div class="font-bold text-slate-900">
                        {{ number_format((float)($r->rr_pct ?? 0),2) }}%
                    </div>
                </div>
            </div>
        @endif
    </div>
@empty
    <div class="p-4 text-center text-slate-500">
        Belum ada data ranking untuk periode ini.
    </div>
@endforelse
