@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto p-4">
  <h1 class="text-2xl font-bold text-slate-900 mb-1">Review Target KPI SO</h1>
  <p class="text-sm text-slate-500 mb-4">
    {{ $target->user?->name }} • Periode {{ \Carbon\Carbon::parse($target->period)->format('M Y') }}
  </p>

  @if(session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm">
      {{ session('status') }}
    </div>
  @endif

  @if($errors->any())
    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800 text-sm">
      <ul class="list-disc pl-5">
        @foreach($errors->all() as $e)
          <li>{{ $e }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">

    {{-- =========================
         UPDATE (Adjust Angka)
         ========================= --}}
    <form method="POST" action="{{ route('kpi.so.approvals.update', $target) }}" class="space-y-4">
      @csrf @method('PUT')

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="text-sm font-semibold text-slate-700">Target OS Realisasi (Rp)</label>
          <input type="number" min="0"
                 name="target_os_disbursement"
                 value="{{ old('target_os_disbursement', (int)$target->target_os_disbursement) }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2">
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700">Target NOA</label>
          <input type="number" min="0"
                 name="target_noa_disbursement"
                 value="{{ old('target_noa_disbursement', (int)$target->target_noa_disbursement) }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2">
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700">Target RR (%)</label>
          <input type="number" step="0.01" min="0" max="100"
                 name="target_rr"
                 value="{{ old('target_rr', number_format((float)$target->target_rr, 2, '.', '')) }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2">
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700">Target Handling Komunitas</label>
          <input type="number" min="0"
                 name="target_activity"
                 value="{{ old('target_activity', (int)$target->target_activity) }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2">
        </div>
      </div>

      {{-- ✅ NOTE: controller mewajibkan review_note kalau ada perubahan angka --}}
      <div>
        <label class="text-sm font-semibold text-slate-700">Catatan Review (wajib jika ada perubahan)</label>
        <textarea name="review_note" rows="3"
                  class="w-full rounded-xl border border-slate-300 px-3 py-2"
                  placeholder="Contoh: OS disesuaikan karena realisasi Q1, NOA diturunkan karena kapasitas tim...">{{ old('review_note', $target->review_note ?? '') }}</textarea>
        <p class="text-xs text-slate-500 mt-1">
          Jika angka diubah, catatan ini wajib (untuk audit & transparansi ke SO).
        </p>
      </div>

      <button class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
        Simpan Perubahan
      </button>
    </form>

    <hr class="my-6">

    {{-- =========================
         ACTIONS (Approve / Reject)
         ========================= --}}
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">

      {{-- Approve (boleh membawa review_note opsional) --}}
      <form method="POST" action="{{ route('kpi.so.approvals.approve', $target) }}"
            onsubmit="return confirm('Approve target ini?')"
            class="flex items-center gap-2">
        @csrf
        <input type="hidden" name="review_note" value="{{ old('review_note', $target->review_note ?? '') }}">
        <button class="rounded-xl bg-emerald-600 px-4 py-2 text-white text-sm font-semibold hover:bg-emerald-700">
          Approve
        </button>
      </form>

      {{-- Reject: WAJIB rejected_note --}}
      <form method="POST" action="{{ route('kpi.so.approvals.reject', $target) }}"
            onsubmit="return confirm('Reject target ini?')"
            class="flex-1">
        @csrf
        <div class="flex flex-col md:flex-row md:items-center gap-2">
          <input type="text"
                 name="rejected_note"
                 value="{{ old('rejected_note') }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2"
                 placeholder="Alasan penolakan (wajib), contoh: target tidak sesuai arah divisi...">
          <button class="rounded-xl bg-rose-600 px-4 py-2 text-white text-sm font-semibold hover:bg-rose-700">
            Reject
          </button>
        </div>
        <p class="text-xs text-slate-500 mt-1">Wajib isi alasan penolakan.</p>
      </form>

      <a href="{{ route('kpi.so.approvals.index') }}"
         class="inline-flex justify-center rounded-xl border border-slate-300 px-4 py-2 text-slate-700 text-sm font-semibold hover:bg-slate-50">
        Kembali
      </a>
    </div>

  </div>
</div>
@endsection
