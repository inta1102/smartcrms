@extends('layouts.app')

@section('title', 'Root Cause AO ' . $aoCode)

@section('content')
@php
  $fmt = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
  $pct = fn($v) => number_format((float)$v * 100, 2) . '%';
  $s = $data['summary'];
@endphp

<div class="space-y-4">
  <div class="flex items-start justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold text-slate-900">🔎 Root Cause AO: {{ $aoCode }}</h1>
      <div class="text-sm text-slate-500">
        Snapshot: <span class="font-semibold">{{ $filter['position_date'] }}</span>
        @if($filter['branch_code']) • Branch: <span class="font-semibold">{{ $filter['branch_code'] }}</span> @endif
      </div>
    </div>
    <a href="{{ route('lending.performance.index', $filter) }}"
       class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold">
      ← Kembali
    </a>
  </div>

  {{-- SUMMARY --}}
  <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">OS</div>
      <div class="mt-1 text-lg font-extrabold text-slate-900">{{ $fmt($s['total_os']) }}</div>
      <div class="mt-1 text-xs text-slate-500">NOA: {{ number_format($s['noa']) }}</div>
    </div>
    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">NPL (3-5)</div>
      <div class="mt-1 text-lg font-extrabold text-slate-900">{{ $fmt($s['npl_os']) }}</div>
      <div class="mt-1 text-xs text-slate-500">Rasio: {{ $pct($s['npl_ratio']) }}</div>
    </div>
    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">DPD&gt;30</div>
      <div class="mt-1 text-lg font-extrabold text-slate-900">{{ $fmt($s['dpd_gt_30_os']) }}</div>
      <div class="mt-1 text-xs text-slate-500">DPD&gt;60: {{ $fmt($s['dpd_gt_60_os']) }}</div>
    </div>
    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">DPD&gt;90</div>
      <div class="mt-1 text-lg font-extrabold text-slate-900">{{ $fmt($s['dpd_gt_90_os']) }}</div>
      <div class="mt-1 text-xs text-slate-500">DPD&gt;0: {{ $fmt($s['dpd_gt_0_os']) }}</div>
    </div>
  </div>

  {{-- TOP DPD90 --}}
  <div class="rounded-2xl border border-slate-100 bg-white shadow-sm">
    <div class="border-b border-slate-100 px-4 py-3">
      <div class="text-sm font-bold text-slate-900">🔥 Top DPD &gt; 90 (Prioritas Rescue)</div>
      <div class="text-xs text-slate-500">Urut outstanding terbesar. Ini biasanya penyebab lampu merah.</div>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-600">
          <tr>
            <th class="px-4 py-3 text-left">Debitur</th>
            <th class="px-4 py-3 text-right">DPD</th>
            <th class="px-4 py-3 text-right">Kolek</th>
            <th class="px-4 py-3 text-right">Outstanding</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @forelse($data['top_dpd90'] as $la)
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-slate-900">{{ $la->customer_name ?? '-' }}</div>
                <div class="text-xs text-slate-500">{{ $la->account_no ?? '-' }} • CIF: {{ $la->cif ?? '-' }}</div>
              </td>
              <td class="px-4 py-3 text-right font-semibold">{{ number_format((int)$la->dpd) }}</td>
              <td class="px-4 py-3 text-right">{{ $la->kolek }}</td>
              <td class="px-4 py-3 text-right font-semibold">{{ $fmt($la->outstanding) }}</td>
            </tr>
          @empty
            <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Tidak ada DPD&gt;90.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- TOP NPL --}}
  <div class="rounded-2xl border border-slate-100 bg-white shadow-sm">
    <div class="border-b border-slate-100 px-4 py-3">
      <div class="text-sm font-bold text-slate-900">📌 Top NPL (Kolek 3–5)</div>
      <div class="text-xs text-slate-500">Portofolio dengan kualitas buruk (urut OS terbesar).</div>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-600">
          <tr>
            <th class="px-4 py-3 text-left">Debitur</th>
            <th class="px-4 py-3 text-right">DPD</th>
            <th class="px-4 py-3 text-right">Kolek</th>
            <th class="px-4 py-3 text-right">Outstanding</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @forelse($data['top_npl'] as $la)
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-slate-900">{{ $la->customer_name ?? '-' }}</div>
                <div class="text-xs text-slate-500">{{ $la->account_no ?? '-' }} • CIF: {{ $la->cif ?? '-' }}</div>
              </td>
              <td class="px-4 py-3 text-right">{{ number_format((int)$la->dpd) }}</td>
              <td class="px-4 py-3 text-right">{{ $la->kolek }}</td>
              <td class="px-4 py-3 text-right font-semibold">{{ $fmt($la->outstanding) }}</td>
            </tr>
          @empty
            <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Tidak ada NPL.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- OVERDUE AGENDA --}}
  <div class="rounded-2xl border border-slate-100 bg-white shadow-sm">
    <div class="border-b border-slate-100 px-4 py-3">
      <div class="text-sm font-bold text-slate-900">⏰ Agenda Overdue (Pending/Escalated)</div>
      <div class="text-xs text-slate-500">Jadwal yang seharusnya sudah dikerjakan tapi lewat tanggal.</div>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-600">
          <tr>
            <th class="px-4 py-3 text-left">Jadwal</th>
            <th class="px-4 py-3 text-left">Debitur</th>
            <th class="px-4 py-3 text-right">Scheduled</th>
            <th class="px-4 py-3 text-center">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @forelse($data['overdue_agendas'] as $a)
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-slate-900">{{ $a->title ?? $a->type ?? '-' }}</div>
                <div class="text-xs text-slate-500">{{ $a->level ?? '-' }}</div>
              </td>
              <td class="px-4 py-3">
                <div class="font-semibold text-slate-900">{{ $a->customer_name ?? '-' }}</div>
                <div class="text-xs text-slate-500">{{ $a->account_no ?? '-' }}</div>
              </td>
              <td class="px-4 py-3 text-right">{{ \Carbon\Carbon::parse($a->scheduled_at)->format('Y-m-d H:i') }}</td>
              <td class="px-4 py-3 text-center">
                <span class="rounded-full border px-2 py-1 text-xs font-bold">
                  {{ strtoupper($a->status) }}
                </span>
              </td>
            </tr>
          @empty
            <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Tidak ada agenda overdue.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- ESCALATED --}}
  <div class="rounded-2xl border border-slate-100 bg-white shadow-sm">
    <div class="border-b border-slate-100 px-4 py-3">
      <div class="text-sm font-bold text-slate-900">📣 Agenda Escalated</div>
      <div class="text-xs text-slate-500">Jadwal yang naik eskalasi karena belum di-follow-up sesuai threshold.</div>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-600">
          <tr>
            <th class="px-4 py-3 text-left">Jadwal</th>
            <th class="px-4 py-3 text-left">Debitur</th>
            <th class="px-4 py-3 text-right">Escalated At</th>
            <th class="px-4 py-3 text-left">Catatan</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @forelse($data['escalated_agendas'] as $a)
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-slate-900">{{ $a->title ?? $a->type ?? '-' }}</div>
                <div class="text-xs text-slate-500">{{ \Carbon\Carbon::parse($a->scheduled_at)->format('Y-m-d H:i') }}</div>
              </td>
              <td class="px-4 py-3">
                <div class="font-semibold text-slate-900">{{ $a->customer_name ?? '-' }}</div>
                <div class="text-xs text-slate-500">{{ $a->account_no ?? '-' }}</div>
              </td>
              <td class="px-4 py-3 text-right">
                {{ $a->escalated_at ? \Carbon\Carbon::parse($a->escalated_at)->format('Y-m-d H:i') : '-' }}
              </td>
              <td class="px-4 py-3 text-slate-700">{{ $a->escalation_note ?? '-' }}</td>
            </tr>
          @empty
            <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Tidak ada agenda escalated.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
