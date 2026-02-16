@extends('layouts.app')

@section('title', 'Detail Komunitas')

@section('content')
@php
  $u = auth()->user();

  // ✅ enum/string safe
  $lvl = strtoupper(trim((string)($u?->roleValue() ?? '')));
  if ($lvl === '') {
      $raw = $u?->level;
      $lvl = strtoupper(trim((string)($raw instanceof \BackedEnum ? $raw->value : $raw)));
  }

  $isKbl = $lvl === 'KBL';
  $isAo  = $lvl === 'AO';
  $isSo  = $lvl === 'SO';
  $isTlSo  = $lvl === 'TLSO';
  $isTlUm  = $lvl === 'TLUM';
@endphp


<div class="w-full max-w-6xl space-y-5">

  <div class="flex items-start justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">
        <a class="hover:underline" href="{{ route('kpi.communities.index') }}">Komunitas</a> / Detail
      </div>
      <h1 class="text-2xl font-extrabold text-slate-900">{{ $c->name }}</h1>
      <div class="text-sm text-slate-500">
        {{ $c->type ?: '-' }} • {{ $c->segment ?: '-' }} • {{ $c->status }}
      </div>
    </div>

    @if($isKbl)
      <a href="{{ route('kpi.communities.edit', $c->id) }}"
         class="rounded-xl border border-slate-200 px-5 py-2 font-semibold">
        Edit Master
      </a>
    @endif
  </div>

  {{-- Master card --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-4">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <div class="text-xs text-slate-500">PIC</div>
        <div class="font-semibold text-slate-900">{{ $c->pic_name ?: '-' }}</div>
        <div class="text-sm text-slate-600">{{ $c->pic_phone ?: '-' }}</div>
        <div class="text-sm text-slate-600">{{ $c->pic_position ?: '-' }}</div>
      </div>
      <div>
        <div class="text-xs text-slate-500">Lokasi</div>
        <div class="text-slate-900">{{ $c->address ?: '-' }}</div>
        <div class="text-sm text-slate-600">{{ $c->village ?: '-' }}, {{ $c->district ?: '-' }}, {{ $c->city ?: '-' }}</div>
      </div>
      <div>
        <div class="text-xs text-slate-500">Catatan</div>
        <div class="text-slate-900 whitespace-pre-wrap">{{ $c->notes ?: '-' }}</div>
      </div>
    </div>
  </div>

  {{-- Assign Handling --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200 flex items-center justify-between">
      <div>
        <div class="text-lg font-bold text-slate-900">Handling Komunitas</div>
        <div class="text-sm text-slate-500">
          Record handling ini yang akan dihitung otomatis menjadi <b>Actual komunitas</b> KPI AO/SO per periode.
        </div>
      </div>
    </div>

    <div class="p-4 border-b border-slate-200">
      <form method="POST" action="{{ route('kpi.communities.handlings.store', $c->id) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
        @csrf

        {{-- Role + User selector khusus KBL --}}
        @if($isKbl)
          <div class="md:col-span-2">
            <div class="text-xs text-slate-500 mb-1">Role</div>
            <select name="role" class="w-full px-3 py-2 rounded-xl border border-slate-200 bg-white">
              <option value="AO">AO</option>
              <option value="SO">SO</option>
            </select>
          </div>

          <div class="md:col-span-4">
            <div class="text-xs text-slate-500 mb-1">User</div>
            <select name="user_id" class="w-full px-3 py-2 rounded-xl border border-slate-200 bg-white">
              @foreach($usersAoSo as $u)
                  @php
                    $lv = strtoupper(trim((string)($u->roleValue() ?? '')));
                    if ($lv === '') {
                        $raw = $u->level;
                        $lv = strtoupper(trim((string)($raw instanceof \BackedEnum ? $raw->value : $raw)));
                    }
                    @endphp
                    <option value="{{ $u->id }}">
                    {{ $lv }} - {{ $u->name }} ({{ str_pad((string)($u->ao_code ?? ''), 6, '0', STR_PAD_LEFT) }})
                    </option>
              @endforeach
            </select>
          </div>
        @else
          {{-- AO/SO otomatis dirinya, jadi tidak usah field user --}}
          <input type="hidden" name="role" value="{{ $isSo ? 'SO' : 'AO' }}">
        @endif

        <div class="{{ $isKbl ? 'md:col-span-3' : 'md:col-span-4' }}">
          <div class="text-xs text-slate-500 mb-1">Mulai (periode)</div>
          <input type="month" name="period_from"
                 value="{{ now()->format('Y-m') }}"
                 class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:outline-none focus:ring-4 focus:ring-indigo-100 focus:border-indigo-400" />
        </div>

        <div class="{{ $isKbl ? 'md:col-span-3' : 'md:col-span-4' }}">
          <div class="text-xs text-slate-500 mb-1">Selesai (opsional)</div>
          <input type="month" name="period_to"
                 class="w-full px-3 py-2 rounded-xl border border-slate-200 focus:outline-none focus:ring-4 focus:ring-indigo-100 focus:border-indigo-400" />
        </div>

        <div class="{{ $isKbl ? 'md:col-span-12' : 'md:col-span-4' }}">
          <button class="rounded-xl bg-emerald-600 text-white px-5 py-2 font-semibold">
            Simpan Handling
          </button>
        </div>
      </form>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-700">
          <tr>
            <th class="text-left px-3 py-2">Role</th>
            <th class="text-left px-3 py-2">User</th>
            <th class="text-left px-3 py-2">Periode</th>
            <th class="text-center px-3 py-2">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          @forelse($handlings as $h)
            @php
              $canEnd = $isKbl || ((int)$h->user_id === (int)auth()->id());
            @endphp
            <tr class="hover:bg-slate-50">
              <td class="px-3 py-3 font-semibold">{{ $h->role }}</td>
              <td class="px-3 py-3">
                <div class="font-semibold text-slate-900">{{ $h->user_name ?: ('#'.$h->user_id) }}</div>
                <div class="text-xs text-slate-500">
                  {{ $h->user_level ?: '-' }} • AO Code: {{ $h->user_ao_code ? str_pad((string)$h->user_ao_code,6,'0',STR_PAD_LEFT) : '-' }}
                </div>
              </td>
              <td class="px-3 py-3 text-slate-700">
                {{ \Carbon\Carbon::parse($h->period_from)->translatedFormat('F Y') }}
                @if($h->period_to)
                  → {{ \Carbon\Carbon::parse($h->period_to)->translatedFormat('F Y') }}
                @else
                  → <span class="text-emerald-700 font-semibold">Aktif</span>
                @endif
              </td>
              <td class="px-3 py-3 text-center">
                @if($canEnd)
                  <form method="POST" action="{{ route('kpi.communities.handlings.end', $h->id) }}" class="inline-flex items-center gap-2">
                    @csrf
                    <input type="month" name="period_to"
                           class="px-3 py-2 rounded-xl border border-slate-200"
                           required />
                    <button class="rounded-xl bg-slate-900 text-white px-4 py-2 font-semibold">
                      End
                    </button>
                  </form>
                @else
                  <span class="text-slate-400">-</span>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="4" class="px-3 py-8 text-center text-slate-500">Belum ada handling.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection
