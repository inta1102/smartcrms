<div class="mt-3 mb-4 flex gap-2">
    <a href="{{ route('kpi.marketing.ranking.index', [
            'period'=>$period->format('Y-m'),
            'tab'=>'score',
            'role'=>$role,
            'mode'=>($role==='RO' ? $mode : null),
        ]) }}"
       class="rounded-xl px-4 py-2 text-sm font-semibold border
              {{ $tab==='score' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
        Ranking KPI (Score)
    </a>

    <a href="{{ route('kpi.marketing.ranking.index', [
            'period'=>$period->format('Y-m'),
            'tab'=>'growth',
            'role'=>$role,
            'mode'=>($role==='RO' ? $mode : null),
        ]) }}"
       class="rounded-xl px-4 py-2 text-sm font-semibold border
              {{ $tab==='growth' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
        Ranking Growth (Realisasi)
    </a>
</div>

@if($tab==='score')
    <div class="text-xs text-slate-500 mb-2">
        @if($role==='RO')
            Ranking KPI RO (weighted). Urut: Total Weighted Score.
        @else
            Ranking KPI resmi (butuh target). Urut: Total Score → Score OS → Score NOA → OS Growth.
        @endif
    </div>
@else
    <div class="text-xs text-slate-500 mb-2">
        @if($role==='RO')
            Ranking realisasi RO. Urut: TopUp → NOA.
        @else
            Ranking realisasi (snapshot {{ $prevPeriod->translatedFormat('M Y') }} vs live {{ $period->translatedFormat('M Y') }}).
            Urut: OS Growth → NOA Growth.
        @endif
    </div>
@endif
