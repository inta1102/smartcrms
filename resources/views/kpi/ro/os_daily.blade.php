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

  {{-- Grafik --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4 space-y-3 sm:space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 sm:gap-3">
      <div>
        <div class="font-bold text-slate-900 text-sm sm:text-base">Grafik Harian (5 garis)</div>
        <div class="text-[11px] sm:text-xs text-slate-500 mt-1">
          Tanggal tanpa snapshot akan tampil <b>putus</b> (bukan 0).
        </div>
      </div>

      <div class="w-full sm:w-auto flex flex-col sm:flex-row gap-2 sm:gap-2 sm:items-center sm:justify-end">
        <div class="rounded-xl border border-slate-200 p-1 bg-slate-50 flex items-center gap-1 w-full sm:w-auto">
          <button type="button" id="btnModeValue"
                  class="flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200">
            Value
          </button>
          <button type="button" id="btnModeGrowth"
                  class="flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg text-slate-700">
            Growth (Δ H vs H-1)
          </button>
        </div>

        <div class="rounded-xl border border-slate-200 p-1 bg-slate-50 flex items-center gap-1 w-full sm:w-auto">
          <button type="button" id="btnLabelsLastOnly"
                  class="flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200">
            Label: Last
          </button>
          <button type="button" id="btnLabelsAll"
                  class="flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg text-slate-700">
            Label: Semua
          </button>
        </div>

        <div class="sm:hidden">
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

    <div class="w-full">
      <div class="relative w-full overflow-x-auto">
        <div class="relative min-w-[680px] sm:min-w-0 h-[320px] sm:h-[280px]">
          <canvas id="roChart" class="absolute inset-0 w-full h-full"></canvas>
        </div>
      </div>

      <div class="sm:hidden text-[11px] text-slate-500 mt-2">
        Tips: geser kiri/kanan kalau label tanggal padat.
      </div>
    </div>
  </div>

  {{-- Summary Cards --}}
  @php
    // pilih sumber cards
    $cardsSrc = ($mode === 'mtd' && !empty($cardsMtd) && is_array($cardsMtd))
      ? $cardsMtd
      : ($cards ?? []);

    $cOs = $cardsSrc['os'] ?? [];
    $cL0 = $cardsSrc['l0'] ?? [];
    $cLt = $cardsSrc['lt'] ?? [];
    $cRr = $cardsSrc['rr'] ?? [];
    $cPL = $cardsSrc['pct_lt'] ?? [];

    // tone
    [$osText,$osBg] = $deltaTone('os', $cOs['delta'] ?? null);
    [$l0Text,$l0Bg] = $deltaTone('l0', $cL0['delta'] ?? null);
    [$ltText,$ltBg] = $deltaTone('lt', $cLt['delta'] ?? null);
    [$rrText,$rrBg] = $deltaTone('rr', $cRr['delta'] ?? null);
    [$plText,$plBg] = $deltaTone('pct_lt', $cPL['delta'] ?? null);

    $growthLabel = $mode === 'mtd' ? 'Growth (MtoD)' : 'Growth (H vs H-1)';

    // baseline info (optional, aman)
    $mtdMeta  = (array)($cardsMtdMeta ?? []);
    $eomMonth = $mtdMeta['eomMonth'] ?? null;
    $lastDate = $mtdMeta['lastDate'] ?? null;

    $baselineText = '';
    if ($mode === 'mtd') {
      $b1 = $eomMonth ? \Carbon\Carbon::parse($eomMonth)->translatedFormat('M Y') : '-';
      $b2 = $lastDate ?: ($latestPosDate ?? '-');
      $baselineText = "EOM {$b1} → Latest {$b2}";
    }

    // =========================
    // ✅ KUNCI: definisikan LT->DPK counter + fallback dari cohort table ($ltLatest)
    // =========================
    $bounceArr = (array)($bounce ?? []);

    $toDpkNoa = $mode === 'mtd'
      ? (int)($bounceArr['lt_eom_to_dpk_noa'] ?? 0)
      : (int)($bounceArr['lt_to_dpk_noa'] ?? 0);

    $toDpkOs  = $mode === 'mtd'
      ? (int)($bounceArr['lt_eom_to_dpk_os'] ?? 0)
      : (int)($bounceArr['lt_to_dpk_os'] ?? 0);

    // fallback: kalau bounce belum ngisi, hitung dari cohort LT EOM -> Hari ini
    if (($toDpkNoa <= 0) && !empty($ltLatest)) {
      try {
        $col = collect($ltLatest);

        $isDpk = fn($r) =>
          ((int)($r->ft_pokok ?? 0) === 2) ||
          ((int)($r->ft_bunga ?? 0) === 2) ||
          ((int)($r->kolek ?? 0) === 2);

        $toDpkNoa = (int) $col->filter($isDpk)->count();

        // os field di cohort table kamu pakai $r->os
        $toDpkOs = (int) $col->filter($isDpk)->sum(function($r){
          return (int)($r->os ?? $r->outstanding ?? 0);
        });
      } catch (\Throwable $e) {
        // biarin 0 kalau ada masalah
      }
    }

    // build LT smart pack
    $ltPack = $ltSmartPack($cLt['delta'] ?? null, $toDpkNoa, $toDpkOs);
    $ltWrapperBg = $ltPack['forceBg'] ?? $ltBg;
    $ltHintTone  = $ltPack['tone'] ?? 'text-slate-500';

    // ✅ OPTIONAL: %LT juga bisa misleading saat LT turun karena migrasi ke DPK (bisa bikin %LT tampak membaik)
    $plDelta = $cPL['delta'] ?? null;
    $plMislead = (!is_null($plDelta) && (float)$plDelta < 0 && $toDpkNoa > 0);
  @endphp

  <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 sm:gap-3">

    {{-- OS --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="flex items-start justify-between gap-2">
        <div class="text-[11px] sm:text-xs text-slate-500">
          Latest OS
          @if($mode==='mtd')
            <div class="text-[10px] text-slate-400 mt-0.5">{{ $baselineText }}</div>
          @endif
        </div>
      </div>

      <div class="text-base sm:text-lg font-extrabold text-slate-900 mt-1 leading-snug">
        {{ $fmtRpFull($cOs['value'] ?? 0) }}
      </div>

      <div class="mt-3">
        <div class="text-[11px] text-slate-500">{{ $growthLabel }}</div>
        <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $osBg }}">
          <span class="text-sm font-bold {{ $osText }}">{{ $fmtDeltaRp($cOs['delta'] ?? null) }}</span>
          <span class="text-[11px] text-slate-500">
            @if($mode==='daily')
              {{ $prevDate ? '' : '(prev n/a)' }}
            @else
              vs EOM
            @endif
          </span>
        </div>
      </div>
    </div>

    {{-- L0 --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-[11px] sm:text-xs text-slate-500">
        Latest L0
        @if($mode==='mtd')
          <div class="text-[10px] text-slate-400 mt-0.5">{{ $baselineText }}</div>
        @endif
      </div>

      <div class="text-base sm:text-lg font-extrabold text-slate-900 mt-1 leading-snug">
        {{ $fmtRpFull($cL0['value'] ?? 0) }}
      </div>

      <div class="mt-3">
        <div class="text-[11px] text-slate-500">{{ $growthLabel }}</div>
        <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $l0Bg }}">
          <span class="text-sm font-bold {{ $l0Text }}">{{ $fmtDeltaRp($cL0['delta'] ?? null) }}</span>
          <span class="text-[11px] text-slate-500">{{ $deltaHint('l0', $cL0['delta'] ?? null) }}</span>
        </div>
      </div>
    </div>

    {{-- LT --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-[11px] sm:text-xs text-slate-500">
        Latest LT
        @if($mode==='mtd')
          <div class="text-[10px] text-slate-400 mt-0.5">{{ $baselineText }}</div>
        @endif
      </div>

      <div class="text-base sm:text-lg font-extrabold text-slate-900 mt-1 leading-snug">
        {{ $fmtRpFull($cLt['value'] ?? 0) }}
      </div>

      <div class="mt-3">
        <div class="text-[11px] text-slate-500">{{ $growthLabel }}</div>

        <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $ltWrapperBg }}">
          <span class="text-sm font-bold {{ $ltText }}">{{ $fmtDeltaRp($cLt['delta'] ?? null) }}</span>
          <span class="text-[11px] {{ $ltHintTone }}">{{ $ltPack['hint'] }}</span>
        </div>

        @if($toDpkNoa > 0)
          <div class="mt-2 text-[11px] text-amber-700">
            ⚠ Ada migrasi LT→DPK: <b>{{ $toDpkNoa }}</b> NOA (OS ± Rp {{ number_format($toDpkOs,0,',','.') }})
          </div>
        @endif
      </div>
    </div>

    {{-- RR --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-[11px] sm:text-xs text-slate-500">
        RR (%L0)
        @if($mode==='mtd')
          <div class="text-[10px] text-slate-400 mt-0.5">{{ $baselineText }}</div>
        @endif
      </div>

      <div class="text-base sm:text-lg font-extrabold text-slate-900 mt-1 leading-snug">
        {{ $fmtPct($cRr['value'] ?? null) }}
      </div>

      <div class="mt-3">
        <div class="text-[11px] text-slate-500">{{ $growthLabel }}</div>
        <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $rrBg }}">
          <span class="text-sm font-bold {{ $rrText }}">{{ $fmtDeltaPts($cRr['delta'] ?? null) }}</span>
          <span class="text-[11px] text-slate-500">{{ $deltaHint('rr', $cRr['delta'] ?? null) }}</span>
        </div>
      </div>
    </div>

    {{-- %LT --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4 col-span-2 sm:col-span-1">
      <div class="text-[11px] sm:text-xs text-slate-500">
        %LT
        @if($mode==='mtd')
          <div class="text-[10px] text-slate-400 mt-0.5">{{ $baselineText }}</div>
        @endif
      </div>

      <div class="text-base sm:text-lg font-extrabold text-slate-900 mt-1 leading-snug">
        {{ $fmtPct($cPL['value'] ?? null) }}
      </div>

      <div class="mt-3">
        <div class="text-[11px] text-slate-500">{{ $growthLabel }}</div>

        @php
          // kalau %LT terlihat "membaik" tapi sebenarnya LT turun karena pindah ke DPK, kasih amber hint
          $plWrapper = $plBg;
          $plHintText = $deltaHint('pct_lt', $cPL['delta'] ?? null);
          $plHintTone = 'text-slate-500';

          if ($plMislead) {
            $plWrapper  = 'bg-amber-50 border-amber-200';
            $plHintText = "Turun terlihat membaik, tapi ada LT→DPK ({$toDpkNoa} NOA)";
            $plHintTone = 'text-amber-700';
          }
        @endphp

        <div class="inline-flex items-center gap-2 mt-1 rounded-xl border px-3 py-1.5 {{ $plWrapper }}">
          <span class="text-sm font-bold {{ $plText }}">{{ $fmtDeltaPts($cPL['delta'] ?? null) }}</span>
          <span class="text-[11px] {{ $plHintTone }}">{{ $plHintText }}</span>
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

    <div class="grid grid-cols-1 md:grid-cols-4 gap-2 sm:gap-3 mt-3">
      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
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
      </div>

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
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Aksi</th>
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

              <td class="px-3 py-2 text-center">
                <label class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 border border-slate-200 bg-white">
                  <input type="checkbox"
                         class="ro-plan-checkbox h-4 w-4"
                         data-account="{{ $acc }}"
                         data-ao="{{ $meAo }}"
                         {{ $plannedToday ? 'checked' : '' }}
                         {{ $locked ? 'disabled' : '' }}>
                  <span class="text-sm {{ $locked ? 'text-slate-400' : 'text-slate-700' }}">
                    {{ $locked ? 'Done' : ($plannedToday ? 'Ya' : 'Tidak') }}
                  </span>
                </label>
              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                <a href="{{ route('ro_visits.create', ['account_no' => $acc, 'back' => request()->fullUrl()]) }}"
                  class="text-xs font-semibold underline text-slate-700">
                  Isi LKH
                </a>
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
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Aksi</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($ltLatest ?? []) as $r)
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

              <td class="px-3 py-2 text-center">
                <label class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 border border-slate-200 bg-white">
                  <input type="checkbox"
                        class="ro-plan-checkbox h-4 w-4"
                        data-account="{{ $acc }}"
                        data-ao="{{ $meAo }}"
                        {{ $plannedToday ? 'checked' : '' }}
                        {{ $locked ? 'disabled' : '' }}>
                  <span class="text-sm {{ $locked ? 'text-slate-400' : 'text-slate-700' }}">
                    {{ $locked ? 'Done' : ($plannedToday ? 'Ya' : 'Tidak') }}
                  </span>
                </label>
              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit !== '' ? $planVisit : '-' }}</span>
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                <a href="{{ route('ro_visits.create', ['account_no' => $acc, 'back' => request()->fullUrl()]) }}"
                  class="text-xs font-semibold underline text-slate-700">
                  Isi LKH
                </a>
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
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Aksi</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($jtAngsuran ?? []) as $r)
            @php
              $due = !empty($r->due_date) ? \Carbon\Carbon::parse($r->due_date) : null;

              $plannedToday = (int)($r->planned_today ?? 0) === 1;
              $planVisitDateRaw = $r->plan_visit_date ?? null;
              $planVisit = !empty($planVisitDateRaw) ? \Carbon\Carbon::parse($planVisitDateRaw)->format('d/m/Y') : '-';
              $acc = (string)($r->account_no ?? '');
              $locked = (string)($r->plan_status ?? '') === 'done';

              $prog = $progressText($r);
              $progClass = $progressBadgeClass((string)$prog);
            @endphp
            <tr>
              <td class="px-3 py-2 whitespace-nowrap">{{ $due ? $due->format('d/m/Y') : '-' }}</td>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $r->account_no ?? '-' }}</td>
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

              <td class="px-3 py-2 text-center">
                <label class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 border border-slate-200 bg-white">
                  <input type="checkbox"
                         class="ro-plan-checkbox h-4 w-4"
                         data-account="{{ $acc }}"
                         data-ao="{{ $meAo }}"
                         {{ $plannedToday ? 'checked' : '' }}
                         {{ $locked ? 'disabled' : '' }}>
                  <span class="text-sm {{ $locked ? 'text-slate-400' : 'text-slate-700' }}">
                    {{ $locked ? 'Done' : ($plannedToday ? 'Ya' : 'Tidak') }}
                  </span>
                </label>
              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                <a href="{{ route('ro_visits.create', ['account_no' => $acc, 'back' => request()->fullUrl()]) }}"
                  class="text-xs font-semibold underline text-slate-700">
                  Isi LKH
                </a>
              </td>

            </tr>
          @empty
            <tr>
              <td colspan="12" class="px-3 py-6 text-center text-slate-500">Tidak ada JT angsuran minggu ini.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- ===========================
      4) OS >= 500jt
      =========================== --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-3 sm:p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900 text-sm sm:text-base">OS ≥ 500 Juta – Posisi Terakhir</div>
      <div class="text-[11px] sm:text-xs text-slate-500 mt-1">
        Filter: outstanding ≥ 500.000.000. Posisi: <b>{{ $latestPosDate ?? '-' }}</b>.
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
            <th class="text-center px-3 py-2 whitespace-nowrap">Progres (H-1→H)</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Aksi</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($osBig ?? []) as $r)
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
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $r->account_no ?? '-' }}</td>
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

              <td class="px-3 py-2 text-center">
                <label class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 border border-slate-200 bg-white">
                  <input type="checkbox"
                         class="ro-plan-checkbox h-4 w-4"
                         data-account="{{ $acc }}"
                         data-ao="{{ $meAo }}"
                         {{ $plannedToday ? 'checked' : '' }}
                         {{ $locked ? 'disabled' : '' }}>
                  <span class="text-sm {{ $locked ? 'text-slate-400' : 'text-slate-700' }}">
                    {{ $locked ? 'Done' : ($plannedToday ? 'Ya' : 'Tidak') }}
                  </span>
                </label>
              </td>

              <td class="px-3 py-2 whitespace-nowrap">
                <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
              </td>

              <td class="px-3 py-2 text-center whitespace-nowrap">
                <a href="{{ route('ro_visits.create', ['account_no' => $acc, 'back' => request()->fullUrl()]) }}"
                  class="text-xs font-semibold underline text-slate-700">
                  Isi LKH
                </a>
              </td>

            </tr>
          @empty
            <tr>
              <td colspan="11" class="px-3 py-6 text-center text-slate-500">Tidak ada OS ≥ 500 juta.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>

{{-- =============================
    CHART JS (tetap)
   ============================= --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>

<script>
  const labels = @json($labels);
  const series = @json($series);

  const isNil = v => v === null || typeof v === 'undefined';
  const isBad = v => isNil(v) || Number.isNaN(Number(v));

  function toGrowth(arr){
    const out = [];
    for (let i=0;i<arr.length;i++){
      const cur = arr[i];
      const prev = i>0 ? arr[i-1] : null;
      if (isNil(cur) || isNil(prev)) out.push(null);
      else out.push(Number(cur)-Number(prev));
    }
    return out;
  }

  function isMobile(){ return window.matchMedia('(max-width: 640px)').matches; }

  Chart.register(ChartDataLabels);

  let mode = 'value';
  let showAllLines = false;
  let showAllPointLabels = false;

  const ctx = document.getElementById('roChart').getContext('2d');

  function dlValue(ctx){
    const raw = ctx?.dataset?.data?.[ctx.dataIndex];
    if (raw && typeof raw === 'object') return raw.y;
    return raw;
  }

  const chart = new Chart(ctx, {
    type: 'line',
    data: { labels, datasets: [] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },

      plugins: {
        legend: {
          display: true,
          position: 'bottom',
          labels: { usePointStyle: true, pointStyle: 'line' }
        },

        datalabels: {
          anchor: 'end',
          align: 'top',
          offset: 6,
          clamp: true,
          clip: false,
          font: { size: isMobile() ? 9 : 10, weight: '600' },

          display: (ctx) => {
            const v = dlValue(ctx);
            if (isBad(v)) return false;

            if (showAllPointLabels) {
              if (isMobile()) return (ctx.dataIndex % 2 === 0);
              return true;
            }

            const data = ctx.dataset.data || [];
            let last = -1;
            for (let i = data.length - 1; i >= 0; i--) {
              const vv = (data[i] && typeof data[i] === 'object') ? data[i].y : data[i];
              if (!isBad(vv)) { last = i; break; }
            }
            return ctx.dataIndex === last;
          },

          formatter: (_value, ctx) => {
            const v = dlValue(ctx);
            if (isBad(v)) return '';
            const isPct = ctx.dataset.yAxisID === 'yPct';
            return isPct ? fmtPct(v) : fmtCompactRp(v);
          },
        }
      },

      scales: {
        yRp: { type: 'linear', position: 'left' },
        yPct:{ type: 'linear', position: 'right', grid:{ drawOnChartArea:false } }
      }
    }
  });

  function applyMobileDatasetRules(datasets){
    if (!isMobile()) return datasets;
    if (showAllLines) return datasets.map(ds => ({ ...ds, hidden:false }));
    const maxLines = 3;
    return datasets.map((ds, idx) => ({ ...ds, hidden: idx >= maxLines }));
  }

  function rebuild(){
    const s = (k)=> (mode==='growth') ? toGrowth(series[k]||[]) : (series[k]||[]);
    const pr  = isMobile() ? 3 : 4;
    const phr = isMobile() ? 5 : 6;

    let dss = [
      { label:'OS Total', data:s('os_total'), yAxisID:'yRp',  tension:0.2, spanGaps:false, borderWidth:2, pointRadius:pr, pointHoverRadius:phr },
      { label:'OS L0',    data:s('os_l0'),    yAxisID:'yRp',  tension:0.2, spanGaps:false, borderWidth:2, pointRadius:pr, pointHoverRadius:phr },
      { label:'OS LT',    data:s('os_lt'),    yAxisID:'yRp',  tension:0.2, spanGaps:false, borderWidth:2, pointRadius:pr, pointHoverRadius:phr },
      { label:'RR (%L0)', data:s('rr'),       yAxisID:'yPct', tension:0.2, spanGaps:false, borderWidth:2, pointRadius:pr, pointHoverRadius:phr },
      { label:'%LT',      data:s('pct_lt'),   yAxisID:'yPct', tension:0.2, spanGaps:false, borderWidth:2, pointRadius:pr, pointHoverRadius:phr },
    ];

    dss = applyMobileDatasetRules(dss);
    chart.data.datasets = dss;
    chart.update();
  }

  function fmtPct(v){ return Number(v||0).toLocaleString('id-ID',{maximumFractionDigits:2}) + '%'; }

  function fmtCompactRp(v){
    const n = Number(v || 0);
    const abs = Math.abs(n);
    if (abs >= 1e12) return (n/1e12).toFixed(2).replace('.',',') + 'T';
    if (abs >= 1e9)  return (n/1e9 ).toFixed(2).replace('.',',') + 'M';
    if (abs >= 1e6)  return (n/1e6 ).toFixed(1).replace('.',',') + 'jt';
    return n.toLocaleString('id-ID');
  }

  rebuild();

  // UI controls
  const btnModeValue   = document.getElementById('btnModeValue');
  const btnModeGrowth  = document.getElementById('btnModeGrowth');
  const btnLabelsLast  = document.getElementById('btnLabelsLastOnly');
  const btnLabelsAll   = document.getElementById('btnLabelsAll');
  const btnShowAllLines= document.getElementById('btnShowAllLines');

  function setActive(btnOn, btnOff){
    btnOn.classList.add('bg-white','shadow-sm','border','border-slate-200');
    btnOn.classList.remove('text-slate-700');
    btnOff.classList.remove('bg-white','shadow-sm','border','border-slate-200');
    btnOff.classList.add('text-slate-700');
  }

  btnModeValue?.addEventListener('click', () => {
    mode = 'value';
    setActive(btnModeValue, btnModeGrowth);
    rebuild();
  });

  btnModeGrowth?.addEventListener('click', () => {
    mode = 'growth';
    setActive(btnModeGrowth, btnModeValue);
    rebuild();
  });

  btnLabelsLast?.addEventListener('click', () => {
    showAllPointLabels = false;
    setActive(btnLabelsLast, btnLabelsAll);
    chart.update();
  });

  btnLabelsAll?.addEventListener('click', () => {
    showAllPointLabels = true;
    setActive(btnLabelsAll, btnLabelsLast);
    chart.update();
  });

  btnShowAllLines?.addEventListener('click', () => {
    showAllLines = !showAllLines;
    btnShowAllLines.textContent = showAllLines ? 'Mode ringkas' : 'Tampilkan semua garis';
    rebuild();
  });

  // Copy TLRO narrative
  const btnCopy = document.getElementById('btnCopyTlro');
  const hint = document.getElementById('copyHint');
  btnCopy?.addEventListener('click', async () => {
    const text = (document.getElementById('tlroNarrative')?.textContent || '').trim();
    try {
      await navigator.clipboard.writeText(text);
      hint.textContent = '✅ Tercopy';
      setTimeout(() => hint.textContent = '', 1500);
    } catch (e) {
      hint.textContent = '❌ Gagal copy (browser block)';
      setTimeout(() => hint.textContent = '', 2500);
    }
  });
</script>

@endsection
