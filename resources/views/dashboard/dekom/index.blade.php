@extends('layouts.app')

@section('content')
@php
    use Carbon\Carbon;

    $fmtMoney = function ($v) {
        return 'Rp ' . number_format((float) $v, 0, ',', '.');
    };

    $fmtMoneyShort = function ($v) {
        $n = (float) $v;

        if (abs($n) >= 1_000_000_000_000) {
            return 'Rp ' . number_format($n / 1_000_000_000_000, 1, ',', '.') . ' T';
        }

        if (abs($n) >= 1_000_000_000) {
            return 'Rp ' . number_format($n / 1_000_000_000, 1, ',', '.') . ' M';
        }

        if (abs($n) >= 1_000_000) {
            return 'Rp ' . number_format($n / 1_000_000, 1, ',', '.') . ' Jt';
        }

        return 'Rp ' . number_format($n, 0, ',', '.');
    };

    $fmtPct = function ($v, $digit = 2) {
        return number_format((float) $v, $digit, ',', '.') . '%';
    };

    $safeRow = $row ?? null;
    $meta = $meta ?? [];

    $portfolioSource = data_get($meta, 'portfolio_source', '-');
    $targetNplPct = (float) data_get($meta, 'target.target_npl_pct', 0);
    $targetOs = (float) data_get($meta, 'target.target_os', 0);
    $achOsPct = (float) data_get($meta, 'target.ach_os_pct', 0);
    $momGrowthPct = (float) data_get($meta, 'growth.mom_os_growth_pct', 0);
    $yoyGrowthPct = (float) data_get($meta, 'growth.yoy_os_growth_pct', 0);

    $nplPct = (float) ($safeRow->npl_pct ?? 0);

    $nplBadge = $nplPct < 3
        ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
        : ($nplPct <= 5
            ? 'bg-amber-50 text-amber-700 border-amber-200'
            : 'bg-rose-50 text-rose-700 border-rose-200');

    $achBadge = $achOsPct >= 100
        ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
        : ($achOsPct >= 80
            ? 'bg-amber-50 text-amber-700 border-amber-200'
            : 'bg-rose-50 text-rose-700 border-rose-200');

    $periodLabel = $safeRow?->period_month
        ? Carbon::parse($safeRow->period_month)->translatedFormat('F Y')
        : '-';
@endphp

<div class="space-y-5 sm:space-y-6">
    {{-- Header --}}
    @include('dashboard.dekom.partials._header', [
        'safeRow' => $safeRow,
        'period' => $period,
        'mode' => $mode,
        'availablePeriods' => $availablePeriods,
        'portfolioSource' => $portfolioSource,
        'nplPct' => $nplPct,
        'achOsPct' => $achOsPct,
        'nplBadge' => $nplBadge,
        'achBadge' => $achBadge,
    ])

    @if(!$safeRow)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Data Dashboard Dekom belum tersedia untuk mode/periode yang dipilih.
        </div>
    @else

        {{-- 1. Kartu ringkasan utama --}}
        @include('dashboard.dekom.partials._summary_cards', [
            'safeRow' => $safeRow,
            'fmtMoney' => $fmtMoney,
            'fmtMoneyShort' => $fmtMoneyShort,
            'fmtPct' => $fmtPct,
            'periodLabel' => $periodLabel,
            'achOsPct' => $achOsPct,
            'momGrowthPct' => $momGrowthPct,
            'yoyGrowthPct' => $yoyGrowthPct,
            'creditCondition' => $creditCondition ?? [],
        ])

        {{-- 2. Insight eksekutif --}}
        @include('dashboard.dekom.partials._risk_radar', [
            'riskRadar' => $riskRadar ?? [],
        ])

        @include('dashboard.dekom.partials._executive_narrative', [
            'executiveNarrative' => $executiveNarrative ?? '',
        ])

        {{-- 3. Grafik utama --}}
        @include('dashboard.dekom.partials._charts_main')

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
            @include('dashboard.dekom.partials._chart_target_vs_actual')
            @include('dashboard.dekom.partials._chart_portfolio_composition')
        </div>

        @include('dashboard.dekom.partials._charts_secondary')

        {{-- 4. Rekap tabel analitik --}}
        @include('dashboard.dekom.partials._credit_condition')

        {{-- 5. Pergerakan kredit --}}
        @include('dashboard.dekom.partials._credit_movements', [
            'movementSections' => $movementSections ?? [],
        ])

        @include('dashboard.dekom.partials._credit_waterfall')

        {{-- 6. Rekap ringkas bawah --}}
        <!-- @include('dashboard.dekom.partials._credit_recap', [
            'safeRow' => $safeRow,
            'fmtMoney' => $fmtMoney,
            'fmtPct' => $fmtPct,
            'periodLabel' => $periodLabel,
        ]) -->
    @endif
</div>
@endsection

@push('scripts')
    @include('dashboard.dekom.partials._scripts')
@endpush