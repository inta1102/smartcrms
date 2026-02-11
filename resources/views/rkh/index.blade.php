@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold">RKH RO</h1>
      <p class="text-sm text-slate-500">Rencana kerja harian kamu.</p>
    </div>
    <a href="{{ route('rkh.create') }}" class="px-3 py-2 rounded border">+ Buat RKH</a>
  </div>

  <div class="mt-4 border rounded overflow-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr>
          <th class="text-left p-2">Tanggal</th>
          <th class="text-left p-2">Total Jam</th>
          <th class="text-left p-2">Status</th>
          <th class="text-left p-2">Aksi</th>
        </tr>
      </thead>
      <tbody>
        @forelse($items as $rkh)
          <tr class="border-t">
            <td class="p-2">{{ $rkh->tanggal->format('d-m-Y') }}</td>
            <td class="p-2">{{ number_format((float)$rkh->total_jam, 2) }}</td>
            <td class="p-2">
              <span class="px-2 py-1 rounded bg-slate-100">
                {{ strtoupper($rkh->status) }}
              </span>
            </td>
            <td class="p-2">
              <a class="underline" href="{{ route('rkh.show', $rkh->id) }}">Detail</a>
              @if(in_array($rkh->status, ['draft','rejected']))
                <span class="mx-2 text-slate-300">|</span>
                <a class="underline" href="{{ route('rkh.edit', $rkh->id) }}">Edit</a>
              @endif
            </td>
          </tr>
        @empty
          <tr><td class="p-4 text-slate-500" colspan="4">Belum ada RKH.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
