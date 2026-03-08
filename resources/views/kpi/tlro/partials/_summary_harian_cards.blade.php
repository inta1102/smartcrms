@php
  $fmtRp = $fmtRp ?? function($v) {
    return 'Rp ' . number_format((int)($v ?? 0), 0, ',', '.');
  };

  $fmtRpDelta = $fmtRpDelta ?? function($v) {
    if ($v === null) return '-';
    $n = (int)$v;
    return ($n >= 0 ? '+' : '') . 'Rp ' . number_format($n, 0, ',', '.');
  };

  $fmtPct = $fmtPct ?? function($v) {
    if ($v === null) return '-';
    return number_format((float)$v, 2, ',', '.') . '%';
  };

  $ltTlPack = $ltTlPack ?? function($deltaLt, array $bounce) {
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

    $deltaTone = $d > 0 ? 'text-rose-700' : ($d < 0 ? 'text-emerald-700' : 'text-slate-500');
    $hintTone  = 'text-slate-500';
    $hint      = $d > 0 ? 'LT naik = memburuk' : ($d < 0 ? 'LT turun = membaik' : 'stagnan');

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

  $cOS  = $cOS  ?? ($cards['os'] ?? ['value'=>null,'delta'=>null]);
  $cL0  = $cL0  ?? ($cards['l0'] ?? ['value'=>null,'delta'=>null]);
  $cLT  = $cLT  ?? ($cards['lt'] ?? ['value'=>null,'delta'=>null]);
  $cRR  = $cRR  ?? ($cards['rr'] ?? ['value'=>null,'delta'=>null]);
  $cPLT = $cPLT ?? ($cards['pct_lt'] ?? ['value'=>null,'delta'=>null]);
  $cDPK = $cDPK ?? ($cards['dpk'] ?? ['value'=>null,'delta'=>null]);

  $bounce = $bounce ?? [];

  $ltPack = $ltTlPack($cLT['delta'] ?? null, (array)$bounce);
  $pltDelta = $cPLT['delta'] ?? null;
  $pltTone  = is_null($pltDelta) ? 'text-slate-500' : (((float)$pltDelta <= 0) ? 'text-emerald-700' : 'text-rose-700');
  $toDpkNoa = (int)($bounce['lt_to_dpk_noa'] ?? 0);
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-4 space-y-4">
  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">

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
      </div>

      <div class="text-[11px] text-slate-500 mt-1">
        RR = L0/OS · Δ dalam <b>points</b>
      </div>
    </div>

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
      </div>

      <div class="text-[11px] {{ $ltPack['hintTone'] }} mt-1">
        {{ $ltPack['hint'] }}
      </div>

      @if($toDpkNoa > 0)
        <div class="mt-2 text-[11px] text-amber-700">
          ⚠ Fokus: migrasi LT→DPK hari ini
        </div>
      @endif

      <div class="text-[11px] text-slate-500 mt-1">
        %LT = LT/OS · %LT turun = membaik
      </div>
    </div>

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

  </div>
</div>