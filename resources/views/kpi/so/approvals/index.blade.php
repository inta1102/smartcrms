@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">
  <div class="flex items-center justify-between mb-4">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">Approval Target KPI SO</h1>
      <p class="text-sm text-slate-500">{{ $inboxLabel }}</p>
    </div>

    <div class="flex gap-2">
        <a href="{{ route('kpi.so.approvals.index', ['tab'=>'inbox']) }}" class="...">Inbox</a>
        <a href="{{ route('kpi.so.approvals.index', ['tab'=>'approved']) }}" class="...">Approved</a>
        <a href="{{ route('kpi.so.approvals.index', ['tab'=>'rejected']) }}" class="...">Rejected</a>
        <a href="{{ route('kpi.so.approvals.index', ['tab'=>'all']) }}" class="...">Semua</a>
    </div>

  </div>

  @if(session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm">
      {{ session('status') }}
    </div>
  @endif

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600">
        <tr>
          <th class="text-left px-4 py-3">Periode</th>
          <th class="text-left px-4 py-3">SO</th>
          <th class="text-right px-4 py-3">Target OS</th>
          <th class="text-right px-4 py-3">Target NOA</th>
          <th class="text-left px-4 py-3">Status</th>
          <th class="text-right px-4 py-3">Aksi</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        @forelse($targets as $t)
          <tr>
            <td class="px-4 py-3 font-semibold">{{ \Carbon\Carbon::parse($t->period)->format('M Y') }}</td>
            <td class="px-4 py-3">{{ $t->user?->name ?? '-' }}</td>
            <td class="px-4 py-3 text-right">Rp {{ number_format((int)$t->target_os_disbursement,0,',','.') }}</td>
            <td class="px-4 py-3 text-right">{{ number_format((int)$t->target_noa_disbursement) }}</td>
            <td class="px-4 py-3"><span class="text-xs rounded-full px-3 py-1 bg-amber-100 text-amber-800 font-semibold">{{ strtoupper($t->status) }}</span></td>
            <td class="px-4 py-3 text-right">
              <a href="{{ route('kpi.so.approvals.show', $t) }}"
                class="inline-flex items-center rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                Review
              </a>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">Tidak ada data.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-4">{{ $targets->links() }}</div>
</div>
@endsection
