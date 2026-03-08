@extends('layouts.app')

@section('title', 'Dashboard TL RO - OS Harian')

@section('content')
@php
  $sum = $sum ?? 'mtd';
  if (!in_array($sum, ['day', 'mtd'], true)) $sum = 'mtd';

  $q = request()->query();
  $buildUrl = function(array $override = []) use ($q) {
    $merged = array_merge($q, $override);
    foreach ($merged as $k => $v) {
      if ($v === null || $v === '') unset($merged[$k]);
    }
    return url()->current() . (count($merged) ? ('?' . http_build_query($merged)) : '');
  };

  $urlDay = $buildUrl(['sum' => 'day']);
  $urlMtd = $buildUrl(['sum' => 'mtd']);
  $activeDay = $sum === 'day';
  $activeMtd = $sum === 'mtd';
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-5">

  @include('kpi.tlro.partials._helpers')

  @include('kpi.tlro.partials._header', [
    'urlDay' => $urlDay,
    'urlMtd' => $urlMtd,
    'activeDay' => $activeDay,
    'activeMtd' => $activeMtd,
    'compareLabel' => $compareLabel ?? null,
    'aoOptions' => $aoOptions ?? [],
    'aoFilter' => $aoFilter ?? '',
    'from' => $from ?? '',
    'to' => $to ?? '',
    'sum' => $sum,
  ])

  @include('kpi.tlro.partials._chart')

  @include('kpi.tlro.partials._summary_prev_eom')

  @include('kpi.tlro.partials._summary_harian_cards')
  
  @include('kpi.tlro.partials._coverage_visit_ro', [
        'visitCoverageSummary' => $visitCoverageSummary ?? [],
        'visitCoverageRows'    => $visitCoverageRows ?? collect(),
        'latestPosDate'        => $latestPosDate ?? null,
        'coverageTo'           => $coverageTo ?? null,
  ])
  <!-- @include('kpi.tlro.partials._visit_discipline') -->

  <!-- @include('kpi.tlro.partials._top_risk_tomorrow') -->

  @include('kpi.tlro.partials._due_this_month')

  @include('kpi.tlro.partials._lt_latest')

  @include('kpi.tlro.partials._migrasi_tunggakan')

  @include('kpi.tlro.partials._jt_angsuran')

  @include('kpi.tlro.partials._os_big')

</div>

@include('kpi.tlro.partials._scripts')
@endsection