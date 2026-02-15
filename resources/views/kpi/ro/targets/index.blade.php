@extends('layouts.app')

@section('title','Target KPI RO')

@section('content')
@php
  $periodYm = $periodYm ?? request('period', now()->format('Y-m'));
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-5">

  <div>
    <h1 class="text-2xl font-bold text-slate-900">Target KPI RO</h1>
    <p class="text-sm text-slate-500">Diinput oleh KBL. Setelah simpan, jalankan Recalc RO.</p>
  </div>

  <form method="GET" class="rounded-2xl border border-slate-200 bg-white p-4 flex items-center gap-3">
    <div class="text-sm font-semibold text-slate-700 w-24">Periode</div>
    <input type="month" name="period" value="{{ $periodYm }}"
           class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
    <button class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
      Tampilkan
    </button>
  </form>

  <form method="POST" action="{{ route('kpi.ro.targets.store') }}"
        class="rounded-2xl border border-slate-200 bg-white p-4">
    @csrf
    <input type="hidden" name="period" value="{{ $periodYm }}">

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-700">
          <tr>
            <th class="text-left px-3 py-3">RO</th>
            <th class="text-left px-3 py-3">AO Code</th>
            <th class="text-right px-3 py-3">Target TopUp (Rp)</th>
            <th class="text-center px-3 py-3">Target NOA</th>
            <th class="text-center px-3 py-3">Target RR (%)</th>
            <th class="text-center px-3 py-3">Target DPK (%)</th>
          </tr>
        </thead>
        <tbody>
          @foreach($ros as $u)
            @php
              $t = $targets[$u->ao_code] ?? null;
              $valTopup = old("target_topup.$u->ao_code", $t?->target_topup ?? '');
              $valNoa   = old("target_noa.$u->ao_code", $t?->target_noa ?? '');
              $valRr    = old("target_rr_pct.$u->ao_code", $t?->target_rr_pct ?? '');
              $valDpk   = old("target_dpk_pct.$u->ao_code", $t?->target_dpk_pct ?? '');
            @endphp
            <tr class="border-t">
              <td class="px-3 py-4">
                <div class="font-semibold text-slate-900">{{ $u->name }}</div>
                <div class="text-xs text-slate-500">{{ $u->level }}</div>
              </td>

              <td class="px-3 py-4 font-mono text-slate-700">{{ $u->ao_code }}</td>

              <td class="px-3 py-4 text-right">
                <input type="text"
                       name="target_topup[{{ $u->ao_code }}]"
                       value="{{ $valTopup }}"
                       placeholder="{{ number_format($defaults['target_topup'],0,',','.') }}"
                       class="w-56 rounded-xl border border-slate-300 px-3 py-2 text-sm text-right">
                <div class="text-xs text-slate-400 mt-1">Rupiah</div>
              </td>

              <td class="px-3 py-4 text-center">
                <input type="number" min="0"
                       name="target_noa[{{ $u->ao_code }}]"
                       value="{{ $valNoa }}"
                       placeholder="{{ $defaults['target_noa'] }}"
                       class="w-24 rounded-xl border border-slate-300 px-3 py-2 text-sm text-center">
              </td>

              <td class="px-3 py-4 text-center">
                <input type="number" step="0.01" min="0" max="100"
                       name="target_rr_pct[{{ $u->ao_code }}]"
                       value="{{ $valRr }}"
                       placeholder="{{ number_format($defaults['target_rr_pct'],2) }}"
                       class="w-28 rounded-xl border border-slate-300 px-3 py-2 text-sm text-center">
                <div class="text-xs text-slate-400 mt-1">0–100</div>
              </td>

              <td class="px-3 py-4 text-center">
                <input type="number" step="0.01" min="0"
                       name="target_dpk_pct[{{ $u->ao_code }}]"
                       value="{{ $valDpk }}"
                       placeholder="{{ number_format($defaults['target_dpk_pct'],2) }}"
                       class="w-28 rounded-xl border border-slate-300 px-3 py-2 text-sm text-center">
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <div class="mt-4 flex justify-end">
      <button class="rounded-xl bg-emerald-600 px-5 py-3 text-white text-sm font-semibold hover:bg-emerald-700">
        Simpan Target
      </button>
    </div>
  </form>

</div>
@endsection
