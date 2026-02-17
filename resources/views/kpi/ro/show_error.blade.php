@extends('layouts.app')

@section('title', 'KPI RO - Detail')

@section('content')
@php
  $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $d=2) => number_format((float)($n ?? 0), $d, ',', '.') . '%';
  $fmtNum = fn($n, $d=2) => number_format((float)($n ?? 0), $d, ',', '.');
  // $periodLabel = \Carbon\Carbon::parse($periodMonth)->translatedFormat('F Y');
@endphp

<div class="max-w-5xl mx-auto p-4 space-y-4">

  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200 flex items-start justify-between gap-3">
      <div>
        <div class="text-2xl font-bold text-slate-900">Detail KPI RO</div>
        <div class="text-sm text-slate-600 mt-1">
          <b>{{ $name }}</b> · AO: <b>{{ $ao }}</b> · Periode: <b>{{ $periodLabel }}</b> · Mode: <b>{{ strtoupper($mode) }}</b>
        </div>
        <div class="text-xs text-slate-500 mt-1">
          Cabang: <b>{{ $row->branch_code ?: '-' }}</b>
        </div>
      </div>

      <a href="{{ route('kpi.ro.index', ['period' => $periodMonth, 'mode' => $mode, 'branch' => $row->branch_code]) }}"
         class="rounded-xl border border-slate-200 px-4 py-2 text-sm">
        ← Kembali
      </a>
    </div>

    <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
      <div class="rounded-2xl border border-slate-200 p-4">
        <div class="text-xs text-slate-500">Total Score (Weighted)</div>
        <div class="text-3xl font-bold text-slate-900">{{ $fmtNum($row->total_score_weighted ?? 0, 2) }}</div>
        <div class="text-xs text-slate-500 mt-1">
          @if($mode==='eom' && $row->locked_at)
            Locked: {{ \Carbon\Carbon::parse($row->locked_at)->format('d/m/Y H:i') }}
          @else
            Realtime
          @endif
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 p-4">
        <div class="text-xs text-slate-500">Baseline</div>
        <div class="text-lg font-bold {{ (int)$row->baseline_ok===1 ? 'text-emerald-700' : 'text-amber-700' }}">
          {{ (int)$row->baseline_ok===1 ? 'OK' : 'TIDAK ADA' }}
        </div>
        <div class="text-xs text-slate-500 mt-1">{{ $row->baseline_note }}</div>
      </div>
    </div>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200 font-bold text-slate-900">Komponen KPI</div>

    <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
      <div class="rounded-2xl border border-slate-200 p-4">
        <div class="font-bold text-slate-900">Repayment Rate (40%)</div>
        <div class="text-2xl font-bold mt-2">{{ $fmtPct($row->repayment_pct ?? 0, 1) }}</div>
        <div class="text-xs text-slate-500 mt-1">Score: {{ (int)($row->repayment_score ?? 0) }}</div>
      </div>

      <div class="rounded-2xl border border-slate-200 p-4">
        <div class="font-bold text-slate-900">TopUp (20%)</div>
        <div class="text-2xl font-bold mt-2">{{ $fmtRp($row->topup_realisasi ?? 0) }}</div>
        <div class="text-xs text-slate-500 mt-1">
          Target: {{ $fmtRp($row->topup_target ?? 0) }} · {{ $fmtPct($row->topup_pct ?? 0, 1) }} · Score: {{ (int)($row->topup_score ?? 0) }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 p-4">
        <div class="font-bold text-slate-900">NOA Pengembangan (10%)</div>
        <div class="text-2xl font-bold mt-2">{{ (int)($row->noa_realisasi ?? 0) }}</div>
        <div class="text-xs text-slate-500 mt-1">
          Target: {{ (int)($row->noa_target ?? 0) }} · {{ $fmtPct($row->noa_pct ?? 0, 1) }} · Score: {{ (int)($row->noa_score ?? 0) }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 p-4">
        <div class="font-bold text-slate-900">Pemburukan DPK (30%)</div>
        <div class="text-2xl font-bold mt-2 {{ ((int)($row->dpk_migrasi_count ?? 0))>0 ? 'text-rose-700' : '' }}">
          {{ $fmtPct($row->dpk_pct ?? 0, 4) }}
        </div>
        <div class="text-xs text-slate-500 mt-1">
          Migrasi: <b>{{ (int)($row->dpk_migrasi_count ?? 0) }}</b>
          · OS Migrasi: {{ $fmtRp($row->dpk_migrasi_os ?? 0) }}
          · Total OS Akhir: {{ $fmtRp($row->dpk_total_os_akhir ?? 0) }}
          · Score: {{ (int)($row->dpk_score ?? 0) }}
        </div>

        @if(((int)($row->dpk_migrasi_count ?? 0))>0)
          <div class="mt-2 text-xs font-semibold text-rose-700">
            ⚠ Ada migrasi Lancar → DPK. Ini harus di-manage cepat agar tidak menekan OS (turun karena jadi DPK).
          </div>
        @endif
      </div>
    </div>
  </div>

</div>
@endsection
