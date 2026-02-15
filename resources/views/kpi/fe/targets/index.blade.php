@extends('layouts.app')

@section('title', 'Target KPI FE')

@section('content')
@php
  $fmtRpInput = fn($n) => (string)(int)round((float)($n ?? 0));
@endphp

<div class="w-full max-w-6xl mx-auto space-y-6 p-4">

  <div>
    <h1 class="text-3xl font-extrabold text-slate-900">Target KPI FE</h1>
    <p class="text-slate-600 mt-1">
      Diinput oleh Leader. Setelah simpan, jalankan <b>Recalc FE</b>.
    </p>
  </div>

  {{-- Filter Periode --}}
  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-4">
    <form method="GET" action="{{ route('kpi.fe.targets.index') }}" class="flex flex-col md:flex-row md:items-end gap-3">
      <div class="w-full md:w-64">
        <label class="text-sm font-semibold text-slate-700">Periode</label>
        <input type="month"
               name="periodYm"
               value="{{ $periodYm ?? now()->format('Y-m') }}"
               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-300">
      </div>

      <button type="submit"
              class="inline-flex items-center justify-center rounded-xl bg-slate-900 text-white px-5 py-2 font-semibold hover:bg-slate-800">
        Tampilkan
      </button>

      <div class="flex-1"></div>

      {{-- tombol recalc FE (opsional) --}}
      <form method="POST" action="{{ route('kpi.recalc.fe') }}">
        @csrf
        <input type="hidden" name="periodYm" value="{{ $periodYm ?? now()->format('Y-m') }}">
        <button type="submit"
                class="inline-flex items-center justify-center rounded-xl bg-indigo-600 text-white px-5 py-2 font-semibold hover:bg-indigo-700">
          Recalc FE
        </button>
      </form>
    </form>
  </div>

  {{-- Alerts --}}
  @if(session('success'))
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3">
      {{ session('success') }}
    </div>
  @endif
  @if(session('error'))
    <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3">
      {{ session('error') }}
    </div>
  @endif

  {{-- Form Save --}}
  <form method="POST" action="{{ route('kpi.fe.targets.store') }}" class="space-y-4">
    @csrf
    <input type="hidden" name="periodYm" value="{{ $periodYm ?? now()->format('Y-m') }}">

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="p-4 border-b border-slate-200 flex items-center justify-between">
        <div>
          <div class="font-bold text-slate-900">Daftar FE</div>
          <div class="text-xs text-slate-500 mt-1">
            Periode: <b>{{ $period?->translatedFormat('F Y') ?? ($periodYm ?? '-') }}</b>
          </div>
        </div>

        <button type="submit"
                class="inline-flex items-center justify-center rounded-xl bg-emerald-600 text-white px-5 py-2 font-semibold hover:bg-emerald-700">
          Simpan Target
        </button>
      </div>

      <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50">
            <tr class="text-slate-700">
              <th class="text-left px-3 py-2">FE</th>
              <th class="text-left px-3 py-2">AO Code</th>
              <th class="text-right px-3 py-2">Target Nett Turun Kol 2 (Rp)</th>
              <th class="text-right px-3 py-2">Target Migrasi NPL (%)</th>
              <th class="text-right px-3 py-2">Target Denda Masuk (Rp)</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-200">
            @forelse($feUsers as $u)
              @php
                $t = $targetMap->get($u->id);
                $vOs  = $t->target_os_turun_kol2 ?? 0;
                $vMg  = $t->target_migrasi_npl_pct ?? 0.30;
                $vPen = $t->target_penalty_paid ?? 0;
              @endphp

              <tr>
                <td class="px-3 py-3">
                  <div class="font-semibold text-slate-900">{{ $u->name }}</div>
                  <div class="text-xs text-slate-500">FE</div>
                </td>

                <td class="px-3 py-3 font-mono text-slate-800">{{ $u->ao_code }}</td>

                <td class="px-3 py-3 text-right">
                  <input type="number" min="0" step="1"
                         name="targets[{{ (int)$u->id }}][target_os_turun_kol2]"
                         value="{{ $fmtRpInput($vOs) }}"
                         class="w-56 text-right rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-300"
                         placeholder="0">
                  <div class="text-xs text-slate-500 mt-1">Rupiah</div>
                </td>

                <td class="px-3 py-3 text-right">
                  <input type="number" min="0" max="100" step="0.01"
                         name="targets[{{ (int)$u->id }}][target_migrasi_npl_pct]"
                         value="{{ number_format((float)$vMg, 2, '.', '') }}"
                         class="w-40 text-right rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-300"
                         placeholder="0.30">
                  <div class="text-xs text-slate-500 mt-1">contoh: 0.30</div>
                </td>

                <td class="px-3 py-3 text-right">
                  <input type="number" min="0" step="1"
                         name="targets[{{ (int)$u->id }}][target_penalty_paid]"
                         value="{{ $fmtRpInput($vPen) }}"
                         class="w-56 text-right rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-300"
                         placeholder="0">
                  <div class="text-xs text-slate-500 mt-1">Rupiah</div>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="5" class="px-3 py-6 text-center text-slate-600">
                  Tidak ada user FE ditemukan.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div class="p-4 border-t border-slate-200 flex items-center justify-end">
        <button type="submit"
                class="inline-flex items-center justify-center rounded-xl bg-emerald-600 text-white px-5 py-2 font-semibold hover:bg-emerald-700">
          Simpan Target
        </button>
      </div>
    </div>
  </form>

</div>
@endsection
