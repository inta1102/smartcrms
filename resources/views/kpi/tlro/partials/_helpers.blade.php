@php
  $riskMeta = function($dpd, $kolek, $os, $lastVisitRaw, $isLt = false) {
    $dpd = (int)($dpd ?? 0);
    $os  = (int)($os ?? 0);

    $kolekRaw = $kolek;
    $kolekNum = null;
    if (is_numeric($kolekRaw)) $kolekNum = (int)$kolekRaw;

    $age = null;
    if (!empty($lastVisitRaw)) {
      try { $age = \Carbon\Carbon::parse($lastVisitRaw)->diffInDays(now()); }
      catch (\Throwable $e) { $age = null; }
    }

    $score = 0;
    $reasons = [];

    if ($dpd >= 60) { $score += 40; $reasons[] = "DPD ≥ 60 (+40)"; }
    elseif ($dpd >= 30) { $score += 30; $reasons[] = "DPD 30–59 (+30)"; }
    elseif ($dpd >= 15) { $score += 20; $reasons[] = "DPD 15–29 (+20)"; }
    elseif ($dpd >= 8)  { $score += 10; $reasons[] = "DPD 8–14 (+10)"; }
    else { $reasons[] = "DPD 0–7 (+0)"; }

    if ($kolekNum !== null) {
      if ($kolekNum >= 5) { $score += 50; $reasons[] = "Kolek 5 (+50)"; }
      elseif ($kolekNum === 4) { $score += 40; $reasons[] = "Kolek 4 (+40)"; }
      elseif ($kolekNum === 3) { $score += 30; $reasons[] = "Kolek 3 (+30)"; }
      elseif ($kolekNum === 2) { $score += 15; $reasons[] = "Kolek 2 (+15)"; }
      else { $reasons[] = "Kolek 1 (+0)"; }
    } else {
      $reasons[] = "Kolek non-angka (skip)";
    }

    if ($os >= 1000000000) { $score += 30; $reasons[] = "OS ≥ 1M (+30)"; }
    elseif ($os >= 500000000) { $score += 20; $reasons[] = "OS 500–999jt (+20)"; }
    elseif ($os >= 100000000) { $score += 10; $reasons[] = "OS 100–499jt (+10)"; }
    else { $reasons[] = "OS < 100jt (+0)"; }

    if ($age === null) {
      $score += 20; $reasons[] = "Belum ada visit / kosong (+20)";
    } else {
      if ($age >= 30) { $score += 30; $reasons[] = "Umur visit ≥ 30 hari (+30)"; }
      elseif ($age >= 14) { $score += 20; $reasons[] = "Umur visit 14–29 hari (+20)"; }
      elseif ($age >= 7)  { $score += 10; $reasons[] = "Umur visit 7–13 hari (+10)"; }
      else { $reasons[] = "Umur visit 0–6 hari (+0)"; }
    }

    $level = 'LOW';
    $cls   = 'bg-emerald-50 text-emerald-700 border-emerald-200';
    if ($score >= 80) { $level = 'CRITICAL'; $cls = 'bg-rose-100 text-rose-800 border-rose-300'; }
    elseif ($score >= 60) { $level = 'HIGH'; $cls = 'bg-rose-50 text-rose-700 border-rose-200'; }
    elseif ($score >= 35) { $level = 'MEDIUM'; $cls = 'bg-amber-50 text-amber-700 border-amber-200'; }

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

  $fmtRp = fn($v) => 'Rp ' . number_format((int)($v ?? 0), 0, ',', '.');

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

  $sum = $sum ?? 'mtd';
  if (!in_array($sum, ['day','mtd'], true)) $sum = 'mtd';

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

    if ($isLt) {
      return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">LT</span>';
    }

    if ($dpd >= 30 || ($kolekNum !== null && $kolekNum >= 3)) {
      return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">High</span>';
    }
    if ($dpd >= 8 || ($kolekNum !== null && $kolekNum === 2)) {
      return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">Medium</span>';
    }
    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">Low</span>';
  };
@endphp