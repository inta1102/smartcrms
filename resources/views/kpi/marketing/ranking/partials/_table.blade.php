<div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
    <div class="p-4 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900">Daftar Ranking</div>
        <div class="text-xs text-slate-500">
            @if($tab==='score')
                {{ $role==='RO' ? 'Urut: Total Weighted Score' : 'Urut: Total Score → Score OS → Score NOA → OS Growth' }}
            @else
                {{ $role==='RO' ? 'Urut: TopUp → NOA' : 'Urut: OS Growth → NOA Growth (snapshot vs live)' }}
            @endif
        </div>
    </div>

    {{-- Mobile --}}
    <div class="md:hidden divide-y divide-slate-200">
        @if($tab==='score')
            @include('kpi.marketing.ranking.partials.rows._mobile_score', compact('rows','period','role','resolveRow'))
        @else
            @include('kpi.marketing.ranking.partials.rows._mobile_growth', compact('rows','period','role','resolveRow'))
        @endif
    </div>

    {{-- Desktop --}}
    <div class="hidden md:block overflow-x-auto">
        @if($tab==='score')
            @include('kpi.marketing.ranking.partials.rows._desktop_score', compact('rows','period','role','resolveRow'))
        @else
            @include('kpi.marketing.ranking.partials.rows._desktop_growth', compact('rows','period','role','resolveRow'))
        @endif
    </div>
</div>
