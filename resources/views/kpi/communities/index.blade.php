@extends('layouts.app')

@section('title', 'Database Komunitas')

@section('content')
<div class="w-full max-w-6xl space-y-5">

  <div class="flex items-start justify-between gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">Komunitas – Database</h1>
      <div class="text-sm text-slate-500">
        Data komunitas yang di-handle MSA. Input oleh AO/SO/KBL, dipakai untuk actual KPI otomatis.
      </div>
    </div>
    @php
        $u = auth()->user();

        // role string aman (enum/string)
        $lvl = strtoupper(trim((string)($u?->roleValue() ?? '')));
        if ($lvl === '') {
            $raw = $u?->level;
            $lvl = strtoupper(trim((string)($raw instanceof \BackedEnum ? $raw->value : $raw)));
        }

        $canCreate = in_array($lvl, ['AO','SO','TLSO','TLUM','KBL'], true);
    @endphp

    @if($canCreate)
      <a href="{{ route('kpi.communities.create') }}"
         class="inline-flex items-center justify-center rounded-xl bg-slate-900 text-white px-5 py-2 font-semibold">
        + Tambah Komunitas
      </a>
    @endif
  </div>

  <form method="GET" action="{{ route('kpi.communities.index') }}" class="rounded-2xl border border-slate-200 bg-white p-4">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
      <div>
        <div class="text-xs text-slate-500 mb-1">Cari</div>
        <input name="q" value="{{ $q }}"
               placeholder="Nama / PIC / No HP / Kode"
               class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:outline-none focus:ring-4 focus:ring-indigo-100 focus:border-indigo-400" />
      </div>

      <div>
        <div class="text-xs text-slate-500 mb-1">Kota</div>
        <input name="city" value="{{ $city }}"
               placeholder="Jogja / Sleman / Bantul..."
               class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:outline-none focus:ring-4 focus:ring-indigo-100 focus:border-indigo-400" />
      </div>

      <div>
        <div class="text-xs text-slate-500 mb-1">Status</div>
        <select name="status" class="w-full px-3 py-2 rounded-xl border border-slate-200 bg-white">
          <option value="active"   @selected($status==='active')>Active</option>
          <option value="inactive" @selected($status==='inactive')>Inactive</option>
          <option value=""         @selected($status==='')>Semua</option>
        </select>
      </div>

      <div class="flex items-end gap-2">
        <button class="rounded-xl bg-indigo-600 text-white px-5 py-2 font-semibold">Tampilkan</button>
        <a href="{{ route('kpi.communities.index') }}" class="rounded-xl border border-slate-200 px-5 py-2 font-semibold">
          Reset
        </a>
      </div>
    </div>
  </form>

  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-700">
          <tr>
            <th class="text-left px-3 py-2">Komunitas</th>
            <th class="text-left px-3 py-2">PIC</th>
            <th class="text-left px-3 py-2">Lokasi</th>
            <th class="text-center px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          @forelse($rows as $r)
            <tr class="hover:bg-slate-50">
              <td class="px-3 py-3">
                <a class="font-semibold text-slate-900 hover:underline"
                   href="{{ route('kpi.communities.show', $r->id) }}">
                   {{ $r->name }}
                </a>
                <div class="text-xs text-slate-500">
                  {{ $r->type ? $r->type : '-' }} • {{ $r->segment ? $r->segment : '-' }} • {{ $r->code ? 'Kode: '.$r->code : '' }}
                </div>
              </td>
              <td class="px-3 py-3">
                <div class="text-slate-900">{{ $r->pic_name ?: '-' }}</div>
                <div class="text-xs text-slate-500">{{ $r->pic_phone ?: '-' }}</div>
              </td>
              <td class="px-3 py-3 text-slate-700">
                {{ trim(($r->district ?: '').' '.($r->city ?: '')) ?: '-' }}
              </td>
              <td class="px-3 py-3 text-center">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px]
                  {{ $r->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                  {{ strtoupper($r->status) }}
                </span>
              </td>
            </tr>
          @empty
            <tr><td colspan="4" class="px-3 py-8 text-center text-slate-500">Belum ada data komunitas.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4 border-t border-slate-200">
      {{ $rows->links() }}
    </div>
  </div>

</div>
@endsection
