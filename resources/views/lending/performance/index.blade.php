@extends('layouts.app')

@section('title', 'Lending Performance')

@section('content')
@php
  $fmt = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
  $pct = fn($v) => number_format((float)$v * 100, 2) . '%';

  $lampClass = fn($lamp) => match ($lamp) {
    'red'    => 'bg-red-50 text-red-700 border-red-100',
    'yellow' => 'bg-amber-50 text-amber-800 border-amber-100',
    default  => 'bg-emerald-50 text-emerald-700 border-emerald-100',
  };

  $lampText = fn($lamp) => match ($lamp) {
    'red'    => 'MERAH',
    'yellow' => 'KUNING',
    default  => 'HIJAU',
  };
@endphp

<div class="space-y-4">
  {{-- HEADER --}}
  <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
    <div>
      <h1 class="text-xl font-bold text-slate-900">📊 Lending Performance</h1>
      <p class="text-sm text-slate-500">
        Monitoring performa tim lending berbasis snapshot posisi (position_date).
      </p>
    </div>
      {{-- TABS --}}
    <div class="flex items-center gap-2">
      <a href="{{ route('lending.performance.index', request()->query()) }}"
        class="rounded-xl px-3 py-2 text-sm font-semibold border
                {{ request()->routeIs('lending.performance.*') ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50' }}">
        📊 Summary
      </a>

      <a href="{{ route('lending.trend.index', [
              // bawa filter yang masih nyambung kalau ada
              'branch' => $filter['branch_code'] ?? 'ALL',
              'ao'     => $filter['ao_code'] ?? 'ALL',
              // month default: bulan sekarang (biar tidak kosong)
              'month'  => now()->format('Y-m-01'),
              'months' => 12,
          ]) }}"
        class="rounded-xl px-3 py-2 text-sm font-semibold border
                {{ request()->routeIs('lending.trend.*') ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50' }}">
        📈 Trend
      </a>
    </div>
  </div>

    {{-- FILTER --}}
  <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
    <form class="flex flex-wrap items-end gap-2" method="GET" action="{{ route('lending.performance.index') }}">
      <div>
        <label class="text-xs text-slate-500">Position Date</label>
        <input type="date" name="position_date" value="{{ $filter['position_date'] }}"
          class="mt-1 w-44 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
      </div>

      <div>
        <label class="text-xs text-slate-500">Branch</label>
        <select name="branch_code"
          class="mt-1 w-40 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
          <option value="">ALL</option>
          @foreach($branches as $b)
            <option value="{{ $b }}" @selected($filter['branch_code']===$b)>{{ $b }}</option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="text-xs text-slate-500">AO</label>
        <select name="ao_code"
          class="mt-1 w-44 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
          <option value="">ALL</option>
          @foreach($aoOptions as $ao)
            <option value="{{ $ao }}" @selected($filter['ao_code']===$ao)>{{ $ao }}</option>
          @endforeach
        </select>
      </div>

      <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">
        Terapkan
      </button>
    </form>
  </div>

  {{-- SUMMARY CARDS --}}
  <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">Total OS</div>
      <div class="mt-1 text-lg font-extrabold text-slate-900">{{ $fmt($summary['total_os']) }}</div>
      <div class="mt-1 text-xs text-slate-500">NOA: {{ number_format($summary['noa']) }}</div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">NPL (Kolek 3-5)</div>
      <div class="mt-1 text-lg font-extrabold text-slate-900">{{ $fmt($summary['npl_os']) }}</div>
      <div class="mt-1 text-xs text-slate-500">Rasio: {{ $pct($summary['npl_ratio']) }}</div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">DPD &gt; 30</div>
      <div class="mt-1 text-lg font-extrabold text-slate-900">{{ $fmt($summary['dpd_gt_30_os']) }}</div>
      <div class="mt-1 text-xs text-slate-500">DPD &gt; 60: {{ $fmt($summary['dpd_gt_60_os']) }}</div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">DPD &gt; 90</div>
      <div class="mt-1 text-lg font-extrabold text-slate-900">{{ $fmt($summary['dpd_gt_90_os']) }}</div>
      <div class="mt-1 text-xs text-slate-500">DPD &gt; 0: {{ $fmt($summary['dpd_gt_0_os']) }}</div>
    </div>
  </div>

  {{-- TABLE RANKING AO --}}
  <div class="rounded-2xl border border-slate-100 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
      <div>
        <div class="text-sm font-bold text-slate-900">🏁 Ranking AO</div>
        <div class="text-xs text-slate-500">Urut OS terbesar, dengan indikator kualitas & disiplin.</div>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-600">
          <tr>
            <th class="px-4 py-3 text-left">AO</th>
            <th class="px-4 py-3 text-right">OS</th>
            <th class="px-4 py-3 text-right">NOA</th>
            <th class="px-4 py-3 text-right">NPL</th>
            <th class="px-4 py-3 text-right">NPL%</th>
            <th class="px-4 py-3 text-right">DPD&gt;30</th>
            <th class="px-4 py-3 text-right">DPD&gt;90</th>
            <th class="px-4 py-3 text-right">Overdue Agenda</th>
            <th class="px-4 py-3 text-center">Lampu</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @forelse($rows as $r)
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <a
                    href="{{ route('lending.performance.ao', ['ao_code' => $r->ao_code] + $filter) }}"
                    class="group inline-flex items-center gap-1 font-semibold text-indigo-700 hover:text-indigo-900 hover:underline"
                    title="Klik untuk lihat Root Cause AO"
                    >
                    <span>{{ $r->ao_code }}</span>
                    <svg class="h-3.5 w-3.5 opacity-60 group-hover:opacity-100"
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </a>
                <div class="text-xs text-slate-500">{{ $r->ao_name }}</div>
              </td>
              <td class="px-4 py-3 text-right font-semibold">{{ $fmt($r->os) }}</td>
              <td class="px-4 py-3 text-right">{{ number_format($r->noa) }}</td>
              <td class="px-4 py-3 text-right">{{ $fmt($r->npl_os) }}</td>
              <td class="px-4 py-3 text-right">{{ $pct($r->npl_pct) }}</td>
              <td class="px-4 py-3 text-right">{{ $fmt($r->dpd_gt_30_os) }}</td>
              <td class="px-4 py-3 text-right">{{ $fmt($r->dpd_gt_90_os) }}</td>
              <td class="px-4 py-3 text-right">{{ number_format($r->overdue_count) }}</td>
              <td class="px-4 py-3 text-center">
                <div class="relative group inline-block">
                    <span class="inline-flex cursor-help items-center rounded-full border px-2 py-1 text-xs font-bold {{ $lampClass($r->lamp) }}">
                        {{ $lampText($r->lamp) }}
                    </span>

                    <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 w-56 -translate-x-1/2 rounded-lg border border-slate-200 bg-white p-2 text-xs text-slate-700 shadow-lg opacity-0 transition group-hover:opacity-100">
                        @if($r->lamp === 'red')
                        <div class="font-bold text-red-600">🔴 MERAH</div>
                        <ul class="mt-1 list-disc pl-4">
                            <li>NPL ≥ 5%</li>
                            <li>atau ada DPD &gt; 90</li>
                            <li>atau overdue ≥ 10 agenda</li>
                        </ul>
                        <div class="mt-1 text-slate-500">Perlu intervensi segera.</div>
                        @elseif($r->lamp === 'yellow')
                        <div class="font-bold text-amber-600">🟡 KUNING</div>
                        <ul class="mt-1 list-disc pl-4">
                            <li>NPL 3–5%</li>
                            <li>atau ada DPD &gt; 60</li>
                            <li>atau overdue 5–9 agenda</li>
                        </ul>
                        <div class="mt-1 text-slate-500">Perlu pemantauan ketat.</div>
                        @else
                        <div class="font-bold text-emerald-600">🟢 HIJAU</div>
                        <ul class="mt-1 list-disc pl-4">
                            <li>NPL &lt; 3%</li>
                            <li>Tidak ada DPD &gt; 60</li>
                            <li>Overdue &lt; 5 agenda</li>
                        </ul>
                        <div class="mt-1 text-slate-500">Portofolio sehat.</div>
                        @endif
                    </div>
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="9" class="px-4 py-6 text-center text-slate-500">
                Tidak ada data untuk filter yang dipilih.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection
