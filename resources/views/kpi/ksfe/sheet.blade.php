@extends('layouts.app')

@section('title', 'KPI KSFE · Leadership Index')

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-5">

  {{-- Header --}}
  <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">Periode</div>
      <div class="text-3xl sm:text-4xl font-black text-slate-900">{{ $periodLabel }}</div>
      <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-1 text-slate-700">
          Role: <b class="ml-1">KSFE</b>
        </span>
        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-1 text-slate-700">
          Mode: <b class="ml-1">{{ $modeLabel }}</b>
        </span>
        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-1 text-slate-700">
          TLFE Scope: <b class="ml-1">{{ (int)($row->tlfe_count ?? 0) }}</b>
        </span>
      </div>
    </div>

    {{-- Actions --}}
    <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
      <form method="GET" action="{{ route('kpi.ksfe.sheet') }}" class="flex items-center gap-2">
        <input type="month"
               name="period"
               value="{{ \Carbon\Carbon::parse($period)->format('Y-m') }}"
               class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
        <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
          Lihat
        </button>
      </form>

      <form method="POST" action="{{ route('kpi.ksfe.sheet.recalc') }}">
        @csrf
        <input type="hidden" name="period" value="{{ $period }}">
        <button class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          Recalc
        </button>
      </form>
    </div>
  </div>

  {{-- Flash --}}
  @if(session('success'))
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-900">
      {{ session('success') }}
    </div>
  @endif

  {{-- KPI Cards --}}
  @php
    $li = (float)($row->leadership_index ?? 0);
    $status = strtoupper((string)($row->status_label ?? ''));
    $badge = $li >= 4.5 ? ['AMAN','bg-emerald-100 text-emerald-800 border-emerald-200']
            : ($li >= 3.5 ? ['CUKUP','bg-sky-100 text-sky-800 border-sky-200']
            : ($li >= 2.5 ? ['WASPADA','bg-amber-100 text-amber-800 border-amber-200']
            : ['KRITIS','bg-rose-100 text-rose-800 border-rose-200']));
  @endphp

  <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 sm:gap-3">

    {{-- Leadership Index --}}
    <div class="col-span-2 sm:col-span-1 rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-xs text-slate-500">Leadership Index</div>
      <div class="mt-1 text-3xl font-black text-slate-900">{{ $fmt2($row->leadership_index ?? 0) }}</div>
      <div class="mt-2">
        <span class="inline-flex items-center rounded-full border px-2 py-1 text-xs font-semibold {{ $badge[1] }}">
          {{ $badge[0] }}
        </span>
      </div>
      <div class="mt-2 text-[11px] text-slate-500">Agregat leadership TLFE (scope KSFE)</div>
    </div>

    {{-- PI Scope --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-xs text-slate-500">PI_scope</div>
      <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $fmt2($row->pi_scope ?? 0) }}</div>
      <div class="mt-2 text-[11px] text-slate-500">Avg LI TLFE</div>
    </div>

    {{-- Stability --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-xs text-slate-500">Stability</div>
      <div class="mt-1 text-2xl font-extrabold text-slate-900">
        {{ is_null($row->stability_index) ? '-' : $fmt2($row->stability_index) }}
      </div>
      <div class="mt-2 text-[11px] text-slate-500">Sebaran antar TLFE</div>
    </div>

    {{-- Risk --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-xs text-slate-500">Risk</div>
      <div class="mt-1 text-2xl font-extrabold text-slate-900">
        {{ is_null($row->risk_index) ? '-' : $fmt2($row->risk_index) }}
      </div>
      <div class="mt-2 text-[11px] text-slate-500">Governance risiko (agregat)</div>
    </div>

    {{-- Improvement --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-xs text-slate-500">Improvement</div>
      <div class="mt-1 text-2xl font-extrabold text-slate-900">
        {{ is_null($row->improvement_index) ? '-' : $fmt2($row->improvement_index) }}
      </div>
      <div class="mt-2 text-[11px] text-slate-500">MoM delta PI_scope</div>
    </div>
  </div>

  {{-- AI Leadership Engine Box --}}
  <div class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-6">
    <div class="flex items-start justify-between gap-3">
      <div>
        <div class="text-sm text-slate-500">AI Leadership Engine</div>
        <div class="mt-1 text-xl sm:text-2xl font-black text-slate-900">{{ $aiTitle }}</div>
      </div>
      <div class="shrink-0">
        <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700">
          {{ $status ?: 'N/A' }}
        </span>
      </div>
    </div>

    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <div class="text-xs font-semibold text-slate-700">Insight</div>
        <ul class="mt-2 space-y-1 text-sm text-slate-700 list-disc pl-5">
          @foreach(($aiBullets ?? []) as $b)
            <li>{{ $b }}</li>
          @endforeach
        </ul>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <div class="text-xs font-semibold text-slate-700">Actions Now</div>
        <ol class="mt-2 space-y-1 text-sm text-slate-700 list-decimal pl-5">
          @foreach(($aiActions ?? []) as $a)
            <li>{{ $a }}</li>
          @endforeach
        </ol>
      </div>
    </div>
  </div>

  {{-- Breakdown TLFE --}}
  <div class="rounded-3xl border border-slate-200 bg-white overflow-hidden">
    <div class="px-4 sm:px-6 py-4 border-b border-slate-200">
      <div class="flex items-center justify-between gap-3">
        <div>
          <div class="text-sm text-slate-500">Breakdown</div>
          <div class="text-lg font-extrabold text-slate-900">TLFE dalam Scope KSFE</div>
        </div>
        <div class="text-xs text-slate-500">
          Total: <b>{{ $tlfeRows->count() }}</b>
        </div>
      </div>
    </div>

    @if($tlfeRows->isEmpty())
      <div class="p-6 text-sm text-slate-600">
        Belum ada data TLFE untuk periode ini. Pastikan:
        <ul class="list-disc pl-5 mt-2 space-y-1">
          <li>org_assignments KSFE → TLFE sudah benar</li>
          <li>TLFE builder sudah direcalc untuk period yang sama</li>
        </ul>
      </div>
    @else
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-600">
            <tr class="border-b">
              <th class="text-left px-4 py-3">Rank</th>
              <th class="text-left px-4 py-3">TLFE</th>
              <th class="text-right px-4 py-3">FE</th>
              <th class="text-right px-4 py-3">PI_scope</th>
              <th class="text-right px-4 py-3">Stability</th>
              <th class="text-right px-4 py-3">Risk</th>
              <th class="text-right px-4 py-3">Improve</th>
              <th class="text-right px-4 py-3">LI</th>
              <th class="text-left px-4 py-3">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            @foreach($tlfeRows as $i => $r)
              @php
                $li2 = (float)($r->leadership_index ?? 0);
                $b2 = $li2 >= 4.5 ? ['AMAN','bg-emerald-100 text-emerald-800 border-emerald-200']
                    : ($li2 >= 3.5 ? ['CUKUP','bg-sky-100 text-sky-800 border-sky-200']
                    : ($li2 >= 2.5 ? ['WASPADA','bg-amber-100 text-amber-800 border-amber-200']
                    : ['KRITIS','bg-rose-100 text-rose-800 border-rose-200']));
              @endphp
              <tr>
                <td class="px-4 py-3 font-semibold">{{ $i+1 }}</td>
                <td class="px-4 py-3">
                  <div class="font-semibold text-slate-900">{{ $r->tlfe_name ?: ('TLFE #'.$r->tlfe_id) }}</div>
                  <div class="text-xs text-slate-500">ID: {{ $r->tlfe_id }}</div>
                </td>
                <td class="px-4 py-3 text-right">{{ (int)($r->fe_count ?? 0) }}</td>
                <td class="px-4 py-3 text-right">{{ $fmt2($r->pi_scope ?? 0) }}</td>
                <td class="px-4 py-3 text-right">{{ is_null($r->stability_index) ? '-' : $fmt2($r->stability_index) }}</td>
                <td class="px-4 py-3 text-right">{{ is_null($r->risk_index) ? '-' : $fmt2($r->risk_index) }}</td>
                <td class="px-4 py-3 text-right">{{ is_null($r->improvement_index) ? '-' : $fmt2($r->improvement_index) }}</td>
                <td class="px-4 py-3 text-right font-extrabold text-slate-900">{{ $fmt2($r->leadership_index ?? 0) }}</td>
                <td class="px-4 py-3">
                  <span class="inline-flex items-center rounded-full border px-2 py-1 text-xs font-semibold {{ $b2[1] }}">
                    {{ strtoupper((string)($r->status_label ?? $b2[0])) }}
                  </span>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

</div>
@endsection