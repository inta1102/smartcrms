@extends('layouts.app')

@section('title', 'Dashboard RO - OS Harian')

@section('content')
@php
  $meAo = str_pad(trim((string)(auth()->user()?->ao_code ?? '')), 6, '0', STR_PAD_LEFT);
  if ($meAo === '000000') $meAo = '';

  // helper tampilan growth
  $fmtMoney = function($n){
    if (is_null($n)) return '-';
    $n = (int)$n;
    $abs = abs($n);
    // compact (jt/M) khusus label kecil
    if ($abs >= 1000000000) return ($n/1000000000) >= 0
        ? number_format($n/1000000000, 2, ',', '.') . 'M'
        : '-' . number_format($abs/1000000000, 2, ',', '.') . 'M';
    if ($abs >= 1000000) return ($n/1000000) >= 0
        ? number_format($n/1000000, 1, ',', '.') . 'jt'
        : '-' . number_format($abs/1000000, 1, ',', '.') . 'jt';
    return number_format($n, 0, ',', '.');
  };
  $fmtDeltaMoney = function($n) use ($fmtMoney){
    if (is_null($n)) return '-';
    $n = (int)$n;
    $sign = $n >= 0 ? '+' : '-';
    return $sign . 'Rp ' . $fmtMoney(abs($n));
  };
  $fmtPct = function($n){
    if (is_null($n)) return '-';
    return number_format((float)$n, 2, ',', '.') . '%';
  };
  $fmtDeltaPct = function($n){
    if (is_null($n)) return '-';
    $n = (float)$n;
    $sign = $n >= 0 ? '+' : '-';
    return $sign . number_format(abs($n), 2, ',', '.') . ' pp';
  };
@endphp

<div class="max-w-6xl mx-auto p-3 sm:p-4 space-y-4 sm:space-y-5">

  {{-- Header --}}
  <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div>
      <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900">📈 Dashboard RO – OS Harian</h1>
      <p class="text-xs sm:text-sm text-slate-500 mt-1">
        Scope: <b>RO sendiri</b>. Data snapshot harian (kpi_os_daily_aos). Posisi terakhir: <b>{{ $latestPosDate }}</b>.
      </p>
      @if(!empty($latestDate))
        <p class="text-[11px] sm:text-xs text-slate-500 mt-1">
          Snapshot terakhir: <b>{{ $latestDate }}</b>
          @if(!empty($prevDate))
            (banding H-1 data: <b>{{ $prevDate }}</b>)
          @endif
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
      <button class="col-span-2 sm:col-auto w-full sm:w-auto rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
        Tampilkan
      </button>
    </form>
  </div>

  {{-- Summary (Value + Growth H vs H-1) --}}
  <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 sm:gap-3">
    @php
      $cOs = $cards['os'] ?? null;
      $cL0 = $cards['l0'] ?? null;
      $cLt = $cards['lt'] ?? null;
      $cRr = $cards['rr'] ?? null;
      $cPl = $cards['pct_lt'] ?? null;
    @endphp

    {{-- OS --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-[11px] sm:text-xs text-slate-500">Latest OS</div>
      <div class="text-base sm:text-lg font-extrabold text-slate-900 mt-1 leading-snug">
        Rp {{ number_format((int)($latestOs ?? 0),0,',','.') }}
      </div>
      <div class="mt-2 flex items-center justify-between text-[11px] sm:text-xs">
        <span class="text-slate-500">Growth (H vs H-1)</span>
        @php $d = $cOs['delta'] ?? null; @endphp
        <span class="font-bold {{ is_null($d) ? 'text-slate-400' : ($d>=0 ? 'text-emerald-700' : 'text-rose-700') }}">
          {{ $fmtDeltaMoney($d) }}
        </span>
      </div>
    </div>

    {{-- L0 --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-[11px] sm:text-xs text-slate-500">Latest L0</div>
      <div class="text-base sm:text-lg font-extrabold text-slate-900 mt-1 leading-snug">
        Rp {{ number_format((int)($latestL0 ?? 0),0,',','.') }}
      </div>
      <div class="mt-2 flex items-center justify-between text-[11px] sm:text-xs">
        <span class="text-slate-500">Growth (H vs H-1)</span>
        @php $d = $cL0['delta'] ?? null; @endphp
        <span class="font-bold {{ is_null($d) ? 'text-slate-400' : ($d<=0 ? 'text-emerald-700' : 'text-rose-700') }}">
          {{-- L0 naik = buruk (merah), turun = baik (hijau) --}}
          {{ $fmtDeltaMoney($d) }}
        </span>
      </div>
    </div>

    {{-- LT --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-[11px] sm:text-xs text-slate-500">Latest LT</div>
      <div class="text-base sm:text-lg font-extrabold text-slate-900 mt-1 leading-snug">
        Rp {{ number_format((int)($latestLT ?? 0),0,',','.') }}
      </div>
      <div class="mt-2 flex items-center justify-between text-[11px] sm:text-xs">
        <span class="text-slate-500">Growth (H vs H-1)</span>
        @php $d = $cLt['delta'] ?? null; @endphp
        <span class="font-bold {{ is_null($d) ? 'text-slate-400' : ($d<=0 ? 'text-emerald-700' : 'text-rose-700') }}">
          {{-- LT naik = buruk (merah), turun = baik (hijau) --}}
          {{ $fmtDeltaMoney($d) }}
        </span>
      </div>
    </div>

    {{-- RR --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-[11px] sm:text-xs text-slate-500">RR (%L0)</div>
      <div class="text-base sm:text-lg font-extrabold text-slate-900 mt-1 leading-snug">
        {{ is_null($latestRR) ? '-' : number_format((float)$latestRR,2,',','.') . '%' }}
      </div>
      <div class="mt-2 flex items-center justify-between text-[11px] sm:text-xs">
        <span class="text-slate-500">Δ (pp)</span>
        @php $d = $cRr['delta'] ?? null; @endphp
        <span class="font-bold {{ is_null($d) ? 'text-slate-400' : ($d>=0 ? 'text-emerald-700' : 'text-rose-700') }}">
          {{ $fmtDeltaPct($d) }}
        </span>
      </div>
    </div>

    {{-- %LT --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4 col-span-2 sm:col-span-1">
      <div class="text-[11px] sm:text-xs text-slate-500">%LT</div>
      <div class="text-base sm:text-lg font-extrabold text-slate-900 mt-1 leading-snug">
        {{ is_null($latestPctLt) ? '-' : number_format((float)$latestPctLt,2,',','.') . '%' }}
      </div>
      <div class="mt-2 flex items-center justify-between text-[11px] sm:text-xs">
        <span class="text-slate-500">Δ (pp)</span>
        @php $d = $cPl['delta'] ?? null; @endphp
        <span class="font-bold {{ is_null($d) ? 'text-slate-400' : ($d<=0 ? 'text-emerald-700' : 'text-rose-700') }}">
          {{-- %LT naik = buruk (merah), turun = baik (hijau) --}}
          {{ $fmtDeltaPct($d) }}
        </span>
      </div>
    </div>
  </div>

  {{-- Insight --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
    <div class="font-extrabold text-slate-900 text-sm sm:text-base">🧠 Catatan Kinerja (Auto Insight)</div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-2 sm:gap-3 mt-3">
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
    </div>
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
            @endphp
            <tr>
              <td class="px-3 py-2 whitespace-nowrap">{{ \Carbon\Carbon::parse($r->maturity_date)->format('d/m/Y') }}</td>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $r->account_no ?? '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">Rp {{ number_format((int)($r->outstanding ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ $r->kolek ?? '-' }}</td>

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
              <td colspan="9" class="px-3 py-6 text-center text-slate-500">Tidak ada JT bulan ini.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- ===========================
      2) LT posisi terakhir
      =========================== --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-3 sm:p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900 text-sm sm:text-base">LT (FT = 1) – Posisi Terakhir</div>
      <div class="text-[11px] sm:text-xs text-slate-500 mt-1">
        Definisi: ft_pokok = 1 atau ft_bunga = 1. Posisi: <b>{{ $latestPosDate ?? '-' }}</b>.
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
            <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          @forelse(($ltLatest ?? []) as $r)
            @php
              $plannedToday = (int)($r->planned_today ?? 0) === 1;
              $planVisitDateRaw = $r->plan_visit_date ?? null;
              $planVisit = !empty($planVisitDateRaw) ? \Carbon\Carbon::parse($planVisitDateRaw)->format('d/m/Y') : '-';
              $acc = (string)($r->account_no ?? '');
              $locked = (string)($r->plan_status ?? '') === 'done';
            @endphp
            <tr>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $r->account_no ?? '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ $r->kolek ?? '-' }}</td>

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
              <td colspan="10" class="px-3 py-6 text-center text-slate-500">Tidak ada LT pada posisi terakhir.</td>
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
              <td colspan="11" class="px-3 py-6 text-center text-slate-500">Tidak ada JT angsuran minggu ini.</td>
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
            @endphp
            <tr>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $r->account_no ?? '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ $r->kolek ?? '-' }}</td>

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
              <td colspan="10" class="px-3 py-6 text-center text-slate-500">Tidak ada OS ≥ 500 juta.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>

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

  function fmtPct(v){ return Number(v||0).toLocaleString('id-ID',{maximumFractionDigits:2}) + '%'; }

  function fmtCompactRp(v){
    const n = Number(v || 0);
    const abs = Math.abs(n);
    if (abs >= 1e12) return (n/1e12).toFixed(2).replace('.',',') + 'T';
    if (abs >= 1e9)  return (n/1e9 ).toFixed(2).replace('.',',') + 'M';
    if (abs >= 1e6)  return (n/1e6 ).toFixed(1).replace('.',',') + 'jt';
    return n.toLocaleString('id-ID');
  }

  // ✅ plugin
  Chart.register(ChartDataLabels);

  let mode = 'value';
  let showAllLines = false;
  let showAllPointLabels = false;

  const ctx = document.getElementById('roChart').getContext('2d');

  // helper ambil value yang aman (number / {x,y})
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

          // ✅ jangan taruh "function dlValue" di options (itu bikin chart blank).
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

  // ========= tombol mode + label =========
  const btnValue  = document.getElementById('btnModeValue');
  const btnGrowth = document.getElementById('btnModeGrowth');

  const btnLabelsLastOnly = document.getElementById('btnLabelsLastOnly');
  const btnLabelsAll      = document.getElementById('btnLabelsAll');

  function paintMode(){
    if (mode==='value'){
      btnValue.className='flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
      btnGrowth.className='flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg text-slate-700';
    } else {
      btnValue.className='flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg text-slate-700';
      btnGrowth.className='flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
    }
  }

  function paintLabels(){
    if (!btnLabelsLastOnly || !btnLabelsAll) return;
    if (!showAllPointLabels) {
      btnLabelsLastOnly.className='flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
      btnLabelsAll.className     ='flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg text-slate-700';
    } else {
      btnLabelsLastOnly.className='flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg text-slate-700';
      btnLabelsAll.className     ='flex-1 sm:flex-none px-3 py-2 sm:py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
    }
  }

  function repaintShowAllButton(){
    const btn = document.getElementById('btnShowAllLines');
    if (!btn) return;
    btn.textContent = showAllLines ? 'Tampilkan ringkas' : 'Tampilkan semua garis';
    btn.className = showAllLines
      ? 'w-full rounded-xl border border-slate-200 bg-slate-900 px-3 py-2 text-xs font-semibold text-white'
      : 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-800';
  }

  btnValue?.addEventListener('click', ()=>{ mode='value'; paintMode(); rebuild(); });
  btnGrowth?.addEventListener('click', ()=>{ mode='growth'; paintMode(); rebuild(); });

  btnLabelsLastOnly?.addEventListener('click', ()=>{ showAllPointLabels=false; paintLabels(); rebuild(); });
  btnLabelsAll?.addEventListener('click', ()=>{ showAllPointLabels=true; paintLabels(); rebuild(); });

  document.getElementById('btnShowAllLines')?.addEventListener('click', ()=>{
    showAllLines=!showAllLines;
    repaintShowAllButton();
    rebuild();
  });

  let resizeT = null;
  window.addEventListener('resize', ()=>{
    clearTimeout(resizeT);
    resizeT = setTimeout(()=>{
      repaintShowAllButton();
      paintLabels();
      rebuild();
    }, 200);
  });

  paintMode();
  paintLabels();
  repaintShowAllButton();
  rebuild();
</script>
@endsection
