@extends('layouts.app')
@php
    $role = strtoupper((string)($role ?? auth()->user()?->level ?? ''));

    $isLeader = in_array($role, ['TLRO', 'TLFE', 'TLUM', 'TLSO', 'TLBE', 'KSLR', 'KSFE', 'KSLU', 'KSBE', 'KBL'], true);
    $isStaff  = !$isLeader;

    $roleLabelMap = [
        'RO'   => 'RO',
        'FE'   => 'FE',
        'AO'   => 'AO',
        'SO'   => 'SO',
        'BE'   => 'BE',
        'TLRO' => 'TLRO',
        'TLFE' => 'TLFE',
        'TLUM' => 'TLUM',
        'TLSO' => 'TLSO',
        'TLBE' => 'TLBE',
        'KSLR' => 'KSLR',
        'KSFE' => 'KSFE',
        'KSLU' => 'KSLU',
        'KSBE' => 'KSBE',
        'KBL'  => 'KBL',
    ];

    $roleLabel = $roleLabelMap[$role] ?? $role;

    $pageTitle = $isLeader ? "RKH - Monitoring {$roleLabel}" : "RKH {$roleLabel}";
    $heroTitle = $isLeader ? "RKH {$roleLabel}" : "RKH {$roleLabel}";
    $heroDesc  = $isLeader
        ? "Monitoring RKH scope bawahan."
        : "Rencana kerja harian kamu.";

    // Label bawahan untuk filter/kolom
    $childLabelMap = [
        'TLRO' => 'RO',
        'TLFE' => 'FE',
        'TLUM' => 'AO/SO',
        'TLSO' => 'SO',
        'TLBE' => 'BE',
        'KSLR' => 'TL/RO',
        'KSFE' => 'TL/FE',
        'KSLU' => 'TL/AO/SO',
        'KSBE' => 'TL/BE',
        'KBL'  => 'Unit',
    ];

    $childLabel = $childLabelMap[$role] ?? 'Staff';
@endphp

@section('title', $pageTitle)

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-4">

  <div class="rounded-2xl border bg-white p-4">
    <div class="text-2xl font-black text-slate-900">
      {{ $heroTitle }}
    </div>
    <div class="text-sm text-slate-600 mt-1">
      {{ $heroDesc }}
    </div>

    {{-- FILTER --}}
    <form method="GET" class="mt-4 flex flex-wrap gap-2 items-end">
      <div>
        <label class="text-xs text-slate-500">From</label>
        <input type="date" name="from" value="{{ $from }}" class="border rounded-lg px-3 py-2 text-sm">
      </div>
      <div>
        <label class="text-xs text-slate-500">To</label>
        <input type="date" name="to" value="{{ $to }}" class="border rounded-lg px-3 py-2 text-sm">
      </div>

      <div>
        <label class="text-xs text-slate-500">Status</label>
        <select name="status" class="border rounded-lg px-3 py-2 text-sm">
          <option value="">Semua</option>
          @foreach(['draft','submitted','approved','done'] as $st)
            <option value="{{ $st }}" @selected($status===$st)>{{ strtoupper($st) }}</option>
          @endforeach
        </select>
      </div>

      @if($isLeader)
        <div>
          <label class="text-xs text-slate-500">{{ $childLabel }}</label>
          <select name="staff_id" class="border rounded-lg px-3 py-2 text-sm">
            <option value="">Semua {{ $childLabel }}</option>
            @foreach(($staffs ?? []) as $staff)
              <option value="{{ $staff->id }}" @selected((string)($staffId ?? '') === (string)$staff->id)>
                {{ $staff->name }}
              </option>
            @endforeach
          </select>
        </div>
      @endif

      <button class="rounded-lg bg-slate-900 text-white px-4 py-2 text-sm">Filter</button>
      <a href="{{ url()->current() }}" class="rounded-lg border px-4 py-2 text-sm">Reset</a>
    </form>
  </div>

  {{-- TABLE --}}
  <div class="rounded-2xl border bg-white overflow-hidden">
    <div class="p-4 border-b">
      <div class="font-bold text-slate-900">Daftar RKH</div>
      <div class="text-xs text-slate-500">Range: {{ $from }} s/d {{ $to }}</div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr class="border-b">
            <th class="text-left px-4 py-3">Tanggal</th>
              @if($isLeader)
                <th class="text-left px-4 py-3">{{ $childLabel }}</th>
              @endif
            <th class="text-right px-4 py-3">Total Jam</th>
            <th class="text-center px-4 py-3">Items</th>
            <th class="text-center px-4 py-3">Status</th>
            <th class="text-right px-4 py-3">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          @forelse($rows as $r)
            <tr>
              <td class="px-4 py-3">
                {{ \Carbon\Carbon::parse($r->tanggal)->format('d-m-Y') }}
              </td>

              @if($isLeader)
                <td class="px-4 py-3">
                  {{ $r->staff_name ?? $r->ro_name ?? $r->fe_name ?? '-' }}
                </td>
              @endif

              <td class="px-4 py-3 text-right">{{ number_format((float)$r->total_jam, 2) }}</td>
              <td class="px-4 py-3 text-center">{{ (int)($r->total_items ?? 0) }}</td>

              <td class="px-4 py-3 text-center">
                <span class="px-2 py-1 rounded-full text-xs border">
                  {{ strtoupper($r->status) }}
                </span>
              </td>

              <td class="px-4 py-3 text-right">
                <a href="{{ route('rkh.show', $r->id) }}" class="rounded-lg border px-3 py-1.5 text-xs">
                  Detail
                </a>
              </td>
            </tr>
          @empty
            <tr>
              <td class="px-4 py-6 text-center text-slate-500" colspan="{{ $role === 'TLRO' ? 6 : 5 }}">
                Belum ada RKH.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4">
      {{ $rows->links() }}
    </div>
  </div>

</div>
@endsection