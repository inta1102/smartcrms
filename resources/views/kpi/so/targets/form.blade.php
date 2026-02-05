@extends('layouts.app')

@section('title', ($mode==='edit'?'Edit':'Buat').' Target KPI SO')

@section('content')
@php
  $isEdit = $mode === 'edit';
@endphp

<div class="max-w-4xl mx-auto p-4">

  <div class="mb-4">
    <h1 class="text-2xl font-bold text-slate-900">
      {{ $isEdit ? 'Edit Draft Target KPI SO' : 'Buat Draft Target KPI SO' }}
    </h1>
    <p class="text-sm text-slate-500">Isi target OS/NOA/RR/Handling, lalu submit untuk approval.</p>
  </div>

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

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
    <form method="POST"
          action="{{ $isEdit ? route('kpi.so.targets.update', $target) : route('kpi.so.targets.store') }}">
      @csrf
      @if($isEdit) @method('PUT') @endif

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Periode</label>
          <input type="month"
                 name="period"
                 value="{{ old('period', \Carbon\Carbon::parse($target->period)->format('Y-m')) }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                 {{ $isEdit ? 'disabled' : '' }}>
          @if($isEdit)
            <p class="text-xs text-slate-500 mt-1">Periode tidak bisa diubah saat edit.</p>
          @endif
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Target RR (%)</label>
          <input type="number" step="0.01" min="0" max="100"
                 name="target_rr"
                 value="{{ old('target_rr', $target->target_rr ?? 100) }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
          <p class="text-xs text-slate-500 mt-1">Default 100%. Bisa 95–100 sesuai kebijakan.</p>
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Target Pencairan (OS)</label>
          <input type="number" min="0"
                 name="target_os_disbursement"
                 value="{{ old('target_os_disbursement', $target->target_os_disbursement ?? 0) }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Target NOA</label>
          <input type="number" min="0"
                 name="target_noa_disbursement"
                 value="{{ old('target_noa_disbursement', $target->target_noa_disbursement ?? 0) }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-semibold text-slate-700 mb-1">Target Handling Komunitas (kegiatan)</label>
          <input type="number" min="0"
                 name="target_activity"
                 value="{{ old('target_activity', $target->target_activity ?? 0) }}"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>

      </div>

      <div class="mt-5 flex items-center gap-2">
        <button class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
          Simpan Draft
        </button>

        <a href="{{ route('kpi.so.targets.index') }}"
           class="rounded-xl border border-slate-300 px-4 py-2 text-slate-700 text-sm font-semibold hover:bg-slate-50">
          Kembali
        </a>
      </div>

    </form>
  </div>

</div>
@endsection
