@extends('layouts.app')

@section('title', 'Dashboard TL - OS Harian')

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-5">

  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">📈 Dashboard TL – OS Total Harian</h1>
      <p class="text-sm text-slate-500">Scope: {{ $aoCount }} AO (bawahan TL). Data dari snapshot harian.</p>
    </div>

    <form method="GET" class="flex items-end gap-2 flex-wrap">
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

  {{-- Chart --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-4">
    <div class="flex items-center justify-between mb-3">
      <div>
        <div class="font-bold text-slate-900">Grafik OS Total Harian</div>
        <div class="text-xs text-slate-500">Tanggal bolong otomatis bernilai 0 (indikasi snapshot belum terbentuk).</div>
      </div>
    </div>

    <div class="w-full overflow-x-auto">
      <canvas id="osChart" height="110"></canvas>
    </div>
  </div>

  {{-- Top AO table --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900">Top 15 AO – OS Terakhir</div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <th class="text-left px-3 py-2">AO Code</th>
            <th class="text-left px-3 py-2">Nama</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">NOA</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          @forelse($topAo as $r)
            <tr>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code }}</td>
              <td class="px-3 py-2">{{ $r->name ?? '-' }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format((int)$r->os_total,0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((int)$r->noa_total,0,',','.') }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="px-3 py-6 text-center text-slate-500">
                Belum ada data snapshot pada range ini.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>

{{-- Chart.js CDN --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  const labels   = @json($labels);
  const datasets = @json($datasets);

  const ctx = document.getElementById('osChart').getContext('2d');

  new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: datasets.map(ds => ({
        label: ds.label,
        data: ds.data,
        spanGaps: false, // biar null = putus
        tension: 0.2
      }))
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          callbacks: {
            label: function(ctx){
              const v = ctx.raw;
              if (v === null || typeof v === 'undefined') return `${ctx.dataset.label}: (no data)`;
              return `${ctx.dataset.label}: Rp ${Number(v).toLocaleString('id-ID')}`;
            }
          }
        }
      },
      scales: {
        y: {
          ticks: {
            callback: (v) => 'Rp ' + Number(v).toLocaleString('id-ID')
          }
        }
      }
    }
  });
</script>

@endsection
