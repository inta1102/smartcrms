@extends('layouts.app')

@section('title', 'KPI Thresholds')

@section('content')
<div class="max-w-5xl mx-auto p-4 space-y-4">

  <div class="flex items-start justify-between gap-3">
    <div>
      <div class="text-2xl font-extrabold text-slate-900">KPI Thresholds</div>
      <div class="text-sm text-slate-500">Parameter untuk badge AMAN/WASPADA/RISIKO tanpa ubah kode.</div>
    </div>
  </div>

  @if(session('success'))
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 p-3 text-sm">
      {{ session('success') }}
    </div>
  @endif

  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b font-semibold">Daftar Threshold</div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr>
            <th class="text-left p-2">Metric</th>
            <th class="text-left p-2">Title</th>
            <th class="text-left p-2">Direction</th>
            <th class="text-right p-2">Green Min</th>
            <th class="text-right p-2">Yellow Min</th>
            <th class="text-center p-2">Active</th>
            <th class="text-right p-2">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          @forelse($items as $it)
            <tr>
              <td class="p-2 font-mono">{{ $it->metric }}</td>
              <td class="p-2 font-semibold">{{ $it->title }}</td>
              <td class="p-2">{{ $it->direction }}</td>
              <td class="p-2 text-right">{{ number_format((float)$it->green_min,2) }}</td>
              <td class="p-2 text-right">{{ number_format((float)$it->yellow_min,2) }}</td>
              <td class="p-2 text-center">
                <span class="inline-flex px-2 py-1 rounded-full text-xs {{ $it->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                  {{ $it->is_active ? 'YES' : 'NO' }}
                </span>
              </td>
              <td class="p-2 text-right">
                <a href="{{ route('kpi.thresholds.edit', $it) }}" class="px-3 py-1.5 rounded-lg border hover:bg-slate-50 text-sm">
                  Edit
                </a>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="p-6 text-slate-500">Belum ada data.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection
