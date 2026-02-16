@extends('layouts.app')

@section('title', 'Adjustment KPI SO')

@section('content')
@php
  $fmtRp = fn($n) => 'Rp ' . number_format((int)($n ?? 0), 0, ',', '.');

  $inputBase  = 'w-full px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 tabular-nums shadow-sm';
  $inputFocus = 'focus:outline-none focus:ring-4 focus:ring-indigo-100 focus:border-indigo-400';
  $inputHover = 'hover:border-slate-300';
  $inputCls   = $inputBase.' '.$inputHover.' '.$inputFocus;
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-5">

  <div>
    <h1 class="text-2xl font-bold text-slate-900">Adjustment KPI SO</h1>
    <p class="text-sm text-slate-500">
      Form ini <b>khusus penyesuaian</b> (override manual).
      Actual komunitas utama akan dihitung otomatis dari modul <b>/kpi/communities</b>.
      Setelah simpan, jalankan <b>Recalc SO</b>.
    </p>
  </div>

  {{-- Filter --}}
  <form method="GET" class="bg-white rounded-2xl border border-slate-200 p-4">
    <div class="flex flex-col md:flex-row md:items-end gap-3">
      <div class="space-y-1">
        <div class="text-sm font-medium text-slate-700">Periode</div>
        <input type="month"
               name="period"
               value="{{ \Carbon\Carbon::parse($period)->format('Y-m') }}"
               class="rounded-xl border-slate-200 focus:border-slate-400 focus:ring-slate-200" />
      </div>

      <button class="inline-flex items-center justify-center rounded-xl bg-slate-900 text-white px-5 py-2 font-semibold">
        Tampilkan
      </button>

      <div class="flex-1"></div>

      {{-- Recalc SO (POST) --}}
      <form method="POST" action="{{ route('kpi.recalc.so') }}" class="inline">
        @csrf
        <input type="hidden" name="period" value="{{ \Carbon\Carbon::parse($period)->format('Y-m') }}">
        <button class="inline-flex items-center justify-center rounded-xl bg-indigo-600 text-white px-5 py-2 font-semibold">
          Recalc SO
        </button>
      </form>
    </div>
  </form>

  @if (session('status'))
    <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-emerald-800">
      {{ session('status') }}
    </div>
  @endif

  {{-- Save adjustment --}}
  <form method="POST" action="{{ route('kpi.so.community_input.store') }}"
        class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
    @csrf
    <input type="hidden" name="period" value="{{ \Carbon\Carbon::parse($period)->startOfMonth()->toDateString() }}">

    <div class="p-4 border-b border-slate-200 flex items-center justify-between">
      <div>
        <div class="text-lg font-semibold text-slate-900">Daftar SO</div>
        <div class="text-sm text-slate-500">
          Periode: <b>{{ \Carbon\Carbon::parse($period)->translatedFormat('F Y') }}</b>
        </div>
        <div class="text-xs text-slate-500 mt-1">
          <b>Handling Adjustment</b> menambah actual handling (khusus scoring) bila ada komunitas yang belum tercatat di master.
          <b>OS Titipan</b> mengurangi actual OS SO saat scoring.
        </div>
      </div>

      <button class="inline-flex items-center justify-center rounded-xl bg-emerald-600 text-white px-5 py-2 font-semibold">
        Simpan Adjustment
      </button>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-700">
          <tr>
            <th class="text-left px-3 py-2">SO</th>
            <th class="text-left px-3 py-2">AO Code</th>
            <th class="text-right px-3 py-2">Target Handling</th>
            <th class="text-right px-3 py-2">Handling Adjustment</th>
            <th class="text-right px-3 py-2">OS Titipan (Adjustment)</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-100">
          @foreach ($users as $i => $u)
            @php
              $row = $inputs->get($u->id);

              // ✅ rename semantik: ini override, bukan actual utama
              $handlingAdj = (int)($row->handling_actual ?? 0); // (nanti kalau sudah ganti kolom, ubah ke handling_adjustment)
              $osAdj       = (int)($row->os_adjustment ?? 0);

              // target dari tabel target SO (kalau sudah disiapkan di controller)
              $tgtHandling = (int)($targetsByUserId[$u->id]->target_handling ?? 0);
            @endphp

            <tr class="hover:bg-slate-50">
              <td class="px-3 py-3">
                <div class="font-semibold text-slate-900">{{ $u->name }}</div>
                <div class="text-xs text-slate-500">{{ $u->level }}</div>
              </td>

              <td class="px-3 py-3 text-slate-700">
                {{ $u->ao_code }}
              </td>

              <td class="px-3 py-3 text-right font-semibold">
                {{ $tgtHandling }}
              </td>

              <td class="px-3 py-3">
                <input type="hidden" name="items[{{ $i }}][user_id]" value="{{ $u->id }}">
                <input type="number"
                       min="0"
                       name="items[{{ $i }}][handling_actual]"
                       value="{{ $handlingAdj }}"
                       class="{{ $inputCls }} max-w-[180px] ml-auto text-right"
                       placeholder="0">
                <div class="text-xs text-slate-400 mt-1 text-right">
                  tambah skor handling (override)
                </div>
              </td>

              <td class="px-3 py-3">
                <input type="number"
                       min="0"
                       name="items[{{ $i }}][os_adjustment]"
                       value="{{ $osAdj }}"
                       class="{{ $inputCls }} max-w-[260px] ml-auto text-right"
                       placeholder="contoh: 3000000000">
                <div class="text-xs text-slate-400 mt-1 text-right">
                  preview: <b>{{ $fmtRp($osAdj) }}</b>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>

      <div class="mt-4 text-xs text-slate-500">
        Tips: kalau input belum terlihat jelas saat fokus, pastikan tidak ada CSS global yang memberi
        <code class="px-1 rounded bg-slate-100">color: transparent</code> atau <code class="px-1 rounded bg-slate-100">-webkit-text-fill-color</code>.
        Dengan class di atas, angka harus tetap kelihatan.
      </div>
    </div>
  </form>

</div>
@endsection
