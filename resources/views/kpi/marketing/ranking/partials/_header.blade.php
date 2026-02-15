<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">
            🏆 Ranking {{ $role }} – KPI Marketing
        </h1>
        <p class="text-sm text-slate-500">
            Periode: <b>{{ $period->translatedFormat('M Y') }}</b>
        </p>
    </div>

    <form method="GET" class="flex flex-wrap items-center gap-2">
        <input type="month"
               name="period"
               value="{{ $period->format('Y-m') }}"
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm"
        />

        {{-- pilih role --}}
        <select name="role" class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
            @foreach(['AO','SO','RO','FE','BE'] as $opt)
                <option value="{{ $opt }}" @selected($role===$opt)>{{ $opt }}</option>
            @endforeach
        </select>

        {{-- mode khusus RO --}}
        @if($role === 'RO')
            <select name="mode" class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="realtime" @selected($mode==='realtime')>Realtime</option>
                <option value="eom" @selected($mode==='eom')>EOM (Locked)</option>
            </select>
        @endif

        <input type="hidden" name="tab" value="{{ $tab }}">

        <button class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
            Tampilkan
        </button>
    </form>

    {{-- Sheet link (kalau route sheet sudah siap) --}}
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('kpi.marketing.sheet', ['role'=>'AO', 'period'=>$period->format('Y-m')]) }}"
           class="rounded-xl bg-white border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">
            📄 KPI Sheet AO
        </a>

        <a href="{{ route('kpi.marketing.sheet', ['role'=>'SO', 'period'=>$period->format('Y-m')]) }}"
           class="rounded-xl bg-white border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">
            📄 KPI Sheet SO
        </a>

        <a href="{{ route('kpi.marketing.sheet', ['role'=>'RO', 'period'=>$period->format('Y-m'), 'mode'=>$mode ?? 'realtime']) }}"
            class="rounded-xl bg-white border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">
            📄 KPI Sheet RO
        </a>

        <a href="{{ route('kpi.marketing.sheet', ['role'=>'FE', 'period'=>$period->format('Y-m'), 'mode'=>$mode ?? 'realtime']) }}"
            class="rounded-xl bg-white border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">
            📄 KPI Sheet FE
        </a>

        <a href="{{ route('kpi.marketing.sheet', ['role'=>'FE', 'period'=>$period->format('Y-m'), 'mode'=>$mode ?? 'realtime']) }}"
            class="rounded-xl bg-white border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">
            📄 KPI Sheet BE
        </a>
    </div>

    @can('recalcMarketingKpi')
        <form method="POST"
              action="{{ route('kpi.marketing.ranking.recalc') }}"
              onsubmit="return confirm('Recalc KPI semua AO untuk periode ini?')">
            @csrf
            <input type="hidden" name="period" value="{{ $period->format('Y-m') }}">
            <button class="w-full md:w-auto rounded-xl bg-amber-600 px-4 py-2 text-white text-sm font-semibold hover:bg-amber-700">
                🔄 Recalc All AO
            </button>
        </form>
    @endcan
</div>
