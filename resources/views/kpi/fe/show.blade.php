@extends('layouts.app')

@section('title', 'FE Sheet')

@section('content')
@php
  $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n) => number_format((float)($n ?? 0), 2, ',', '.') . '%';

  $badgeOs = $badges['os'] ?? null;
  $badgeMig = $badges['migrasi'] ?? null;
  $badgePen = $badges['penalty'] ?? null;
@endphp


<div class="max-w-6xl mx-auto p-4 space-y-5">

  <div class="flex items-start justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">FE Sheet</div>
      <div class="text-4xl font-black text-slate-900">
        {{ $row->name ?? '—' }}
      </div>
      <div class="text-sm text-slate-600 mt-1">
        Periode: <b>{{ $periodLabel }}</b>
        @if(!empty($row?->ao_code))
          · AO Code: <b>{{ $row->ao_code }}</b>
        @endif
      </div>
    </div>

    <form method="GET" action="{{ route('kpi.fe.show', ['feUserId' => request()->route('feUserId')]) }}" class="flex items-end gap-2">
      <div>
        <div class="text-sm text-slate-600 mb-1">Ganti periode</div>
        <input type="month" name="period"
          value="{{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}"
          class="rounded-xl border border-slate-200 px-3 py-2">
      </div>
      <button class="rounded-xl bg-slate-900 text-white px-4 py-2 font-semibold">
        Terapkan
      </button>
    </form>
  </div>

  @if($baselineNote)
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-800">
      {{ $baselineNote }}
    </div>
  @endif

  @if($row)
  {{-- Cards --}}
  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-sm text-slate-500">Total Score</div>
      <div class="text-4xl font-black text-slate-900 mt-1">
        {{ number_format((float)$row->total_score_weighted, 2, '.', '') }}
      </div>
      <div class="text-sm text-slate-500 mt-2">
        Mode: <b>{{ $row->calc_mode }}</b>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="flex items-center justify-between">
        <div class="text-sm text-slate-500">OS Turun (Kol2+)</div>
        @if($badgeOs)
            <span class="text-xs px-2 py-1 rounded-full border {{ $badgeOs['class'] }}">
            {{ $badgeOs['label'] }}
            </span>
        @endif
      </div>
      <div class="text-2xl font-black text-slate-900 mt-1">{{ $fmtRp($row->os_kol2_turun_total) }}</div>
      <div class="text-sm text-slate-500 mt-1">
        Target: <b>{{ $fmtRp($row->target_os_turun_kol2_fix) }}</b>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-sm text-slate-500">Migrasi NPL %</div>
        @if($badgeMig)
            <span class="text-xs px-2 py-1 rounded-full border {{ $badgeMig['class'] }}">
            {{ $badgeMig['label'] }}
            </span>
        @endif
      <div class="text-2xl font-black text-slate-900 mt-1">{{ $fmtPct($row->migrasi_npl_pct) }}</div>
      <div class="text-sm text-slate-500 mt-1">
        Target: <b>{{ $fmtPct(($row->target_migrasi_npl_pct_fix ?? 0) * 100) }}</b>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-sm text-slate-500">Pendapatan Denda</div>
        @if($badgePen)
            <span class="text-xs px-2 py-1 rounded-full border {{ $badgePen['class'] }}">
            {{ $badgePen['label'] }}
            </span>
        @endif
      <div class="text-2xl font-black text-slate-900 mt-1">{{ $fmtRp($row->penalty_paid_total) }}</div>
      <div class="text-sm text-slate-500 mt-1">
        Target: <b>{{ $fmtRp($row->target_penalty_paid_fix) }}</b>
      </div>
    </div>
  </div>

  {{-- Interpretasi --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="text-xl font-bold text-slate-900">Interpretasi</div>
      <div class="text-sm text-slate-500 mt-1">Ringkasan cepat untuk tindakan.</div>
    </div>
    <div class="p-4">
      <ul class="list-disc pl-5 space-y-2 text-slate-700">
        @foreach(($bullets ?? []) as $b)
          <li>{{ $b }}</li>
        @endforeach
      </ul>
    </div>
  </div>
    @if(!empty($insights['penalty_to_os_ratio']))
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <div class="text-sm font-semibold text-slate-900">Insight Kualitas Recovery</div>
            <div class="text-sm text-slate-600 mt-1">
            Rasio Penalty terhadap Penurunan OS:
            <b>{{ number_format($insights['penalty_to_os_ratio'] * 100, 2, ',', '.') }}%</b>
            <div class="text-xs text-slate-500 mt-1">
                Semakin tinggi → indikasi pembayaran cenderung penalty tanpa menurunkan pokok. Pastikan recovery pokok tetap berjalan.
            </div>
            </div>
        </div>
    @endif


  {{-- Breakdown --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="text-xl font-bold text-slate-900">Breakdown KPI FE</div>
      <div class="text-sm text-slate-500 mt-1">Detail target, realisasi, persentase, dan skor.</div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-left text-slate-600">
            <th class="px-3 py-2">Komponen</th>
            <th class="px-3 py-2">Target</th>
            <th class="px-3 py-2">Actual</th>
            <th class="px-3 py-2">Ach %</th>
            <th class="px-3 py-2">Score</th>
          </tr>
        </thead>
        <tbody>
          <tr class="border-t">
            <td class="px-3 py-3 font-semibold">OS Turun (Kol2+)</td>
            <td class="px-3 py-3">{{ $fmtRp($row->target_os_turun_kol2_fix) }}</td>
            <td class="px-3 py-3">{{ $fmtRp($row->os_kol2_turun_total) }}</td>
            <td class="px-3 py-3">{{ $fmtPct($row->ach_os_turun_pct) }}</td>
            <td class="px-3 py-3 font-black">{{ number_format((float)$row->score_os_turun, 2, '.', '') }}</td>
          </tr>

          <tr class="border-t">
            <td class="px-3 py-3 font-semibold">Migrasi NPL</td>
            <td class="px-3 py-3">{{ $fmtPct(($row->target_migrasi_npl_pct_fix ?? 0) * 100) }}</td>
            <td class="px-3 py-3">{{ $fmtPct($row->migrasi_npl_pct) }}</td>
            <td class="px-3 py-3">{{ $fmtPct($row->ach_migrasi_pct) }}</td>
            <td class="px-3 py-3 font-black">{{ number_format((float)$row->score_migrasi, 2, '.', '') }}</td>
          </tr>

          <tr class="border-t">
            <td class="px-3 py-3 font-semibold">Pdpt Denda</td>
            <td class="px-3 py-3">{{ $fmtRp($row->target_penalty_paid_fix) }}</td>
            <td class="px-3 py-3">{{ $fmtRp($row->penalty_paid_total) }}</td>
            <td class="px-3 py-3">{{ $fmtPct($row->ach_penalty_pct) }}</td>
            <td class="px-3 py-3 font-black">{{ number_format((float)$row->score_penalty, 2, '.', '') }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  @endif

</div>
@endsection
