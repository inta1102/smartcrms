@extends('layouts.app')

@section('title', 'SO Sheet')

@section('content')
@php
  $fmtRp  = $fmtRp ?? fn($n) => 'Rp ' . number_format((int)($n ?? 0), 0, ',', '.');
  $fmtPct = $fmtPct ?? function($n,$d=2){ return number_format((float)($n??0),$d,',','.') . '%'; };
  $fmt2   = $fmt2  ?? fn($n) => number_format((float)($n ?? 0), 2, ',', '.');

  $k = $kpi;
  $t = $target;

  $scoreTotal = (float)($k->score_total ?? 0);

  $osTarget  = (float)($t->target_os_disbursement ?? 0);
  $noaTarget = (float)($t->target_noa_disbursement ?? 0);
  $rrTarget  = (float)($t->target_rr ?? 100);
  $actTarget = (float)($t->target_activity ?? 0);

  $osAct  = (float)($k->os_disbursement ?? 0);
  $noaAct = (float)($k->noa_disbursement ?? 0);
  $rrAct  = (float)($k->rr_pct ?? 0);
  $actAct = (float)($k->activity_actual ?? 0);
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-4">

  {{-- Header --}}
  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">SO Sheet</div>
      <div class="text-3xl font-extrabold text-slate-900">{{ $user->name }}</div>
      <div class="text-sm text-slate-500 mt-1">
        Periode: <b>{{ $periodLabel }}</b> • AO Code: <b class="font-mono">{{ $user->ao_code ?? '-' }}</b>
      </div>
    </div>

    <form method="GET" action="{{ route('kpi.so.show', $user->id) }}" class="flex items-end gap-2">
      <div>
        <div class="text-sm text-slate-600">Ganti periode</div>
        <input type="month" name="period"
               value="{{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}"
               class="mt-1 w-44 rounded-xl border border-slate-200 px-3 py-2">
      </div>
      <button class="rounded-xl bg-slate-900 text-white px-4 py-2 hover:bg-slate-800">
        Terapkan
      </button>
    </form>
  </div>

  {{-- KPI Cards --}}
  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">Total Score</div>
      <div class="text-3xl font-extrabold">{{ number_format($scoreTotal, 2) }}</div>
      <div class="text-xs text-slate-400 mt-1">Mode: {{ strtoupper((string)($k->calc_mode ?? '')) }}</div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">RR %</div>
      <div class="text-3xl font-extrabold">{{ $fmtPct($rrAct, 2) }}</div>
      <div class="text-xs text-slate-400 mt-1">Target: {{ $fmtPct($rrTarget, 2) }}</div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">OS Disbursement</div>
      <div class="text-2xl font-extrabold">{{ $fmtRp($osAct) }}</div>
      <div class="text-xs text-slate-400 mt-1">Target: {{ $fmtRp($osTarget) }}</div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">NOA Disbursement</div>
      <div class="text-3xl font-extrabold">{{ (int)$noaAct }}</div>
      <div class="text-xs text-slate-400 mt-1">Target: {{ (int)$noaTarget }}</div>
    </div>
  </div>

  {{-- Interpretasi --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200">
      <div class="text-lg font-extrabold text-slate-900">Interpretasi</div>
      <div class="text-sm text-slate-500">Ringkasan cepat untuk tindakan.</div>
    </div>
    <div class="p-5 space-y-2 text-sm text-slate-700">
      @foreach(($interpretasi ?? []) as $line)
        <div>• {{ $line }}</div>
      @endforeach
      @if(!$k)
        <div class="text-slate-500">Tidak ada data KPI untuk ditampilkan.</div>
      @endif
    </div>
  </div>

  {{-- Breakdown --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200">
      <div class="text-xl font-extrabold text-slate-900">Breakdown KPI SO</div>
      <div class="text-sm text-slate-500">Detail target, realisasi, persentase, dan skor.</div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-900 text-white">
          <tr>
            <th class="text-left px-3 py-3">Komponen</th>
            <th class="text-right px-3 py-3">Target</th>
            <th class="text-right px-3 py-3">Actual</th>
            <th class="text-right px-3 py-3">%</th>
            <th class="text-center px-3 py-3">Score</th>
            <!-- <th class="text-right px-3 py-3">Catatan</th> -->
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          {{-- OS --}}
          <tr>
            <td class="px-3 py-2 font-semibold">OS Disbursement</td>
            <td class="px-3 py-2 text-right">{{ $fmtRp($osTarget) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtRp($osAct) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtPct($osPct ?? 0, 2) }}</td>
            <td class="px-3 py-2 text-center font-bold">{{ $fmt2($k->score_os ?? 0) }}</td>
            <!-- <td class="px-3 py-2 text-right text-xs text-slate-500">-</td> -->
          </tr>

          {{-- NOA --}}
          <tr>
            <td class="px-3 py-2 font-semibold">NOA Disbursement</td>
            <td class="px-3 py-2 text-right">{{ (int)$noaTarget }}</td>
            <td class="px-3 py-2 text-right">{{ (int)$noaAct }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtPct($noaPct ?? 0, 2) }}</td>
            <td class="px-3 py-2 text-center font-bold">{{ $fmt2($k->score_noa ?? 0) }}</td>
            <!-- <td class="px-3 py-2 text-right text-xs text-slate-500">-</td> -->
          </tr>

          {{-- RR --}}
          <tr>
            <td class="px-3 py-2 font-semibold">RR %</td>
            <td class="px-3 py-2 text-right">{{ $fmtPct($rrTarget, 2) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtPct($rrAct, 2) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtPct($rrAch ?? 0, 2) }}</td>
            <td class="px-3 py-2 text-center font-bold">{{ $fmt2($k->score_rr ?? 0) }}</td>
            <!-- <td class="px-3 py-2 text-right text-xs text-slate-500">
              due: {{ (int)($k->rr_due_count ?? 0) }} • ontime: {{ (int)($k->rr_paid_ontime_count ?? 0) }}
            </td> -->
          </tr>

          {{-- Activity --}}
          <tr>
            <td class="px-3 py-2 font-semibold">Community</td>
            <td class="px-3 py-2 text-right">{{ (int)($actTarget) }}</td>
            <td class="px-3 py-2 text-right">{{ (int)($actAct) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtPct($actPct ?? 0, 2) }}</td>
            <td class="px-3 py-2 text-center font-bold">{{ $fmt2($k->score_activity ?? 0) }}</td>
            <!-- <td class="px-3 py-2 text-right text-xs text-slate-500">-</td> -->
          </tr>

          {{-- Total --}}
          <tr class="bg-yellow-50">
            <td class="px-3 py-3 font-extrabold" colspan="4">TOTAL</td>
            <td class="px-3 py-3 text-center font-extrabold">{{ $fmt2($k->score_total ?? 0) }}</td>
            <td class="px-3 py-3"></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection
