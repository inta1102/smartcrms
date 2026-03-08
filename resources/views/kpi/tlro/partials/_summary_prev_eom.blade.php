@php
  $m1 = $summaryM1 ?? ['os'=>0,'l0'=>0,'lt'=>0,'dpk'=>0];

  $fmtRp = $fmtRp ?? function($v) {
    return 'Rp ' . number_format((int)($v ?? 0), 0, ',', '.');
  };
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