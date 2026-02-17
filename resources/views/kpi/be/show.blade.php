@extends('layouts.app')

@section('title', 'BE Sheet')

@section('content')
@php
  $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtNum = fn($n) => number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n) => number_format((float)($n ?? 0), 2, ',', '.') . '%';

  // badges optional (kalau nanti kamu bikin InterpretationService)
  $badgeOs    = $badges['os_selesai'] ?? null;
  $badgeNoa   = $badges['noa_selesai'] ?? null;
  $badgeBunga = $badges['bunga_masuk'] ?? null;
  $badgeDenda = $badges['denda_masuk'] ?? null;

  $status = strtoupper((string)($row->status ?? ($badges['status'] ?? 'DRAFT')));
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-5">

  <div class="flex items-start justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">BE Sheet</div>
      <div class="text-4xl font-black text-slate-900">
        {{ $row->name ?? '—' }}
      </div>
      <div class="text-sm text-slate-600 mt-1">
        Periode: <b>{{ $periodLabel }}</b>
        · Status: <b>{{ $status }}</b>
      </div>
    </div>

    <form method="GET"
          action="{{ route('kpi.be.show', ['beUserId' => request()->route('beUserId')]) }}"
          class="flex items-end gap-2">
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
      <div class="text-sm text-slate-500">Total PI</div>
      <div class="text-4xl font-black text-slate-900 mt-1">
        {{ number_format((float)($row->total_pi ?? 0), 2, '.', '') }}
      </div>
      <div class="text-sm text-slate-500 mt-2">
        Status: <b>{{ $status }}</b>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="flex items-center justify-between">
        <div class="text-sm text-slate-500">OS Selesai</div>
        @if($badgeOs)
          <span class="text-xs px-2 py-1 rounded-full border {{ $badgeOs['class'] }}">
            {{ $badgeOs['label'] }}
          </span>
        @endif
      </div>
      <div class="text-2xl font-black text-slate-900 mt-1">{{ $fmtRp($row->actual_os_selesai ?? 0) }}</div>
      <div class="text-sm text-slate-500 mt-1">
        Target: <b>{{ $fmtRp($row->target_os_selesai_fix ?? 0) }}</b>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="flex items-center justify-between">
        <div class="text-sm text-slate-500">Bunga Masuk</div>
        @if($badgeBunga)
          <span class="text-xs px-2 py-1 rounded-full border {{ $badgeBunga['class'] }}">
            {{ $badgeBunga['label'] }}
          </span>
        @endif
      </div>
      <div class="text-2xl font-black text-slate-900 mt-1">{{ $fmtRp($row->actual_bunga_masuk ?? 0) }}</div>
      <div class="text-sm text-slate-500 mt-1">
        Target: <b>{{ $fmtRp($row->target_bunga_masuk_fix ?? 0) }}</b>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="flex items-center justify-between">
        <div class="text-sm text-slate-500">Denda Masuk</div>
        @if($badgeDenda)
          <span class="text-xs px-2 py-1 rounded-full border {{ $badgeDenda['class'] }}">
            {{ $badgeDenda['label'] }}
          </span>
        @endif
      </div>
      <div class="text-2xl font-black text-slate-900 mt-1">{{ $fmtRp($row->actual_denda_masuk ?? 0) }}</div>
      <div class="text-sm text-slate-500 mt-1">
        Target: <b>{{ $fmtRp($row->target_denda_masuk_fix ?? 0) }}</b>
      </div>
    </div>

  </div>

  {{-- Extra Cards Row --}}
  <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="flex items-center justify-between">
        <div class="text-sm text-slate-500">NOA Selesai</div>
        @if($badgeNoa)
          <span class="text-xs px-2 py-1 rounded-full border {{ $badgeNoa['class'] }}">
            {{ $badgeNoa['label'] }}
          </span>
        @endif
      </div>
      <div class="text-2xl font-black text-slate-900 mt-1">{{ $fmtNum($row->actual_noa_selesai ?? 0) }}</div>
      <div class="text-sm text-slate-500 mt-1">
        Target: <b>{{ $fmtNum($row->target_noa_selesai_fix ?? 0) }}</b>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-sm text-slate-500">NPL Prev</div>
      <div class="text-2xl font-black text-slate-900 mt-1">{{ $fmtRp($row->os_npl_prev ?? 0) }}</div>
      <div class="text-sm text-slate-500 mt-1">
        NPL Now: <b>{{ $fmtRp($row->os_npl_now ?? 0) }}</b>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-sm text-slate-500">Net Penurunan NPL</div>
      <div class="text-2xl font-black text-slate-900 mt-1">{{ $fmtRp($row->net_npl_drop ?? 0) }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Positif = NPL turun (membaik). Negatif = NPL naik (perlu aksi).
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
          <li>{!! $b !!}</li>
        @endforeach
      </ul>
    </div>
  </div>

  {{-- Breakdown --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="text-xl font-bold text-slate-900">Breakdown KPI BE</div>
      <div class="text-sm text-slate-500 mt-1">Detail target, realisasi, skor, dan PI.</div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-left text-slate-600">
            <th class="px-3 py-2">Komponen</th>
            <th class="px-3 py-2">Target</th>
            <th class="px-3 py-2">Actual</th>
            <th class="px-3 py-2">Score</th>
            <th class="px-3 py-2">PI</th>
          </tr>
        </thead>
        <tbody>

          <tr class="border-t">
            <td class="px-3 py-3 font-semibold">OS Selesai</td>
            <td class="px-3 py-3">{{ $fmtRp($row->target_os_selesai_fix ?? 0) }}</td>
            <td class="px-3 py-3">{{ $fmtRp($row->actual_os_selesai ?? 0) }}</td>
            <td class="px-3 py-3 font-black">{{ (int)($row->score_os ?? 0) }}</td>
            <td class="px-3 py-3 font-black">{{ number_format((float)($row->pi_os ?? 0), 2, '.', '') }}</td>
          </tr>

          <tr class="border-t">
            <td class="px-3 py-3 font-semibold">NOA Selesai</td>
            <td class="px-3 py-3">{{ $fmtNum($row->target_noa_selesai_fix ?? 0) }}</td>
            <td class="px-3 py-3">{{ $fmtNum($row->actual_noa_selesai ?? 0) }}</td>
            <td class="px-3 py-3 font-black">{{ (int)($row->score_noa ?? 0) }}</td>
            <td class="px-3 py-3 font-black">{{ number_format((float)($row->pi_noa ?? 0), 2, '.', '') }}</td>
          </tr>

          <tr class="border-t">
            <td class="px-3 py-3 font-semibold">Bunga Masuk</td>
            <td class="px-3 py-3">{{ $fmtRp($row->target_bunga_masuk_fix ?? 0) }}</td>
            <td class="px-3 py-3">{{ $fmtRp($row->actual_bunga_masuk ?? 0) }}</td>
            <td class="px-3 py-3 font-black">{{ (int)($row->score_bunga ?? 0) }}</td>
            <td class="px-3 py-3 font-black">{{ number_format((float)($row->pi_bunga ?? 0), 2, '.', '') }}</td>
          </tr>

          <tr class="border-t">
            <td class="px-3 py-3 font-semibold">Denda Masuk</td>
            <td class="px-3 py-3">{{ $fmtRp($row->target_denda_masuk_fix ?? 0) }}</td>
            <td class="px-3 py-3">{{ $fmtRp($row->actual_denda_masuk ?? 0) }}</td>
            <td class="px-3 py-3 font-black">{{ (int)($row->score_denda ?? 0) }}</td>
            <td class="px-3 py-3 font-black">{{ number_format((float)($row->pi_denda ?? 0), 2, '.', '') }}</td>
          </tr>

        </tbody>
        <tfoot class="bg-slate-50">
          <tr class="border-t">
            <td class="px-3 py-3 font-semibold" colspan="4">TOTAL PI</td>
            <td class="px-3 py-3 font-black">
              {{ number_format((float)($row->total_pi ?? 0), 2, '.', '') }}
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  @endif

</div>
@endsection
