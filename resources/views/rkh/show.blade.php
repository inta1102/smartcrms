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

      {{-- hint kecil --}}
      <div class="mt-2 text-xs text-slate-500">
        Catatan: <b>Isi LKH</b> akan masuk ke timeline penanganan jika kegiatan terhubung ke <b>account_no</b> (nasabah existing).
      </div>
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

  @if(session('status'))
    <div class="mt-4 p-3 border rounded bg-green-50 text-green-700 text-sm">
      {{ session('status') }}
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
          @php
            $hasAcc = !empty(trim((string)($d->account_no ?? ''))); // kolom baru (nullable)
            $isProspect = !$hasAcc && empty($d->nasabah_id);
          @endphp

          <tr class="border-t">
            <td class="p-2 whitespace-nowrap">
              {{ substr($d->jam_mulai,0,5) }}-{{ substr($d->jam_selesai,0,5) }}
            </td>

            <td class="p-2">
              <div class="font-semibold">{{ $d->nama_nasabah ?? '-' }}</div>

              {{-- badge status target --}}
              <div class="mt-1 flex flex-wrap gap-1">
                @if($hasAcc)
                  <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 text-[11px]">
                    Existing • {{ $d->account_no }}
                  </span>
                @elseif($isProspect)
                  <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[11px]">
                    Prospect • belum ada account_no
                  </span>
                @else
                  <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[11px]">
                    Belum ter-link
                  </span>
                @endif
              </div>

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

            <td class="p-2 whitespace-nowrap">
              @if($d->lkh)
                <a class="underline" href="{{ route('lkh.edit', $d->lkh->id) }}">Edit LKH</a>
              @else
                @if($hasAcc)
                  {{-- ✅ Existing nasabah: pakai flow lama, akan redirect ke visits.create --}}
                  <a class="underline text-msa-blue" href="{{ route('rkh.details.visitStart', $d->id) }}">
                    Isi LKH
                  </a>
                  <div class="text-[11px] text-slate-500 mt-1">
                    Masuk timeline penanganan
                  </div>
                @else
                  {{-- ❌ Prospect: belum bisa pakai visits.create --}}
                  <button type="button"
                          class="text-slate-400 cursor-not-allowed underline"
                          onclick="alert('Prospect belum punya account_no. Silakan link account_no dulu jika sudah jadi nasabah.');">
                    Isi LKH
                  </button>

                  {{-- optional: tombol/link ke edit RKH detail untuk isi account_no --}}
                  <div class="mt-1">
                    <a class="text-[11px] text-msa-blue hover:underline"
                       href="{{ route('rkh.edit', $rkh->id) }}">
                      Link account_no di Edit RKH
                    </a>
                  </div>
                @endif
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
