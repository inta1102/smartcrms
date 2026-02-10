@extends('layouts.app')

@section('title','Target KPI SO')

@section('content')
@php
  // dari controller: periodYm, period (Carbon), users, targets(map by user_id)
  $periodYm = $periodYm ?? request('period', now()->format('Y-m'));
@endphp

<div class="max-w-6xl mx-auto p-4">
  <div class="mb-4">
    <h1 class="text-2xl font-bold text-slate-900">Target KPI SO</h1>
    <p class="text-sm text-slate-500">Diinput oleh KBL. Setelah simpan, jalankan Recalc SO.</p>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white p-4 mb-5">
    <form method="GET" class="flex flex-col md:flex-row md:items-center gap-3">
      <div class="flex items-center gap-3">
        <div class="text-sm font-semibold text-slate-700 w-20">Periode</div>
        <input type="month"
               name="period"
               value="{{ $periodYm }}"
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm" />
      </div>

      <button class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800 w-fit">
        Tampilkan
      </button>
    </form>
  </div>

  {{-- ✅ STORE BULK --}}
  <form method="POST" action="{{ route('kpi.so.targets.store') }}">
    @csrf

    {{-- ✅ period harus Y-m (sesuai validate controller) --}}
    <input type="hidden" name="period" value="{{ $periodYm }}">

    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
      <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50">
            <tr class="text-slate-700">
              <th class="text-left px-3 py-2">SO</th>
              <th class="text-left px-3 py-2">AO Code</th>

              <th class="text-right px-3 py-2">Target OS</th>
              <th class="text-right px-3 py-2">Target NOA</th>
              <th class="text-right px-3 py-2">Target RR (%)</th>
              <th class="text-right px-3 py-2">Target Handling</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-200">
            @forelse($users as $u)
              @php
                $t = $targets->get($u->id); // bisa null kalau belum ada target period tsb

                $targetOs  = (int)($t->target_os_disbursement ?? 0);
                $targetNoa = (int)($t->target_noa_disbursement ?? 0);
                $targetRr  = (float)($t->target_rr ?? 100);
                $targetAct = (int)($t->target_activity ?? 0);
              @endphp

              <tr>
                <td class="px-3 py-3">
                  <div class="font-semibold text-slate-900">{{ $u->name }}</div>
                  <div class="text-xs text-slate-500">{{ $u->level }}</div>
                </td>

                <td class="px-3 py-3 font-mono">{{ $u->ao_code }}</td>

                <td class="px-3 py-3 text-right">
                  <input type="number" min="0"
                         name="targets[{{ $u->id }}][target_os_disbursement]"
                         value="{{ old("targets.$u->id.target_os_disbursement", $targetOs) }}"
                         class="w-44 rounded-xl border border-slate-300 px-3 py-2 text-sm text-right" />
                  <div class="text-xs text-slate-500 mt-1">Rupiah</div>
                </td>

                <td class="px-3 py-3 text-right">
                  <input type="number" min="0"
                         name="targets[{{ $u->id }}][target_noa_disbursement]"
                         value="{{ old("targets.$u->id.target_noa_disbursement", $targetNoa) }}"
                         class="w-28 rounded-xl border border-slate-300 px-3 py-2 text-sm text-right" />
                </td>

                <td class="px-3 py-3 text-right">
                  <input type="number" min="0" max="100" step="0.01"
                         name="targets[{{ $u->id }}][target_rr]"
                         value="{{ old("targets.$u->id.target_rr", number_format($targetRr, 2, '.', '')) }}"
                         class="w-28 rounded-xl border border-slate-300 px-3 py-2 text-sm text-right" />
                  <div class="text-xs text-slate-500 mt-1">0–100</div>
                </td>

                <td class="px-3 py-3 text-right">
                  <input type="number" min="0"
                         name="targets[{{ $u->id }}][target_activity]"
                         value="{{ old("targets.$u->id.target_activity", $targetAct) }}"
                         class="w-28 rounded-xl border border-slate-300 px-3 py-2 text-sm text-right" />
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="6" class="px-3 py-6 text-center text-slate-500">
                  Tidak ada user SO.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div class="p-4 flex justify-end">
        <button class="rounded-xl bg-emerald-600 px-5 py-3 text-white text-sm font-semibold hover:bg-emerald-700">
          Simpan Target
        </button>
      </div>
    </div>
  </form>
</div>
@endsection
