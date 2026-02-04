@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto p-4">
  <div class="mb-4">
    <h1 class="text-2xl font-bold text-slate-900">Review Target KPI</h1>
    <p class="text-sm text-slate-500">
      {{ $target->user?->name }} • {{ \Carbon\Carbon::parse($target->period)->format('M Y') }}
    </p>
  </div>

  @if(session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm">
      {{ session('status') }}
    </div>
  @endif

  <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <form method="POST" action="{{ route('kpi.marketing.approvals.update', $target) }}">
      @csrf
      @method('PUT')

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="text-sm font-semibold text-slate-700">Target OS Growth (Final)</label>
          <input type="number" step="0.01" name="target_os_growth"
            value="{{ old('target_os_growth', $target->target_os_growth) }}"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700">Target NOA (Final)</label>
          <input type="number" name="target_noa"
            value="{{ old('target_noa', $target->target_noa) }}"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700">Target RR (Final)</label>
          <input type="number" step="0.01" name="target_rr"
            value="{{ old('target_rr', $target->target_rr ?? 100) }}"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
          <p class="mt-1 text-xs text-slate-500">Range 0–100%</p>
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700">Target Handling Komunitas (Final)</label>
          <input type="number" name="target_activity"
            value="{{ old('target_activity', $target->target_activity ?? 0) }}"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
          <p class="mt-1 text-xs text-slate-500">Jumlah kegiatan dalam 1 bulan</p>
        </div>

        <div class="md:col-span-2">
          <label class="text-sm font-semibold text-slate-700">Catatan</label>
          <textarea name="notes" rows="3"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">{{ old('notes', $target->notes) }}</textarea>
        </div>
      </div>

      <div class="mt-5 flex items-center justify-between gap-2">
        <a href="{{ route('kpi.marketing.approvals.index') }}"
           class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
          Kembali
        </a>

        <div class="flex items-center gap-2">
          <button type="submit"
            class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Simpan Perubahan
          </button>
        </div>
      </div>
    </form>

    <div class="mt-6 flex flex-wrap items-center justify-end gap-2 border-t pt-4">
      <form method="POST" action="{{ route('kpi.marketing.approvals.approve', $target) }}">
        @csrf
        <button
          class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
          onclick="return confirm('Approve target ini? Target akan terkunci.');">
          Approve & Lock
        </button>
      </form>

      <form method="POST" action="{{ route('kpi.marketing.approvals.reject', $target) }}">
        @csrf
        <input type="text" name="reject_note" required maxlength="500"
          class="rounded-xl border border-slate-200 px-3 py-2 text-sm"
          placeholder="Alasan reject (wajib)">
        <button
          class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700"
          onclick="return confirm('Reject target ini? AO dapat submit ulang.');">
          Reject
        </button>
      </form>
    </div>
  </div>
</div>
@endsection
