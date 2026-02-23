@php
  $li = (float)($row->leadership_index ?? 0);
  $badge = $li >= 4.5 ? ['AMAN','bg-emerald-100 text-emerald-800 border-emerald-200']
          : ($li >= 3.5 ? ['CUKUP','bg-sky-100 text-sky-800 border-sky-200']
          : ($li >= 2.5 ? ['WASPADA','bg-amber-100 text-amber-800 border-amber-200']
          : ['KRITIS','bg-rose-100 text-rose-800 border-rose-200']));
@endphp

{{-- Header --}}
<div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
  <div>
    <div class="text-sm text-slate-500">Periode</div>
    <div class="text-3xl sm:text-4xl font-black text-slate-900">{{ $periodLabel }}</div>

    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
      <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-1 text-slate-700">
        Role: <b class="ml-1">TLFE</b>
      </span>
      <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-1 text-slate-700">
        Mode: <b class="ml-1">{{ $modeLabel }}</b>
      </span>
      <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-1 text-slate-700">
        FE Scope: <b class="ml-1">{{ (int)($row->fe_count ?? 0) }}</b>
      </span>
    </div>
  </div>

  <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
    <form method="GET" action="{{ route('kpi.tlfe.sheet') }}" class="flex items-center gap-2">
      <input type="month"
             name="period"
             value="{{ \Carbon\Carbon::parse($period)->format('Y-m') }}"
             class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
      <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
        Lihat
      </button>
    </form>

    <form method="POST" action="{{ route('kpi.tlfe.sheet.recalc') }}">
      @csrf
      <input type="hidden" name="period" value="{{ $period }}">
      <button class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
        Recalc
      </button>
    </form>
  </div>
</div>

@if(session('success'))
  <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-900">
    {{ session('success') }}
  </div>
@endif

{{-- Cards --}}
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
    <div class="mt-2 text-[11px] text-slate-500">Agregat performa FE dalam scope</div>
  </div>

  {{-- PI Scope --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
    <div class="text-xs text-slate-500">PI_scope</div>
    <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $fmt2($row->pi_scope ?? 0) }}</div>
    <div class="mt-2 text-[11px] text-slate-500">Avg PI FE</div>
  </div>

  {{-- Stability --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
    <div class="text-xs text-slate-500">Stability</div>
    <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $fmt2($row->stability_index ?? 0) }}</div>
    <div class="mt-2 text-[11px] text-slate-500">Spread + bottom + coverage</div>
  </div>

  {{-- Risk --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
    <div class="text-xs text-slate-500">Risk</div>
    <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $fmt2($row->risk_index ?? 0) }}</div>
    <div class="mt-2 text-[11px] text-slate-500">Governance migrasi NPL</div>
  </div>

  {{-- Improvement --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
    <div class="text-xs text-slate-500">Improvement</div>
    <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $fmt2($row->improvement_index ?? 0) }}</div>
    <div class="mt-2 text-[11px] text-slate-500">MoM delta PI_scope</div>
  </div>
</div>

{{-- AI Box --}}
<div class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-6">
  <div class="flex items-start justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">AI Leadership Engine</div>
      <div class="mt-1 text-xl sm:text-2xl font-black text-slate-900">
        {{ $aiTitle ?: 'Ringkasan kepemimpinan TLFE berdasarkan agregasi KPI FE.' }}
      </div>
    </div>
    <div class="shrink-0">
      <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700">
        {{ strtoupper((string)($row->status_label ?? 'N/A')) }}
      </span>
    </div>
  </div>

  <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
      <div class="text-xs font-semibold text-slate-700">Insight</div>
      <ul class="mt-2 space-y-1 text-sm text-slate-700 list-disc pl-5">
        @forelse(($aiBullets ?? []) as $b)
          <li>{{ $b }}</li>
        @empty
          <li>Belum ada insight otomatis.</li>
        @endforelse
      </ul>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
      <div class="text-xs font-semibold text-slate-700">Actions Now</div>
      <ol class="mt-2 space-y-1 text-sm text-slate-700 list-decimal pl-5">
        @forelse(($aiActions ?? []) as $a)
          <li>{{ $a }}</li>
        @empty
          <li>Recalc untuk memunculkan rekomendasi.</li>
        @endforelse
      </ol>
    </div>
  </div>
</div>