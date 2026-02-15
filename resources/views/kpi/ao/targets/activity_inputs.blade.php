@extends('layouts.app')

@section('title', 'Input Komunitas & Daily Report AO')

@section('content')
<div class="w-full max-w-6xl space-y-5">

  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <h1 class="text-2xl font-semibold text-slate-800">Input Komunitas & Daily Report AO</h1>
      <p class="text-sm text-slate-500">
        Diinput oleh Leader. Setelah simpan, jalankan <b>Recalc AO</b>.
      </p>
    </div>
  </div>

  {{-- Filter period --}}
  <form method="GET" action="{{ route('kpi.ao.activity_inputs') }}" class="rounded-2xl border border-slate-200 bg-white p-4">
    <div class="flex flex-col md:flex-row md:items-end gap-3">
      <div class="space-y-1">
        <div class="text-sm font-medium text-slate-700">Periode</div>
        <input type="month" name="period"
               value="{{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}"
               class="rounded-xl border-slate-200 focus:border-slate-400 focus:ring-slate-200" />
      </div>
      <button class="inline-flex items-center justify-center rounded-xl bg-slate-900 text-white px-5 py-2 font-semibold">
        Tampilkan
      </button>

      <div class="flex-1"></div>

      <a href="{{ route('kpi.ao.recalc', ['period' => $periodYmd]) }}"
         class="inline-flex items-center justify-center rounded-xl bg-indigo-600 text-white px-5 py-2 font-semibold">
        Recalc AO
      </a>
    </div>
  </form>

  {{-- Save input --}}
  <form method="POST" action="{{ route('kpi.ao.activity_inputs.store') }}"
        class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    @csrf
    <input type="hidden" name="period" value="{{ $periodYmd }}"/>

    <div class="p-4 border-b border-slate-200 flex items-center justify-between">
      <div>
        <div class="text-lg font-semibold text-slate-900">Daftar AO</div>
        <div class="text-sm text-slate-500">Periode: <b>{{ \Carbon\Carbon::parse($periodYmd)->translatedFormat('F Y') }}</b></div>
      </div>
      <button class="inline-flex items-center justify-center rounded-xl bg-emerald-600 text-white px-5 py-2 font-semibold">
        Simpan Input
      </button>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-700">
          <tr>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-left px-3 py-2">AO Code</th>
            <th class="text-left px-3 py-2">Grab to Community (Actual)</th>
            <th class="text-left px-3 py-2">Daily Report/Kunjungan (Actual)</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          @foreach($users as $u)
            @php
              $inp = $inputs[$u->id] ?? null;
              $aoCode = str_pad(trim((string)($u->ao_code ?? '')), 6, '0', STR_PAD_LEFT);
              $valCom = (int)($inp->community_actual ?? 0);
              $valDay = (int)($inp->daily_report_actual ?? 0);
            @endphp

            <tr>
              <td class="px-3 py-3 font-semibold text-slate-900">
                {{ $u->name }}
                <div class="text-xs text-slate-500">AO</div>
              </td>
              <td class="px-3 py-3 text-slate-700">{{ $aoCode }}</td>

              <td class="px-3 py-3">
                <input type="number" name="inputs[{{ $u->id }}][community_actual]"
                       value="{{ $valCom }}"
                       class="w-36 rounded-xl border-slate-200 focus:border-slate-400 focus:ring-slate-200"/>
              </td>

              <td class="px-3 py-3">
                <input type="number" name="inputs[{{ $u->id }}][daily_report_actual]"
                       value="{{ $valDay }}"
                       class="w-36 rounded-xl border-slate-200 focus:border-slate-400 focus:ring-slate-200"/>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </form>

</div>
@endsection
