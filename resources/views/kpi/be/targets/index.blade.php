@extends('layouts.app')

@section('title', 'Target KPI BE')

@section('content')
@php
  $periodYm = $periodYm ?? now()->format('Y-m');
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-6">

  <div>
    <div class="text-3xl font-extrabold text-slate-900">Target KPI BE</div>
    <div class="text-sm text-slate-500 mt-1">
      Diinput oleh Leader. Setelah simpan, jalankan <b>Recalc BE</b>.
    </div>
  </div>

  @if(session('status'))
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
      {{ session('status') }}
    </div>
  @endif

  {{-- Filter --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-4">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <form method="GET" class="flex flex-wrap items-end gap-3">
        <div>
          <div class="text-sm font-semibold text-slate-700 mb-1">Periode</div>
          <input type="month" name="period" value="{{ $periodYm }}"
                 class="rounded-xl border border-slate-300 px-3 py-2 text-sm w-56">
        </div>

        <button class="rounded-xl bg-slate-900 px-5 py-2 text-white text-sm font-semibold hover:bg-slate-800">
          Tampilkan
        </button>
      </form>

      <form method="POST" action="{{ route('kpi.recalc.be') }}"
            onsubmit="return confirm('Recalc KPI BE untuk periode ini?')">
        @csrf
        <input type="hidden" name="period" value="{{ $periodYm }}">
        <button class="rounded-xl bg-indigo-600 px-5 py-2 text-white text-sm font-semibold hover:bg-indigo-700">
          Recalc BE
        </button>
      </form>
    </div>
  </div>

  {{-- Table --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
      <div>
        <div class="text-lg font-extrabold text-slate-900">Daftar BE</div>
        <div class="text-sm text-slate-500">Periode: <b>{{ $period->translatedFormat('F Y') }}</b></div>
      </div>

      <button form="form-targets"
              class="rounded-xl bg-emerald-600 px-5 py-2 text-white text-sm font-semibold hover:bg-emerald-700">
        Simpan Target
      </button>
    </div>

    <form id="form-targets" method="POST" action="{{ route('kpi.be.targets.store') }}">
      @csrf
      <input type="hidden" name="period" value="{{ $periodYm }}">

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="text-left px-4 py-3 font-bold">BE</th>
              <th class="text-left px-4 py-3 font-bold">AO Code</th>
              <th class="text-left px-4 py-3 font-bold">Target OS Selesai (Rp)</th>
              <th class="text-left px-4 py-3 font-bold">Target NOA Selesai</th>
              <th class="text-left px-4 py-3 font-bold">Target Bunga Masuk (Rp)</th>
              <th class="text-left px-4 py-3 font-bold">Target Denda Masuk (Rp)</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-100 bg-white">
          @forelse($beUsers as $idx => $u)
            @php $t = $targets->get((int)$u->id); @endphp

            <tr>
              <td class="px-4 py-4">
                <div class="font-semibold text-slate-900">{{ $u->name }}</div>
                <div class="text-xs text-slate-500">{{ strtoupper(trim((string)$u->level)) }}</div>
                <input type="hidden" name="targets[{{ $idx }}][be_user_id]" value="{{ (int)$u->id }}">
              </td>

              <td class="px-4 py-4 font-mono text-slate-700">{{ $u->ao_code }}</td>

              <td class="px-4 py-4">
                <input type="number" step="0.01" min="0"
                       name="targets[{{ $idx }}][target_os_selesai]"
                       value="{{ old("targets.$idx.target_os_selesai", (float)($t->target_os_selesai ?? 0)) }}"
                       class="w-56 rounded-xl border border-slate-300 px-3 py-2 text-sm text-right">
                <div class="text-xs text-slate-500 mt-1">Rupiah</div>
              </td>

              <td class="px-4 py-4">
                <input type="number" step="1" min="0"
                       name="targets[{{ $idx }}][target_noa_selesai]"
                       value="{{ old("targets.$idx.target_noa_selesai", (int)($t->target_noa_selesai ?? 0)) }}"
                       class="w-40 rounded-xl border border-slate-300 px-3 py-2 text-sm text-right">
                <div class="text-xs text-slate-500 mt-1">Rekening</div>
              </td>

              <td class="px-4 py-4">
                <input type="number" step="0.01" min="0"
                       name="targets[{{ $idx }}][target_bunga_masuk]"
                       value="{{ old("targets.$idx.target_bunga_masuk", (float)($t->target_bunga_masuk ?? 0)) }}"
                       class="w-56 rounded-xl border border-slate-300 px-3 py-2 text-sm text-right">
                <div class="text-xs text-slate-500 mt-1">Rupiah</div>
              </td>

              <td class="px-4 py-4">
                <input type="number" step="0.01" min="0"
                       name="targets[{{ $idx }}][target_denda_masuk]"
                       value="{{ old("targets.$idx.target_denda_masuk", (float)($t->target_denda_masuk ?? 0)) }}"
                       class="w-56 rounded-xl border border-slate-300 px-3 py-2 text-sm text-right">
                <div class="text-xs text-slate-500 mt-1">Rupiah</div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="6" class="px-4 py-6 text-slate-600">
                Tidak ada user BE (level=BE) yang punya AO Code.
              </td>
            </tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </form>
  </div>

</div>
@endsection
