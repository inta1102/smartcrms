@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">

  <div class="flex items-start justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold">Detail RKH</h1>
      <div class="text-sm text-slate-600 mt-1">
        Tanggal: <b>{{ $rkh->tanggal->format('d-m-Y') }}</b> |
        RO: <b>{{ $rkh->user->name }}</b> |
        Total Jam: <b>{{ number_format((float)$rkh->total_jam, 2) }}</b>
      </div>
      <div class="text-sm text-slate-600">
        Status: <b>{{ strtoupper($rkh->status) }}</b>
        @if($rkh->approved_at)
          | Approved at: <b>{{ $rkh->approved_at->format('d-m-Y H:i') }}</b>
        @endif
      </div>

      @if($rkh->approval_note)
        <div class="mt-2 p-3 border rounded bg-yellow-50 text-sm">
          <div class="font-semibold">Catatan Approval:</div>
          <div class="whitespace-pre-line">{{ $rkh->approval_note }}</div>
        </div>
      @endif
    </div>

    <div class="flex items-center gap-2">
      <a href="{{ route('rkh.index') }}" class="px-3 py-2 rounded border">Kembali</a>

      @if(in_array($rkh->status, ['draft','rejected']))
        <a href="{{ route('rkh.edit', $rkh->id) }}" class="px-3 py-2 rounded border">Edit</a>

        <form method="POST" action="{{ route('rkh.submit', $rkh->id) }}">
          @csrf
          <button class="px-3 py-2 rounded bg-black text-white"
            onclick="return confirm('Submit RKH ke TL? Setelah submit, jam kegiatan tidak bisa diubah.')">
            Submit ke TL
          </button>
        </form>
      @endif
    </div>
  </div>

  @if(session('success'))
    <div class="mt-4 p-3 border rounded bg-green-50 text-green-700 text-sm">
      {{ session('success') }}
    </div>
  @endif

  <div class="mt-4 border rounded overflow-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr>
          <th class="text-left p-2">Jam</th>
          <th class="text-left p-2">Nasabah</th>
          <th class="text-left p-2">Kolek</th>
          <th class="text-left p-2">Jenis</th>
          <th class="text-left p-2">Tujuan</th>
          <th class="text-left p-2">Area</th>
          <th class="text-left p-2">LKH</th>
          <th class="text-left p-2">Aksi</th>
        </tr>
      </thead>
      <tbody>
        @forelse($rkh->details as $d)
          <tr class="border-t">
            <td class="p-2">{{ substr($d->jam_mulai,0,5) }}-{{ substr($d->jam_selesai,0,5) }}</td>
            <td class="p-2">
              <div class="font-semibold">{{ $d->nama_nasabah ?? '-' }}</div>
              @if($d->networking)
                <div class="text-xs text-slate-600 mt-1">
                  Networking: <b>{{ $d->networking->nama_relasi }}</b> ({{ $d->networking->jenis_relasi }})
                </div>
              @endif
            </td>
            <td class="p-2">{{ $d->kolektibilitas ?? '-' }}</td>
            <td class="p-2">{{ $d->jenis_kegiatan }}</td>
            <td class="p-2">{{ $d->tujuan_kegiatan }}</td>
            <td class="p-2">{{ $d->area ?? '-' }}</td>

            <td class="p-2">
              @if($d->lkh)
                <span class="px-2 py-1 rounded bg-green-100 text-green-700">Terisi</span>
              @else
                <span class="px-2 py-1 rounded bg-slate-100 text-slate-700">Kosong</span>
              @endif
            </td>

            <td class="p-2">
              @if($d->lkh)
                <a class="underline" href="{{ route('lkh.edit', $d->lkh->id) }}">Edit LKH</a>
              @else
                <a class="underline" href="{{ route('lkh.create', $d->id) }}">Isi LKH</a>
              @endif
            </td>
          </tr>
        @empty
          <tr><td class="p-4 text-slate-500" colspan="8">Belum ada kegiatan.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-4">
    <a class="underline" href="{{ route('lkh.recap.show', $rkh->id) }}">Rekap LKH (Siap Cetak)</a>
  </div>

</div>
@endsection
