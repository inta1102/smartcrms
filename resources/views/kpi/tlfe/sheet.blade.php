@extends('layouts.app')

@section('title', 'KPI TLFE')

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-5">

  {{-- =========================
      TLFE Leadership Header
     ========================= --}}
  @include('kpi.tlfe.partials.leadership_header', [
      'row'         => $row,
      'period'      => $period,
      'periodLabel' => $periodLabel ?? \Carbon\Carbon::parse($period)->translatedFormat('F Y'),
      // ✅ label mode tampil mengikuti variabel $modeLabel dari controller (service-driven)
      'modeLabel'   => $modeLabel ?? '—',
      'aiTitle'     => $aiTitle ?? null,
      'aiBullets'   => $aiBullets ?? [],
      'aiActions'   => $aiActions ?? [],
      'fmt2'        => $fmt2 ?? fn($v) => number_format((float)($v ?? 0), 2, ',', '.'),
  ])

  {{-- =========================
      Reuse FE Sheet (source of truth: YTD from FeKpiMonthlyService)
      - items    : YTD per FE
      - weights  : weights dari service
      - tlRecap  : YTD recap TLFE + ranking
      - startYtd/endYtd : range YTD yang bener
     ========================= --}}
  @php
      // ✅ SOURCE OF TRUTH (dari controller yang sudah pakai FeKpiMonthlyService)
      $feItems = $items ?? $feRows ?? collect(); // fallback terakhir kalau controller belum diubah
      $feWeights = $weights ?? [
          'os_turun' => 0.40,
          'migrasi'  => 0.40,
          'penalty'  => 0.20,
      ];

      // ✅ mode untuk sheet_fe: jangan pakai row->calc_mode kalau sudah service-driven
      // karena YTD itu mix-mode: bulan < endMonth eom, endMonth realtime
      // di view cukup tampilkan "mode akhir" (pack['mode'])
      $sheetMode = $mode ?? ($row->calc_mode ?? 'eom');

      // ✅ pastikan object recap tersedia kalau controller kirim tlRecap dari service
      $tlRecapSafe = $tlRecap ?? null;
  @endphp

  @include('kpi.marketing.partials.sheet_fe', [
      'items'    => $feItems,
      'period'   => $period,
      'mode'     => $sheetMode,
      'weights'  => $feWeights,
      'startYtd' => $startYtd ?? null,
      'endYtd'   => $endYtd ?? null,
      'tlRecap'  => $tlRecapSafe,
  ])

</div>
@endsection