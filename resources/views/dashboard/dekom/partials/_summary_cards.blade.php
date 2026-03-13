<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-5">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm overflow-hidden">
        <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Total OS</div>
        <div class="mt-2 text-xl sm:text-2xl font-bold leading-tight tracking-tight text-slate-900 break-words">
            {{ $fmtMoneyShort($safeRow->total_os) }}
        </div>
        <div class="mt-2 text-xs text-slate-500 truncate" title="{{ $fmtMoney($safeRow->total_os) }}">
            {{ $fmtMoney($safeRow->total_os) }}
        </div>
        <div class="mt-1 text-sm text-slate-500">MoM: {{ $fmtPct($momGrowthPct) }}</div>
    </div>

    <!-- <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm overflow-hidden">
        <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Total NOA</div>
        <div class="mt-2 text-xl sm:text-2xl font-bold leading-tight tracking-tight text-slate-900 break-words">
            {{ number_format((int) $safeRow->total_noa, 0, ',', '.') }}
        </div>
        <div class="mt-2 text-sm text-slate-500">YoY: {{ $fmtPct($yoyGrowthPct) }}</div>
    </div> -->

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm overflow-hidden">
        <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">NPL</div>
        <div class="mt-2 text-xl sm:text-2xl font-bold leading-tight tracking-tight text-slate-900 break-words">
            {{ $fmtMoneyShort($safeRow->npl_os) }}
        </div>
        <div class="mt-2 text-xs text-slate-500 truncate" title="{{ $fmtMoney($safeRow->npl_os) }}">
            {{ $fmtMoney($safeRow->npl_os) }}
        </div>
        <div class="mt-1 text-sm text-slate-500">NPL %: {{ $fmtPct($safeRow->npl_pct) }}</div>
    </div>

    <!-- <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm overflow-hidden">
        <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Target YTD vs Realisasi YTD</div>
        <div class="mt-2 text-xl sm:text-2xl font-bold leading-tight tracking-tight text-slate-900 break-words">
            {{ $fmtMoneyShort($safeRow->realisasi_ytd) }}
        </div>
        <div class="mt-2 text-xs text-slate-500 truncate" title="{{ $fmtMoney($safeRow->realisasi_ytd) }}">
            {{ $fmtMoney($safeRow->realisasi_ytd) }}
        </div>
        <div class="mt-1 text-sm text-slate-500">
            Target: {{ $fmtMoneyShort($safeRow->os_ytd) }} · Ach: {{ $fmtPct($achOsPct) }}
        </div>
    </div> -->
<!-- </div> -->

<!-- <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4"> -->
    @php
        $restrOsCard = (float) data_get($creditCondition ?? [], 'totals.restruktur_os', 0);
        $restrNoaCard = (int) data_get($creditCondition ?? [], 'totals.restruktur_noa', 0);
    @endphp
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm overflow-hidden">
        <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Restrukturisasi</div>

        <div class="mt-2 text-lg sm:text-xl font-bold leading-tight tracking-tight text-slate-900 break-words">
            {{ $fmtMoneyShort($restrOsCard) }}
        </div>

        <div class="mt-2 text-xs text-slate-500 truncate" title="{{ $fmtMoney($restrOsCard) }}">
            {{ $fmtMoney($restrOsCard) }}
        </div>

        <div class="mt-1 text-sm text-slate-500">
            NOA: {{ number_format($restrNoaCard, 0, ',', '.') }}
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm overflow-hidden">
        <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">DPD &gt; 6 Bulan</div>
        <div class="mt-2 text-lg sm:text-xl font-bold leading-tight tracking-tight text-slate-900 break-words">
            {{ $fmtMoneyShort($safeRow->dpd6_os) }}
        </div>
        <div class="mt-2 text-xs text-slate-500 truncate" title="{{ $fmtMoney($safeRow->dpd6_os) }}">
            {{ $fmtMoney($safeRow->dpd6_os) }}
        </div>
        <div class="mt-1 text-sm text-slate-500">Eksposur aging 180+ hari</div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm overflow-hidden">
        <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">DPD &gt; 12 Bulan</div>
        <div class="mt-2 text-lg sm:text-xl font-bold leading-tight tracking-tight text-slate-900 break-words">
            {{ $fmtMoneyShort($safeRow->dpd12_os) }}
        </div>
        <div class="mt-2 text-xs text-slate-500 truncate" title="{{ $fmtMoney($safeRow->dpd12_os) }}">
            {{ $fmtMoney($safeRow->dpd12_os) }}
        </div>
        <div class="mt-1 text-sm text-slate-500">Eksposur aging 360+ hari</div>
    </div>
</div>