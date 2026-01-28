@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto p-4">
  <div class="mb-4">
    <h1 class="text-2xl font-bold text-slate-900">
      {{ $mode === 'create' ? 'Buat Draft Target KPI' : 'Edit Draft Target KPI' }}
    </h1>
    <p class="text-sm text-slate-500">Isi target OS growth & NOA, lalu submit untuk approval.</p>
  </div>

  @if($errors->any())
    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800 text-sm">
      <ul class="list-disc ml-5">
        @foreach($errors->all() as $e)
          <li>{{ $e }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  @if(session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm">
      {{ session('status') }}
    </div>
  @endif

  {{-- helper card --}}
  <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <div class="text-sm font-semibold text-slate-700">Helper Portofolio (AO)</div>
        <div class="text-xs text-slate-500">Supaya input target lebih realistis.</div>
      </div>
      <div class="text-sm text-slate-700">
        <span class="font-semibold">AO Code:</span> {{ $helper['ao_code'] ?? '-' }} |
        <span class="font-semibold">Posisi:</span> {{ $helper['position_date'] ?? '-' }}
      </div>
    </div>
    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
      <div class="rounded-xl bg-slate-50 p-3">
        <div class="text-xs text-slate-500">OS saat ini</div>
        <div class="text-lg font-bold text-slate-900">
          Rp {{ number_format((float)($helper['os_current'] ?? 0), 0, ',', '.') }}
        </div>
      </div>
      <div class="rounded-xl bg-slate-50 p-3">
        <div class="text-xs text-slate-500">NOA saat ini</div>
        <div class="text-lg font-bold text-slate-900">
          {{ number_format((int)($helper['noa_current'] ?? 0)) }}
        </div>
      </div>
    </div>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <form method="POST"
      action="{{ $mode === 'create' ? route('kpi.marketing.targets.store') : route('kpi.marketing.targets.update', $target) }}">
      @csrf
      @if($mode === 'edit') @method('PUT') @endif

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="text-sm font-semibold text-slate-700">Periode</label>
          <input type="month"
            name="period_month"
            value="{{ old('period_month', \Carbon\Carbon::parse($target->period ?? now())->format('Y-m')) }}"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
            {{ $mode === 'edit' ? 'disabled' : '' }}>
          @if($mode === 'create')
            <input type="hidden" name="period"
              value="{{ old('period', \Carbon\Carbon::parse($target->period ?? now())->startOfMonth()->toDateString()) }}">
          @else
            <input type="hidden" name="period" value="{{ \Carbon\Carbon::parse($target->period)->startOfMonth()->toDateString() }}">
          @endif
          <p class="mt-1 text-xs text-slate-500">Periode otomatis disimpan sebagai tanggal 1 bulan tersebut.</p>
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700">Kode Cabang (opsional)</label>
          <input type="text" name="branch_code"
            value="{{ old('branch_code', $target->branch_code) }}"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
            placeholder="misal: 001">
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700">Target OS Growth (Rp)</label>
          <input type="number" step="0.01" name="target_os_growth"
            value="{{ old('target_os_growth', $target->target_os_growth) }}"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
            placeholder="misal: 2000000000">
          <p class="mt-1 text-xs text-slate-500">Isi angka tanpa titik/koma. Contoh: 2000000000</p>
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700">Target NOA (Debitur baru)</label>
          <input type="number" name="target_noa"
            value="{{ old('target_noa', $target->target_noa) }}"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
            placeholder="misal: 20">
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700">Bobot OS (%)</label>
          <input type="number" name="weight_os"
            value="{{ old('weight_os', $target->weight_os ?? 60) }}"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
        </div>

        <div>
          <label class="text-sm font-semibold text-slate-700">Bobot NOA (%)</label>
          <input type="number" name="weight_noa"
            value="{{ old('weight_noa', $target->weight_noa ?? 40) }}"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
          <p class="mt-1 text-xs text-slate-500">Saran: OS 60% + NOA 40% = 100%</p>
        </div>

        <div class="md:col-span-2">
          <label class="text-sm font-semibold text-slate-700">Catatan / Asumsi AO (opsional)</label>
          <textarea name="notes" rows="3"
            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
            placeholder="misal: pipeline UMKM pasar, payroll instansi X, dll">{{ old('notes', $target->notes) }}</textarea>
        </div>
      </div>

        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('kpi.marketing.targets.index') }}"
                class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Kembali
            </a>

            <div class="flex items-center gap-2">

                {{-- =========================
                    FORM UPDATE (PUT) - SIMPAN DRAFT
                    ========================= --}}
                <form method="POST"
                    action="{{ $mode === 'create'
                        ? route('kpi.marketing.targets.store')
                        : route('kpi.marketing.targets.update', $target) }}">
                    @csrf
                    @if($mode === 'edit')
                        @method('PUT')
                    @endif

                    <button type="submit"
                        class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Simpan Draft
                    </button>
                </form>

                {{-- =========================
                    FORM SUBMIT (POST) - KHUSUS SUBMIT
                    ========================= --}}
                @if($mode === 'edit')
                    <form method="POST"
                        action="{{ route('kpi.marketing.targets.submit', $target) }}">
                        @csrf

                        <button type="submit"
                            class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700"
                            onclick="return confirm('Submit target ini untuk approval? Setelah submit tidak bisa diedit.');">
                            Submit
                        </button>
                    </form>
                @endif

            </div>

        </div>
    </form>
  </div>
</div>
@endsection
@if($mode === 'create')
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const monthInput = document.querySelector('input[name="period_month"]');
    const hiddenPeriod = document.querySelector('input[name="period"]');
    if (!monthInput || !hiddenPeriod) return;

    const sync = () => {
      const v = (monthInput.value || '').trim(); // YYYY-MM
      if (!v) return;
      hiddenPeriod.value = v + '-01';
    };
    monthInput.addEventListener('change', sync);
    sync();
  });
</script>
@endif
