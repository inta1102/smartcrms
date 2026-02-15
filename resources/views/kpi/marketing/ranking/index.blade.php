@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">

    @php
        // default safety
        $role = strtoupper(trim((string)($role ?? request('role','AO'))));
        $mode = strtolower(trim((string)($mode ?? request('mode','realtime'))));
        $tab  = $tab ?? request('tab','score');

        $rows = $tab==='score' ? $scoreRows : $growthRows;

        /**
         * Resolver aman untuk semua row (Eloquent vs stdClass)
         */
        $resolveRow = function ($r) {
            $uid    = $r->user?->id ?? ($r->user_id ?? null);
            $name   = $r->user?->name ?? ($r->ao_name ?? '-');
            $aoCode = $r->user?->ao_code ?? ($r->ao_code ?? '-');
            return [$uid, $name, $aoCode];
        };

        $top    = $rows->take(3)->values();
        $labels = ['🥇 Juara 1','🥈 Juara 2','🥉 Juara 3'];
    @endphp

    {{-- Header + Filter + Actions --}}
    @include('kpi.marketing.ranking.partials._header', compact('period','tab','role','mode'))

    {{-- Tabs --}}
    @include('kpi.marketing.ranking.partials._tabs', compact('period','prevPeriod','tab','role','mode'))

    {{-- Top 3 --}}
    @include('kpi.marketing.ranking.partials._top3', compact('tab','role','mode','top','labels','resolveRow'))

    {{-- Table --}}
    @include('kpi.marketing.ranking.partials._table', compact('period','tab','role','mode','rows','resolveRow'))

</div>
@endsection
