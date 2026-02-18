@extends('layouts.app')

@section('title', 'KPI RO - Sheet')

@section('content')
@php
  $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $d=2) => number_format((float)($n ?? 0), $d) . '%';

  // safety defaults biar view nggak pernah 500
  $user = $user ?? null;
  $name = $name ?? ($user->name ?? '-');
  $aoCode = $aoCode ?? ($user->ao_code ?? '-');
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-5">

  {{-- Header --}}
  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
    <div>
      <div class="text-sm text-slate-500">Periode Errorrrr</div>
      <div class="text-4xl font-extrabold text-slate-900">{{ $periodLabel }}</div>

      <div class="mt-2 text-sm text-slate-600">
        RO: <b class="text-slate-900">{{ $name }}</b>
        <span class="text-slate-400">•</span>
        AO Code: <b class="font-mono text-slate-900">{{ $aoCode }}</b>

      </div>

      <div class="mt-1 text-xs text-slate-400">
        Sumber: tabel <b>kpi_ro_monthly</b> (period_month).
      </div>
    </div>

    {{-- Period Picker --}}
    <form method="GET" action="{{ $user ? route('kpi.ro.show', $user->id) : '#' }}" class="flex items-end gap-2">
      <div>
        <div class="text-sm text-slate-600">Ganti periode</div>
        <input type="month"
               name="period"
               value="{{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}"
               class="mt-1 w-48 rounded-xl border border-slate-200 px-3 py-2">
      </div>
      <button class="rounded-xl bg-slate-900 text-white px-4 py-2 hover:bg-slate-800">
        Terapkan
      </button>
    </form>
  </div>

  {{-- Status kosong --}}
  @if(!$kpi)
    <div class="rounded-2xl border border-slate-200 bg-white p-6 text-slate-600">
      <div class="font-bold text-slate-900">Belum ada data KPI untuk periode ini.</div>
      <div class="text-sm mt-1">Jalankan proses kalkulasi KPI RO untuk mengisi <b>kpi_ro_monthly</b>.</div>
    </div>
  @else

    {{-- Ringkasan (enterprise cards) --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Total Score Weighted</div>
        <div class="mt-1 text-3xl font-extrabold text-slate-900">
          {{ number_format((float)($kpi->total_score_weighted ?? 0), 2) }}
        </div>
        <div class="text-xs text-slate-400 mt-1">
          Mode: <b>{{ strtoupper((string)($kpi->calc_mode ?? '-')) }}</b>
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">RR (Repayment)</div>
        <div class="mt-1 text-2xl font-extrabold text-slate-900">
          {{ number_format((float)($kpi->repayment_rate ?? 0)*100, 2) }}%
        </div>
        <div class="mt-2">
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] {{ $rrBadge['cls'] }}">
            RR {{ $rrBadge['label'] }} • {{ $fmtPct($rrPct,2) }}
          </span>
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">DPK %</div>
        <div class="mt-1 text-2xl font-extrabold text-slate-900">
          {{ $fmtPct($dpkPct,2) }}
        </div>
        <div class="mt-2">
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] {{ $dpkBadge['cls'] }}">
            DPK {{ $dpkBadge['label'] }}
          </span>
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Baseline</div>
        <div class="mt-2 text-sm text-slate-700">
          Status:
          @if((int)($kpi->baseline_ok ?? 1) === 1)
            <b class="text-emerald-700">OK</b>
          @else
            <b class="text-rose-700">NOT OK</b>
          @endif
        </div>
        <div class="text-xs text-slate-500 mt-1">
          {{ $kpi->baseline_note ?? '-' }}
        </div>
      </div>
    </div>

    {{-- Interpretasi + Legend thresholds --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200">
        <div class="text-lg font-extrabold text-slate-900">Interpretasi</div>
        <div class="text-xs text-slate-500">Ringkasan cepat untuk tindakan.</div>
      </div>

      <div class="px-5 py-4 space-y-2">
        <div class="text-sm text-slate-800">
          <span class="font-semibold">Interpretasi:</span> {{ $interpretasi }}
        </div>

        {{-- legend kecil (mudah diedit dari thresholds controller) --}}
        <div class="text-xs text-slate-500 leading-relaxed">
          <div class="font-semibold text-slate-600 mb-1">Kriteria Badge:</div>
          <div>
            RR:
            <b>AMAN</b> ≥ {{ number_format((float)($thr['rr']['aman_min'] ?? 90),0) }}%,
            <b>WASPADA</b> ≥ {{ number_format((float)($thr['rr']['waspada_min'] ?? 80),0) }}%,
            <b>RISIKO</b> &lt; {{ number_format((float)($thr['rr']['waspada_min'] ?? 80),0) }}%.
          </div>
          <div>
            DPK:
            <b>AMAN</b> ≤ {{ number_format((float)($thr['dpk']['aman_max'] ?? 1),1) }}%,
            <b>WASPADA</b> ≤ {{ number_format((float)($thr['dpk']['waspada_max'] ?? 3),1) }}%,
            <b>RISIKO</b> &gt; {{ number_format((float)($thr['dpk']['waspada_max'] ?? 3),1) }}%.
          </div>
        </div>
      </div>
    </div>

    {{-- Breakdown KPI (Topup / Repayment / NOA / DPK) --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200">
        <div class="text-lg font-extrabold text-slate-900">Breakdown KPI RO</div>
        <div class="text-xs text-slate-500">Detail target, realisasi, persentase, dan skor.</div>
      </div>

      <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-900 text-white">
            <tr>
              <th class="text-left px-3 py-2">Komponen</th>
              <th class="text-right px-3 py-2">Target</th>
              <th class="text-right px-3 py-2">Actual</th>
              <th class="text-right px-3 py-2">%</th>
              <th class="text-center px-3 py-2">Score</th>
              <th class="text-right px-3 py-2">Catatan</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-200">

            {{-- Repayment --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Repayment Rate</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($target->target_rr_pct ?? 0,2) }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($kpi->repayment_rate ?? 0),4) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($kpi->repayment_pct ?? 0,2) }}</td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($kpi->repayment_score ?? 0) }}</td>
              <td class="px-3 py-2 text-right text-xs text-slate-500">
                OS Lancar: {{ $fmtRp($kpi->repayment_os_lancar ?? 0) }} / Total: {{ $fmtRp($kpi->repayment_total_os ?? 0) }}
              </td>
            </tr>

            {{-- DPK --}}
            <tr>
              <td class="px-3 py-2 font-semibold">DPK (Migrasi)</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($target->target_dpk_pct ?? 0,2) }}</td></td>
              <td class="px-3 py-2 text-right">{{ (int)($kpi->dpk_migrasi_count ?? 0) }} kasus</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($kpi->dpk_pct ?? 0,2) }}</td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($kpi->dpk_score ?? 0) }}</td>
              <td class="px-3 py-2 text-right text-xs text-slate-500">
                OS migrasi: {{ $fmtRp($kpi->dpk_migrasi_os ?? 0) }} • OS akhir: {{ $fmtRp($kpi->dpk_total_os_akhir ?? 0) }}
              </td>
            </tr>


            {{-- Topup --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Topup</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($target->target_topup ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($kpi->topup_realisasi ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($kpi->topup_pct ?? 0,2) }}</td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($kpi->topup_score ?? 0) }}</td>
              <td class="px-3 py-2 text-right text-xs text-slate-500">
                Konsentrasi: {{ $fmtPct($kpi->topup_concentration_pct ?? 0,2) }}
              </td>
            </tr>

            {{-- NOA --}}
            <tr>
              <td class="px-3 py-2 font-semibold">NOA Realisasi</td>
              <td class="px-3 py-2 text-right">{{ (int)($target->target_noa ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($kpi->noa_realisasi ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($kpi->noa_pct ?? 0,2) }}</td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($kpi->noa_score ?? 0) }}</td>
              <td class="px-3 py-2 text-right text-xs text-slate-500">-</td>
            </tr>


          </tbody>
        </table>
      </div>
    </div>

    {{-- Top 3 Topup JSON --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200">
        <div class="text-lg font-extrabold text-slate-900">Top 3 Topup (JSON)</div>
        <div class="text-xs text-slate-500">Daftar konsentrasi topup terbesar untuk kontrol risiko.</div>
      </div>

      <div class="p-4 overflow-x-auto">
        @if(empty($top3))
          <div class="text-sm text-slate-500">Belum ada data top3 (topup_top3_json kosong).</div>
        @else
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="text-left px-3 py-2">#</th>
                <th class="text-left px-3 py-2">CIF / Nama</th>
                <th class="text-right px-3 py-2">Nominal</th>
                <th class="text-right px-3 py-2">%</th>
                <th class="text-left px-3 py-2">Catatan</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              @foreach($top3 as $i => $x)
                @php
                  $cif = $x['cif'] ?? ($x['CIF'] ?? '-');
                  $name = $x['name'] ?? ($x['nama'] ?? '');
                  $amt = $x['amount'] ?? ($x['nominal'] ?? 0);
                  $pct = $x['pct'] ?? ($x['persen'] ?? null);
                @endphp
                <tr>
                  <td class="px-3 py-2">{{ $i+1 }}</td>
                  <td class="px-3 py-2">
                    <div class="font-semibold text-slate-900">{{ $cif }}</div>
                    <div class="text-xs text-slate-500">{{ $name }}</div>
                  </td>
                  <td class="px-3 py-2 text-right font-semibold">{{ $fmtRp($amt) }}</td>
                  <td class="px-3 py-2 text-right">{{ $pct !== null ? $fmtPct($pct,2) : '-' }}</td>
                  <td class="px-3 py-2 text-xs text-slate-500">
                    Fokus pantau konsentrasi.
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>
    </div>

  @endif
</div>
@endsection
