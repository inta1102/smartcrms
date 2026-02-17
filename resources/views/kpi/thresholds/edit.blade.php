@extends('layouts.app')

@section('title', 'Edit KPI Threshold')

@section('content')
<div class="max-w-3xl mx-auto p-4 space-y-4">

  <div>
    <div class="text-2xl font-extrabold text-slate-900">Edit Threshold</div>
    <div class="text-sm text-slate-500">Metric: <b class="font-mono">{{ $t->metric }}</b></div>
  </div>

  <form method="POST" action="{{ route('kpi.thresholds.update', $t) }}" class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
    @csrf
    @method('PUT')

    <div>
      <label class="text-sm text-slate-600">Title</label>
      <input name="title" value="{{ old('title', $t->title) }}" class="mt-1 w-full border rounded-xl px-3 py-2">
      @error('title') <div class="text-xs text-rose-600 mt-1">{{ $message }}</div> @enderror
    </div>

    <div>
      <label class="text-sm text-slate-600">Direction</label>
      <select name="direction" class="mt-1 w-full border rounded-xl px-3 py-2">
        <option value="higher_is_better" @selected(old('direction',$t->direction)==='higher_is_better')>higher_is_better</option>
        <option value="lower_is_better" @selected(old('direction',$t->direction)==='lower_is_better')>lower_is_better</option>
      </select>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="text-sm text-slate-600">Green Min</label>
        <input name="green_min" value="{{ old('green_min', $t->green_min) }}" class="mt-1 w-full border rounded-xl px-3 py-2">
        <div class="text-xs text-slate-500 mt-1">RR: nilai ≥ ini = AMAN</div>
      </div>

      <div>
        <label class="text-sm text-slate-600">Yellow Min</label>
        <input name="yellow_min" value="{{ old('yellow_min', $t->yellow_min) }}" class="mt-1 w-full border rounded-xl px-3 py-2">
        <div class="text-xs text-slate-500 mt-1">RR: nilai ≥ ini = WASPADA</div>
      </div>
    </div>

    <label class="inline-flex items-center gap-2">
      <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $t->is_active))>
      <span class="text-sm text-slate-700">Active</span>
    </label>

    <div class="flex items-center justify-between">
      <a href="{{ route('kpi.thresholds.index') }}" class="px-4 py-2 rounded-xl border hover:bg-slate-50">Batal</a>
      <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:bg-slate-800">Simpan</button>
    </div>
  </form>

</div>
@endsection
