@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto p-4">
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold">Isi LKH (RKH)</h1>
      <div class="text-sm text-slate-600">
        Tanggal: <b>{{ $rkh->tanggal->format('d-m-Y') }}</b>
      </div>
      <div class="text-sm text-slate-600">
        Nasabah: <b>{{ $detail->nama_nasabah ?? '-' }}</b>
        @if(!empty($detail->account_no))
          <span class="text-slate-400">|</span> Account: <b>{{ $detail->account_no }}</b>
        @endif
      </div>
    </div>

    <a href="{{ route('rkh.show', $rkh->id) }}" class="px-3 py-2 rounded border">
      Kembali
    </a>
  </div>

  <div class="mt-4 p-3 border rounded bg-slate-50">
    <div class="text-xs text-slate-500">Schedule</div>
    <div class="font-semibold">{{ $visitSchedule->title }}</div>
    <div class="text-sm text-slate-600">{{ optional($visitSchedule->scheduled_at)->format('d-m-Y H:i') }}</div>
  </div>

  {{-- nanti form LKH kamu taruh di sini --}}
</div>
@endsection
