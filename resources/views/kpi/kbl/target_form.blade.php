@extends('layouts.app')
@section('title', 'Input Target KBL')

@section('content')
<div class="max-w-3xl mx-auto p-4 space-y-4">
  <div class="flex items-start justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">Target KPI KBL</div>
      <div class="text-3xl font-black text-slate-900">{{ $periodLabel }}</div>
      <div class="text-sm text-slate-600 mt-1">KBL: <b>{{ $me->name }}</b></div>
    </div>
    <a href="{{ route('kpi.kbl.sheet', ['period' => \Carbon\Carbon::parse($periodDate)->format('Y-m')]) }}"
       class="rounded-xl border border-slate-200 bg-white px-4 py-2 font-semibold text-slate-700 hover:bg-slate-50">
      Kembali
    </a>
  </div>

  @if(session('status'))
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
      {{ session('status') }}
    </div>
  @endif

  <form method="POST" action="{{ route('kpi.kbl.target.upsert') }}" class="rounded-2xl border border-slate-200 bg-white p-4 space-y-4">
    @csrf

    <input type="hidden" name="period" value="{{ $periodDate }}">

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div>
        <label class="text-sm font-semibold text-slate-700">Target OS (Rp)</label>
        <input name="target_os" value="{{ old('target_os', $row->target_os ?? 0) }}"
               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="contoh: 350000000000">
        @error('target_os') <div class="text-xs text-rose-600 mt-1">{{ $message }}</div> @enderror
      </div>

      <div>
        <label class="text-sm font-semibold text-slate-700">Target NPL (%)</label>
        <input name="target_npl_pct" type="number" step="0.01" value="{{ old('target_npl_pct', $row->target_npl_pct ?? 0) }}"
               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="contoh: 2.00">
        @error('target_npl_pct') <div class="text-xs text-rose-600 mt-1">{{ $message }}</div> @enderror
      </div>

      <div>
        <label class="text-sm font-semibold text-slate-700">Target Pendapatan Bunga (Rp)</label>
        <input name="target_interest_income" value="{{ old('target_interest_income', $row->target_interest_income ?? 0) }}"
               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="contoh: 1200000000">
        @error('target_interest_income') <div class="text-xs text-rose-600 mt-1">{{ $message }}</div> @enderror
      </div>

      <div>
        <label class="text-sm font-semibold text-slate-700">Target Komunitas (count)</label>
        <input name="target_community" type="number" value="{{ old('target_community', $row->target_community ?? 0) }}"
               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="contoh: 5">
        @error('target_community') <div class="text-xs text-rose-600 mt-1">{{ $message }}</div> @enderror
      </div>
    </div>

    <div>
      <label class="text-sm font-semibold text-slate-700">Catatan (opsional)</label>
      <textarea name="meta" rows="3"
                class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2"
                placeholder="Sumber target / asumsi / catatan rapat">{{ old('meta', $row->meta ?? '') }}</textarea>
    </div>

    <div class="flex items-center justify-end gap-2">
      <button class="rounded-xl bg-slate-900 text-white px-5 py-2 font-semibold">
        Simpan Target
      </button>
    </div>
  </form>
</div>
@endsection