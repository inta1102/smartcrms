@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto p-4">

  <div class="flex items-start justify-between gap-4 mb-4">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">Pencapaian Target KPI Marketing</h1>
      <p class="text-sm text-slate-500">
        Periode: <b>{{ \Carbon\Carbon::parse($target->period)->translatedFormat('M Y') }}</b>
      </p>
    </div>

    <div class="flex items-center gap-2">
      <a href="{{ route('kpi.marketing.targets.index') }}"
         class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
        Kembali
      </a>

      <a href="{{ route('kpi.marketing.targets.achievement', [$target, 'force' => 1]) }}"
        class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
        Recalc
      </a>
    </div>
  </div>

  {{-- badge final/estimasi --}}
  @php
      $src = strtolower((string) $ach->os_source_now);
      $srcLabel = $src === 'snapshot' ? 'snapshot' : 'live';
  @endphp

  <span class="mb-4 inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold bg-emerald-100 text-emerald-800">
      {{ $target->status === \App\Models\MarketingKpiTarget::STATUS_APPROVED ? 'APPROVED' : $target->status }}
      @if($ach->is_final) FINAL @endif
      ({{ $srcLabel }})
  </span>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">OS End (Now)</div>
      <div class="text-lg font-bold text-slate-900">Rp {{ number_format($ach->os_end_now,0,',','.') }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Source: <b>{{ $ach->os_source_now }}</b>
        @if($ach->position_date_now) | Posisi: {{ \Carbon\Carbon::parse($ach->position_date_now)->toDateString() }} @endif
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">OS End (Prev)</div>
      <div class="text-lg font-bold text-slate-900">Rp {{ number_format($ach->os_end_prev,0,',','.') }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Source: <b>{{ $ach->os_source_prev }}</b>
        @if($ach->position_date_prev) | Posisi: {{ \Carbon\Carbon::parse($ach->position_date_prev)->toDateString() }} @endif
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">OS Growth (Actual)</div>
      <div class="text-lg font-bold text-slate-900">Rp {{ number_format($ach->os_growth,0,',','.') }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Target: Rp {{ number_format($target->target_os_growth,0,',','.') }}
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">NOA End Now</div>
      <div class="text-lg font-bold text-slate-900">{{ number_format($ach->noa_end_now) }}</div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">NOA End Prev</div>
      <div class="text-lg font-bold text-slate-900">{{ number_format($ach->noa_end_prev) }}</div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">NOA Growth (Proxy Debitur Baru)</div>
      <div class="text-lg font-bold text-slate-900">{{ number_format($ach->noa_growth) }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Target NOA: {{ number_format($target->target_noa) }}
      </div>
    </div>

  </div>

  <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="text-sm font-bold text-slate-900 mb-2">Skor KPI</div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div class="rounded-xl bg-slate-50 p-3">
        <div class="text-xs text-slate-500">OS Achievement</div>
        <div class="text-lg font-bold">{{ number_format($ach->os_ach_pct,2) }}%</div>
        <div class="text-xs text-slate-500">Score OS: <b>{{ number_format($ach->score_os,2) }}</b> (bobot {{ $target->weight_os }}%)</div>
      </div>

      <div class="rounded-xl bg-slate-50 p-3">
        <div class="text-xs text-slate-500">NOA Achievement</div>
        <div class="text-lg font-bold">{{ number_format($ach->noa_ach_pct,2) }}%</div>
        <div class="text-xs text-slate-500">Score NOA: <b>{{ number_format($ach->score_noa,2) }}</b> (bobot {{ $target->weight_noa }}%)</div>
      </div>

      <div class="rounded-xl bg-slate-50 p-3">
        <div class="text-xs text-slate-500">Total Score</div>
        <div class="text-2xl font-bold text-slate-900">{{ number_format($ach->score_total,2) }}</div>
        <div class="text-xs text-slate-500">0..120 (default cap)</div>
      </div>
    </div>

    @if($target->notes)
      <div class="mt-3 text-sm text-slate-600">
        <div class="font-semibold text-slate-800">Catatan Target:</div>
        <div class="whitespace-pre-line">{{ $target->notes }}</div>
      </div>
    @endif
  </div>

  {{-- ====== Grafik Progress 12 Bulan ====== --}}
  <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="text-sm font-bold text-slate-900 mb-3">Progress Achievement (12 Bulan)</div>

    <div class="grid grid-cols-1 gap-4">
      <div class="rounded-xl bg-slate-50 p-3">
        <div class="text-xs text-slate-500 mb-2">OS Growth vs Target (Rp)</div>
        <canvas id="chartOs" height="110"></canvas>
      </div>

      <div class="rounded-xl bg-slate-50 p-3">
        <div class="text-xs text-slate-500 mb-2">Achievement % (OS & NOA)</div>
        <canvas id="chartAch" height="110"></canvas>
      </div>

      <div class="rounded-xl bg-slate-50 p-3">
        <div class="text-xs text-slate-500 mb-2">NOA Growth</div>
        <canvas id="chartNoa" height="110"></canvas>
      </div>
    </div>
  </div>

  @php
    // $series dari controller adalah array normalized 12 bulan
    $labels    = array_map(fn($r) => $r['label'], $series);

    $osGrowth  = array_map(fn($r) => (int)$r['os_growth'], $series);
    $osTarget  = array_map(fn($r) => (int)$r['target_os_growth'], $series);

    $osAch     = array_map(fn($r) => (float)$r['os_ach_pct'], $series);
    $noaAch    = array_map(fn($r) => (float)$r['noa_ach_pct'], $series);

    $noaGrowth = array_map(fn($r) => (int)$r['noa_growth'], $series);
  @endphp

</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  const labels   = @json($labels);

  const osGrowth = @json($osGrowth);
  const osTarget = @json($osTarget);

  const osAch    = @json($osAch);
  const noaAch   = @json($noaAch);

  const noaGrowth = @json($noaGrowth);

  // OS Growth vs Target
  new Chart(document.getElementById('chartOs'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'OS Growth', data: osGrowth },
        { label: 'Target OS', data: osTarget, type: 'line' },
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: true } },
      scales: { y: { beginAtZero: true } }
    }
  });

  // Achievement %
  new Chart(document.getElementById('chartAch'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'OS Ach %', data: osAch },
        { label: 'NOA Ach %', data: noaAch },
      ]
    },
    options: {
      responsive: true,
      scales: { y: { beginAtZero: true } }
    }
  });

  // NOA Growth
  new Chart(document.getElementById('chartNoa'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'NOA Growth', data: noaGrowth },
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: true } },
      scales: { y: { beginAtZero: true } }
    }
  });
</script>
@endpush
