@extends('layouts.app')

@section('title', 'Target KPI AO')

@section('content')
@php
  // ✅ Input style yang "ngunci" text color & caret biar angka gak hilang saat focus
  $inputBase  = 'px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 tabular-nums shadow-sm';
  $inputFocus = 'focus:outline-none focus:ring-4 focus:ring-indigo-100 focus:border-indigo-400 focus:text-slate-900 caret-slate-900';
  $inputHover = 'hover:border-slate-300';
  $inputCls   = $inputBase.' '.$inputHover.' '.$inputFocus;

  $fmtRp = fn($n) => 'Rp ' . number_format((int)($n ?? 0), 0, ',', '.');
@endphp

<div class="w-full max-w-6xl space-y-5">

  {{-- Header --}}
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <h1 class="text-2xl font-semibold text-slate-800">Target KPI AO</h1>
      <p class="text-sm text-slate-500">
        Diinput oleh Leader. Setelah simpan, jalankan <b>Recalc AO</b>.
      </p>
    </div>
  </div>

  {{-- ✅ Recalc form (pisah, jangan nested) --}}
  <form id="recalcAoForm" method="POST" action="{{ route('kpi.recalc.ao') }}" class="hidden">
    @csrf
    <input type="hidden" name="period" value="{{ $periodYmd }}">
  </form>

  {{-- Filter period --}}
  <form method="GET" action="{{ route('kpi.ao.targets.index') }}"
        class="rounded-2xl border border-slate-200 bg-white p-4">
    <div class="flex flex-col md:flex-row md:items-end gap-3">
      <div class="space-y-1">
        <div class="text-sm font-medium text-slate-700">Periode</div>
        <input type="month" name="period"
               value="{{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}"
               class="{{ $inputCls }} w-48" />
      </div>

      <button class="inline-flex items-center justify-center rounded-xl bg-slate-900 text-white px-5 py-2 font-semibold">
        Tampilkan
      </button>

      <div class="flex-1"></div>

      {{-- tombol pakai form="recalcAoForm" --}}
      <button type="submit" form="recalcAoForm"
              class="inline-flex items-center justify-center rounded-xl bg-indigo-600 text-white px-5 py-2 font-semibold">
        Recalc AO
      </button>
    </div>
  </form>

  {{-- Form save target --}}
  <form method="POST" action="{{ route('kpi.ao.targets.store') }}"
        class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    @csrf
    <input type="hidden" name="period" value="{{ $periodYmd }}"/>

    <div class="p-4 border-b border-slate-200 flex items-center justify-between">
      <div>
        <div class="text-lg font-semibold text-slate-900">Daftar AO</div>
        <div class="text-sm text-slate-500">
          Periode: <b>{{ \Carbon\Carbon::parse($periodYmd)->translatedFormat('F Y') }}</b>
        </div>
      </div>

      <button class="inline-flex items-center justify-center rounded-xl bg-emerald-600 text-white px-5 py-2 font-semibold">
        Simpan Target
      </button>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-700">
          <tr>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-left px-3 py-2">AO Code</th>
            <th class="text-left px-3 py-2">Target OS Realisasi</th>
            <th class="text-left px-3 py-2">Target NOA</th>
            <th class="text-left px-3 py-2">Target RR</th>
            <th class="text-left px-3 py-2">Target Grab Community</th>
            <th class="text-left px-3 py-2">Target Daily Report</th>
          </tr>
        </thead>

        <tbody class="divide-y">
          @foreach($users as $u)
            @php
              $t = $targets[$u->id] ?? null;
              $aoCode = str_pad(trim((string)($u->ao_code ?? '')), 6, '0', STR_PAD_LEFT);

              $valOs  = (int)($t->target_os_disbursement ?? 500000000);
              $valNoa = (int)($t->target_noa_disbursement ?? 15);
              $valRr  = (float)($t->target_rr ?? 90);
              $valCom = (int)($t->target_community ?? 2);
              $valDay = (int)($t->target_daily_report ?? 5);
            @endphp

            <tr class="hover:bg-slate-50/60">
              <td class="px-3 py-3 font-semibold text-slate-900">
                {{ $u->name }}
                <div class="text-xs text-slate-500">AO</div>
              </td>

              <td class="px-3 py-3 text-slate-700">{{ $aoCode }}</td>

              {{-- OS --}}
              <td class="px-3 py-3">
                <input type="number"
                       name="targets[{{ $u->id }}][target_os_disbursement]"
                       value="{{ $valOs }}"
                       class="{{ $inputCls }} w-64 text-right font-semibold"
                       min="0" step="1000" />
                <div class="text-xs text-slate-400 mt-1">
                  Preview: <span class="font-semibold text-slate-600">{{ $fmtRp($valOs) }}</span>
                </div>
              </td>

              {{-- NOA --}}
              <td class="px-3 py-3">
                <input type="number"
                       name="targets[{{ $u->id }}][target_noa_disbursement]"
                       value="{{ $valNoa }}"
                       class="{{ $inputCls }} w-28 text-right font-semibold"
                       min="0" step="1" />
              </td>

              {{-- RR --}}
              <td class="px-3 py-3">
                <input type="number"
                       step="0.01"
                       name="targets[{{ $u->id }}][target_rr]"
                       value="{{ $valRr }}"
                       class="{{ $inputCls }} w-28 text-right font-semibold"
                       min="0" max="100" />
                <div class="text-xs text-slate-400 mt-1">0–100</div>
              </td>

              {{-- Community --}}
              <td class="px-3 py-3">
                <input type="number"
                       name="targets[{{ $u->id }}][target_community]"
                       value="{{ $valCom }}"
                       class="{{ $inputCls }} w-28 text-right font-semibold"
                       min="0" step="1" />
              </td>

              {{-- Daily --}}
              <td class="px-3 py-3">
                <input type="number"
                       name="targets[{{ $u->id }}][target_daily_report]"
                       value="{{ $valDay }}"
                       class="{{ $inputCls }} w-28 text-right font-semibold"
                       min="0" step="1" />
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </form>

</div>
@endsection
