@extends('layouts.app')

@section('title', 'Dashboard RO - OS Harian')

@section('content')
@php
  $meAo = str_pad(trim((string)(auth()->user()?->ao_code ?? '')), 6, '0', STR_PAD_LEFT);
  if ($meAo === '000000') $meAo = '';

  // =============================
  // Helpers view (format & badge)
  // =============================
  $fmtRpFull = fn($n) => 'Rp ' . number_format((int)($n ?? 0), 0, ',', '.');

  $fmtRpCompact = function ($n) {
      $n = (float)($n ?? 0);
      $abs = abs($n);
      if ($abs >= 1e12) return number_format($n/1e12, 2, ',', '.') . 'T';
      if ($abs >= 1e9)  return number_format($n/1e9, 2, ',', '.') . 'M';
      if ($abs >= 1e6)  return number_format($n/1e6, 1, ',', '.') . 'jt';
      if ($abs >= 1e3)  return number_format($n/1e3, 1, ',', '.') . 'rb';
      return number_format($n, 0, ',', '.');
  };

  $fmtPct = fn($v) => is_null($v) ? '-' : number_format((float)$v, 2, ',', '.') . '%';

  $mode = request('mode', 'mtd'); // default MtoD
  if (!in_array($mode, ['daily','mtd'], true)) $mode = 'mtd';

  // sign display
  $fmtDeltaRp = function ($delta) use ($fmtRpCompact) {
      if (is_null($delta)) return '-';
      $d = (float)$delta;
      $sign = $d > 0 ? '+' : '';
      return $sign . $fmtRpCompact($d);
  };

  $fmtDeltaPts = function ($deltaPts) {
      if (is_null($deltaPts)) return '-';
      $d = (float)$deltaPts;
      $sign = $d > 0 ? '+' : '';
      return $sign . number_format($d, 2, ',', '.') . ' pts';
  };

  // ========= bucket helper (L0 / LT / DPK) =========
  // DPK: ft_pokok=2 atau ft_bunga=2
  // LT : ft_pokok=1 atau ft_bunga=1
  // L0 : selain itu
  $bucketFromFt = function ($ftPokok, $ftBunga) {
      $fp = (int)($ftPokok ?? 0);
      $fb = (int)($ftBunga ?? 0);
      if ($fp === 2 || $fb === 2) return 'DPK';
      if ($fp === 1 || $fb === 1) return 'LT';
      return 'L0';
  };

  // ========= progress badge builder =========
  // expects prev_ft_pokok/prev_ft_bunga from controller; fallback '-' if not available
  $progressText = function ($r) use ($bucketFromFt) {
      $hasPrev = isset($r->prev_ft_pokok) || isset($r->prev_ft_bunga) || isset($r->prev_bucket);
      if (!$hasPrev) return '-';

      $prev = isset($r->prev_bucket)
        ? (string)($r->prev_bucket ?? '-')
        : $bucketFromFt($r->prev_ft_pokok ?? 0, $r->prev_ft_bunga ?? 0);

      $cur = isset($r->cur_bucket)
        ? (string)($r->cur_bucket ?? '-')
        : $bucketFromFt($r->ft_pokok ?? 0, $r->ft_bunga ?? 0);

      if ($prev === '-' || $cur === '-') return '-';
      if ($prev === $cur) return $cur; // tetap

      return $prev . '→' . $cur;
  };

  $progressBadgeClass = function (string $prog) {
      if ($prog === '-' || $prog === '') return 'bg-slate-50 border-slate-200 text-slate-600';

      // worsening
      if (str_contains($prog, 'L0→LT') || str_contains($prog, 'LT→DPK') || str_contains($prog, 'L0→DPK')) {
          return 'bg-rose-50 border-rose-200 text-rose-700';
      }

      // improving
      if (str_contains($prog, 'DPK→LT') || str_contains($prog, 'LT→L0') || str_contains($prog, 'DPK→L0')) {
          return 'bg-emerald-50 border-emerald-200 text-emerald-700';
      }

      // stable
      if ($prog === 'L0' || $prog === 'LT' || $prog === 'DPK') {
          return 'bg-slate-50 border-slate-200 text-slate-700';
      }

      return 'bg-slate-50 border-slate-200 text-slate-600';
  };

  // color logic per KPI
  // returns [textClass, bgClass]
  $deltaTone = function (string $key, $delta) {
      if (is_null($delta) || (is_numeric($delta) && (float)$delta == 0.0)) {
          return ['text-slate-600', 'bg-slate-50 border-slate-200'];
      }
      $up = ((float)$delta) > 0;

      if ($key === 'os') return $up ? ['text-emerald-700', 'bg-emerald-50 border-emerald-200'] : ['text-rose-700', 'bg-rose-50 border-rose-200'];
      if ($key === 'l0') return $up ? ['text-emerald-700', 'bg-emerald-50 border-emerald-200'] : ['text-rose-700', 'bg-rose-50 border-rose-200'];
      if ($key === 'lt') return $up ? ['text-rose-700', 'bg-rose-50 border-rose-200'] : ['text-emerald-700', 'bg-emerald-50 border-emerald-200'];
      if ($key === 'rr') return $up ? ['text-emerald-700', 'bg-emerald-50 border-emerald-200'] : ['text-rose-700', 'bg-rose-50 border-rose-200'];
      if ($key === 'pct_lt') return $up ? ['text-rose-700', 'bg-rose-50 border-rose-200'] : ['text-emerald-700', 'bg-emerald-50 border-emerald-200'];

      return ['text-slate-600', 'bg-slate-50 border-slate-200'];
  };

  // ========= hint text helper (dipakai di card) =========
  $deltaHint = function(string $metric, $delta): string {
      if (is_null($delta)) return 'prev n/a';
      $d = (float) $delta;

      // naik = baik
      if (in_array($metric, ['os','l0','rr'], true)) {
          if ($d > 0) return $metric === 'rr' ? 'RR naik = membaik' : strtoupper($metric).' naik = membaik';
          if ($d < 0) return $metric === 'rr' ? 'RR turun = memburuk' : strtoupper($metric).' turun = memburuk';
          return 'stagnan';
      }

      // naik = buruk
      if (in_array($metric, ['lt','pct_lt'], true)) {
          if ($d > 0) return $metric === 'lt' ? 'LT naik = memburuk' : '%LT naik = memburuk';
          if ($d < 0) return $metric === 'lt' ? 'LT turun = membaik'  : '%LT turun = membaik';
          return 'stagnan';
      }

      return '—';
  };

  // ========= Smart LT pack: kalau LT turun tapi ada migrasi LT->DPK, jangan tampil "membaik" hijau =========
  // note: $toDpkNoa/$toDpkOs akan kita hitung di bagian Summary Cards, lalu dipassing via $bounce + fallback.
  $ltSmartPack = function($deltaLt, int $toDpkNoa, int $toDpkOs) {
      if (is_null($deltaLt)) {
          return [
            'hint' => 'prev n/a',
            'tone' => 'text-slate-500',
            'forceBg' => null,
          ];
      }

      $d = (float)$deltaLt;

      // KRITIKAL: LT turun bukan karena cure, tapi "pindah bucket" jadi DPK
      if ($d < 0 && $toDpkNoa > 0) {
          return [
            'hint' => "LT turun, tapi ada migrasi LT→DPK: {$toDpkNoa} NOA (OS ± Rp ".number_format($toDpkOs,0,',','.').")",
            'tone' => 'text-amber-700',
            'forceBg' => 'bg-amber-50 border-amber-200',
          ];
      }

      // normal interpretation
      if ($d > 0) return ['hint' => 'LT naik = memburuk', 'tone' => 'text-rose-700', 'forceBg' => null];
      if ($d < 0) return ['hint' => 'LT turun = membaik',  'tone' => 'text-emerald-700', 'forceBg' => null];

      return ['hint' => 'stagnan', 'tone' => 'text-slate-500', 'forceBg' => null];
  };

  // build TLRO sentence (copy WA) -> gunakan cardsSrc (bukan selalu $cards)
  $tlroText = function (array $cardsUse) use ($bounce, $latestDate, $prevDate, $fmtRpCompact, $fmtDeltaRp, $fmtDeltaPts, $fmtPct, $mode) {
      $dt = $latestDate ?: '-';
      $pd = $prevDate ?: 'H-1';

      $dOs = $cardsUse['os']['delta'] ?? null;
      $dL0 = $cardsUse['l0']['delta'] ?? null;
      $dLt = $cardsUse['lt']['delta'] ?? null;
      $dRr = $cardsUse['rr']['delta'] ?? null;     // pts
      $dPL = $cardsUse['pct_lt']['delta'] ?? null; // pts

      $latestRR = $cardsUse['rr']['value'] ?? null;
      $latestPL = $cardsUse['pct_lt']['value'] ?? null;

      $parts = [];
      $parts[] = "Ringkas {$dt} (vs {$pd}):";

      if (!is_null($dOs))  $parts[] = "OS " . ($dOs >= 0 ? "naik " : "turun ") . $fmtDeltaRp($dOs) . ".";
      if (!is_null($dL0))  $parts[] = "L0 " . ($dL0 >= 0 ? "membaik (naik) " : "memburuk (turun) ") . $fmtDeltaRp($dL0) . ".";
      if (!is_null($dLt))  $parts[] = "LT " . ($dLt >= 0 ? "naik (memburuk) " : "turun (membaik) ") . $fmtDeltaRp($dLt) . ".";

      if (!is_null($latestRR)) {
          $rrTxt = $fmtPct($latestRR);
          $rrD   = is_null($dRr) ? '' : " (" . $fmtDeltaPts($dRr) . ")";
          $parts[] = "RR {$rrTxt}{$rrD}.";
      }

      if (!is_null($latestPL)) {
          $plTxt = $fmtPct($latestPL);
          $plD   = is_null($dPL) ? '' : " (" . $fmtDeltaPts($dPL) . ")";
          $parts[] = "%LT {$plTxt}{$plD}.";
      }

      $signalBounce = (bool)($bounce['signal_bounce_risk'] ?? false);

      $ltToL0Noa = (int)($bounce['lt_to_l0_noa'] ?? 0);
      $ltToL0Os  = (int)($bounce['lt_to_l0_os'] ?? 0);

      // mode aware key
      $ltToDpkNoa = $mode === 'mtd' ? (int)($bounce['lt_eom_to_dpk_noa'] ?? 0) : (int)($bounce['lt_to_dpk_noa'] ?? 0);
      $ltToDpkOs  = $mode === 'mtd' ? (int)($bounce['lt_eom_to_dpk_os'] ?? 0)  : (int)($bounce['lt_to_dpk_os'] ?? 0);

      $jtNoa     = (int)($bounce['jt_next2_noa'] ?? 0);
      $jtOs      = (int)($bounce['jt_next2_os'] ?? 0);

      if ($ltToL0Noa > 0) {
          $parts[] = "Ada cure LT→L0: {$ltToL0Noa} NOA (±" . $fmtRpCompact($ltToL0Os) . ").";
      }

      if ($ltToDpkNoa > 0) {
          $parts[] = "⚠️ LT→DPK (FT=2): {$ltToDpkNoa} NOA (±" . $fmtRpCompact($ltToDpkOs) . ").";
      }

      if ($jtNoa > 0) {
          $parts[] = "JT angsuran 1–2 hari ke depan: {$jtNoa} NOA (±" . $fmtRpCompact($jtOs) . ").";
      }

      if ($signalBounce) {
          $parts[] = "⚠️ Hati-hati bounce-back: L0 naik karena sebagian LT bayar, tapi masih ada JT dekat → besok bisa LT naik lagi & RR turun bila gagal bayar.";
      }

      $parts[] = "Aksi: prioritaskan kunjungan/penagihan debitur JT dekat & yang baru cure. Pantau yang mulai masuk DPK (FT=2). Inputkan di LKH.";

      return implode(' ', $parts);
  };
@endphp

<div class="max-w-6xl mx-auto p-3 sm:p-4 space-y-4 sm:space-y-5">

  {{-- Header --}}
  <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div>
      <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900">📈 Dashboard RO </h1>
      <!-- <p class="text-xs sm:text-sm text-slate-500 mt-1">
        Scope: <b>RO sendiri</b>. Data snapshot harian (kpi_os_daily_aos). Posisi terakhir: <b>{{ $latestPosDate }}</b>.
      </p>
      <p class="text-[11px] sm:text-xs text-slate-500 mt-1">
        Snapshot compare: <b>{{ $latestDate ?? '-' }}</b> vs <b>{{ $prevDate ?? '-' }}</b>.
      </p> -->
      @if($mode === 'mtd')
        <p class="text-[11px] sm:text-xs text-slate-500 mt-1">
          Mode: <b>MtoD</b> (EOM bulan lalu → posisi terakhir).
        </p>
      @endif
    </div>

    <form method="GET" class="w-full sm:w-auto grid grid-cols-2 sm:flex sm:items-end gap-2">
      <div class="col-span-1">
        <div class="text-[11px] sm:text-xs text-slate-500 mb-1">Dari</div>
        <input type="date" name="from" value="{{ $from }}"
               class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white">
      </div>
      <div class="col-span-1">
        <div class="text-[11px] sm:text-xs text-slate-500 mb-1">Sampai</div>
        <input type="date" name="to" value="{{ $to }}"
               class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white">
      </div>

      <input type="hidden" name="mode" value="{{ $mode }}">

      <button class="col-span-2 sm:col-auto w-full sm:w-auto rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
        Tampilkan
      </button>

      <div class="col-span-2 sm:col-auto flex gap-2">
        <a href="{{ request()->fullUrlWithQuery(['mode'=>'daily']) }}"
          class="w-full sm:w-auto rounded-xl border px-4 py-2 text-sm font-semibold
              {{ $mode==='daily' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50' }}">
          H vs H-1
        </a>

        <a href="{{ request()->fullUrlWithQuery(['mode'=>'mtd']) }}"
          class="w-full sm:w-auto rounded-xl border px-4 py-2 text-sm font-semibold
              {{ $mode==='mtd' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50' }}">
          MtoD (EOM→Latest)
        </a>
      </div>

    </form>
  </div>
{{-- ===========================
    RO CHART (MODEL = TLRO)
    - Scope: hanya RO yang login
    - UX: sama seperti TLRO (metric switcher + KPI strip + mode + labels + mobile)
   =========================== --}}

<div class="rounded-2xl border border-slate-200 bg-white p-4 space-y-4">

  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
    <div>
      <div class="font-bold text-slate-900">Grafik Harian</div>
      <!-- <div class="text-xs text-slate-500">
        Tanggal tanpa snapshot akan tampil <b>putus</b> (bukan 0).
      </div> -->

      {{-- KPI strip (diisi JS berdasarkan titik terakhir) --}}
      <!-- <div class="mt-3 flex flex-wrap gap-2">
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
      </div> -->
    </div>

    <div class="flex items-center gap-2 flex-wrap justify-end">

      {{-- Metric (sama seperti TLRO) --}}
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

      {{-- Mobile: toggle show all lines (tetap ada walau 1 line, konsisten UX) --}}
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
      <canvas id="roOsChart" class="w-full h-full"></canvas>
    </div>

    <div class="mt-2 text-[11px] text-slate-500 sm:hidden">
      Tips: geser layar ke samping untuk melihat detail legend & garis.
    </div>
  </div>
</div>

  {{-- Summary Cards (Smart-CRMS Grade A) --}}
  @php
    // pilih sumber cards: MTD pakai cardsMtd (punya base=EOM), kalau tidak fallback cards (H vs H-1)
    $cardsSrc = ($mode === 'mtd' && !empty($cardsMtd) && is_array($cardsMtd))
      ? $cardsMtd
      : ($cards ?? []);

    $cOs  = (array)($cardsSrc['os'] ?? []);
    $cL0  = (array)($cardsSrc['l0'] ?? []);
    $cLt  = (array)($cardsSrc['lt'] ?? []);
    $cDpk = (array)($cardsSrc['dpk'] ?? []);         // ✅ baru
    $cRr  = (array)($cardsSrc['rr'] ?? []);          // dipakai sbg delta RR
    $cPL  = (array)($cardsSrc['pct_lt'] ?? []);      // dipakai sbg delta %LT
    $cPD  = (array)($cardsSrc['pct_dpk'] ?? []);     // ✅ baru (%DPK)

    // tone (DPK naik = memburuk, jadi tone-nya kita samakan logika LT)
    [$osText,$osBg]   = $deltaTone('os', $cOs['delta'] ?? null);
    [$l0Text,$l0Bg]   = $deltaTone('l0', $cL0['delta'] ?? null);
    [$ltText,$ltBg]   = $deltaTone('lt', $cLt['delta'] ?? null);
    [$dpkText,$dpkBg] = $deltaTone('lt', $cDpk['delta'] ?? null); // treat like LT risk

    // label growth
    $growthLabel = $mode === 'mtd' ? 'Growth (MtoD)' : 'Growth (H vs H-1)';

    // baseline info (MTD)
    $mtdMeta  = (array)($cardsMtdMeta ?? []);
    $eomMonth = $mtdMeta['eomMonth'] ?? null;
    $lastDate = $mtdMeta['lastDate'] ?? null;

    $baselineText = '';
    if ($mode === 'mtd') {
      $b1 = $eomMonth ? \Carbon\Carbon::parse($eomMonth)->translatedFormat('M Y') : '-';
      $b2 = $lastDate ?: ($latestPosDate ?? '-');
      $baselineText = "EOM {$b1} → Latest {$b2}";
    }

    // ====== Derive RR, %LT, %DPK (latest & EOM/base) ======
    $osV   = (float)($cOs['value'] ?? 0);
    $l0V   = (float)($cL0['value'] ?? 0);
    $ltV   = (float)($cLt['value'] ?? 0);
    $dpkV  = (float)($cDpk['value'] ?? 0);

    $osB   = (float)($cOs['base'] ?? ($cOs['prev'] ?? 0));   // base=EOM, fallback prev
    $l0B   = (float)($cL0['base'] ?? ($cL0['prev'] ?? 0));
    $ltB   = (float)($cLt['base'] ?? ($cLt['prev'] ?? 0));
    $dpkB  = (float)($cDpk['base'] ?? ($cDpk['prev'] ?? 0));

    $rrV   = $osV > 0 ? round(($l0V / $osV) * 100, 2) : null;
    $rrB   = $osB > 0 ? round(($l0B / $osB) * 100, 2) : null;

    $pctLtV  = $osV > 0 ? round(($ltV / $osV) * 100, 2) : null;
    $pctLtB  = $osB > 0 ? round(($ltB / $osB) * 100, 2) : null;

    $pctDpkV = $osV > 0 ? round(($dpkV / $osV) * 100, 2) : null;
    $pctDpkB = $osB > 0 ? round(($dpkB / $osB) * 100, 2) : null;

    // ====== Delta pts (pakai dari controller kalau ada, fallback hitung sendiri) ======
    $rrDeltaPts     = $cRr['delta'] ?? (!is_null($rrV) && !is_null($rrB) ? round($rrV - $rrB, 2) : null);
    $pctLtDeltaPts  = $cPL['delta'] ?? (!is_null($pctLtV) && !is_null($pctLtB) ? round($pctLtV - $pctLtB, 2) : null);
    $pctDpkDeltaPts = $cPD['delta'] ?? (!is_null($pctDpkV) && !is_null($pctDpkB) ? round($pctDpkV - $pctDpkB, 2) : null);

    // tone ratio (RR naik = membaik; %LT & %DPK naik = memburuk)
    [$rrText,$rrBg]     = $deltaTone('rr', $rrDeltaPts);
    [$plText,$plBg]     = $deltaTone('pct_lt', $pctLtDeltaPts);
    [$pdText,$pdBg]     = $deltaTone('pct_lt', $pctDpkDeltaPts); // treat like %LT risk

    $bounceArr = (array)($bounce ?? []);

    $toDpkNoa = ($mode ?? 'mtd') === 'mtd'
      ? (int)($bounceArr['lt_eom_to_dpk_noa'] ?? 0)
      : (int)($bounceArr['lt_to_dpk_noa'] ?? 0);

    $toDpkOs  = ($mode ?? 'mtd') === 'mtd'
      ? (int)($bounceArr['lt_eom_to_dpk_os'] ?? 0)
      : (int)($bounceArr['lt_to_dpk_os'] ?? 0);

    // fallback cohort LT kalau bounce kosong
    if (($toDpkNoa <= 0) && !empty($ltLatest)) {
      try {
        $col = collect($ltLatest);
        $isDpk = fn($r) =>
          ((int)($r->ft_pokok ?? 0) === 2) ||
          ((int)($r->ft_bunga ?? 0) === 2) ||
          ((int)($r->kolek ?? 0) === 2);

        $toDpkNoa = (int) $col->filter($isDpk)->count();
        $toDpkOs = (int) $col->filter($isDpk)->sum(fn($r) => (int)($r->os ?? $r->outstanding ?? 0));
      } catch (\Throwable $e) {}
    }
  @endphp


  <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-3">

    {{-- OS --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-[11px] sm:text-xs text-slate-500">
        OS
        @if($mode==='mtd')
          <div class="text-[10px] text-slate-400 mt-0.5">{{ $baselineText }}</div>
        @endif
      </div>

      <div class="mt-1">
        <div class="text-[11px] text-slate-500">Latest</div>
        <div class="text-base sm:text-lg font-extrabold text-slate-900 leading-snug">
          {{ $fmtRpFull($cOs['value'] ?? 0) }}
        </div>

        <div class="text-[11px] text-slate-500 mt-2">EOM</div>
        <div class="text-sm font-semibold text-slate-800">
          {{ $fmtRpFull($cOs['base'] ?? ($cOs['prev'] ?? 0)) }}
        </div>
      </div>

      <div class="mt-3">
        <div class="text-[11px] text-slate-500">{{ $growthLabel }}</div>
        <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $osBg }}">
          <span class="text-sm font-bold {{ $osText }}">{{ $fmtDeltaRp($cOs['delta'] ?? null) }}</span>
          <span class="text-[11px] text-slate-500">
            @if($mode==='mtd') vs EOM @else {{ $prevDate ? '' : '(prev n/a)' }} @endif
          </span>
        </div>
      </div>
    </div>

    {{-- L0 (+ RR) --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-[11px] sm:text-xs text-slate-500">
        L0
        @if($mode==='mtd')
          <div class="text-[10px] text-slate-400 mt-0.5">{{ $baselineText }}</div>
        @endif
      </div>

      <div class="mt-1">
        <div class="text-[11px] text-slate-500">Latest</div>
        <div class="text-base sm:text-lg font-extrabold text-slate-900 leading-snug">
          {{ $fmtRpFull($cL0['value'] ?? 0) }}
        </div>

        <div class="flex items-center justify-between mt-2">
          <div>
            <div class="text-[11px] text-slate-500">EOM</div>
            <div class="text-sm font-semibold text-slate-800">
              {{ $fmtRpFull($cL0['base'] ?? ($cL0['prev'] ?? 0)) }}
            </div>
          </div>
          <div class="text-right">
            <div class="text-[11px] text-slate-500">RR</div>
            <div class="text-sm font-extrabold text-slate-900">
              {{ $fmtPct($rrV) }}
            </div>
            <div class="text-[10px] text-slate-400">
              EOM: {{ $fmtPct($rrB) }}
            </div>
          </div>
        </div>
      </div>

      <div class="mt-3 space-y-2">
        <div>
          <div class="text-[11px] text-slate-500">{{ $growthLabel }} L0</div>
          <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $l0Bg }}">
            <span class="text-sm font-bold {{ $l0Text }}">{{ $fmtDeltaRp($cL0['delta'] ?? null) }}</span>
            <span class="text-[11px] text-slate-500">{{ $deltaHint('l0', $cL0['delta'] ?? null) }}</span>
          </div>
        </div>

        <div>
          <div class="text-[11px] text-slate-500">{{ $growthLabel }} RR</div>
          <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $rrBg }}">
            <span class="text-sm font-bold {{ $rrText }}">{{ $fmtDeltaPts($rrDeltaPts) }}</span>
            <span class="text-[11px] text-slate-500">{{ $deltaHint('rr', $rrDeltaPts) }}</span>
          </div>
        </div>
      </div>
    </div>

    {{-- LT (+ %LT) --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-[11px] sm:text-xs text-slate-500">
        LT
        @if($mode==='mtd')
          <div class="text-[10px] text-slate-400 mt-0.5">{{ $baselineText }}</div>
        @endif
      </div>

      <div class="mt-1">
        <div class="text-[11px] text-slate-500">Latest</div>
        <div class="text-base sm:text-lg font-extrabold text-slate-900 leading-snug">
          {{ $fmtRpFull($cLt['value'] ?? 0) }}
        </div>

        <div class="flex items-center justify-between mt-2">
          <div>
            <div class="text-[11px] text-slate-500">EOM</div>
            <div class="text-sm font-semibold text-slate-800">
              {{ $fmtRpFull($cLt['base'] ?? ($cLt['prev'] ?? 0)) }}
            </div>
          </div>
          <div class="text-right">
            <div class="text-[11px] text-slate-500">%LT</div>
            <div class="text-sm font-extrabold text-slate-900">
              {{ $fmtPct($pctLtV) }}
            </div>
            <div class="text-[10px] text-slate-400">
              EOM: {{ $fmtPct($pctLtB) }}
            </div>
          </div>
        </div>
      </div>

      <div class="mt-3 space-y-2">
        <div>
          <div class="text-[11px] text-slate-500">{{ $growthLabel }} LT</div>
          <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $ltBg }}">
            <span class="text-sm font-bold {{ $ltText }}">{{ $fmtDeltaRp($cLt['delta'] ?? null) }}</span>
            <span class="text-[11px] text-slate-500">{{ $deltaHint('lt', $cLt['delta'] ?? null) }}</span>
          </div>
        </div>

        <div>
          <div class="text-[11px] text-slate-500">{{ $growthLabel }} %LT</div>
          <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $plBg }}">
            <span class="text-sm font-bold {{ $plText }}">{{ $fmtDeltaPts($pctLtDeltaPts) }}</span>
            <span class="text-[11px] text-slate-500">{{ $deltaHint('pct_lt', $pctLtDeltaPts) }}</span>
          </div>
        </div>
      </div>
    </div>

    {{-- DPK (+ %DPK) --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-[11px] sm:text-xs text-slate-500">
        DPK
        @if($mode==='mtd')
          <div class="text-[10px] text-slate-400 mt-0.5">{{ $baselineText }}</div>
        @endif
      </div>

      <div class="mt-1">
        <div class="text-[11px] text-slate-500">Latest</div>
        <div class="text-base sm:text-lg font-extrabold text-slate-900 leading-snug">
          {{ $fmtRpFull($cDpk['value'] ?? 0) }}
        </div>

        <div class="flex items-center justify-between mt-2">
          <div>
            <div class="text-[11px] text-slate-500">EOM</div>
            <div class="text-sm font-semibold text-slate-800">
              {{ $fmtRpFull($cDpk['base'] ?? ($cDpk['prev'] ?? 0)) }}
            </div>
          </div>
          <div class="text-right">
            <div class="text-[11px] text-slate-500">%DPK</div>
            <div class="text-sm font-extrabold text-slate-900">
              {{ $fmtPct($pctDpkV) }}
            </div>
            <div class="text-[10px] text-slate-400">
              EOM: {{ $fmtPct($pctDpkB) }}
            </div>
          </div>
        </div>
      </div>

      <div class="mt-3 space-y-2">
        <div>
          <div class="text-[11px] text-slate-500">{{ $growthLabel }} DPK</div>
          <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $dpkBg }}">
            <span class="text-sm font-bold {{ $dpkText }}">{{ $fmtDeltaRp($cDpk['delta'] ?? null) }}</span>
            <span class="text-[11px] text-slate-500">{{ $deltaHint('lt', $cDpk['delta'] ?? null) }}</span>
          </div>
        </div>

        <div>
          <div class="text-[11px] text-slate-500">{{ $growthLabel }} %DPK</div>
          <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $pdBg }}">
            <span class="text-sm font-bold {{ $pdText }}">{{ $fmtDeltaPts($pctDpkDeltaPts) }}</span>
            <span class="text-[11px] text-slate-500">{{ $deltaHint('pct_lt', $pctDpkDeltaPts) }}</span>
          </div>
        </div>
      </div>
    </div>

  </div>

  {{-- TLRO Narrative --}}
  <!-- <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
    <div class="font-extrabold text-slate-900 text-sm sm:text-base">📣 Pangandikanipun Pimpinan kangge Panjenengan </div>
    <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
      <div class="text-sm text-slate-800 leading-relaxed whitespace-pre-line" id="tlroNarrative">
        {{ $tlroText($cardsSrc) }}
      </div>
      <div class="mt-3 flex items-center gap-2">
        <button type="button" id="btnCopyTlro"
                class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">
          Copy
        </button>
        <span id="copyHint" class="text-[11px] text-slate-500"></span>
      </div>
    </div>
  </div> -->

  {{-- Insight --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
    <div class="font-extrabold text-slate-900 text-sm sm:text-base">🧠 Catatan Kinerja (Auto Insight)</div>
{{-- UPDATED RISK PANEL --}}
      <div class="rounded-2xl border border-amber-200 bg-amber-50 p-3">
        <div class="text-[11px] font-extrabold text-amber-800 mb-2">⚠️ Risiko Besok (Bounce-back + EOM)</div>
        <ul class="text-sm text-amber-900 space-y-1 list-disc pl-5">
          @forelse(($insight['risk'] ?? []) as $t)
            <li>{{ $t }}</li>
          @empty
            <li>Tidak ada sinyal bounce-back yang kuat.</li>
          @endforelse
        </ul>

        <div class="mt-3 text-[11px] text-amber-900/80 space-y-1">
          <div>Sinyal: <b>L0 naik & LT turun</b> + ada <b>JT angsuran 1–2 hari</b>.</div>
          <div>Tambahan: <b>LT→DPK</b> saat <b>FT Pokok/Bunga/Kolek = 2</b> (indikasi mulai masuk bucket risiko lebih tinggi).</div>
          @if($toDpkNoa > 0)
            <div>Terbaca: <b>{{ $toDpkNoa }}</b> NOA migrasi ke DPK (OS ± <b>Rp {{ number_format($toDpkOs,0,',','.') }}</b>).</div>
          @endif
        </div>
      </div>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-2 sm:gap-3 mt-3">
      <!-- <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
        <div class="text-[11px] font-bold text-slate-700 mb-2">Yang Baik</div>
        <ul class="text-sm text-slate-700 space-y-1 list-disc pl-5">
          @forelse(($insight['good'] ?? []) as $t)
            <li>{{ $t }}</li>
          @empty
            <li>-</li>
          @endforelse
        </ul>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
        <div class="text-[11px] font-bold text-slate-700 mb-2">Yang Buruk / Perlu Aksi</div>
        <ul class="text-sm text-slate-700 space-y-1 list-disc pl-5">
          @forelse(($insight['bad'] ?? []) as $t)
            <li>{{ $t }}</li>
          @empty
            <li>-</li>
          @endforelse
        </ul>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
        <div class="text-[11px] font-bold text-slate-700 mb-2">Penyebab (indikasi)</div>
        <ul class="text-sm text-slate-700 space-y-1 list-disc pl-5">
          @forelse(($insight['why'] ?? []) as $t)
            <li>{{ $t }}</li>
          @empty
            <li>-</li>
          @endforelse
        </ul>
      </div> -->

      <!-- {{-- UPDATED RISK PANEL --}}
      <div class="rounded-2xl border border-amber-200 bg-amber-50 p-3">
        <div class="text-[11px] font-extrabold text-amber-800 mb-2">⚠️ Risiko Besok (Bounce-back + EOM)</div>
        <ul class="text-sm text-amber-900 space-y-1 list-disc pl-5">
          @forelse(($insight['risk'] ?? []) as $t)
            <li>{{ $t }}</li>
          @empty
            <li>Tidak ada sinyal bounce-back yang kuat.</li>
          @endforelse
        </ul>

        <div class="mt-3 text-[11px] text-amber-900/80 space-y-1">
          <div>Sinyal: <b>L0 naik & LT turun</b> + ada <b>JT angsuran 1–2 hari</b>.</div>
          <div>Tambahan: <b>LT→DPK</b> saat <b>FT Pokok/Bunga/Kolek = 2</b> (indikasi mulai masuk bucket risiko lebih tinggi).</div>
          @if($toDpkNoa > 0)
            <div>Terbaca: <b>{{ $toDpkNoa }}</b> NOA migrasi ke DPK (OS ± <b>Rp {{ number_format($toDpkOs,0,',','.') }}</b>).</div>
          @endif
        </div>
      </div> -->
    </div>
  </div>

  
  {{-- ===========================
    1) JT bulan ini
    =========================== --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-3 sm:p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900 text-sm sm:text-base">
        Debitur Jatuh Tempo – {{ $dueMonthLabel ?? now()->translatedFormat('F Y') }}
      </div>
      <div class="text-[11px] sm:text-xs text-slate-500 mt-1">
        Sumber: maturity_date (tgl_jto). Scope RO sendiri.
      </div>
    </div>

    <div class="p-3 sm:p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <th class="text-left px-3 py-2 whitespace-nowrap">Jatuh Tempo</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">No Rek</th>
            <th class="text-left px-3 py-2 min-w-[220px]">Nama Debitur</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">OS</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">DPD</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">Kolek</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Progres (H-1→H)</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Umur Visit</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          @forelse(($dueThisMonth ?? []) as $r)
            @php
              $plannedToday = (int)($r->planned_today ?? 0) === 1;
              $planVisitDateRaw = $r->plan_visit_date ?? null;
              $planVisit = !empty($planVisitDateRaw) ? \Carbon\Carbon::parse($planVisitDateRaw)->format('d/m/Y') : '-';
              $acc = (string)($r->account_no ?? '');
              $locked = (string)($r->plan_status ?? '') === 'done';

              $prog = $progressText($r);
              $progClass = $progressBadgeClass((string)$prog);
            @endphp
            <tr>
              <td class="px-3 py-2 whitespace-nowrap">{{ \Carbon\Carbon::parse($r->maturity_date)->format('d/m/Y') }}</td>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $r->account_no ?? '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">Rp {{ number_format((int)($r->outstanding ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ $r->kolek ?? '-' }}</td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-bold {{ $progClass }}">
                  {{ $prog }}
                </span>
              </td>

              @php
                $plannedToday = (int)($r->planned_today ?? 0) === 1;
                $planVisitDateRaw = $r->plan_visit_date ?? null;
                $planVisit = !empty($planVisitDateRaw) ? \Carbon\Carbon::parse($planVisitDateRaw)->format('d/m/Y') : '-';

                $acc = (string)($r->account_no ?? '');

                $planStatus = strtolower(trim((string)($r->plan_status ?? '')));
                $locked = ($planStatus === 'done' || $plannedToday);

                $lastVisitAt = $r->last_visit_at ?? null;
                $ageDays = null;
                if ($lastVisitAt) {
                  try { $ageDays = \Carbon\Carbon::parse($lastVisitAt)->diffInDays(now()); } catch (\Throwable $e) {}
                }

                $prog = $progressText($r);
                $progClass = $progressBadgeClass((string)$prog);
              @endphp

              <td class="px-3 py-2 text-center whitespace-nowrap">
                {{ $lastVisitAt ? \Carbon\Carbon::parse($lastVisitAt)->format('d/m/Y') : '-' }}
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                {{ is_null($ageDays) ? '-' : ($ageDays.' hari') }}
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                 @php
                  $isPlanned = (int)($plannedToday ?? 0) === 1;
                  $acc = trim((string)($r->account_no ?? ''));
                @endphp

                <button
                  type="button"
                  class="ro-plan-today-btn inline-flex items-center justify-center rounded-full px-4 py-2 text-xs font-bold border
                    {{ $isPlanned ? 'bg-slate-900 text-white border-slate-900 opacity-80 cursor-not-allowed' : 'bg-white text-slate-800 border-slate-300 hover:bg-slate-50' }}"
                  data-account="{{ $acc }}"
                  data-url="{{ route('ro_visits.plan_today') }}"
                  {{ $isPlanned ? 'disabled' : '' }}
                >
                  {{ $isPlanned ? 'Planned' : 'Ya' }}
                </button>
              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="10" class="px-3 py-6 text-center text-slate-500">Tidak ada JT bulan ini.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- =============================
     TABEL A) LT EOM -> DPK (Risk Escalation)
   ============================= --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden mt-4">
    <div class="p-4 border-b border-slate-200">
      <div class="font-extrabold text-slate-900 text-lg">
        LT (EOM Bulan Lalu) → DPK (Hari Ini)
      </div>
      <div class="text-sm text-slate-600 mt-1">
        Fokus: akun yang memburuk (indikasi risiko naik). Total: <b>{{ (int)($ltToDpkNoa ?? 0) }}</b> NOA
        · OS: <b>Rp {{ number_format((int)($ltToDpkOs ?? 0),0,',','.') }}</b>
      </div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr class="border-b">
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-center px-3 py-2">FT Pokok</th>
            <th class="text-center px-3 py-2">FT Bunga</th>
            <th class="text-center px-3 py-2">DPD</th>
            <th class="text-center px-3 py-2">Kolek</th>
            <th class="text-center px-3 py-2">Progres</th>
            <th class="text-center px-3 py-2">Tgl Visit Terakhir</th>
            <th class="text-center px-3 py-2">Umur Visit</th>
            <th class="text-center px-3 py-2">Plan Visit</th>
            <th class="text-center px-3 py-2">Tgl Plan Visit</th>

          </tr>
        </thead>

        <tbody class="divide-y divide-slate-100">
          @forelse(($ltEomToDpk  ?? []) as $r)

            @php
              $acc = (string)($r->account_no ?? '');

              // ===== Last Visit =====
              $lastVisitAt = $r->last_visit_at ?? null;
              $ageDays = null;
              if ($lastVisitAt) {
                  try {
                      $ageDays = \Carbon\Carbon::parse($lastVisitAt)->diffInDays(now());
                  } catch (\Throwable $e) {
                      $ageDays = null;
                  }
              }

              // ===== Plan State =====
              $plannedToday = (int)($r->planned_today ?? 0) === 1;
              $planStatus   = strtolower(trim((string)($r->plan_status ?? '')));
              $locked       = ($plannedToday || $planStatus === 'done');
              $planVisit    = (string)($r->plan_visit_date ?? '');
            @endphp

            <tr>
              <td class="px-3 py-2 font-mono">{{ $acc }}</td>

              <td class="px-3 py-2 font-semibold">
                {{ $r->customer_name }}
              </td>

              <td class="px-3 py-2 text-right">
                Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}
              </td>

              <td class="px-3 py-2 text-center">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-center">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-center">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-center">{{ (int)($r->kolek ?? 0) }}</td>

              {{-- Progress Badge --}}
              <td class="px-3 py-2 text-center">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">
                  LT→DPK
                </span>
              </td>

              {{-- Last Visit Date --}}
              <td class="px-3 py-2 text-center whitespace-nowrap">
                {{ $lastVisitAt ? \Carbon\Carbon::parse($lastVisitAt)->format('d/m/Y') : '-' }}
              </td>

              {{-- Age Visit --}}
              <td class="px-3 py-2 text-center whitespace-nowrap">
                {{ is_null($ageDays) ? '-' : ($ageDays.' hari') }}
              </td>

              {{-- PLAN BUTTON --}}
              <td class="px-3 py-2 text-center whitespace-nowrap">
                 @php
                  $isPlanned = (int)($plannedToday ?? 0) === 1;
                  $acc = trim((string)($r->account_no ?? ''));
                @endphp

                <button
                  type="button"
                  class="ro-plan-today-btn inline-flex items-center justify-center rounded-full px-4 py-2 text-xs font-bold border
                    {{ $isPlanned ? 'bg-slate-900 text-white border-slate-900 opacity-80 cursor-not-allowed' : 'bg-white text-slate-800 border-slate-300 hover:bg-slate-50' }}"
                  data-account="{{ $acc }}"
                  data-url="{{ route('ro_visits.plan_today') }}"
                  {{ $isPlanned ? 'disabled' : '' }}
                >
                  {{ $isPlanned ? 'Planned' : 'Ya' }}
                </button>
              </td>

              {{-- PLAN DATE --}}
              <td class="px-3 py-2 text-center whitespace-nowrap">
                <span class="ro-plan-date font-semibold text-slate-700" data-account="{{ $acc }}">
                  {{ $planVisit !== '' 
                      ? \Carbon\Carbon::parse($planVisit)->format('d/m/Y') 
                      : '-' 
                  }}
                </span>
              </td>

            </tr>

          @empty
            <tr>
              <td colspan="12" class="px-3 py-6 text-center text-slate-500">
                Tidak ada akun LT yang berubah menjadi DPK pada periode ini ✅
              </td>
            </tr>
          @endforelse
          </tbody>

      </table>
    </div>
  </div>


  {{-- ===========================
      2) LT EOM (cohort) -> posisi hari ini
      =========================== --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-3 sm:p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900 text-sm sm:text-base">LT (EOM Bulan Lalu) → Posisi Hari Ini</div>
      <div class="text-[11px] sm:text-xs text-slate-500 mt-1">
        Cohort: yang <b>LT (FT=1)</b> pada EOM (<b>{{ $prevSnapMonth ?? '-' }}</b>).
        Status hari ini: <b>{{ $latestPosDate ?? '-' }}</b>.
        <span class="ml-2">Catatan: <b>DPK</b> saat ft_pokok/ft_bunga/kolek = 2.</span>
      </div>
    </div>

    <div class="p-3 sm:p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <th class="text-left px-3 py-2 whitespace-nowrap">No Rek</th>
            <th class="text-left px-3 py-2 min-w-[220px]">Nama Debitur</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">OS</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">FT Pokok</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">FT Bunga</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">DPD</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">Kolek</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Progres (EOM→H)</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Umur Visit</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($ltStillLt ?? []) as $r)
            @php
              $bucketFromFt2 = function($ftPokok, $ftBunga, $kolek){
                $fp = (int)($ftPokok ?? 0);
                $fb = (int)($ftBunga ?? 0);
                $k  = (int)($kolek ?? 0);
                if ($fp === 2 || $fb === 2 || $k === 2) return 'DPK';
                if ($fp === 1 || $fb === 1) return 'LT';
                return 'L0';
              };

              $progressBadge = function($from, $to){
                if ($from === $to) return ['LT→LT', 'bg-slate-50 border-slate-200 text-slate-700'];
                if ($to === 'DPK') return ['LT→DPK', 'bg-rose-50 border-rose-200 text-rose-700'];
                if ($to === 'L0')  return ['LT→L0',  'bg-emerald-50 border-emerald-200 text-emerald-700'];
                return ["LT→{$to}", 'bg-slate-50 border-slate-200 text-slate-700'];
              };

              $acc = (string)($r->account_no ?? '');

              $plannedToday = (int)($r->planned_today ?? 0) === 1;
              $planVisit = (string)($r->plan_visit_date ?? '');

              $planStatus = strtolower(trim((string)($r->plan_status ?? '')));
              $locked = ($planStatus === 'done');

              $from = $bucketFromFt2($r->eom_ft_pokok ?? 1, $r->eom_ft_bunga ?? 0, $r->eom_kolek ?? null);
              $to   = $bucketFromFt2($r->ft_pokok ?? 0,      $r->ft_bunga ?? 0,      $r->kolek ?? null);
              [$txt, $cls] = $progressBadge($from, $to);
            @endphp

            <tr>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $acc !== '' ? $acc : '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ $r->kolek ?? '-' }}</td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-bold {{ $cls }}">
                  {{ $txt }}
                </span>

                @if($txt === 'LT→L0')
                  <div class="mt-1 text-[11px] text-slate-500">Cure sementara • rawan bounce</div>
                @endif
              </td>

              
              <td class="px-3 py-2 text-center whitespace-nowrap">
                {{ !empty($r->last_visit_at) ? \Carbon\Carbon::parse($r->last_visit_at)->format('d/m/Y') : '-' }}
              </td>
              @php
                $acc = (string)($r->account_no ?? '');

                $plannedToday = (int)($r->planned_today ?? 0) === 1;
                $planVisitRaw = $r->plan_visit_date ?? null;
                $planVisit    = !empty($planVisitRaw) ? \Carbon\Carbon::parse($planVisitRaw)->format('d/m/Y') : '-';

                $planStatus = strtolower(trim((string)($r->plan_status ?? '')));
                $locked = ($planStatus === 'done' || $plannedToday);

                $lastVisitAt = $r->last_visit_at ?? null;
                $ageDays = null;
                if ($lastVisitAt) {
                  try { $ageDays = \Carbon\Carbon::parse($lastVisitAt)->diffInDays(now()); } catch (\Throwable $e) {}
                }
              @endphp

              <td class="px-3 py-2 text-center whitespace-nowrap">
                @php
                  $ageDays = null;
                  if (!empty($r->last_visit_at)) {
                    try { $ageDays = \Carbon\Carbon::parse($r->last_visit_at)->diffInDays(now()); } catch (\Throwable $e) {}
                  }
                @endphp
                {{ is_null($ageDays) ? '-' : ($ageDays.' hari') }}
              </td>

              
              <td class="px-3 py-2 text-center whitespace-nowrap">
                 @php
                  $isPlanned = (int)($plannedToday ?? 0) === 1;
                  $acc = trim((string)($r->account_no ?? ''));
                @endphp

                <button
                  type="button"
                  class="ro-plan-today-btn inline-flex items-center justify-center rounded-full px-4 py-2 text-xs font-bold border
                    {{ $isPlanned ? 'bg-slate-900 text-white border-slate-900 opacity-80 cursor-not-allowed' : 'bg-white text-slate-800 border-slate-300 hover:bg-slate-50' }}"
                  data-account="{{ $acc }}"
                  data-url="{{ route('ro_visits.plan_today') }}"
                  {{ $isPlanned ? 'disabled' : '' }}
                >
                  {{ $isPlanned ? 'Planned' : 'Ya' }}
                </button>
              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>

            </tr>
          @empty
            <tr>
              <td colspan="11" class="px-3 py-6 text-center text-slate-500">
                Tidak ada cohort LT pada EOM bulan lalu.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- ===========================
      3) JT angsuran minggu ini
      =========================== --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-3 sm:p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900 text-sm sm:text-base">
        JT Angsuran Minggu Ini
        @if(!empty($weekStart) && !empty($weekEnd))
          <span class="text-slate-500 font-normal text-sm">({{ $weekStart }} s/d {{ $weekEnd }})</span>
        @endif
      </div>
      <div class="text-[11px] sm:text-xs text-slate-500 mt-1">
        Sumber: installment_day. Dibaca terhadap posisi terakhir: <b>{{ $latestPosDate ?? '-' }}</b>.
      </div>
    </div>

    <div class="p-3 sm:p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <th class="text-left px-3 py-2 whitespace-nowrap">JT (Tanggal)</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">No Rek</th>
            <th class="text-left px-3 py-2 min-w-[220px]">Nama Debitur</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">OS</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">FT Pokok</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">FT Bunga</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">DPD</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">Kolek</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Progres (H-1→H)</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Umur Visit</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($jtAngsuran ?? []) as $r)
            @php
              $due = !empty($r->due_date) ? \Carbon\Carbon::parse($r->due_date) : null;

              $acc = (string)($r->account_no ?? '');

              // ===== progress =====
              $prog = $progressText($r);
              $progClass = $progressBadgeClass((string)$prog);

              // ===== last visit =====
              $lastVisitAt = $r->last_visit_at ?? null;
              $ageDays = null;
              if (!empty($lastVisitAt)) {
                try { $ageDays = \Carbon\Carbon::parse($lastVisitAt)->diffInDays(now()); } catch (\Throwable $e) {}
              }

              // ===== plan state =====
              $plannedToday = (int)($r->planned_today ?? 0) === 1;
              $planStatus   = strtolower(trim((string)($r->plan_status ?? '')));
              $planVisitRaw = $r->plan_visit_date ?? null;
              $planVisit    = !empty($planVisitRaw) ? \Carbon\Carbon::parse($planVisitRaw)->format('d/m/Y') : '-';

              // seragam dengan tabel A: kalau sudah planned -> disable juga
              $locked = ($planStatus === 'done' || $plannedToday);
            @endphp

            <tr>
              <td class="px-3 py-2 whitespace-nowrap">{{ $due ? $due->format('d/m/Y') : '-' }}</td>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $acc !== '' ? $acc : '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ $r->kolek ?? '-' }}</td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-bold {{ $progClass }}">
                  {{ $prog }}
                </span>
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                {{ !empty($lastVisitAt) ? \Carbon\Carbon::parse($lastVisitAt)->format('d/m/Y') : '-' }}
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                {{ is_null($ageDays) ? '-' : ($ageDays.' hari') }}
              </td>

              {{-- PLAN BUTTON: seragam tabel A --}}
              <td class="px-3 py-2 text-center whitespace-nowrap">
                @php
                  $isPlanned = (int)($plannedToday ?? 0) === 1;
                  $acc = trim((string)($r->account_no ?? ''));
                @endphp

                <button
                  type="button"
                  class="ro-plan-today-btn inline-flex items-center justify-center rounded-full px-4 py-2 text-xs font-bold border
                    {{ $isPlanned ? 'bg-slate-900 text-white border-slate-900 opacity-80 cursor-not-allowed' : 'bg-white text-slate-800 border-slate-300 hover:bg-slate-50' }}"
                  data-account="{{ $acc }}"
                  data-url="{{ route('ro_visits.plan_today') }}"
                  {{ $isPlanned ? 'disabled' : '' }}
                >
                  {{ $isPlanned ? 'Planned' : 'Ya' }}
                </button>

              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="13" class="px-3 py-6 text-center text-slate-500">Tidak ada JT angsuran minggu ini.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

{{-- ===========================
      4) >500juta
      =========================== --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-3 sm:p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900 text-sm sm:text-base">
        Debitur besar > 500juta
        @if(!empty($weekStart) && !empty($weekEnd))
          <span class="text-slate-500 font-normal text-sm">({{ $weekStart }} s/d {{ $weekEnd }})</span>
        @endif
      </div>
      <!-- <div class="text-[11px] sm:text-xs text-slate-500 mt-1">
        Sumber: installment_day. Dibaca terhadap posisi terakhir: <b>{{ $latestPosDate ?? '-' }}</b>.
      </div> -->
    </div>

    <div class="p-3 sm:p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <th class="text-left px-3 py-2 whitespace-nowrap">JT (Tanggal)</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">No Rek</th>
            <th class="text-left px-3 py-2 min-w-[220px]">Nama Debitur</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">OS</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">FT Pokok</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">FT Bunga</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">DPD</th>
            <th class="text-right px-3 py-2 whitespace-nowrap">Kolek</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Progres (H-1→H)</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Umur Visit</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($osBig ?? []) as $r)
            @php
              $due = !empty($r->due_date) ? \Carbon\Carbon::parse($r->due_date) : null;

              $acc = (string)($r->account_no ?? '');

              // ===== progress =====
              $prog = $progressText($r);
              $progClass = $progressBadgeClass((string)$prog);

              // ===== last visit =====
              $lastVisitAt = $r->last_visit_at ?? null;
              $ageDays = null;
              if (!empty($lastVisitAt)) {
                try { $ageDays = \Carbon\Carbon::parse($lastVisitAt)->diffInDays(now()); } catch (\Throwable $e) {}
              }

              // ===== plan state =====
              $plannedToday = (int)($r->planned_today ?? 0) === 1;
              $planStatus   = strtolower(trim((string)($r->plan_status ?? '')));
              $planVisitRaw = $r->plan_visit_date ?? null;
              $planVisit    = !empty($planVisitRaw) ? \Carbon\Carbon::parse($planVisitRaw)->format('d/m/Y') : '-';

              // seragam dengan tabel A: kalau sudah planned -> disable juga
              $locked = ($planStatus === 'done' || $plannedToday);
            @endphp

            <tr>
              <td class="px-3 py-2 whitespace-nowrap">{{ $due ? $due->format('d/m/Y') : '-' }}</td>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $acc !== '' ? $acc : '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ $r->kolek ?? '-' }}</td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-bold {{ $progClass }}">
                  {{ $prog }}
                </span>
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                {{ !empty($lastVisitAt) ? \Carbon\Carbon::parse($lastVisitAt)->format('d/m/Y') : '-' }}
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                {{ is_null($ageDays) ? '-' : ($ageDays.' hari') }}
              </td>

              {{-- PLAN BUTTON: seragam tabel A --}}
              <td class="px-3 py-2 text-center whitespace-nowrap">
                @php
                  $isPlanned = (int)($plannedToday ?? 0) === 1;
                  $acc = trim((string)($r->account_no ?? ''));
                @endphp

                <button
                  type="button"
                  class="ro-plan-today-btn inline-flex items-center justify-center rounded-full px-4 py-2 text-xs font-bold border
                    {{ $isPlanned ? 'bg-slate-900 text-white border-slate-900 opacity-80 cursor-not-allowed' : 'bg-white text-slate-800 border-slate-300 hover:bg-slate-50' }}"
                  data-account="{{ $acc }}"
                  data-url="{{ route('ro_visits.plan_today') }}"
                  {{ $isPlanned ? 'disabled' : '' }}
                >
                  {{ $isPlanned ? 'Planned' : 'Ya' }}
                </button>

              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="13" class="px-3 py-6 text-center text-slate-500">Tidak ada JT angsuran minggu ini.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>

{{-- =============================
    CHART JS (RO)
    - Pastikan hanya 1x include
   ============================= --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>

<script>
  // ===== Data dari Controller =====
  const labels = @json($labels ?? []);
  const datasetsByMetric = @json($datasetsByMetric ?? []);

  console.log('RO labels:', labels?.length, labels);
  console.log('RO datasetsByMetric keys:', Object.keys(datasetsByMetric || {}));

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

  function carryForward(arr){
    const out = [];
    let last = null;
    for (const v of (arr || [])){
      if (!isNil(v)) last = Number(v);
      out.push(last);
    }
    return out;
  }

  // growth pakai prev non-null terakhir
  function toGrowthSeriesSparse(arr){
    const out = [];
    let prev = null;
    for (let i=0; i<(arr?.length||0); i++){
      const cur = arr[i];
      if (isNil(cur)) { out.push(null); continue; }
      if (isNil(prev)) { out.push(null); prev = Number(cur); continue; }
      out.push(Number(cur) - Number(prev));
      prev = Number(cur);
    }
    return out;
  }

  // ===== Guard =====
  const hasAnyKey = datasetsByMetric && Object.keys(datasetsByMetric).length > 0;
  if (!hasAnyKey) {
    console.warn('datasetsByMetric kosong. Pastikan controller mengirim $datasetsByMetric.');
  }

  // ===== State =====
  let metric = 'os_total';
  let mode = 'value';
  let showAllLines = false;
  let showAllPointLabels = false;

  const isMobile = () => window.matchMedia('(max-width: 639px)').matches;

  const metricPalette = {
    os_total: { border: '#1e3a8a', fill: 'rgba(30,58,138,0.20)' },
    os_l0:    { border: '#991b1b', fill: 'rgba(153,27,27,0.20)' },
    os_lt:    { border: '#581c87', fill: 'rgba(88,28,135,0.20)' },
    rr:       { border: '#16a34a', fill: 'rgba(22,163,74,0.55)' },
    pct_lt:   { border: '#ea580c', fill: 'rgba(234,88,12,0.55)' },
  };
  function getMetricColor(metricKey){
    return metricPalette[metricKey] || { border:'#2563eb', fill:'rgba(37,99,235,0.2)' };
  }

  function getRawDatasets() {
    if (!datasetsByMetric || !datasetsByMetric[metric]) return [];
    const item = datasetsByMetric[metric];
    return Array.isArray(item) ? item : [item];
  }

  function applyMobileDatasetRules(datasets) {
    if (!isMobile()) return datasets;
    if (showAllLines) return datasets.map(ds => ({ ...ds, hidden: false }));
    return datasets.map((ds, idx) => ({ ...ds, hidden: idx >= 1 }));
  }

  function buildDatasets() {
    const raw = getRawDatasets();
    const isGrowth = (mode === 'growth');
    const wantBar = isGrowth || isPercentMetric(metric);
    const col = getMetricColor(metric);

    let ds = raw.map((ds) => {
      const baseRaw = ds.data || [];
      const baseFilled = carryForward(baseRaw);
      const data = isGrowth ? toGrowthSeriesSparse(baseFilled) : baseFilled;

      if (wantBar) {
        return {
          label: ds.label || (metric === 'rr' ? 'RR (% L0)' : (metric === 'pct_lt' ? '% LT' : 'Growth')),
          data,
          type: 'bar',
          backgroundColor: (ctx) => {
            const v = ctx.raw;
            if (isNil(v)) return 'rgba(0,0,0,0)';
            if (isGrowth) return Number(v) >= 0 ? '#16a34a' : '#dc2626';
            return col.fill;
          },
          borderColor: (ctx) => {
            const v = ctx.raw;
            if (isNil(v)) return 'rgba(0,0,0,0)';
            if (isGrowth) return Number(v) >= 0 ? '#16a34a' : '#dc2626';
            return col.border;
          },
          borderWidth: 1,
          borderSkipped: false,
          categoryPercentage: 0.85,
          barPercentage: 0.90,
          maxBarThickness: 28,
          pointRadius: 0,
          pointHoverRadius: 0,
        };
      }

      return {
        label: ds.label || 'Metric',
        data,
        type: 'line',
        showLine: true,
        spanGaps: true,
        stepped: 'before',
        tension: 0,
        borderColor: col.border,
        backgroundColor: col.fill,
        borderWidth: 3,
        fill: false,
        pointRadius: 4,
        pointHoverRadius: 6,
        pointBorderWidth: 2,
        pointBackgroundColor: '#ffffff',
        pointBorderColor: col.border,
      };
    });

    ds = applyMobileDatasetRules(ds);
    return ds;
  }

  // ===== Init Chart =====
  const canvas = document.getElementById('roOsChart');
  if (!canvas) {
    console.error('Canvas #roOsChart tidak ditemukan.');
  }

  const chart = canvas ? new Chart(canvas.getContext('2d'), {
    type: 'bar',
    data: { labels, datasets: buildDatasets() },
    plugins: [ChartDataLabels],
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: true, position: 'bottom', labels: { usePointStyle: true } },
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
            return pct ? fmtPct(value) : fmtCompactRp(value);
          },
        },
      },
      scales: {
        x: { ticks: { autoSkip: true, maxTicksLimit: isMobile() ? 6 : 14 } },
        y: {
          beginAtZero: false,
          grace: '5%',
          ticks: {
            display: !isMobile(), // ✅ HILANGKAN angka sumbu Y di mobile
            callback: (v) => isPercentMetric(metric)
              ? fmtPct(v)
              : ('Rp ' + Number(v).toLocaleString('id-ID')),
          },
          // opsional: tick kecil di sumbu Y ikut dihemat
          // grid: { drawTicks: !isMobile() }
        }
      }
    },
  }) : null;


  function refreshChart() {
    if (!chart) return;
    chart.data.datasets = buildDatasets();
    chart.update();
  }

  // ===== PLAN VISIT TODAY (anti double click) =====
 

(function () {
  const planLocks = new Set();

  function lockButtons(account, json) {
    document.querySelectorAll('.ro-plan-today-btn[data-account="' + account + '"]').forEach(b => {
      b.textContent = 'Planned';
      b.disabled = true;
      b.classList.remove(
        'bg-white','text-slate-800','border-slate-300','hover:bg-slate-50','hover:border-slate-400'
      );
      b.classList.add('bg-slate-900','text-white','border-slate-900','opacity-80','cursor-not-allowed');
    });

    document.querySelectorAll('.ro-plan-date[data-account="' + account + '"]').forEach(el => {
      const d = (json?.plan_visit_date || '').toString();
      el.textContent = d ? new Date(d).toLocaleDateString('id-ID') : '-';
    });
  }

  document.addEventListener('click', async function (e) {
    const btn = e.target.closest('.ro-plan-today-btn');
    if (!btn) return;

    e.preventDefault();
    if (btn.disabled) return;

    const account = (btn.dataset.account || '').trim();
    const url = (btn.dataset.url || '').trim();
    if (!account || !url) return;

    if (planLocks.has(account)) return;
    planLocks.add(account);

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const oldText = btn.textContent;

    btn.disabled = true;
    btn.textContent = 'Loading...';

    try {
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify({ account_no: account }),
      });

      const raw = await res.text();
      let json = {};
      try { json = raw ? JSON.parse(raw) : {}; } catch (e) {}

      if (!res.ok || !json.ok) {
        btn.disabled = false;
        btn.textContent = oldText || 'Ya';
        alert(json.message || raw || ('HTTP ' + res.status));
        return;
      }

      lockButtons(account, json);

    } catch (err) {
      console.error(err);
      btn.disabled = false;
      btn.textContent = oldText || 'Ya';
      alert('Error jaringan / server.');
    } finally {
      planLocks.delete(account);
    }
  });
})();
</script>

@endsection