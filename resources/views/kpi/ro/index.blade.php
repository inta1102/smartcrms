@extends('layouts.app')

@section('title', 'KPI RO - Ranking')

@section('content')
@php
  $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $d=2) => number_format((float)($n ?? 0), $d, ',', '.') . '%';
  $fmtNum = fn($n, $d=0) => number_format((float)($n ?? 0), $d, ',', '.');

  $periodLabel = \Carbon\Carbon::parse($periodMonth)->translatedFormat('F Y');
@endphp

<div class="max-w-7xl mx-auto p-4 space-y-4">

  {{-- Header --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <div>
        <div class="text-2xl font-bold text-slate-900">KPI RO – Ranking</div>
        <div class="text-sm text-slate-600 mt-1">
          Periode: <b>{{ $periodLabel }}</b> · Mode:
          <b class="{{ $mode==='eom' ? 'text-emerald-700' : 'text-indigo-700' }}">{{ strtoupper($mode) }}</b>
          @if($branch) · Cabang: <b>{{ $branch }}</b> @endif
        </div>
        <div class="text-xs text-slate-500 mt-1">
          Bobot: Repayment 40% · TopUp 20% · NOA 10% · Pemburukan DPK 30%
        </div>
      </div>

      {{-- Filters --}}
      <form method="GET" action="{{ route('kpi.ro.index') }}" class="flex flex-wrap gap-2 items-end">
        <div class="space-y-1">
          <label class="text-xs text-slate-600">Periode</label>
          <input type="month"
                 name="period"
                 value="{{ \Carbon\Carbon::parse($periodMonth)->format('Y-m') }}"
                 class="rounded-xl border border-slate-200 px-3 py-2 text-sm w-40">
        </div>

        <div class="space-y-1">
          <label class="text-xs text-slate-600">Mode</label>
          <select name="mode" class="rounded-xl border border-slate-200 px-3 py-2 text-sm w-40">
            <option value="realtime" @selected($mode==='realtime')>Realtime</option>
            <option value="eom" @selected($mode==='eom')>EOM (Locked)</option>
          </select>
        </div>

        <div class="space-y-1">
          <label class="text-xs text-slate-600">Cabang</label>
          <select name="branch" class="rounded-xl border border-slate-200 px-3 py-2 text-sm w-44">
            <option value="">Semua</option>
            @foreach($branches as $b)
              <option value="{{ $b }}" @selected(($branch ?? '')===$b)>{{ $b }}</option>
            @endforeach
          </select>
        </div>

        <div class="space-y-1">
          <label class="text-xs text-slate-600">Cari</label>
          <input type="text" name="q" value="{{ $q }}"
                 placeholder="AO code / Nama"
                 class="rounded-xl border border-slate-200 px-3 py-2 text-sm w-56">
        </div>

        <button class="rounded-xl bg-slate-900 text-white px-4 py-2 text-sm font-semibold">
          Tampilkan
        </button>

        <a href="{{ route('kpi.ro.index') }}"
           class="rounded-xl border border-slate-200 px-4 py-2 text-sm">
          Reset
        </a>
      </form>
    </div>

    {{-- Summary --}}
    <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
      <div class="rounded-2xl border border-slate-200 p-4">
        <div class="text-xs text-slate-500">Jumlah RO</div>
        <div class="text-2xl font-bold text-slate-900">{{ (int)($summary->cnt ?? 0) }}</div>
      </div>
      <div class="rounded-2xl border border-slate-200 p-4">
        <div class="text-xs text-slate-500">Rata-rata Skor</div>
        <div class="text-2xl font-bold text-slate-900">{{ $fmtNum($summary->avg_score ?? 0, 2) }}</div>
      </div>
      <div class="rounded-2xl border border-slate-200 p-4">
        <div class="text-xs text-slate-500">RO ada migrasi LT→DPK</div>
        <div class="text-2xl font-bold text-rose-700">{{ (int)($summary->ao_with_migrasi ?? 0) }}</div>
        <div class="text-xs text-slate-500 mt-1">Flag: dpk_migrasi_count &gt; 0</div>
      </div>
    </div>
  </div>

  {{-- Table --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200 flex items-center justify-between">
      <div class="font-bold text-slate-900">Ranking</div>
      <div class="text-xs text-slate-500">
        Menampilkan {{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} dari {{ $rows->total() }}
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr>
            <th class="text-left px-4 py-3">#</th>
            <th class="text-left px-4 py-3">RO</th>
            <th class="text-right px-4 py-3">Skor</th>

            <th class="text-right px-4 py-3">Repayment</th>
            <th class="text-right px-4 py-3">DPK (LT→DPK)</th>
            <th class="text-right px-4 py-3">TopUp</th>
            <th class="text-right px-4 py-3">NOA</th>
            
            <th class="text-left px-4 py-3">Catatan</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-100">
          @forelse($rows as $i => $r)
            @php
              $rank = ($rows->firstItem() ?? 1) + $i;

              $hasMigrasi = ((int)($r->dpk_migrasi_count ?? 0)) > 0;
              $baselineOk = (int)($r->baseline_ok ?? 0) === 1;

              $score = (float)($r->total_score_weighted ?? 0);

              $scoreBadge =
                $score >= 5.0 ? 'bg-emerald-100 text-emerald-800' :
                ($score >= 4.0 ? 'bg-indigo-100 text-indigo-800' :
                ($score >= 3.0 ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800'));
            @endphp

            <tr class="{{ $hasMigrasi ? 'bg-rose-50/40' : '' }}">
              <td class="px-4 py-3 font-semibold text-slate-700">{{ $rank }}</td>

              <td class="px-4 py-3">
                <div class="font-semibold text-slate-900">
                  <a class="hover:underline"
                     href="{{ route('kpi.ro.show', ['ao' => $r->ao_code, 'period' => $periodMonth, 'mode' => $mode]) }}">
                    {{ $r->ro_name ?? ('AO '.$r->ao_code) }}
                  </a>
                </div>
                <div class="text-xs text-slate-500">
                  AO: <b>{{ $r->ao_code }}</b>
                  @if(!empty($r->branch_code)) · Cabang: <b>{{ $r->branch_code }}</b> @endif
                </div>
              </td>

              <td class="px-4 py-3 text-right">
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-bold {{ $scoreBadge }}">
                  {{ $fmtNum($score, 2) }}
                </span>
                <div class="text-xs text-slate-500 mt-1">
                  @if($mode==='eom' && $r->locked_at)
                    Locked: {{ \Carbon\Carbon::parse($r->locked_at)->format('d/m H:i') }}
                  @else
                    Realtime
                  @endif
                </div>
              </td>

              <td class="px-4 py-3 text-right">
                <div class="font-semibold">{{ $fmtPct($r->repayment_pct ?? 0, 1) }}</div>
                <div class="text-xs text-slate-500">Score: {{ (int)($r->repayment_score ?? 0) }}</div>
              </td>

              <td class="px-4 py-3 text-right">
                <div class="font-semibold {{ $hasMigrasi ? 'text-rose-700' : '' }}">
                  {{ $fmtPct($r->dpk_pct ?? 0, 4) }}
                </div>
                <div class="text-xs text-slate-500">
                  Migrasi: <b class="{{ $hasMigrasi ? 'text-rose-700' : '' }}">{{ (int)($r->dpk_migrasi_count ?? 0) }}</b>
                  · OS: {{ $fmtRp($r->dpk_migrasi_os ?? 0) }}
                  · Denom: {{ $fmtRp($r->dpk_total_os_akhir ?? 0) }}
                  · Score: {{ (int)($r->dpk_score ?? 0) }}
                </div>
              </td>

              <td class="px-4 py-3 text-right">
                <div class="font-semibold">{{ $fmtRp($r->topup_realisasi ?? 0) }}</div>
                <div class="text-xs text-slate-500">
                  Target: {{ $fmtRp($r->topup_target ?? 0) }} · {{ $fmtPct($r->topup_pct ?? 0, 1) }} · Score: {{ (int)($r->topup_score ?? 0) }}
                </div>
              </td>

              <td class="px-4 py-3 text-right">
                <div class="font-semibold">{{ (int)($r->noa_realisasi ?? 0) }}</div>
                <div class="text-xs text-slate-500">
                  Target: {{ (int)($r->noa_target ?? 0) }} · {{ $fmtPct($r->noa_pct ?? 0, 1) }} · Score: {{ (int)($r->noa_score ?? 0) }}
                </div>
              </td>

              <td class="px-4 py-3">
                @if(!$baselineOk)
                  <div class="text-xs font-semibold text-amber-700">
                    Baseline belum ada
                  </div>
                  <div class="text-xs text-slate-500">{{ $r->baseline_note }}</div>
                @endif

                @if($hasMigrasi)
                  <div class="mt-2 text-xs font-semibold text-rose-700">
                    ⚠ Ada migrasi Lancar → DPK (perlu dikelola segera agar tidak menekan OS)
                  </div>
                @endif
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="8" class="px-4 py-10 text-center text-slate-500">
                Data tidak ditemukan untuk filter ini.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4 border-t border-slate-200">
      {{ $rows->links() }}
    </div>
  </div>
</div>
@endsection
