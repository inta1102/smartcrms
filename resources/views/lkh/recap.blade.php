@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">

  <div class="flex items-start justify-between gap-4">
    <div>
      <h1 class="text-2xl font-bold">LKH Harian (RO)</h1>
      <div class="text-sm text-slate-600 mt-1">
        Tanggal: <b>{{ $rkh->tanggal->format('d-m-Y') }}</b> |
        RO: <b>{{ $rkh->user->name }}</b>
      </div>
      <div class="text-sm text-slate-600">
        Status RKH: <b>{{ strtoupper($rkh->status) }}</b> |
        Terisi: <b>{{ $stats['lkh_terisi'] }}</b> / {{ $stats['total_kegiatan'] }}
      </div>
    </div>

    <button class="px-3 py-2 border rounded" onclick="window.print()">Print</button>
  </div>

  <div class="mt-4 overflow-auto border rounded">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr>
          <th class="text-left p-2">Jam</th>
          <th class="text-left p-2">Nasabah</th>
          <th class="text-left p-2">Kolek</th>
          <th class="text-left p-2">Kegiatan</th>
          <th class="text-left p-2">Hasil</th>
          <th class="text-left p-2">Tindak Lanjut</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $r)
          <tr class="border-t">
            <td class="p-2">{{ $r['jam'] }}</td>
            <td class="p-2">{{ $r['nasabah'] }}</td>
            <td class="p-2">{{ $r['kolek'] }}</td>
            <td class="p-2">
              <div><b>{{ $r['jenis'] }}</b></div>
              <div class="text-slate-600">{{ $r['tujuan'] }} | {{ $r['area'] }}</div>

              @if($r['networking'])
                <div class="mt-2 text-xs text-slate-700">
                  <b>Networking:</b> {{ $r['networking']['nama_relasi'] }}
                  ({{ $r['networking']['jenis_relasi'] }})
                </div>
              @endif
            </td>
            <td class="p-2">
              @if(is_null($r['is_visited']) && empty($r['hasil']))
                <span class="text-slate-400">Belum diisi</span>
              @else
                @if($r['is_visited'] === false)
                  <div class="text-red-700 text-xs font-semibold">Tidak dikunjungi</div>
                @endif
                <div class="whitespace-pre-line">{{ $r['hasil'] }}</div>
                @if(!empty($r['respon']))
                  <div class="mt-1 text-slate-600 text-xs whitespace-pre-line">{{ $r['respon'] }}</div>
                @endif
              @endif
            </td>
            <td class="p-2 whitespace-pre-line">{{ $r['tindak_lanjut'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- Tanda tangan --}}
  <div class="mt-8 grid grid-cols-4 gap-6 text-sm">
    <div class="text-center">
      <div>RO</div>
      <div class="mt-16 font-semibold">( {{ $rkh->user->name }} )</div>
    </div>
    <div class="text-center">
      <div>TL</div>
      <div class="mt-16 font-semibold">( .................... )</div>
    </div>
    <div class="text-center">
      <div>Kasi</div>
      <div class="mt-16 font-semibold">( .................... )</div>
    </div>
    <div class="text-center">
      <div>Kabag</div>
      <div class="mt-16 font-semibold">( .................... )</div>
    </div>
  </div>

</div>
@endsection
