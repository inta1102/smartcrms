@extends('layouts.app')

@section('title','Input Komunitas')

@section('content')
@php
  $u = auth()->user();

  $lvl = strtoupper(trim((string)($u?->roleValue() ?? '')));
  if ($lvl === '') {
      $raw = $u?->level;
      $lvl = strtoupper(trim((string)($raw instanceof \BackedEnum ? $raw->value : $raw)));
  }
  abort_unless(in_array($lvl, ['AO','SO','KBL'], true), 403);

  $input = 'w-full px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 shadow-sm
            focus:outline-none focus:ring-4 focus:ring-indigo-100 focus:border-indigo-400';
@endphp

<div class="max-w-4xl mx-auto p-4 space-y-5">
  <div class="flex items-start justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">Input Komunitas</h1>
      <p class="text-sm text-slate-500">
        Diinput oleh AO/SO/KBL. Otomatis dihitung sebagai <b>Actual Komunitas</b> untuk KPI user yang input.
      </p>
    </div>

    <a href="{{ route('kpi.communities.index') }}"
       class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold hover:bg-slate-50">
      ← Kembali
    </a>
  </div>

  <form method="POST" action="{{ route('kpi.communities.store') }}"
        class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    @csrf

    <div class="p-4 border-b border-slate-200">
      <div class="text-sm font-semibold text-slate-800">Identitas Komunitas</div>
      <div class="text-xs text-slate-500 mt-1">Isi minimal: Nama, PIC, No HP.</div>
    </div>

    <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="text-sm font-medium text-slate-700">Nama Komunitas</label>
        <input name="name" required class="{{ $input }}" placeholder="Contoh: Komunitas Pedagang Pasar X">
      </div>

      <div>
        <label class="text-sm font-medium text-slate-700">Jenis / Segmen</label>
        <input name="segment" class="{{ $input }}" placeholder="Contoh: UMKM / Paguyuban / Instansi">
      </div>

      <div>
        <label class="text-sm font-medium text-slate-700">Kota / Wilayah</label>
        <input name="city" class="{{ $input }}" placeholder="Contoh: Sleman">
      </div>

      <div>
        <label class="text-sm font-medium text-slate-700">Status</label>
        <select name="status" class="{{ $input }}">
          <option value="active" selected>Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div>
        <label class="text-sm font-medium text-slate-700">Nama PIC</label>
        <input name="pic_name" required class="{{ $input }}" placeholder="Nama penanggung jawab komunitas">
      </div>

      <div>
        <label class="text-sm font-medium text-slate-700">No HP PIC</label>
        <input name="pic_phone" required class="{{ $input }}" placeholder="08xxxxxxxxxx">
        <div class="text-xs text-slate-500 mt-1">Simpan angka saja biar konsisten (tanpa spasi/titik).</div>
      </div>

      <div class="md:col-span-2">
        <label class="text-sm font-medium text-slate-700">Alamat / Catatan</label>
        <textarea name="notes" rows="3" class="{{ $input }}" placeholder="Alamat, catatan pertemuan, dll"></textarea>
      </div>
    </div>

    <div class="p-4 border-t border-slate-200 flex justify-end gap-2">
      <button class="inline-flex items-center justify-center rounded-xl bg-emerald-600 text-white px-5 py-2 font-semibold">
        Simpan Komunitas
      </button>
    </div>
  </form>
</div>
@endsection
