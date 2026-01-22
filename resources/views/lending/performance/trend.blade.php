@extends('layouts.app')

@section('title', 'Lending Performance • Trend')

@section('content')
@php
  // -------------------------
  // Helpers
  // -------------------------
  $fmtRp = function ($n) {
    $n = (float)($n ?? 0);
    return 'Rp ' . number_format($n, 0, ',', '.');
  };

  $fmtInt = fn($n) => number_format((int)($n ?? 0), 0, ',', '.');

  $fmtPct = function ($pct) {
    if ($pct === null) return '—';
    return number_format((float)$pct, 2, ',', '.') . '%';
  };

  $badgeGrowth = function ($v) {
    $v = (float)($v ?? 0);
    if ($v > 0) return 'text-emerald-700';
    if ($v < 0) return 'text-rose-700';
    return 'text-slate-600';
  };

  $monthLabel = function ($ymd) {
    try {
      return \Carbon\Carbon::parse($ymd)->translatedFormat('F Y');
    } catch (\Throwable $e) {
      return $ymd;
    }
  };

  $snapLabel = function ($ymd) {
    try {
      return \Carbon\Carbon::parse($ymd)->translatedFormat('d M Y');
    } catch (\Throwable $e) {
      return $ymd;
    }
  };

  $scopeBranch = $branch ?? 'ALL';
  $scopeAo     = $ao ?? 'ALL';

  // Trend arrays for chart
  $labels = [];
  $osData = [];
  $noaData = [];
  foreach (($trend ?? []) as $t) {
    $labels[] = $t->snapshot_month;
    $osData[] = (float)($t->os ?? 0);
    $noaData[] = (int)($t->noa ?? 0);
  }

  // KPI
  $k = $kpi ?? [];
  $os = (float)($k['os'] ?? 0);
  $osPrev = (float)($k['os_prev'] ?? 0);
  $osDelta = (float)($k['os_growth_abs'] ?? 0);
  $osPct = $k['os_growth_pct'] ?? null;

  $noa = (int)($k['noa'] ?? 0);
  $noaPrev = (int)($k['noa_prev'] ?? 0);
  $noaDelta = (int)($k['noa_growth_abs'] ?? 0);
  $noaPct = $k['noa_growth_pct'] ?? null;

  $latestPos = $k['latest_position_date'] ?? null;
  $prevSnap  = $k['prev_snapshot_month'] ?? null;

  // For filter defaults
  $monthsBackVal = (int)($monthsBack ?? 12);
  $monthVal = $month ?? now()->startOfMonth()->toDateString();

@endphp

<script>
  const trendMeta = @json($trendMeta ?? []);
</script>

<div class="space-y-4">

  {{-- =========================
      HEADER + TAB
  ========================== --}}
  <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
    <div>
      <h1 class="text-xl font-bold text-slate-900">📊 Lending Performance</h1>
      <p class="text-sm text-slate-500">
        Tab <span class="font-semibold text-slate-700">Trend</span> (OS & NOA). Growth pakai MoD: closing bulan sebelumnya vs posisi terakhir.
      </p>
    </div>

    {{-- Tabs --}}
    <div class="flex items-center gap-2">
      <a href="{{ route('lending.performance.index') }}"
         class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
        📊 Summary
      </a>
      <span class="rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white">
        📈 Trend
      </span>
    </div>
  </div>

  {{-- =========================
      FILTER BAR
  ========================== --}}
  <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
    <form class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between"
          method="GET"
          action="{{ route('lending.trend.index') }}">

      <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
        <div>
          <label class="text-xs font-semibold text-slate-500">Bulan (anchor trend)</label>
          <input type="date"
                 name="month"
                 value="{{ $monthVal }}"
                 class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
          <div class="mt-1 text-[11px] text-slate-400">
            Dipakai untuk range trend & baseline closing (prev).
          </div>
        </div>

        <div>
          <label class="text-xs font-semibold text-slate-500">Range (bulan)</label>
          <input type="number"
                 min="3" max="36"
                 name="months"
                 value="{{ $monthsBackVal }}"
                 class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
          <div class="mt-1 text-[11px] text-slate-400">
            Disarankan 12 / 18.
          </div>
        </div>

        <div>
          <label class="text-xs font-semibold text-slate-500">Branch</label>
          <select name="branch"
                  class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
            <option value="ALL" @selected($scopeBranch==='ALL')>ALL</option>
            @foreach(($branchOptions ?? []) as $b)
              <option value="{{ $b }}" @selected($scopeBranch===$b)>{{ $b }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="text-xs font-semibold text-slate-500">AO</label>
            <select name="ao" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="ALL" @selected(($ao ?? 'ALL')==='ALL')>ALL</option>
                @foreach(($aoOptions ?? []) as $a)
                    @php $nm = $aoNameMap[$a] ?? null; @endphp
                    <option value="{{ $a }}" @selected(($ao ?? 'ALL')===$a)>
                    {{ $a }}{{ $nm ? ' — '.$nm : '' }}
                    </option>
                @endforeach
            </select>
        </div>
      </div>

      <div class="flex gap-2">
        <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">
          Terapkan
        </button>

        <a href="{{ route('lending.trend.index') }}"
           class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
          Reset
        </a>
      </div>
    </form>
  </div>

  {{-- =========================
      KPI CARDS
  ========================== --}}
  <div class="grid grid-cols-1 gap-3 md:grid-cols-4">

    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs font-semibold text-slate-500">Outstanding (OS)</div>
      <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $fmtRp($os) }}</div>
      <div class="mt-2 text-xs text-slate-500">
        Prev (closing): <span class="font-semibold text-slate-700">{{ $fmtRp($osPrev) }}</span>
      </div>
      <div class="mt-1 text-sm font-bold {{ $badgeGrowth($osDelta) }}">
        {{ $osDelta >= 0 ? '+' : '' }}{{ $fmtRp($osDelta) }}
        <span class="text-xs font-semibold text-slate-500">({{ $fmtPct($osPct) }})</span>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs font-semibold text-slate-500">Jumlah Rekening (NOA)</div>
      <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $fmtInt($noa) }}</div>
      <div class="mt-2 text-xs text-slate-500">
        Prev (closing): <span class="font-semibold text-slate-700">{{ $fmtInt($noaPrev) }}</span>
      </div>
      <div class="mt-1 text-sm font-bold {{ $badgeGrowth($noaDelta) }}">
        {{ $noaDelta >= 0 ? '+' : '' }}{{ $fmtInt($noaDelta) }}
        <span class="text-xs font-semibold text-slate-500">({{ $fmtPct($noaPct) }})</span>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs font-semibold text-slate-500">Scope</div>
      <div class="mt-1 text-lg font-extrabold text-slate-900">
        {{ $scopeBranch === 'ALL' ? 'All Branch' : $scopeBranch }}
      </div>
      <div class="mt-1 text-sm text-slate-600">
        {{ $scopeAo === 'ALL' ? 'All AO' : ('AO ' . $scopeAo) }}
      </div>
      <div class="mt-2 text-xs text-slate-500">
        Bulan: <span class="font-semibold text-slate-700">{{ $monthLabel($monthVal) }}</span>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs font-semibold text-slate-500">Catatan</div>
      <div class="mt-2 text-sm text-slate-700 leading-relaxed">
        Growth = <span class="font-semibold">closing {{ $prevSnap ? $monthLabel($prevSnap) : '—' }}</span>
        vs <span class="font-semibold">posisi terakhir {{ $latestPos ? $snapLabel($latestPos) : '—' }}</span>.
      </div>
      <div class="mt-2 text-xs text-slate-500">
        Trend chart tetap dari <span class="font-semibold">snapshot bulanan</span> (closing).
      </div>
    </div>

  </div>

  {{-- =========================
      CHARTS
  ========================== --}}
  <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="mb-2">
        <div class="text-sm font-bold text-slate-900">Trend Outstanding (OS)</div>
        <div class="text-xs text-slate-500">SUM(outstanding) per snapshot_month</div>
      </div>
      <div class="h-64">
        <canvas id="osChart"></canvas>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="mb-2">
        <div class="text-sm font-bold text-slate-900">Trend NOA</div>
        <div class="text-xs text-slate-500">COUNT(distinct account_no) per snapshot_month</div>
      </div>
      <div class="h-64">
        <canvas id="noaChart"></canvas>
      </div>
    </div>
  </div>

  {{-- =========================
      RANKING
  ========================== --}}
  <div class="rounded-2xl border border-slate-100 bg-white shadow-sm">
    <div class="border-b border-slate-100 px-4 py-3">
      <div class="text-sm font-bold text-slate-900">🏁 Ranking AO Growth (Top 20)</div>
      <div class="mt-0.5 text-xs text-slate-500">
        Perbandingan: <span class="font-semibold">{{ $latestPos ? $snapLabel($latestPos) : '—' }}</span>
        vs closing <span class="font-semibold">{{ $prevSnap ? $monthLabel($prevSnap) : '—' }}</span>.
        <span class="text-slate-400">(Ranking tetap global per scope Branch.)</span>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-600">
          <tr>
            <th class="px-4 py-3 text-left">AO</th>
            <th class="px-4 py-3 text-right">OS</th>
            <th class="px-4 py-3 text-right">OS Prev</th>
            <th class="px-4 py-3 text-right">Δ OS</th>
            <th class="px-4 py-3 text-right">Δ OS %</th>
            <th class="px-4 py-3 text-right">NOA</th>
            <th class="px-4 py-3 text-right">NOA Prev</th>
            <th class="px-4 py-3 text-right">Δ NOA</th>
            <th class="px-4 py-3 text-right">Δ NOA %</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @forelse(($aoRank ?? []) as $r)
            @php
              $osG = (float)($r->os_growth_abs ?? 0);
              $noaG = (int)($r->noa_growth_abs ?? 0);
            @endphp
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-slate-900">
                    {{ $r->ao_code }}
                </div>
                <div class="text-xs text-slate-500">
                    {{ $aoNames[$r->ao_code] ?? '—' }}
                </div>
              </td>

              <td class="px-4 py-3 text-right font-semibold">{{ $fmtRp($r->os) }}</td>
              <td class="px-4 py-3 text-right text-slate-700">{{ $fmtRp($r->os_prev) }}</td>
              <td class="px-4 py-3 text-right font-bold {{ $badgeGrowth($osG) }}">
                {{ $osG >= 0 ? '+' : '' }}{{ $fmtRp($osG) }}
              </td>
              <td class="px-4 py-3 text-right text-slate-700">{{ $fmtPct($r->os_growth_pct ?? null) }}</td>

              <td class="px-4 py-3 text-right font-semibold">{{ $fmtInt($r->noa) }}</td>
              <td class="px-4 py-3 text-right text-slate-700">{{ $fmtInt($r->noa_prev) }}</td>
              <td class="px-4 py-3 text-right font-bold {{ $badgeGrowth($noaG) }}">
                {{ $noaG >= 0 ? '+' : '' }}{{ $fmtInt($noaG) }}
              </td>
              <td class="px-4 py-3 text-right text-slate-700">{{ $fmtPct($r->noa_growth_pct ?? null) }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="9" class="px-4 py-8 text-center text-slate-500">
                Data ranking belum tersedia. Pastikan:
                <span class="font-semibold">loan_accounts.position_date</span> terisi dan
                snapshot closing bulan sebelumnya ada.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>

{{-- =========================
    Chart.js
========================== --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  (function () {
    const labels    = @json($labels);
    const osData    = @json($osData);
    const noaData   = @json($noaData);
    const trendMeta = @json($trendMeta ?? []); // [{snapshot_month, source, position_date}, ...]

    // 👉 ubah YYYY-MM-DD -> YYYY-MM
    const monthLabels = (labels || []).map(l => (l || '').slice(0, 7));

    // Simple number formatter (ID)
    const fmtId = (n) => new Intl.NumberFormat('id-ID').format(Math.round(n || 0));
    const fmtRp = (n) => 'Rp ' + fmtId(n);

    // helper: label sumber data per titik (opsional untuk tooltip)
    const srcLabel = (i) => {
      const m = trendMeta?.[i] || {};
      if (m.source === 'live') return `Live (${m.position_date || '-'})`;
      return 'Closing';
    };

    // OS Chart
    const osCtx = document.getElementById('osChart');
    if (osCtx) {
      new Chart(osCtx, {
        type: 'line',
        data: {
          labels: monthLabels,
          datasets: [{
            label: 'OS',
            data: osData,
            tension: 0.25,
            fill: false,
            pointRadius: 3,
            pointHoverRadius: 5
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            tooltip: {
              callbacks: {
                title: (items) => {
                  const i = items?.[0]?.dataIndex ?? 0;
                  return `${monthLabels[i]} • ${srcLabel(i)}`;
                },
                label: (ctx) => `OS: ${fmtRp(ctx.parsed.y)}`
              }
            },
            legend: { display: true }
          },
          scales: {
            y: { ticks: { callback: v => fmtRp(v) } }
          }
        }
      });
    }

    // NOA Chart
    const noaCtx = document.getElementById('noaChart');
    if (noaCtx) {
      new Chart(noaCtx, {
        type: 'line',
        data: {
          labels: monthLabels,
          datasets: [{
            label: 'NOA',
            data: noaData,
            tension: 0.25,
            fill: false,
            pointRadius: 3,
            pointHoverRadius: 5
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            tooltip: {
              callbacks: {
                title: (items) => {
                  const i = items?.[0]?.dataIndex ?? 0;
                  return `${monthLabels[i]} • ${srcLabel(i)}`;
                },
                label: (ctx) => `NOA: ${fmtId(ctx.parsed.y)}`
              }
            },
            legend: { display: true }
          },
          scales: {
            y: { ticks: { callback: v => fmtId(v) } }
          }
        }
      });
    }
  })();
</script>
@endsection
