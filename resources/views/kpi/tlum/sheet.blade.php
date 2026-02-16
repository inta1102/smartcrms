@extends('layouts.app')

@section('title', 'KPI TLUM – Sheet')

@section('content')
@php
  $fmtRp  = fn($n) => 'Rp ' . number_format((int)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n) => number_format((float)($n ?? 0), 2) . '%';
@endphp

<div class="max-w-7xl mx-auto p-4 space-y-4">

  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">KPI TLUM – Sheet</h1>
      <div class="text-sm text-slate-600">Periode: <b>{{ \Carbon\Carbon::parse($periodDate)->translatedFormat('F Y') }}</b></div>
      <div class="text-xs text-slate-500 mt-1">Scope: AO UMKM di bawah TLUM (scheme = AO_UMKM).</div>
    </div>

    <form method="GET" class="flex items-center gap-2">
      <input type="date" name="period" value="{{ $periodDate }}" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
      <button class="rounded-xl bg-slate-900 text-white px-4 py-2 text-sm font-semibold">Tampilkan</button>
    </form>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
      <div class="font-bold text-slate-900">Ranking AO UMKM</div>
      <div class="text-xs text-slate-500">Skor 1–6 • Bobot 30/20/25/20/5</div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-orange-500 text-white">
          <tr>
            <th class="text-left px-3 py-2">#</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">Total PI</th>

            <th class="text-right px-3 py-2">NOA<br><span class="text-[11px] opacity-90">actual / skor</span></th>
            <th class="text-right px-3 py-2">OS Bulanan<br><span class="text-[11px] opacity-90">% / skor</span></th>
            <th class="text-right px-3 py-2">RR<br><span class="text-[11px] opacity-90">% / skor</span></th>
            <th class="text-right px-3 py-2">Community<br><span class="text-[11px] opacity-90">actual / skor</span></th>
            <th class="text-right px-3 py-2">Daily<br><span class="text-[11px] opacity-90">actual / skor</span></th>

            <th class="text-right px-3 py-2">Detail</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse($items as $i => $it)
            <tr class="hover:bg-slate-50">
              <td class="px-3 py-2">{{ $i+1 }}</td>
              <td class="px-3 py-2">
                <div class="font-semibold text-slate-900">{{ $it->name }}</div>
                <div class="text-xs text-slate-500">AO Code: <b>{{ $it->ao_code }}</b></div>
              </td>

              <td class="px-3 py-2 text-right font-extrabold text-slate-900">
                {{ number_format((float)($it->pi_total ?? 0), 2) }}
              </td>

              <td class="px-3 py-2 text-right">
                <div class="font-semibold">{{ (int)($it->noa_disbursement ?? 0) }}</div>
                <div class="text-xs text-slate-500">skor {{ (int)($it->score_noa ?? 0) }}</div>
              </td>

              <td class="px-3 py-2 text-right">
                <div class="font-semibold">{{ $fmtPct($it->os_disbursement_pct ?? 0) }}</div>
                <div class="text-xs text-slate-500">skor {{ (int)($it->score_os ?? 0) }}</div>
              </td>

              <td class="px-3 py-2 text-right">
                <div class="font-semibold">{{ $fmtPct($it->rr_pct ?? 0) }}</div>
                <div class="text-xs text-slate-500">skor {{ (int)($it->score_rr ?? 0) }}</div>
              </td>

              <td class="px-3 py-2 text-right">
                <div class="font-semibold">{{ (int)($it->community_actual ?? 0) }}</div>
                <div class="text-xs text-slate-500">skor {{ (int)($it->score_community ?? 0) }}</div>
              </td>

              <td class="px-3 py-2 text-right">
                <div class="font-semibold">{{ (int)($it->daily_report_actual ?? 0) }}</div>
                <div class="text-xs text-slate-500">skor {{ (int)($it->score_daily_report ?? 0) }}</div>
              </td>

              <td class="px-3 py-2 text-right">
                {{-- sesuaikan route detail AO sheet kamu --}}
                <a href="{{ route('kpi.ao.sheet', ['period' => $periodDate, 'user_id' => $it->user_id]) }}"
                   class="inline-flex items-center rounded-xl border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-100">
                  Lihat
                </a>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="9" class="px-3 py-8 text-center text-slate-500">
                Tidak ada data AO UMKM pada periode ini (atau mapping scope TLUM belum ada).
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection
