@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">
  <div class="mb-4">
    <h1 class="text-2xl font-bold text-slate-900">Realisasi Handling Komunitas (SO)</h1>
    <p class="text-sm text-slate-500">Diinput oleh KBL. Setelah simpan, jalankan Recalc SO agar skor/pct ter-update.</p>
  </div>

  <form method="GET" class="bg-white rounded-2xl shadow p-4 mb-6">
    <div class="flex flex-wrap items-center gap-3">
      <div class="font-semibold text-slate-700">Periode</div>
      <input type="month"
             name="period"
             value="{{ \Carbon\Carbon::parse($period)->format('Y-m') }}"
             class="border rounded-xl px-3 py-2">
      <button class="px-5 py-2 rounded-xl bg-slate-900 text-white font-semibold">Tampilkan</button>
    </div>
  </form>

  @if (session('status'))
    <div class="mb-4 rounded-xl bg-green-50 border border-green-200 p-3 text-green-800">
      {{ session('status') }}
    </div>
  @endif

  <form method="POST" action="{{ route('kpi.so.community_input.store') }}" class="bg-white rounded-2xl shadow p-4">
    @csrf
    <input type="hidden" name="period" value="{{ $period }}">

    <div class="overflow-x-auto">
      <table class="w-full text-left">
        <thead class="text-slate-600 border-b">
          <tr>
            <th class="py-3 pr-4">SO</th>
            <th class="py-3 pr-4">AO Code</th>
            <th class="py-3 pr-4">Target Handling</th>
            <th class="py-3 pr-4">Realisasi Handling</th>
            <th class="py-3 pr-4">Adjustment OS (Titipan)</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($users as $i => $u)
            @php
              $row = $inputs->get($u->id);
              $handling = (int)($row->handling_actual ?? 0);
              $osAdj = (int)($row->os_adjustment ?? 0);
            @endphp
            <tr class="border-b">
              <td class="py-4 pr-4">
                <div class="font-semibold text-slate-900">{{ $u->name }}</div>
                <div class="text-xs text-slate-500">{{ $u->level }}</div>
              </td>
              <td class="py-4 pr-4">{{ $u->ao_code }}</td>
              <td class="py-4 pr-4">
                {{-- kalau target handling ada dari tabel target KPI, nanti kita sambungkan --}}
                2
              </td>
              <td class="py-4 pr-4">
                <input type="hidden" name="items[{{ $i }}][user_id]" value="{{ $u->id }}">
                <input type="number"
                       min="0"
                       name="items[{{ $i }}][handling_actual]"
                       value="{{ $handling }}"
                       class="w-40 border rounded-xl px-3 py-2">
              </td>
              <td class="py-4 pr-4">
                <input type="number"
                       min="0"
                       name="items[{{ $i }}][os_adjustment]"
                       value="{{ $osAdj }}"
                       class="w-60 border rounded-xl px-3 py-2"
                       placeholder="contoh: 3000000000">
                <div class="text-xs text-slate-500 mt-1">Mengurangi Actual OS SO saat scoring.</div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <div class="mt-6 flex justify-end">
      <button class="px-6 py-3 rounded-xl bg-emerald-600 text-white font-semibold">
        Simpan Realisasi
      </button>
    </div>
  </form>
</div>
@endsection
