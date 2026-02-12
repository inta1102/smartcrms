@extends('layouts.app')

@section('title', 'Dashboard TL RO - OS Harian')

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-5">

  {{-- Header --}}
  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">📈 Dashboard TL RO – OS Harian</h1>
      <p class="text-sm text-slate-500">
        Scope: {{ $aoCount }} staff. Data snapshot harian (kpi_os_daily_aos). Posisi terakhir: <b>{{ $latestPosDate ?? '-' }}</b>.
      </p>
    </div>

    <form method="GET" class="flex items-end gap-2 flex-wrap">
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

  {{-- Summary cards --}}
  <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">OS Terakhir</div>
      <div class="text-xl font-extrabold text-slate-900">
        Rp {{ number_format((int)$latestOs, 0, ',', '.') }}
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">OS H-1</div>
      <div class="text-xl font-extrabold text-slate-900">
        Rp {{ number_format((int)$prevOs, 0, ',', '.') }}
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">Perubahan (Terakhir vs H-1)</div>
      <div class="text-xl font-extrabold {{ $delta >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
        {{ $delta >= 0 ? '+' : '' }}Rp {{ number_format((int)$delta, 0, ',', '.') }}
      </div>
    </div>
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
      {{-- legend akan lebih enak kalau chart bisa “nafas” --}}
      <div class="relative w-full h-[260px] sm:h-[360px] md:h-[420px]">
        <canvas id="osChart" class="w-full h-full"></canvas>
      </div>

      {{-- Catatan kecil buat user HP --}}
      <div class="mt-2 text-[11px] text-slate-500 sm:hidden">
        Tips: geser layar ke samping untuk melihat detail legend & garis.
      </div>
    </div>
  </div>

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
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>

            {{-- NEW --}}
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($dueThisMonth ?? []) as $r)
            @php
              $lastVisit = !empty($r->last_visit_date) ? \Carbon\Carbon::parse($r->last_visit_date)->format('d/m/Y') : '-';
              $isToday   = !empty($r->visit_today) && (int)$r->visit_today === 1;
              $planVisit = !empty($r->plan_visit_date) ? \Carbon\Carbon::parse($r->plan_visit_date)->format('d/m/Y') : '-';
            @endphp
            <tr>
              <td class="px-3 py-2 whitespace-nowrap">
                {{ \Carbon\Carbon::parse($r->maturity_date)->format('d/m/Y') }}
              </td>
              <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format((int)($r->outstanding ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>

              {{-- NEW --}}
              <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>
              <td class="px-3 py-2 text-center">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                  {{ $isToday ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-50 text-slate-600 border border-slate-200' }}">
                  {{ $isToday ? 'Ya' : 'Tidak' }}
                </span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">{{ $planVisit }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="10" class="px-3 py-6 text-center text-slate-500">
                Belum ada data jatuh tempo bulan ini.
              </td>
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
    <div class="p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900">LT (FT = 1) – Posisi Terakhir</div>
      <div class="text-xs text-slate-500 mt-1">
        Definisi: LT = ft_pokok = 1 atau ft_bunga = 1. Posisi terakhir: <b>{{ $latestPosDate ?? '-' }}</b>.
      </div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>

            {{-- NEW --}}
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($ltLatest ?? []) as $r)
            @php
              $lastVisit = !empty($r->last_visit_date) ? \Carbon\Carbon::parse($r->last_visit_date)->format('d/m/Y') : '-';
              $isToday   = !empty($r->visit_today) && (int)$r->visit_today === 1;
              $planVisit = !empty($r->plan_visit_date) ? \Carbon\Carbon::parse($r->plan_visit_date)->format('d/m/Y') : '-';
            @endphp
            <tr>
              <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>

              {{-- NEW --}}
              <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>
              <td class="px-3 py-2 text-center">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                  {{ $isToday ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-50 text-slate-600 border border-slate-200' }}">
                  {{ $isToday ? 'Ya' : 'Tidak' }}
                </span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">{{ $planVisit }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="11" class="px-3 py-6 text-center text-slate-500">
                Tidak ada data LT untuk posisi terakhir.
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
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>

            {{-- NEW --}}
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse($migrasiTunggakan as $r)
            @php
              $lastVisit = !empty($r->last_visit_date) ? \Carbon\Carbon::parse($r->last_visit_date)->format('d/m/Y') : '-';
              $isToday   = !empty($r->visit_today) && (int)$r->visit_today === 1;
              $planVisit = !empty($r->plan_visit_date) ? \Carbon\Carbon::parse($r->plan_visit_date)->format('d/m/Y') : '-';
            @endphp
            <tr>
              <td class="px-3 py-2 font-mono">{{ $r->account_no }}</td>
              <td class="px-3 py-2">{{ $r->customer_name }}</td>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format((int)$r->os,0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->kolek ?? 0) }}</td>

              {{-- NEW --}}
              <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>
              <td class="px-3 py-2 text-center">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                  {{ $isToday ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-50 text-slate-600 border border-slate-200' }}">
                  {{ $isToday ? 'Ya' : 'Tidak' }}
                </span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">{{ $planVisit }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="11" class="px-3 py-6 text-center text-slate-500">
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
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>

            {{-- NEW --}}
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @php $pos = !empty($latestPosDate) ? \Carbon\Carbon::parse($latestPosDate) : now(); @endphp

          @forelse(($jtAngsuran ?? []) as $r)
            @php
              $day = (int)($r->installment_day ?? 0);
              $day = $day > 0 ? min(max($day, 1), $pos->daysInMonth) : null;
              $due = $day ? $pos->copy()->day($day) : null;

              $lastVisit = !empty($r->last_visit_date) ? \Carbon\Carbon::parse($r->last_visit_date)->format('d/m/Y') : '-';
              $isToday   = !empty($r->visit_today) && (int)$r->visit_today === 1;
              $planVisit = !empty($r->plan_visit_date) ? \Carbon\Carbon::parse($r->plan_visit_date)->format('d/m/Y') : '-';
            @endphp
            <tr>
              <td class="px-3 py-2 whitespace-nowrap">
                {{ !empty($r->due_date) ? \Carbon\Carbon::parse($r->due_date)->format('d/m/Y') : '-' }}
              </td>
              <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->kolek ?? 0) }}</td>

              {{-- NEW --}}
              <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>
              <td class="px-3 py-2 text-center">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                  {{ $isToday ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-50 text-slate-600 border border-slate-200' }}">
                  {{ $isToday ? 'Ya' : 'Tidak' }}
                </span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">{{ $planVisit }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="12" class="px-3 py-6 text-center text-slate-500">
                Tidak ada JT angsuran minggu ini.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- ===========================
      5) OS > 500jt
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
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>

            {{-- NEW --}}
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
            <th class="text-center px-3 py-2 whitespace-nowrap">Visit Hari Ini</th>
            <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($osBig ?? []) as $r)
            @php
              $lastVisit = !empty($r->last_visit_date) ? \Carbon\Carbon::parse($r->last_visit_date)->format('d/m/Y') : '-';
              $isToday   = !empty($r->visit_today) && (int)$r->visit_today === 1;
              $planVisit = !empty($r->plan_visit_date) ? \Carbon\Carbon::parse($r->plan_visit_date)->format('d/m/Y') : '-';
            @endphp
            <tr>
              <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->kolek ?? 0) }}</td>

              {{-- NEW --}}
              <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>
              <td class="px-3 py-2 text-center">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                  {{ $isToday ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-50 text-slate-600 border border-slate-200' }}">
                  {{ $isToday ? 'Ya' : 'Tidak' }}
                </span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">{{ $planVisit }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="11" class="px-3 py-6 text-center text-slate-500">
                Tidak ada OS besar pada posisi terakhir.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  </div>

</div>

{{-- Chart.js --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  const labels = @json($labels ?? []);
  const datasetsByMetric = @json($datasetsByMetric ?? []);

  // ===== Helpers =====
  const isNil = (v) => v === null || typeof v === 'undefined';
  const fmtRp = (v) => 'Rp ' + Number(v || 0).toLocaleString('id-ID');
  const fmtPct = (v) => Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + '%';
  const isPercentMetric = (m) => (m === 'rr' || m === 'pct_lt');

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

  function lastNonNull(arr) {
    for (let i = (arr?.length || 0) - 1; i >= 0; i--) {
      if (!isNil(arr[i])) return arr[i];
    }
    return null;
  }

  // ===== State =====
  let metric = 'os_total';
  let mode = 'value'; // value|growth
  let showAllLines = false; // ✅ default: ringkas di HP


  function getRawDatasets() {
    return (datasetsByMetric && datasetsByMetric[metric]) ? datasetsByMetric[metric] : [];
  }

    function buildDatasets() {
      const raw = getRawDatasets();

      let ds = raw.map((ds) => {
        const base = ds.data || [];
        const data = (mode === 'growth') ? toGrowthSeries(base) : base;

        return {
          label: ds.label || 'Series',
          data,
          spanGaps: false,
          tension: 0.2,
          pointRadius: 0,
          pointHoverRadius: isMobile() ? 3 : 4,
          borderWidth: isMobile() ? 2 : 2,
        };
      });

      // ✅ apply mobile simplification
      ds = applyMobileDatasetRules(ds);

      return ds;
    }

  function updateKpiStrip() {
    // KPI selalu pakai value (bukan growth)
    const os = lastNonNull((datasetsByMetric?.os_total?.[0]?.data) || []);
    const l0 = lastNonNull((datasetsByMetric?.os_l0?.[0]?.data) || []);
    const lt = lastNonNull((datasetsByMetric?.os_lt?.[0]?.data) || []);
    const rr = lastNonNull((datasetsByMetric?.rr?.[0]?.data) || []);
    const pctlt = lastNonNull((datasetsByMetric?.pct_lt?.[0]?.data) || []);

    document.getElementById('kpiLatestOs').textContent = isNil(os) ? '-' : fmtRp(os);
    document.getElementById('kpiLatestL0').textContent = isNil(l0) ? '-' : fmtRp(l0);
    document.getElementById('kpiLatestLT').textContent = isNil(lt) ? '-' : fmtRp(lt);
    document.getElementById('kpiLatestRR').textContent = isNil(rr) ? '-' : fmtPct(rr);
    document.getElementById('kpiLatestPctLT').textContent = isNil(pctlt) ? '-' : fmtPct(pctlt);
  }

  const isMobile = () => window.matchMedia('(max-width: 639px)').matches; // <sm
  const isTablet = () => window.matchMedia('(max-width: 1023px)').matches; // <lg

  // Pada HP: default tampil lebih "sedikit garis" biar kebaca
  function applyMobileDatasetRules(datasets) {
    if (!isMobile()) return datasets;

    // kalau showAllLines ON → jangan hide
    if (showAllLines) {
      return datasets.map(ds => ({ ...ds, hidden: false }));
    }

    // default ringkas
    const maxLines = isPercentMetric(metric) ? 2 : 3;

    return datasets.map((ds, idx) => ({
      ...ds,
      hidden: idx >= maxLines, // sisanya hidden
    }));
  }

  // ===== Init Chart =====
  const canvas = document.getElementById('osChart');
  if (!canvas) {
    console.error('Canvas #osChart not found. Pastikan tidak dikomentari.');
  }

  const chart = new Chart(canvas.getContext('2d'), {
    type: 'line',
    data: { labels, datasets: buildDatasets() },
        options: {
      responsive: true,
      maintainAspectRatio: false, // ✅ penting supaya ngikut tinggi container
      resizeDelay: 150,
      interaction: { mode: 'index', intersect: false },

      plugins: {
        legend: {
          display: true,
          position: isMobile() ? 'bottom' : 'bottom',
          labels: {
            // bikin legend ringkas di HP
            boxWidth: isMobile() ? 10 : 12,
            boxHeight: isMobile() ? 10 : 12,
            usePointStyle: true,
            pointStyle: 'line',
            padding: isMobile() ? 10 : 14,
            font: {
              size: isMobile() ? 10 : 12
            }
          }
        },

        tooltip: {
          callbacks: {
            label: function(ctx){
              const v = ctx.raw;
              if (isNil(v)) return `${ctx.dataset.label}: (no data)`;

              const pct = isPercentMetric(metric);
              if (mode === 'growth') {
                const sign = Number(v) >= 0 ? '+' : '';
                return `${ctx.dataset.label}: ${sign}${pct ? fmtPct(v) : fmtRp(v)}`;
              }
              return `${ctx.dataset.label}: ${pct ? fmtPct(v) : fmtRp(v)}`;
            }
          }
        }
      },

      scales: {
        x: {
          ticks: {
            autoSkip: true,
            maxTicksLimit: isMobile() ? 6 : 14,
            maxRotation: isMobile() ? 45 : 0,
            minRotation: isMobile() ? 45 : 0,
            font: { size: isMobile() ? 10 : 11 }
          },
          grid: {
            display: !isMobile()
          }
        },

        y: {
          ticks: {
            font: { size: isMobile() ? 10 : 11 },
            callback: (v) => {
              const pct = isPercentMetric(metric);
              if (mode === 'growth') {
                const sign = Number(v) >= 0 ? '+' : '';
                return sign + (pct ? fmtPct(v) : ('Rp ' + Number(v).toLocaleString('id-ID')));
              }
              return pct ? fmtPct(v) : ('Rp ' + Number(v).toLocaleString('id-ID'));
            }
          }
        }
      }
    }
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

    // sedikit beda style biar terasa aktif
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

  function refreshChart() {
    chart.data.datasets = buildDatasets();
    chart.update();
  }

  // ===== Bind Buttons =====
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

      if (isMobile()) {
        showAllLines = false;        // reset ke ringkas
        repaintShowAllButton();
      }

      repaintMetricButtons();
      refreshChart();
    });
  });


  document.getElementById('btnShowAllLines')?.addEventListener('click', () => {
    showAllLines = !showAllLines;
    repaintShowAllButton();

    // refresh chart agar hidden rules diterapkan ulang
    refreshChart();
  });

  // ===== first paint =====
  
  repaintMetricButtons();
  repaintModeButtons();
  repaintShowAllButton();
  updateKpiStrip();

  // ✅ handle resize/rotate biar chart tidak “nge-freeze” ukuran
  let __resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(__resizeTimer);
    __resizeTimer = setTimeout(() => {
      refreshChart();
    }, 200);
  });

</script>

@endsection
