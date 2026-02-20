@extends('layouts.app')

@section('title', 'Dashboard TL RO - OS Harian')

@section('content')

@php
  $riskMeta = function($dpd, $kolek, $os, $lastVisitRaw, $isLt = false) {
    $dpd = (int)($dpd ?? 0);
    $os  = (int)($os ?? 0);

    // kolek numeric only
    $kolekRaw = $kolek;
    $kolekNum = null;
    if (is_numeric($kolekRaw)) $kolekNum = (int)$kolekRaw;

    // age visit
    $age = null;
    if (!empty($lastVisitRaw)) {
      try { $age = \Carbon\Carbon::parse($lastVisitRaw)->diffInDays(now()); }
      catch (\Throwable $e) { $age = null; }
    }

    $score = 0;
    $reasons = [];

    // ===== DPD score =====
    if ($dpd >= 60) { $score += 40; $reasons[] = "DPD ≥ 60 (+40)"; }
    elseif ($dpd >= 30) { $score += 30; $reasons[] = "DPD 30–59 (+30)"; }
    elseif ($dpd >= 15) { $score += 20; $reasons[] = "DPD 15–29 (+20)"; }
    elseif ($dpd >= 8)  { $score += 10; $reasons[] = "DPD 8–14 (+10)"; }
    else { $reasons[] = "DPD 0–7 (+0)"; }

    // ===== Kolek score =====
    if ($kolekNum !== null) {
      if ($kolekNum >= 5) { $score += 50; $reasons[] = "Kolek 5 (+50)"; }
      elseif ($kolekNum === 4) { $score += 40; $reasons[] = "Kolek 4 (+40)"; }
      elseif ($kolekNum === 3) { $score += 30; $reasons[] = "Kolek 3 (+30)"; }
      elseif ($kolekNum === 2) { $score += 15; $reasons[] = "Kolek 2 (+15)"; }
      else { $reasons[] = "Kolek 1 (+0)"; }
    } else {
      $reasons[] = "Kolek non-angka (skip)";
    }

    // ===== OS score =====
    if ($os >= 1000000000) { $score += 30; $reasons[] = "OS ≥ 1M (+30)"; }
    elseif ($os >= 500000000) { $score += 20; $reasons[] = "OS 500–999jt (+20)"; }
    elseif ($os >= 100000000) { $score += 10; $reasons[] = "OS 100–499jt (+10)"; }
    else { $reasons[] = "OS < 100jt (+0)"; }

    // ===== Age visit score =====
    if ($age === null) {
      $score += 20; $reasons[] = "Belum ada visit / kosong (+20)";
    } else {
      if ($age >= 30) { $score += 30; $reasons[] = "Umur visit ≥ 30 hari (+30)"; }
      elseif ($age >= 14) { $score += 20; $reasons[] = "Umur visit 14–29 hari (+20)"; }
      elseif ($age >= 7)  { $score += 10; $reasons[] = "Umur visit 7–13 hari (+10)"; }
      else { $reasons[] = "Umur visit 0–6 hari (+0)"; }
    }

    // ===== Level =====
    $level = 'LOW';
    $cls   = 'bg-emerald-50 text-emerald-700 border-emerald-200';
    if ($score >= 80) { $level = 'CRITICAL'; $cls = 'bg-rose-100 text-rose-800 border-rose-300'; }
    elseif ($score >= 60) { $level = 'HIGH'; $cls = 'bg-rose-50 text-rose-700 border-rose-200'; }
    elseif ($score >= 35) { $level = 'MEDIUM'; $cls = 'bg-amber-50 text-amber-700 border-amber-200'; }

    // Override LT label (supervisi)
    if ($isLt) {
      $level = 'LT';
      $cls   = 'bg-pink-50 text-pink-700 border-pink-200';
      array_unshift($reasons, 'Status: LT (override label)');
    }

    $tooltip = "Risk: {$level} | Score: {$score}\n- " . implode("\n- ", $reasons);

    return [
      'level' => $level,
      'score' => $score,
      'cls' => $cls,
      'age' => $age,
      'tooltip' => $tooltip,
    ];
  };

  // helpers UI cards
  $fmtRp = function($v){
    return 'Rp ' . number_format((int)($v ?? 0), 0, ',', '.');
  };
  $fmtRpDelta = function($v){
    if ($v === null) return '-';
    $n = (int)$v;
    return ($n >= 0 ? '+' : '') . 'Rp ' . number_format($n, 0, ',', '.');
  };
  $fmtPts = function($v){
    if ($v === null) return '-';
    $n = (float)$v;
    $sign = ($n >= 0 ? '+' : '');
    return $sign . number_format($n, 2, ',', '.') . ' pts';
  };
  $fmtPct = function($v){
    if ($v === null) return '-';
    return number_format((float)$v, 2, ',', '.') . '%';
  };

  $card = function($key, $cards){
    return $cards[$key] ?? ['value'=>null,'delta'=>null];
  };

  $cOS  = $card('os', $cards ?? []);
  $cL0  = $card('l0', $cards ?? []);
  $cLT  = $card('lt', $cards ?? []);
  $cRR  = $card('rr', $cards ?? []);
  $cPLT = $card('pct_lt', $cards ?? []);
  $cDPK = $card('dpk', $cards ?? []);


  $bounce = $bounce ?? [];
  $bouncePrev = $bounce['prev_pos_date'] ?? null;

  $hasSignal = (bool)($bounce['signal_bounce_risk'] ?? false);

  $jtNext2Start = $jtNext2Start ?? null;
  $jtNext2End   = $jtNext2End ?? null;

  $ltTlPack = function($deltaLt, array $bounce) {
    $toDpkNoa = (int)($bounce['lt_to_dpk_noa'] ?? 0);
    $toDpkOs  = (int)($bounce['lt_to_dpk_os'] ?? 0);

    if (is_null($deltaLt)) {
      return [
        'deltaTone' => 'text-slate-500',
        'hintTone'  => 'text-slate-500',
        'hint'      => 'prev n/a',
      ];
    }

    $d = (float)$deltaLt;

    // ✅ delta tone murni dari sign (LT naik buruk, LT turun baik)
    $deltaTone = $d > 0 ? 'text-rose-700' : ($d < 0 ? 'text-emerald-700' : 'text-slate-500');
    $hintTone  = 'text-slate-500';
    $hint      = $d > 0 ? 'LT naik = memburuk' : ($d < 0 ? 'LT turun = membaik' : 'stagnan');

    // ⚠️ eskalasi: jangan ubah deltaTone, cukup hint warning
    if ($toDpkNoa > 0) {
      $hintTone = 'text-amber-700';
      if ($d < 0) {
        $hint = "LT turun, tapi ada eskalasi LT→DPK: {$toDpkNoa} NOA (OS ± Rp ".number_format($toDpkOs,0,',','.').")";
      } elseif ($d > 0) {
        $hint = "LT naik, dan ada eskalasi LT→DPK: {$toDpkNoa} NOA (OS ± Rp ".number_format($toDpkOs,0,',','.').")";
      } else {
        $hint = "LT stagnan, ada eskalasi LT→DPK: {$toDpkNoa} NOA (OS ± Rp ".number_format($toDpkOs,0,',','.').")";
      }
    }

    return compact('deltaTone','hintTone','hint');
  };

  // ===== summary mode (day|mtd) from controller =====
  $sum = $sum ?? 'mtd';
  if (!in_array($sum, ['day','mtd'], true)) $sum = 'mtd';

  // ===== build query helper (preserve all existing query params) =====
  $q = request()->query(); // ambil semua query string saat ini
  $buildUrl = function(array $override = []) use ($q) {
    $merged = array_merge($q, $override);
    // bersihkan null/empty agar URL rapi
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

  {{-- Header --}}
  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">📈 Dashboard TL RO</h1>
      

      {{-- Mode ringkasan (Harian / MTD) --}}
      <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
       
        {{-- Toggle ringkasan --}}
        <span class="ml-0 md:ml-2 inline-flex rounded-xl border border-slate-200 overflow-hidden bg-white">
          <a href="{{ $urlDay }}"
             class="px-3 py-1.5 text-xs font-semibold {{ $activeDay ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50' }}">
            Harian
          </a>
          <a href="{{ $urlMtd }}"
             class="px-3 py-1.5 text-xs font-semibold {{ $activeMtd ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50' }}">
            MTD
          </a>
        </span>

        @if(!empty($compareLabel))
          <span class="px-3 py-1 rounded-xl bg-white border border-slate-200 text-slate-600">
            {{ $compareLabel }}
          </span>
        @endif
      </div>
    </div>

    <form method="GET" class="flex items-end gap-2 flex-wrap">
      {{-- ✅ pertahankan sum saat klik Tampilkan --}}
      <input type="hidden" name="sum" value="{{ $sum }}">

      <div>
        <div class="text-xs text-slate-500 mb-1">AO</div>
        <select name="ao" class="rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white">
          <option value="">ALL (Scope TL)</option>
          @foreach(($aoOptions ?? []) as $o)
            <option value="{{ $o['ao_code'] }}" {{ ($aoFilter ?? '') === $o['ao_code'] ? 'selected' : '' }}>
              {{ $o['label'] }}
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <div class="text-xs text-slate-500 mb-1">Dari</div>
        <input type="date" name="from" value="{{ $from }}"
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
      </div>

      <div>
        <div class="text-xs text-slate-500 mb-1">Sampai</div>
        <input type="date" name="to" value="{{ $to }}"
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
      </div>

      <button class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
        Tampilkan
      </button>
    </form>
  </div>

    {{-- Chart + Controls --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-4 space-y-4">

    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
      <div>
        <div class="font-bold text-slate-900">Grafik Harian</div>
        <div class="text-xs text-slate-500">
          Tanggal tanpa snapshot akan tampil <b>putus</b> (bukan 0).
        </div>

        {{-- KPI strip (diisi JS berdasarkan titik terakhir) --}}
        <div class="mt-3 flex flex-wrap gap-2">
          <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
            <span class="text-slate-500">Latest OS:</span>
            <b id="kpiLatestOs" class="text-slate-900">-</b>
          </span>
          <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
            <span class="text-slate-500">Latest L0:</span>
            <b id="kpiLatestL0" class="text-slate-900">-</b>
          </span>
          <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
            <span class="text-slate-500">Latest LT:</span>
            <b id="kpiLatestLT" class="text-slate-900">-</b>
          </span>
          <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
            <span class="text-slate-500">RR:</span>
            <b id="kpiLatestRR" class="text-slate-900">-</b>
          </span>
          <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
            <span class="text-slate-500">%LT:</span>
            <b id="kpiLatestPctLT" class="text-slate-900">-</b>
          </span>
        </div>
      </div>

      <div class="flex items-center gap-2 flex-wrap justify-end">
        {{-- Metric --}}
        <div class="rounded-xl border border-slate-200 p-1 bg-slate-50 flex items-center gap-1">
          <button type="button" data-metric="os_total" id="btnMetricTotal"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200">
            OS Total
          </button>
          <button type="button" data-metric="os_l0" id="btnMetricL0"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
            OS L0
          </button>
          <button type="button" data-metric="os_lt" id="btnMetricLT"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
            OS LT
          </button>
          <button type="button" data-metric="rr" id="btnMetricRR"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
            RR (% L0)
          </button>
          <button type="button" data-metric="pct_lt" id="btnMetricPctLT"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
            % LT
          </button>
        </div>

        {{-- Mode --}}
        <div class="rounded-xl border border-slate-200 p-1 bg-slate-50 flex items-center gap-1">
          <button type="button" id="btnModeValue"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200">
            Value
          </button>
          <button type="button" id="btnModeGrowth"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
            Growth (Δ H vs H-1)
          </button>
        </div>

        {{-- Labels toggle --}}
        <div class="rounded-xl border border-slate-200 p-1 bg-slate-50 flex items-center gap-1">
          <button type="button" id="btnLabelsLastOnly"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200">
            Label: Last
          </button>
          <button type="button" id="btnLabelsAll"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
            Label: Semua
          </button>
        </div>

        {{-- Mobile: toggle show all lines --}}
        <div class="w-full sm:hidden">
          <button type="button" id="btnShowAllLines"
                  class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-800">
            Tampilkan semua garis
          </button>
          <div class="mt-1 text-[11px] text-slate-500">
            Mode ringkas membantu grafik lebih kebaca di HP.
          </div>
        </div>
      </div>
    </div>

    {{-- Chart wrapper (mobile-friendly) --}}
    <div class="w-full">
      <div class="relative w-full h-[260px] sm:h-[360px] md:h-[420px]">
        <canvas id="osChart" class="w-full h-full"></canvas>
      </div>

      <div class="mt-2 text-[11px] text-slate-500 sm:hidden">
        Tips: geser layar ke samping untuk melihat detail legend & garis.
      </div>
    </div>
  </div>


  {{-- Summary cards (M-1 / EOM bulan lalu) --}}
  @php
    $m1 = $summaryM1 ?? ['os'=>0,'l0'=>0,'lt'=>0,'dpk'=>0];
  @endphp

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">
        OS Bulan Lalu <span class="text-slate-400">({{ $prevOsLabel ?? '-' }})</span>
      </div>
      <div class="text-l font-extrabold text-slate-900">
        {{ $fmtRp($m1['os'] ?? 0) }}
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">
        L0 Bulan Lalu <span class="text-slate-400">({{ $prevOsLabel ?? '-' }})</span>
      </div>
      <div class="text-l font-extrabold text-slate-900">
        {{ $fmtRp($m1['l0'] ?? 0) }}
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">
        LT Bulan Lalu <span class="text-slate-400">({{ $prevOsLabel ?? '-' }})</span>
      </div>
      <div class="text-l font-extrabold text-slate-900">
        {{ $fmtRp($m1['lt'] ?? 0) }}
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">
        DPK Bulan Lalu <span class="text-slate-400">({{ $prevOsLabel ?? '-' }})</span>
      </div>
      <div class="text-l font-extrabold text-slate-900">
        {{ $fmtRp($m1['dpk'] ?? 0) }}
      </div>
    </div>
  </div>

  {{-- ✅ NEW: Cards H vs H-1 + TLRO Panel --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-4 space-y-4">
    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
      <!-- <div>
        <div class="font-extrabold text-slate-900">TLRO – Ringkasan Harian (H vs H-1)</div>
        <div class="text-xs text-slate-500 mt-1">
          Basis perbandingan: <b>{{ $prevDate ?? '-' }}</b> → <b>{{ $latestDate ?? '-' }}</b>.
          @if(!empty($bouncePrev))
            <span class="ml-2">Bounce compare (loan_accounts): <b>{{ $bouncePrev }}</b> → <b>{{ $latestPosDate }}</b>.</span>
          @endif
        </div>
      </div> -->

      <!-- <div class="flex flex-wrap gap-2 justify-end">
        <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
          <span class="text-slate-500">JT Next2:</span>
          <b class="text-slate-900">
            {{ $jtNext2Start && $jtNext2End ? ($jtNext2Start.' s/d '.$jtNext2End) : '-' }}
          </b>
        </span>
        <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
          <span class="text-slate-500">NOA Cure LT→L0:</span>
          <b class="text-slate-900">{{ (int)($bounce['lt_to_l0_noa'] ?? 0) }}</b>
        </span>
        <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
          <span class="text-slate-500">NOA JT Next2:</span>
          <b class="text-slate-900">{{ (int)($bounce['jt_next2_noa'] ?? 0) }}</b>
        </span>
        <span class="px-3 py-1 rounded-xl bg-rose-50 border border-rose-200 text-xs">
          <span class="text-rose-700">NOA LT→DPK:</span>
          <b class="text-rose-800">{{ (int)($bounce['lt_to_dpk_noa'] ?? 0) }}</b>
        </span>
      </div> -->
    </div>

    {{-- cards H vs H-1 (OS, L0+RR, LT+%LT, DPK, Meta) --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">

      {{-- OS --}}
      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">OS tgl terakhir</div>
        <div class="text-l font-extrabold text-slate-900">
          {{ $fmtRp($cOS['value'] ?? 0) }}
        </div>
        <div class="mt-1 text-sm font-bold {{ (($cOS['delta'] ?? 0) >= 0) ? 'text-emerald-700' : 'text-rose-700' }}">
          Δ {{ $fmtRpDelta($cOS['delta'] ?? null) }}
        </div>
        <div class="text-[11px] text-slate-500 mt-1">
          OS naik = membaik
        </div>
      </div>

      {{-- L0 + RR --}}
      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex items-start justify-between gap-3">
          <div class="text-xs text-slate-500">L0 tgl terakhir</div>
          <span class="shrink-0 px-2 py-1 rounded-lg bg-slate-50 border border-slate-200 text-[11px] text-slate-600">
            RR: <b class="text-slate-900">{{ $fmtPct($cRR['value'] ?? null) }}</b>
          </span>
        </div>

        <div class="text-l font-extrabold text-slate-900">
          {{ $fmtRp($cL0['value'] ?? 0) }}
        </div>

        <div class="mt-1 flex items-baseline justify-between gap-3">
          <div class="text-sm font-bold {{ (($cL0['delta'] ?? 0) >= 0) ? 'text-emerald-700' : 'text-rose-700' }}">
            Δ {{ $fmtRpDelta($cL0['delta'] ?? null) }}
          </div>

          <!-- <div class="text-sm font-bold {{ (($cRR['delta'] ?? 0) >= 0) ? 'text-emerald-700' : 'text-rose-700' }}">
            Δ RR {{ $fmtPts($cRR['delta'] ?? null) }}
          </div> -->
        </div>

        <div class="text-[11px] text-slate-500 mt-1">
          RR = L0/OS · Δ dalam <b>points</b> (bukan %)
        </div>
      </div>

      {{-- LT + %LT --}}
      @php
        $ltPack = $ltTlPack($cLT['delta'] ?? null, (array)($bounce ?? []));
        $pltDelta = $cPLT['delta'] ?? null;
        $pltTone  = is_null($pltDelta) ? 'text-slate-500' : (((float)$pltDelta <= 0) ? 'text-emerald-700' : 'text-rose-700');
        $toDpkNoa = (int)($bounce['lt_to_dpk_noa'] ?? 0);
      @endphp

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex items-start justify-between gap-3">
          <div class="text-xs text-slate-500">LT tgl terakhir</div>
          <span class="shrink-0 px-2 py-1 rounded-lg bg-slate-50 border border-slate-200 text-[11px] text-slate-600">
            %LT: <b class="text-slate-900">{{ $fmtPct($cPLT['value'] ?? null) }}</b>
          </span>
        </div>

        <div class="text-l font-extrabold text-slate-900">
          {{ $fmtRp($cLT['value'] ?? 0) }}
        </div>

        <div class="mt-1 flex items-baseline justify-between gap-3">
          <div class="text-sm font-bold {{ $ltPack['deltaTone'] }}">
            Δ {{ $fmtRpDelta($cLT['delta'] ?? null) }}
          </div>

          <!-- <div class="text-sm font-bold {{ $pltTone }}">
            Δ %LT {{ $fmtPts($pltDelta) }}
          </div> -->
        </div>

        <div class="text-[11px] {{ $ltPack['hintTone'] }} mt-1">
          {{ $ltPack['hint'] }}
        </div>

        @if($toDpkNoa > 0)
          <div class="mt-2 text-[11px] text-amber-700">
            ⚠ Fokus: migrasi LT→DPK hari ini (potensi pindah FE)
          </div>
        @endif

        <div class="text-[11px] text-slate-500 mt-1">
          %LT = LT/OS · %LT turun = membaik
        </div>
      </div>

      {{-- DPK --}}
      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">DPK tgl terakhir</div>
        <div class="text-l font-extrabold text-slate-900">
          {{ $fmtRp($cDPK['value'] ?? 0) }}
        </div>
        <div class="mt-1 text-sm font-bold {{ (($cDPK['delta'] ?? 0) <= 0) ? 'text-emerald-700' : 'text-rose-700' }}">
          Δ {{ $fmtRpDelta($cDPK['delta'] ?? null) }}
        </div>
        <div class="text-[11px] text-slate-500 mt-1">
          DPK naik = memburuk
        </div>
      </div>

      {{-- META / COMPARE --}}
      <!-- <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Basis perbandingan</div>
        <div class="text-sm font-extrabold text-slate-900 mt-1">
          {{ $compareLabel ?? '-' }}
        </div>

        <div class="text-[11px] text-slate-500 mt-2">
          Latest: <b>{{ $latestDataDate ?? '-' }}</b><br>
          Compare: <b>{{ $prevDate ?? ($prevDataDate ?? '-') }}</b>
        </div>

        @php
          $snapWarn = (int)($bounce['jt_next2_noa'] ?? 0) > 0 || (int)($bounce['lt_to_dpk_noa'] ?? 0) > 0;
        @endphp

        @if($snapWarn)
          <div class="mt-2 text-[11px] text-amber-700">
            ⚠ Ada sinyal risiko di scope TL
          </div>
        @endif
      </div> -->

    </div>

    {{-- Risk panel TLRO --}}
    <!-- <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 md:col-span-2">
        <div class="font-bold text-slate-900">Kalimat TLRO (Interpretasi)</div>

        @php
          $l0Delta = (int)($cL0['delta'] ?? 0);
          $ltDelta = (int)($cLT['delta'] ?? 0);
          $rrDelta = (float)($cRR['delta'] ?? 0);

          $toneCls = 'bg-emerald-50 border-emerald-200 text-emerald-800';
          $toneTxt = 'Membaik';
          if ($l0Delta < 0 || $ltDelta > 0 || $rrDelta < 0) { $toneCls = 'bg-rose-50 border-rose-200 text-rose-800'; $toneTxt = 'Perlu perhatian'; }
          elseif ($hasSignal) { $toneCls = 'bg-amber-50 border-amber-200 text-amber-800'; $toneTxt = 'Waspada bounce'; }
        @endphp

        <div class="mt-2 inline-flex items-center rounded-xl border px-3 py-2 text-xs font-bold {{ $toneCls }}">
          Status: {{ $toneTxt }}
        </div>

        <div class="mt-3 text-sm text-slate-700 leading-relaxed">
          <ul class="list-disc pl-5 space-y-1">
            <li>
              <b>L0 growth positif</b> berarti kualitas pembayaran <b>membaik</b> (warna hijau).
            </li>
            <li>
              Namun, ada kondisi <b>perlu perhatian</b>: L0 naik karena <b>LT bayar lebih awal</b> dan <b>angsuran belum jatuh tempo</b>.
              Jika besok saat JT angsuran debitur tidak bayar, debitur bisa <b>balik LT (bounce back)</b> dan <b>RR turun lagi</b>.
            </li>
            <li>
              Fokus TL: debitur <b>JT H+1 s/d H+2</b>, terutama yang baru <b>Cure (LT→L0)</b> dan/atau masih ada sinyal risiko (DPD>0 / FT / kolek memburuk).
            </li>
          </ul>
        </div>
      </div> -->

      <!-- <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="font-bold text-slate-900">Bounce-back Snapshot</div>
        <div class="text-xs text-slate-500 mt-1">
          Indikator untuk membaca “L0 naik tapi rawan balik LT”.
        </div>

        <div class="mt-3 space-y-2 text-sm">
          <div class="flex items-center justify-between">
            <span class="text-slate-600">Cure LT→L0 (NOA)</span>
            <span class="font-extrabold text-slate-900">{{ (int)($bounce['lt_to_l0_noa'] ?? 0) }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-slate-600">Cure LT→L0 (OS)</span>
            <span class="font-extrabold text-slate-900">{{ $fmtRp((int)($bounce['lt_to_l0_os'] ?? 0)) }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-slate-600">JT Next2 (NOA)</span>
            <span class="font-extrabold text-slate-900">{{ (int)($bounce['jt_next2_noa'] ?? 0) }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-slate-600">JT Next2 (OS)</span>
            <span class="font-extrabold text-slate-900">{{ $fmtRp((int)($bounce['jt_next2_os'] ?? 0)) }}</span>
          </div>

          <div class="pt-2">
            @if($hasSignal)
              <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-800 text-xs font-bold">
                ⚠️ Sinyal: L0 ↑, LT ↓, dan ada JT Next2 → rawan bounce. Cek “Top Risiko Besok”.
              </div>
            @else
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-slate-700 text-xs font-semibold">
                Sinyal bounce tidak dominan (berdasarkan rule sederhana). Tetap cek Top Risiko Besok jika ada JT besar.
              </div>
            @endif
          </div>
        </div>
      </div>
    </div> -->

    {{-- ✅ Top Risiko Besok --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
      <div class="p-4 border-b border-slate-200 flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
          <div class="font-extrabold text-slate-900">🔥 Top Risiko Besok</div>
          <div class="text-xs text-slate-500 mt-1">
            Prioritas: (1) Cure LT→L0 yang JT dekat (rawan bounce), (2) JT dekat dengan DPD&gt;0 / masih FT.
          </div>
        </div>
        <div class="text-xs text-slate-500">
          Range JT: <b>{{ $jtNext2Start && $jtNext2End ? ($jtNext2Start.' s/d '.$jtNext2End) : '-' }}</b>
        </div>
      </div>

      <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50">
            <tr class="text-slate-700">
              <th class="text-left px-3 py-2">Due Date</th>
              <th class="text-left px-3 py-2">No Rek</th>
              <th class="text-left px-3 py-2">Nama</th>
              <th class="text-left px-3 py-2">AO</th>
              <th class="text-right px-3 py-2">OS</th>
              <th class="text-right px-3 py-2">DPD</th>
              <th class="text-right px-3 py-2">Kolek</th>
              <th class="text-center px-3 py-2">FT?</th>
              <th class="text-left px-3 py-2">Alasan</th>

              <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit</th>
              <th class="text-right px-3 py-2 whitespace-nowrap">Umur</th>
              <th class="text-center px-3 py-2 whitespace-nowrap">Plan</th>
              <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-200">
            @forelse(($topRiskTomorrow ?? []) as $r)
              @php
                $lastVisitRaw = $r->last_visit_date ?? null;
                $lastVisit = !empty($lastVisitRaw) ? \Carbon\Carbon::parse($lastVisitRaw)->format('d/m/Y') : '-';
                $age = null;
                try { $age = !empty($lastVisitRaw) ? \Carbon\Carbon::parse($lastVisitRaw)->diffInDays(now()) : null; } catch (\Throwable $e) { $age = null; }

                $planned = (int)($r->planned_today ?? 0) === 1;
                $status  = (string)($r->plan_status ?? '');
                $isDone  = $planned && $status === 'done';

                $planVisitDateRaw = $r->plan_visit_date ?? null;
                $planVisit = !empty($planVisitDateRaw) ? \Carbon\Carbon::parse($planVisitDateRaw)->format('d/m/Y') : '-';

                $acc = (string)($r->account_no ?? '');
                $ao  = (string)($r->ao_code ?? '');
                $os  = (int)($r->os ?? 0);

                // emphasis: cure+JT (risk_reason) atau DPD>0/FT
                $rowCls = str_contains((string)($r->risk_reason ?? ''), 'Cure') ? 'bg-amber-50/40' : 'bg-white';
                if ((int)($r->dpd ?? 0) >= 8) $rowCls = 'bg-rose-50/35';

                $due = !empty($r->due_date) ? \Carbon\Carbon::parse($r->due_date)->format('d/m/Y') : '-';
              @endphp

              <tr class="{{ $rowCls }}">
                <td class="px-3 py-2 whitespace-nowrap">
                  <span class="font-semibold text-slate-900">{{ $due }}</span>
                </td>
                <td class="px-3 py-2 font-mono">{{ $acc }}</td>
                <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
                <td class="px-3 py-2 font-mono">{{ $ao }}</td>
                <td class="px-3 py-2 text-right font-semibold text-slate-900">Rp {{ number_format($os,0,',','.') }}</td>
                <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
                <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>
                <td class="px-3 py-2 text-center">
                  @if((int)($r->ft_flag ?? 0) === 1)
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">FT</span>
                  @else
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-slate-50 text-slate-700 border border-slate-200">-</span>
                  @endif
                </td>
                <td class="px-3 py-2">
                  <span class="text-xs font-semibold text-slate-800">{{ $r->risk_reason ?? '-' }}</span>
                </td>

                <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  @if($age === null)
                    <span class="text-slate-400">-</span>
                  @else
                    <span class="{{ $age >= 14 ? 'text-rose-700 font-semibold' : ($age >= 7 ? 'text-amber-700 font-semibold' : 'text-slate-700') }}">
                      {{ $age }} hari
                    </span>
                  @endif
                </td>

                <td class="px-3 py-2 text-center whitespace-nowrap">
                  @if($isDone)
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                      bg-emerald-50 text-emerald-700 border border-emerald-200">Done</span>
                  @else
                    <button type="button"
                      class="btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border
                        {{ $planned ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-800 border-slate-200' }}"
                      data-acc="{{ $acc }}"
                      data-ao="{{ $ao }}"
                      data-checked="{{ $planned ? '1' : '0' }}">
                      {{ $planned ? 'Unplan' : 'Plan' }}
                    </button>
                  @endif
                </td>

                <td class="px-3 py-2 whitespace-nowrap">
                  <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="13" class="px-3 py-6 text-center text-slate-500">
                  Tidak ada kandidat “Top Risiko Besok” pada range JT ini.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>

        <div class="mt-3 text-[11px] text-slate-500">
          Catatan: “Top Risiko Besok” bukan berarti pasti gagal bayar. Ini shortlist supervisi untuk memastikan follow-up sebelum jatuh tempo.
        </div>
      </div>
    </div>

    <!-- {{-- ✅ Collapsible lists: Cure & JT Next2 --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      {{-- Cure LT->L0 --}}
      <details class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
        <summary class="cursor-pointer select-none p-4 border-b border-slate-200 flex items-center justify-between">
          <div>
            <div class="font-extrabold text-slate-900">✅ Cure LT→L0 (Top)</div>
            <div class="text-xs text-slate-500 mt-1">
              Debitur yang kemarin LT lalu hari ini L0 (by OS).
            </div>
          </div>
          <span class="text-xs font-bold text-slate-700">
            {{ (int)($bounce['lt_to_l0_noa'] ?? 0) }} NOA
          </span>
        </summary>

        <div class="p-4 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50">
              <tr class="text-slate-700">
                <th class="text-left px-3 py-2">No Rek</th>
                <th class="text-left px-3 py-2">Nama</th>
                <th class="text-left px-3 py-2">AO</th>
                <th class="text-right px-3 py-2">OS</th>
                <th class="text-right px-3 py-2">DPD</th>
                <th class="text-right px-3 py-2">Kolek</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              @forelse(($ltToL0List ?? []) as $r)
                <tr>
                  <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
                  <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
                  <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
                  <td class="px-3 py-2 text-right">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
                  <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
                  <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>
                </tr>
              @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">Tidak ada data cure.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </details>

      {{-- JT Next2 --}}
      <details class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
        <summary class="cursor-pointer select-none p-4 border-b border-slate-200 flex items-center justify-between">
          <div>
            <div class="font-extrabold text-slate-900">⏰ JT Next2 (Top)</div>
            <div class="text-xs text-slate-500 mt-1">
              JT H s/d H+2 (by OS), lintas bulan aman.
            </div>
          </div>
          <span class="text-xs font-bold text-slate-700">
            {{ (int)($bounce['jt_next2_noa'] ?? 0) }} NOA
          </span>
        </summary>

        <div class="p-4 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50">
              <tr class="text-slate-700">
                <th class="text-left px-3 py-2">Due</th>
                <th class="text-left px-3 py-2">No Rek</th>
                <th class="text-left px-3 py-2">Nama</th>
                <th class="text-left px-3 py-2">AO</th>
                <th class="text-right px-3 py-2">OS</th>
                <th class="text-right px-3 py-2">DPD</th>
                <th class="text-center px-3 py-2">FT?</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              @forelse(($jtNext2List ?? []) as $r)
                @php
                  $due = !empty($r->due_date) ? \Carbon\Carbon::parse($r->due_date)->format('d/m/Y') : '-';
                  $ft = ((int)($r->ft_pokok ?? 0) > 0 || (int)($r->ft_bunga ?? 0) > 0);
                @endphp
                <tr>
                  <td class="px-3 py-2 whitespace-nowrap">{{ $due }}</td>
                  <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
                  <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
                  <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
                  <td class="px-3 py-2 text-right">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
                  <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
                  <td class="px-3 py-2 text-center">
                    @if($ft)
                      <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">FT</span>
                    @else
                      <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-slate-50 text-slate-700 border border-slate-200">-</span>
                    @endif
                  </td>
                </tr>
              @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-slate-500">Tidak ada JT Next2.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </details>

       {{-- ✅ NEW: LT -> DPK --}}
      <details class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
        <summary class="cursor-pointer select-none p-4 border-b border-slate-200 flex items-center justify-between">
          <div>
            <div class="font-extrabold text-slate-900">🚨 LT→DPK (Top)</div>
            <div class="text-xs text-slate-500 mt-1">
              Debitur LT (EOM) yang naik jadi DPK (ft=2). Prioritas penanganan segera.
            </div>
          </div>
          <span class="text-xs font-bold text-rose-700">
            {{ (int)($bounce['lt_to_dpk_noa'] ?? 0) }} NOA
          </span>
        </summary>

        <div class="p-4">
          <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-rose-800 text-xs font-semibold">
            ⚠️ LT→DPK harus ditangani segera untuk mencegah penurunan OS & kualitas portofolio.
            Total OS terdampak: <b>{{ 'Rp ' . number_format((int)($bounce['lt_to_dpk_os'] ?? 0),0,',','.') }}</b>
          </div>

          <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50">
                <tr class="text-slate-700">
                  <th class="text-left px-3 py-2">No Rek</th>
                  <th class="text-left px-3 py-2">Nama</th>
                  <th class="text-left px-3 py-2">AO</th>
                  <th class="text-right px-3 py-2">OS</th>
                  <th class="text-right px-3 py-2">DPD</th>
                  <th class="text-right px-3 py-2">Kolek</th>
                  <th class="text-right px-3 py-2">FT P</th>
                  <th class="text-right px-3 py-2">FT B</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-200">
                @forelse(($ltToDpkList ?? []) as $r)
                  <tr class="bg-rose-50/20">
                    <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
                    <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
                    <td class="px-3 py-2 text-right font-semibold text-slate-900">
                      Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}
                    </td>
                    <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="8" class="px-3 py-6 text-center text-slate-500">
                      Tidak ada LT→DPK pada periode ini.
                    </td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </details>
    </div>
  </div> -->


  @php
    // ===== Helpers Supervisi (Blade side) =====
    $fmtDate = function($v){
      return !empty($v) ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '-';
    };

    $visitAgeDays = function($lastVisit){
      if (empty($lastVisit)) return null;
      try {
        return \Carbon\Carbon::parse($lastVisit)->diffInDays(now());
      } catch (\Throwable $e) {
        return null;
      }
    };

    $riskBadge = function($dpd, $kolek, $isLt = false){
      $dpd = (int)($dpd ?? 0);
      $kolek = (string)($kolek ?? '');
      $kolekNum = is_numeric($kolek) ? (int)$kolek : null;

      // LT selalu jadi prioritas
      if ($isLt) {
        return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">LT</span>';
      }

      // heuristik sederhana: DPD tinggi / kolek tinggi
      if ($dpd >= 30 || ($kolekNum !== null && $kolekNum >= 3)) {
        return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">High</span>';
      }
      if ($dpd >= 8 || ($kolekNum !== null && $kolekNum === 2)) {
        return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">Medium</span>';
      }
      return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">Low</span>';
    };
  @endphp

  {{-- ===========================
      TABLE STYLE NOTE:
      - Kolom supervisi diseragamkan:
        Last Visit | Umur (hari) | Plan (button) | Plan Date
      - Plan status: Done / Plan / Unplan
      =========================== --}}

  {{-- ===========================
      1) JT bulan ini
      =========================== --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900">Debitur Jatuh Tempo – {{ $dueMonthLabel ?? now()->translatedFormat('F Y') }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Sumber: maturity_date (tgl_jto). Scope mengikuti bawahan TL (atau 1 AO bila difilter).
      </div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <th class="text-left px-3 py-2">Jatuh Tempo</th>
            <!-- <th class="text-left px-3 py-2">No Rek</th> -->
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>

            <th class="text-left px-3 py-2 whitespace-nowrap">Risk</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">Umur Visit</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($dueThisMonth ?? []) as $r)
            @php
              $lastVisitRaw = $r->last_visit_date ?? null;
              $lastVisit = $fmtDate($lastVisitRaw);
              $age = $visitAgeDays($lastVisitRaw);

              $planned = (int)($r->planned_today ?? $r->visit_today ?? 0) === 1;
              $status  = (string)($r->plan_status ?? '');
              $isDone  = $planned && $status === 'done';

              $planVisitDateRaw = $r->plan_visit_date ?? null;
              $planVisit = $fmtDate($planVisitDateRaw);

              $acc = (string)($r->account_no ?? '');
              $ao  = (string)($r->ao_code ?? '');
              $os  = (int)($r->outstanding ?? 0);

              $rowEmphasis = ($os >= (int)($bigThreshold ?? 500000000) || (int)($r->dpd ?? 0) >= 8)
                ? 'bg-amber-50/30'
                : '';
            @endphp
            @php
                $rm = $riskMeta(
                  $r->dpd ?? 0,
                  $r->kolek ?? null,
                  $r->outstanding ?? ($r->os ?? 0),
                  $r->last_visit_date ?? null,
                  false
                );
              @endphp
            <tr class="{{ $rowEmphasis }}">
              <td class="px-3 py-2 whitespace-nowrap">
                {{ !empty($r->maturity_date) ? \Carbon\Carbon::parse($r->maturity_date)->format('d/m/Y') : '-' }}
              </td>
              <!-- <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td> -->
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format($os,0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>

              <td class="px-3 py-2 whitespace-nowrap">{!! $riskBadge($r->dpd ?? 0, $r->kolek ?? '-', false) !!}</td>

              <!-- <td class="px-3 py-2 whitespace-nowrap">
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold border {{ $rm['cls'] }}"
                  title="{{ $rm['tooltip'] }}"
                >
                  {{ $rm['level'] }}
                </span>
              </td> -->

              <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                @if($age === null)
                  <span class="text-slate-400">-</span>
                @else
                  <span class="{{ $age >= 14 ? 'text-rose-700 font-semibold' : ($age >= 7 ? 'text-amber-700 font-semibold' : 'text-slate-700') }}">
                    {{ number_format($age,0,',','.') }} hari
                  </span>
                @endif
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                @if($isDone)
                  <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                    bg-emerald-50 text-emerald-700 border border-emerald-200">Done</span>
                @else
                  <button type="button"
                    class="btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border
                      {{ $planned ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-800 border-slate-200' }}"
                    data-acc="{{ $acc }}"
                    data-ao="{{ $ao }}"
                    data-checked="{{ $planned ? '1' : '0' }}">
                    {{ $planned ? 'Unplan' : 'Plan' }}
                  </button>
                @endif
              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="12" class="px-3 py-6 text-center text-slate-500">
                Belum ada data jatuh tempo bulan ini.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- ===========================
      2) LT EOM bulan lalu (snapshot) -> status hari ini
      =========================== --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900">LT (EOM) → Posisi Hari Ini</div>
      <div class="text-xs text-slate-500 mt-1">
        Cohort: <b>LT di EOM</b> (snapshot bulan lalu) yaitu <code>m.ft_pokok = 1</code> atau <code>m.ft_bunga = 1</code>.
        Posisi hari ini: <b>{{ $latestPosDate ?? '-' }}</b>.
        <span class="ml-2">Catatan: <b>DPK</b> saat <code>ft_pokok/ft_bunga/kolek = 2</code> (potensi migrasi ke FE).</span>
      </div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <!-- <th class="text-left px-3 py-2">No Rek</th> -->
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>

            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>

            <th class="text-center px-3 py-2 whitespace-nowrap">Progres (EOM→H)</th>

            <th class="text-left px-3 py-2 whitespace-nowrap">Risk</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">Umur Visit</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($ltLatest ?? []) as $r)
            @php
              $lastVisitRaw = $r->last_visit_date ?? null;
              $lastVisit = $fmtDate($lastVisitRaw);
              $age = $visitAgeDays($lastVisitRaw);

              $planned = (int)($r->planned_today ?? $r->visit_today ?? 0) === 1;
              $status  = (string)($r->plan_status ?? '');
              $isDone  = $planned && $status === 'done';

              $planVisitDateRaw = $r->plan_visit_date ?? null;
              $planVisit = $fmtDate($planVisitDateRaw);

              $acc = (string)($r->account_no ?? '');
              $ao  = (string)($r->ao_code ?? '');

              $aoName = trim((string)($r->ao_name ?? ''));

              $os  = (int)($r->os ?? 0);

              $bucketFromFt = function($ftPokok, $ftBunga, $kolek){
                $fp = (int)($ftPokok ?? 0);
                $fb = (int)($ftBunga ?? 0);
                $k = (int)($kolek ?? 0);
                if ($fp === 2 || $fb === 2 || $k === 2) return 'DPK';
                if ($fp === 1 || $fb === 1) return 'LT';
                return 'L0';
              };

             $from = $bucketFromFt($r->eom_ft_pokok ?? 1, $r->eom_ft_bunga ?? 0, $r->eom_kolek ?? null);
             $to   = $bucketFromFt($r->ft_pokok ?? 0,      $r->ft_bunga ?? 0,      $r->kolek ?? null);

              $progressBadge = function($from, $to){
                if ($from === $to) return ['LT→LT', 'bg-slate-50 border-slate-200 text-slate-700'];
                if ($to === 'DPK') return ['LT→DPK', 'bg-rose-50 border-rose-200 text-rose-700'];
                if ($to === 'L0')  return ['LT→L0',  'bg-emerald-50 border-emerald-200 text-emerald-700'];
                return ["LT→{$to}", 'bg-slate-50 border-slate-200 text-slate-700'];
              };

              [$progTxt, $progCls] = $progressBadge($from, $to);

              $rowCls = ($to === 'DPK')
                ? 'bg-rose-50/35'
                : (($to === 'L0') ? 'bg-emerald-50/25' : 'bg-white');
            @endphp

            <tr class="{{ $rowCls }}">
              <!-- <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td> -->
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>

              <td class="px-3 py-2">
                @if($aoName !== '')
                  <div class="font-semibold text-slate-900">{{ $aoName }}</div>
                  <div class="text-[11px] text-slate-500 font-mono">{{ $ao !== '' ? $ao : '-' }}</div>
                @else
                  <span class="font-mono">{{ $ao !== '' ? $ao : '-' }}</span>
                @endif
              </td>

              <td class="px-3 py-2 text-right">Rp {{ number_format($os,0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-bold {{ $progCls }}">
                  {{ $progTxt }}
                </span>
              </td>

              <td class="px-3 py-2 whitespace-nowrap">{!! $riskBadge($r->dpd ?? 0, $r->kolek ?? '-', true) !!}</td>

              <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                @if($age === null)
                  <span class="text-slate-400">-</span>
                @else
                  <span class="{{ $age >= 14 ? 'text-rose-700 font-semibold' : ($age >= 7 ? 'text-amber-700 font-semibold' : 'text-slate-700') }}">
                    {{ $age }} hari
                  </span>
                @endif
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                @if($isDone)
                  <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                    bg-emerald-50 text-emerald-700 border border-emerald-200">Done</span>
                @else
                  <button type="button"
                    class="btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border
                      {{ $planned ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-800 border border-slate-200' }}"
                    data-acc="{{ $acc }}"
                    data-ao="{{ $ao }}"
                    data-checked="{{ $planned ? '1' : '0' }}">
                    {{ $planned ? 'Unplan' : 'Plan' }}
                  </button>
                @endif
              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="14" class="px-3 py-6 text-center text-slate-500">
                Tidak ada data <b>LT EOM</b> untuk snapshot bulan lalu.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- ===========================
      3) L0 -> LT bulan ini
      =========================== --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="text-lg font-extrabold text-slate-900">L0 → LT Bulan Ini</div>
      <div class="text-sm text-slate-500 mt-1">
        Pembanding: snapshot bulan lalu <b>{{ \Carbon\Carbon::parse($prevSnapMonth)->format('Y-m-d') }}</b>
        → posisi terakhir <b>{{ $latestPosDate }}</b>.
      </div>
      <div class="text-xs text-slate-500 mt-1">
        Definisi L0: bulan lalu ft_pokok=0 & ft_bunga=0, lalu sekarang FT &gt; 0.
      </div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <!-- <th class="text-left px-3 py-2">No Rek</th> -->
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>

            <th class="text-left px-3 py-2 whitespace-nowrap">Risk</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">Umur Visit</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse($migrasiTunggakan as $r)
            @php
              $lastVisitRaw = $r->last_visit_date ?? null;
              $lastVisit = $fmtDate($lastVisitRaw);
              $age = $visitAgeDays($lastVisitRaw);

              $planned = (int)($r->planned_today ?? $r->visit_today ?? 0) === 1;
              $status  = (string)($r->plan_status ?? '');
              $isDone  = $planned && $status === 'done';

              $planVisitDateRaw = $r->plan_visit_date ?? null;
              $planVisit = $fmtDate($planVisitDateRaw);

              $acc = (string)($r->account_no ?? '');
              $ao  = (string)($r->ao_code ?? '');
              $os  = (int)($r->os ?? 0);
            @endphp
            <tr>
              <!-- <td class="px-3 py-2 font-mono">{{ $r->account_no }}</td> -->
              <td class="px-3 py-2">{{ $r->customer_name }}</td>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format($os,0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->kolek ?? 0) }}</td>

              <td class="px-3 py-2 whitespace-nowrap">{!! $riskBadge($r->dpd ?? 0, $r->kolek ?? '-', false) !!}</td>

              <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                @if($age === null)
                  <span class="text-slate-400">-</span>
                @else
                  <span class="{{ $age >= 14 ? 'text-rose-700 font-semibold' : ($age >= 7 ? 'text-amber-700 font-semibold' : 'text-slate-700') }}">
                    {{ $age }} hari
                  </span>
                @endif
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                @if($isDone)
                  <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                    bg-emerald-50 text-emerald-700 border border-emerald-200">Done</span>
                @else
                  <button type="button"
                    class="btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border
                      {{ $planned ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-800 border border-slate-200' }}"
                    data-acc="{{ $acc }}"
                    data-ao="{{ $ao }}"
                    data-checked="{{ $planned ? '1' : '0' }}">
                    {{ $planned ? 'Unplan' : 'Plan' }}
                  </button>
                @endif
              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="13" class="px-3 py-6 text-center text-slate-500">
                Belum ada data L0 → LT untuk periode ini.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>

      <div class="mt-3">
        {{ $migrasiTunggakan->onEachSide(1)->links() }}
      </div>
    </div>
  </div>

  {{-- ===========================
      4) JT angsuran minggu ini
      =========================== --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900">
        JT Angsuran Minggu Ini
        @if(!empty($weekStart) && !empty($weekEnd))
          <span class="text-slate-500 font-normal text-sm">({{ $weekStart }} s/d {{ $weekEnd }})</span>
        @endif
      </div>
      <div class="text-xs text-slate-500 mt-1">
        Sumber: installment_day. Dibaca terhadap posisi terakhir: <b>{{ $latestPosDate ?? '-' }}</b>.
      </div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <th class="text-left px-3 py-2">JT (Tanggal)</th>
            <!-- <th class="text-left px-3 py-2">No Rek</th> -->
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>

            <th class="text-left px-3 py-2 whitespace-nowrap">Risk</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">Umur Visit</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($jtAngsuran ?? []) as $r)
            @php
              $lastVisitRaw = $r->last_visit_date ?? null;
              $lastVisit = $fmtDate($lastVisitRaw);
              $age = $visitAgeDays($lastVisitRaw);

              $planned = (int)($r->planned_today ?? $r->visit_today ?? 0) === 1;
              $status  = (string)($r->plan_status ?? '');
              $isDone  = $planned && $status === 'done';

              $planVisitDateRaw = $r->plan_visit_date ?? null;
              $planVisit = $fmtDate($planVisitDateRaw);

              $acc = (string)($r->account_no ?? '');
              $ao  = (string)($r->ao_code ?? '');
              $os  = (int)($r->os ?? 0);
            @endphp
            <tr>
              <td class="px-3 py-2 whitespace-nowrap">
                {{ !empty($r->due_date) ? \Carbon\Carbon::parse($r->due_date)->format('d/m/Y') : '-' }}
              </td>
              <!-- <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td> -->
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format($os,0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->kolek ?? 0) }}</td>

              <td class="px-3 py-2 whitespace-nowrap">{!! $riskBadge($r->dpd ?? 0, $r->kolek ?? '-', false) !!}</td>

              <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                @if($age === null)
                  <span class="text-slate-400">-</span>
                @else
                  <span class="{{ $age >= 14 ? 'text-rose-700 font-semibold' : ($age >= 7 ? 'text-amber-700 font-semibold' : 'text-slate-700') }}">
                    {{ $age }} hari
                  </span>
                @endif
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                @if($isDone)
                  <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                    bg-emerald-50 text-emerald-700 border border-emerald-200">Done</span>
                @else
                  <button type="button"
                    class="btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border
                      {{ $planned ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-800 border border-slate-200' }}"
                    data-acc="{{ $acc }}"
                    data-ao="{{ $ao }}"
                    data-checked="{{ $planned ? '1' : '0' }}">
                    {{ $planned ? 'Unplan' : 'Plan' }}
                  </button>
                @endif
              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="14" class="px-3 py-6 text-center text-slate-500">
                Tidak ada JT angsuran minggu ini.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- ===========================
      5) OS > threshold
      =========================== --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900">OS ≥ {{ number_format((int)($bigThreshold ?? 500000000),0,',','.') }} – Posisi Terakhir</div>
      <div class="text-xs text-slate-500 mt-1">
        Posisi terakhir: <b>{{ $latestPosDate ?? '-' }}</b>.
      </div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <!-- <th class="text-left px-3 py-2">No Rek</th> -->
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>

            <th class="text-left px-3 py-2 whitespace-nowrap">Risk</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">Umur Visit</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($osBig ?? []) as $r)
            @php
              $lastVisitRaw = $r->last_visit_date ?? null;
              $lastVisit = $fmtDate($lastVisitRaw);
              $age = $visitAgeDays($lastVisitRaw);

              $planned = (int)($r->planned_today ?? $r->visit_today ?? 0) === 1;
              $status  = (string)($r->plan_status ?? '');
              $isDone  = $planned && $status === 'done';

              $planVisitDateRaw = $r->plan_visit_date ?? null;
              $planVisit = $fmtDate($planVisitDateRaw);

              $acc = (string)($r->account_no ?? '');
              $ao  = (string)($r->ao_code ?? '');
              $os  = (int)($r->os ?? 0);
            @endphp
            <tr class="bg-slate-50/40">
              <!-- <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td> -->
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
              <td class="px-3 py-2 text-right font-semibold text-slate-900">Rp {{ number_format($os,0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->kolek ?? 0) }}</td>

              <td class="px-3 py-2 whitespace-nowrap">{!! $riskBadge($r->dpd ?? 0, $r->kolek ?? '-', false) !!}</td>

              <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                @if($age === null)
                  <span class="text-slate-400">-</span>
                @else
                  <span class="{{ $age >= 14 ? 'text-rose-700 font-semibold' : ($age >= 7 ? 'text-amber-700 font-semibold' : 'text-slate-700') }}">
                    {{ $age }} hari
                  </span>
                @endif
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                @if($isDone)
                  <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                    bg-emerald-50 text-emerald-700 border border-emerald-200">Done</span>
                @else
                  <button type="button"
                    class="btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border
                      {{ $planned ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-800 border border-slate-200' }}"
                    data-acc="{{ $acc }}"
                    data-ao="{{ $ao }}"
                    data-checked="{{ $planned ? '1' : '0' }}">
                    {{ $planned ? 'Unplan' : 'Plan' }}
                  </button>
                @endif
              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="13" class="px-3 py-6 text-center text-slate-500">
                Tidak ada OS besar pada posisi terakhir.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>

{{-- Chart.js --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
<script>
  const labels = @json($labels ?? []);
  const datasetsByMetric = @json($datasetsByMetric ?? []);

  // ===== Helpers =====
  const isNil = (v) => v === null || typeof v === 'undefined';
  const fmtRp = (v) => 'Rp ' + Number(v || 0).toLocaleString('id-ID');
  const fmtPct = (v) => Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + '%';
  const isPercentMetric = (m) => (m === 'rr' || m === 'pct_lt');

  Chart.register(ChartDataLabels);

  function fmtCompactRp(v){
    const n = Number(v || 0);
    const abs = Math.abs(n);
    if (abs >= 1e12) return (n/1e12).toFixed(2).replace('.',',') + 'T';
    if (abs >= 1e9)  return (n/1e9 ).toFixed(2).replace('.',',') + 'M';
    if (abs >= 1e6)  return (n/1e6 ).toFixed(1).replace('.',',') + 'jt';
    return n.toLocaleString('id-ID');
  }

  function lastIndexNonNull(arr) {
    for (let i = (arr?.length || 0) - 1; i >= 0; i--) {
      if (!isNil(arr[i])) return i;
    }
    return -1;
  }

  function toGrowthSeries(arr) {
    const out = [];
    for (let i = 0; i < arr.length; i++) {
      const cur = arr[i];
      const prev = i > 0 ? arr[i-1] : null;
      if (isNil(cur) || isNil(prev)) out.push(null);
      else out.push(Number(cur) - Number(prev));
    }
    return out;
  }

  function anomalyThreshold(metricKey) {
    if (metricKey === 'rr' || metricKey === 'pct_lt') return 2; // 2% threshold
    return 500000000; // 500jt untuk growth OS
  }

  function isAnomalyPoint(metricKey, value) {
    if (isNil(value)) return false;
    const thr = anomalyThreshold(metricKey);
    return Math.abs(Number(value)) >= thr;
  }

  // ===== State =====
  let metric = 'os_total';
  let mode = 'value'; // value|growth
  let showAllLines = false; // default ringkas di HP
  let showAllPointLabels = false; // default: hanya label titik terakhir
  let showTotalLine = true; // default ON biar feel “head office”

  const isMobile = () => window.matchMedia('(max-width: 639px)').matches;

  function getRawDatasets() {
    return (datasetsByMetric && datasetsByMetric[metric]) ? datasetsByMetric[metric] : [];
  }

  function applyMobileDatasetRules(datasets) {
    if (!isMobile()) return datasets;
    if (showAllLines) return datasets.map(ds => ({ ...ds, hidden: false }));

    const maxLines = isPercentMetric(metric) ? 2 : 3;
    return datasets.map((ds, idx) => ({ ...ds, hidden: idx >= maxLines }));
  }

  function sumAtIndex(metricKey, idx) {
    const sets = (datasetsByMetric && datasetsByMetric[metricKey]) ? datasetsByMetric[metricKey] : [];
    let sum = 0;
    let hasAny = false;

    for (const ds of sets) {
      const v = ds?.data?.[idx];
      if (!isNil(v)) {
        sum += Number(v);
        hasAny = true;
      }
    }

    return hasAny ? sum : null;
  }

  function seriesSum(metricKey) {
    const sets = (datasetsByMetric && datasetsByMetric[metricKey]) ? datasetsByMetric[metricKey] : [];
    const n = labels?.length || 0;
    const out = new Array(n).fill(null);

    for (let i = 0; i < n; i++) {
      let sum = 0;
      let has = false;
      for (const ds of sets) {
        const v = ds?.data?.[i];
        if (!isNil(v)) { sum += Number(v); has = true; }
      }
      out[i] = has ? sum : null;
    }
    return out;
  }

  function topContributorAtIndex(metricKey, idx) {
    const sets = (datasetsByMetric && datasetsByMetric[metricKey]) ? datasetsByMetric[metricKey] : [];
    let best = null;
    for (const ds of sets) {
      const v = ds?.data?.[idx];
      if (isNil(v)) continue;
      if (!best || Number(v) > Number(best.value)) {
        best = { label: ds.label || 'Series', value: Number(v) };
      }
    }
    return best;
  }

  function findLastIndexWithAnyDataForMetric(metricKey) {
    const sets = (datasetsByMetric && datasetsByMetric[metricKey]) ? datasetsByMetric[metricKey] : [];
    const n = labels?.length || 0;
    for (let i = n - 1; i >= 0; i--) {
      let has = false;
      for (const ds of sets) {
        if (!isNil(ds?.data?.[i])) { has = true; break; }
      }
      if (has) return i;
    }
    return null;
  }

  function findLastIndexWithAnyData() {
    const n = labels?.length || 0;
    for (let i = n - 1; i >= 0; i--) {
      const t = sumAtIndex('os_total', i);
      const l0 = sumAtIndex('os_l0', i);
      const lt = sumAtIndex('os_lt', i);
      if (!isNil(t) || !isNil(l0) || !isNil(lt)) return i;
    }
    return null;
  }

  function buildDatasets() {
    const raw = getRawDatasets();
    const lastIdx = findLastIndexWithAnyDataForMetric(metric);
    const top = (!isNil(lastIdx) ? topContributorAtIndex(metric, lastIdx) : null);

    let ds = raw.map((ds) => {
      const base = ds.data || [];
      const data = (mode === 'growth') ? toGrowthSeries(base) : base;
      const isTop = (top && (ds.label === top.label));

      return {
        label: ds.label || 'Series',
        data,
        spanGaps: false,
        tension: 0.2,

        pointBorderWidth: (ctx) => {
          const v = ctx.raw;
          const i = ctx.dataIndex;

          if (top && (ds.label === top.label) && i === lastIdx) return 3;
          if (mode === 'growth' && isAnomalyPoint(metric, v)) return 3;
          return 2;
        },

        pointRadius: (ctx) => {
          const v = ctx.raw;
          const i = ctx.dataIndex;

          if (top && (ds.label === top.label) && i === lastIdx) return (isMobile() ? 5 : 6);
          if (mode === 'growth' && isAnomalyPoint(metric, v)) return (isMobile() ? 4 : 5);
          return (isMobile() ? 2.5 : 3);
        },

        pointHoverRadius: isMobile() ? 4 : 5,
        borderWidth: isTop ? 3 : 2,
      };
    });

    if (showTotalLine) {
      const totalBase = seriesSum(metric);
      const totalData = (mode === 'growth') ? toGrowthSeries(totalBase) : totalBase;

      ds.unshift({
        label: 'TOTAL TL',
        data: totalData,
        spanGaps: false,
        tension: 0.25,
        borderWidth: 3,
        pointRadius: isMobile() ? 3 : 3.5,
        pointHoverRadius: isMobile() ? 5 : 6,
        pointBorderWidth: 2,
      });
    }

    ds = applyMobileDatasetRules(ds);
    return ds;
  }

  function updateKpiStrip() {
    const idx = findLastIndexWithAnyData();

    if (isNil(idx)) {
      document.getElementById('kpiLatestOs').textContent = '-';
      document.getElementById('kpiLatestL0').textContent = '-';
      document.getElementById('kpiLatestLT').textContent = '-';
      document.getElementById('kpiLatestRR').textContent = '-';
      document.getElementById('kpiLatestPctLT').textContent = '-';
      return;
    }

    const os = sumAtIndex('os_total', idx);
    const l0 = sumAtIndex('os_l0', idx);
    const lt = sumAtIndex('os_lt', idx);

    const rr = (!isNil(os) && os > 0 && !isNil(l0)) ? (Number(l0) / Number(os)) * 100 : null;
    const pctlt = (!isNil(os) && os > 0 && !isNil(lt)) ? (Number(lt) / Number(os)) * 100 : null;

    document.getElementById('kpiLatestOs').textContent = isNil(os) ? '-' : fmtRp(os);
    document.getElementById('kpiLatestL0').textContent = isNil(l0) ? '-' : fmtRp(l0);
    document.getElementById('kpiLatestLT').textContent = isNil(lt) ? '-' : fmtRp(lt);
    document.getElementById('kpiLatestRR').textContent = isNil(rr) ? '-' : fmtPct(rr);
    document.getElementById('kpiLatestPctLT').textContent = isNil(pctlt) ? '-' : fmtPct(pctlt);
  }

  // ===== Init Chart =====
  const canvas = document.getElementById('osChart');

  const chart = new Chart(canvas.getContext('2d'), {
    type: 'line',
    data: { labels, datasets: buildDatasets() },
    plugins: [ChartDataLabels],
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },

      plugins: {
        legend: {
          display: true,
          position: 'bottom',
          labels: {
            usePointStyle: true,
            pointStyle: 'line',
            boxWidth: isMobile() ? 10 : 12,
            boxHeight: isMobile() ? 10 : 12,
            padding: isMobile() ? 10 : 14,
            font: { size: isMobile() ? 10 : 12 },
          },
        },

        tooltip: {
          callbacks: {
            label: function (ctx) {
              const v = ctx.raw;
              if (isNil(v)) return `${ctx.dataset.label}: (no data)`;
              const pct = isPercentMetric(metric);
              if (mode === 'growth') {
                const sign = Number(v) >= 0 ? '+' : '';
                return `${ctx.dataset.label}: ${sign}${pct ? fmtPct(v) : fmtRp(v)}`;
              }
              return `${ctx.dataset.label}: ${pct ? fmtPct(v) : fmtRp(v)}`;
            },
          },
        },

        datalabels: {
          anchor: 'end',
          align: 'top',
          offset: 6,
          clamp: true,
          clip: false,
          font: { size: isMobile() ? 9 : 10, weight: '600' },

          display: function(ctx){
            const v = ctx.dataset?.data?.[ctx.dataIndex];
            if (isNil(v)) return false;

            if (showAllPointLabels) {
              if (isMobile()) return (ctx.dataIndex % 2 === 0);
              return true;
            }

            const data = ctx.dataset.data || [];
            const li = lastIndexNonNull(data);
            return li >= 0 && ctx.dataIndex === li;
          },

          formatter: function (value) {
            if (isNil(value)) return '';
            const pct = isPercentMetric(metric);
            if (pct) return Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + '%';
            return fmtCompactRp(value);
          },
        },
      },

      scales: {
        x: {
          ticks: {
            autoSkip: true,
            maxTicksLimit: isMobile() ? 6 : 14,
            maxRotation: isMobile() ? 45 : 0,
            minRotation: isMobile() ? 45 : 0,
            font: { size: isMobile() ? 10 : 11 },
          },
          grid: { display: !isMobile() },
        },
        y: {
          ticks: {
            font: { size: isMobile() ? 10 : 11 },
            callback: (v) => {
              if (isMobile()) return '';
              const pct = isPercentMetric(metric);
              if (mode === 'growth') {
                const sign = Number(v) >= 0 ? '+' : '';
                return sign + (pct ? fmtPct(v) : ('Rp ' + Number(v).toLocaleString('id-ID')));
              }
              return pct ? fmtPct(v) : ('Rp ' + Number(v).toLocaleString('id-ID'));
            },
          },
        },
      },
    },
  });

  function repaintModeButtons() {
    const btnValue = document.getElementById('btnModeValue');
    const btnGrowth = document.getElementById('btnModeGrowth');

    if (mode === 'value') {
      btnValue.className  = 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
      btnGrowth.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
    } else {
      btnValue.className  = 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
      btnGrowth.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
    }
  }

  function repaintShowAllButton() {
    const btn = document.getElementById('btnShowAllLines');
    if (!btn) return;

    btn.textContent = showAllLines ? 'Tampilkan ringkas' : 'Tampilkan semua garis';
    btn.className = showAllLines
      ? 'w-full rounded-xl border border-slate-200 bg-slate-900 px-3 py-2 text-xs font-semibold text-white'
      : 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-800';
  }

  function repaintMetricButtons() {
    const map = {
      os_total: 'btnMetricTotal',
      os_l0: 'btnMetricL0',
      os_lt: 'btnMetricLT',
      rr: 'btnMetricRR',
      pct_lt: 'btnMetricPctLT',
    };

    Object.entries(map).forEach(([m, id]) => {
      const el = document.getElementById(id);
      if (!el) return;

      el.className = (m === metric)
        ? 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200'
        : 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
    });
  }

  function repaintLabelButtons() {
    const btnLast = document.getElementById('btnLabelsLastOnly');
    const btnAll  = document.getElementById('btnLabelsAll');
    if (!btnLast || !btnAll) return;

    if (!showAllPointLabels) {
      btnLast.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
      btnAll.className  = 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
    } else {
      btnLast.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
      btnAll.className  = 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
    }
  }

  function refreshChart() {
    chart.data.datasets = buildDatasets();
    chart.update();
    updateKpiStrip();
  }

  document.getElementById('btnModeValue')?.addEventListener('click', () => {
    mode = 'value';
    repaintModeButtons();
    refreshChart();
  });

  document.getElementById('btnModeGrowth')?.addEventListener('click', () => {
    mode = 'growth';
    repaintModeButtons();
    refreshChart();
  });

  document.querySelectorAll('[data-metric]')?.forEach(btn => {
    btn.addEventListener('click', () => {
      metric = btn.getAttribute('data-metric');
      if (isMobile()) { showAllLines = false; repaintShowAllButton(); }
      repaintMetricButtons();
      refreshChart();
    });
  });

  document.getElementById('btnShowAllLines')?.addEventListener('click', () => {
    showAllLines = !showAllLines;
    repaintShowAllButton();
    refreshChart();
  });

  document.getElementById('btnLabelsLastOnly')?.addEventListener('click', () => {
    showAllPointLabels = false;
    repaintLabelButtons();
    refreshChart();
  });

  document.getElementById('btnLabelsAll')?.addEventListener('click', () => {
    showAllPointLabels = true;
    repaintLabelButtons();
    refreshChart();
  });

  repaintMetricButtons();
  repaintModeButtons();
  repaintShowAllButton();
  repaintLabelButtons();
  updateKpiStrip();

  let __resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(__resizeTimer);
    __resizeTimer = setTimeout(() => refreshChart(), 200);
  });

  // =========================
  // ✅ Plan Visit (AJAX) - Consistent Button
  // =========================
  const toggleUrl = @json(route('ro_visits.toggle'));
  const csrf = @json(csrf_token());

  function formatPlanDate(planDateYmd) {
    if (!planDateYmd) return '-';
    const d = new Date(planDateYmd + 'T00:00:00');
    const dd = String(d.getDate()).padStart(2,'0');
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const yy = d.getFullYear();
    return `${dd}/${mm}/${yy}`;
  }

  function setPlanUi(accountNo, checked, locked, planDateYmd) {
    document.querySelectorAll(`.btnPlanVisit[data-acc="${CSS.escape(accountNo)}"]`).forEach(btn => {
      btn.dataset.checked = checked ? '1' : '0';
      btn.disabled = !!locked;

      if (checked) {
        btn.textContent = 'Unplan';
        btn.className = 'btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border bg-slate-900 text-white border-slate-900';
      } else {
        btn.textContent = 'Plan';
        btn.className = 'btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border bg-white text-slate-800 border-slate-200';
      }

      if (locked) {
        btn.className += ' opacity-60 cursor-not-allowed';
      }
    });

    const planText = formatPlanDate(planDateYmd);
    document.querySelectorAll(`.ro-plan-date[data-account="${CSS.escape(accountNo)}"]`).forEach(el => {
      el.textContent = planText;
    });
  }

  async function postToggle(accountNo, aoCode, checked) {
    const res = await fetch(toggleUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf,
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        account_no: accountNo,
        ao_code: aoCode || null,
        checked: !!checked,
        source: 'dashboard',
      }),
    });

    if (!res.ok) {
      const txt = await res.text();
      throw new Error(txt || 'Request failed');
    }
    return await res.json();
  }

  function bindPlanButtons() {
    document.querySelectorAll('.btnPlanVisit').forEach(btn => {
      btn.addEventListener('click', async () => {
        const accountNo = btn.getAttribute('data-acc') || '';
        const aoCode = btn.getAttribute('data-ao') || '';
        const currentlyChecked = (btn.dataset.checked === '1');
        const nextChecked = !currentlyChecked;

        btn.disabled = true;

        try {
          const json = await postToggle(accountNo, aoCode, nextChecked);
          setPlanUi(accountNo, json.checked, json.locked, json.plan_date);
        } catch (err) {
          btn.disabled = false;
          alert('Gagal update plan visit. Coba refresh halaman.\n\n' + (err?.message || err));
        }
      });
    });
  }

  bindPlanButtons();
</script>

@endsection
