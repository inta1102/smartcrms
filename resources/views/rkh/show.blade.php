@extends('layouts.app')

@section('content')
@php
  use App\Enums\UserRole;

  // ===== role viewer (aman untuk enum/string/null) =====
  $me = auth()->user();
  $roleValue = 'RO';

  
  $myRole = method_exists($me,'role') && $me->role() ? $me->role()->value : ($me->level?->value ?? $me->level ?? 'RO');
  $myRole = strtoupper(trim((string)$myRole));
  $isTLRO = $myRole === 'TLRO';

  if (isset($role) && is_string($role) && $role !== '') {
      $roleValue = strtoupper(trim($role));
  } elseif ($me) {
      $lvl = $me->getAttribute('level'); // bisa enum UserRole atau string
      if ($lvl instanceof UserRole) $roleValue = strtoupper(trim((string)$lvl->value));
      elseif (is_string($lvl) && trim($lvl) !== '') $roleValue = strtoupper(trim($lvl));
  }

  $isOwner = $me && ((int)$me->id === (int)$rkh->user_id);
  

  // helper status label
  $statusUpper = strtoupper((string)($rkh->status ?? ''));

  $canFillLkh = ($rkh->status === 'approved'); // aturan utama

@endphp

<div class="max-w-6xl mx-auto p-4">

  <div class="flex items-start justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold">Detail RKH</h1>

      <div class="text-sm text-slate-600 mt-1">
        Tanggal: <b>{{ $rkh->tanggal->format('d-m-Y') }}</b> |
        RO: <b>{{ $rkh->user->name }}</b> |
        Total Jam: <b>{{ number_format((float)$rkh->total_jam, 2) }}</b>
      </div>

      {{-- ===== Status + Metadata ===== --}}
      <div class="text-sm text-slate-600 mt-1">
        Status: <b>{{ $statusUpper }}</b>

        @if(!empty($rkh->submitted_at))
          | Submitted: <b>{{ \Carbon\Carbon::parse($rkh->submitted_at)->format('d-m-Y H:i') }}</b>
        @endif

        @if(!empty($rkh->approved_at))
          | Approved:
          <b>{{ $rkh->approver?->name ?? '-' }}</b>
          ({{ \Carbon\Carbon::parse($rkh->approved_at)->format('d-m-Y H:i') }})
        @endif

        @if(!empty($rkh->rejected_at))
          | Rejected:
          <b>{{ $rkh->rejector?->name ?? '-' }}</b>
          ({{ \Carbon\Carbon::parse($rkh->rejected_at)->format('d-m-Y H:i') }})
        @endif
      </div>

      {{-- ===== Notes ===== --}}
      @if(!empty($rkh->approval_note))
        <div class="mt-2 p-3 border rounded bg-yellow-50 text-sm">
          <div class="font-semibold">Catatan Approval:</div>
          <div class="whitespace-pre-line">{{ $rkh->approval_note }}</div>
        </div>
      @endif

      @if(!empty($rkh->rejection_note))
        <div class="mt-2 p-3 border rounded bg-rose-50 text-sm text-rose-800 border-rose-200">
          <div class="font-semibold">Catatan Reject TL:</div>
          <div class="whitespace-pre-line">{{ $rkh->rejection_note }}</div>
        </div>
      @endif

      {{-- hint kecil --}}
      <div class="mt-2 text-xs text-slate-500">
        Catatan: <b>Isi LKH</b> akan masuk ke timeline penanganan jika kegiatan terhubung ke <b>account_no</b> (nasabah existing).
      </div>
    </div>

    <div class="flex flex-col items-end gap-2">
      <div class="flex items-center gap-2">
        <a href="{{ route('rkh.index') }}" class="px-3 py-2 rounded border">Kembali</a>

        {{-- Edit: hanya owner dan status draft/rejected --}}
        @if($isOwner && in_array($rkh->status, ['draft','rejected']))
          <a href="{{ route('rkh.edit', $rkh->id) }}" class="px-3 py-2 rounded border">Edit</a>
        @endif

        {{-- Submit: hanya owner dan status draft/rejected --}}
        @if($isOwner && in_array($rkh->status, ['draft','rejected']))
          <form method="POST" action="{{ route('rkh.submit', $rkh->id) }}">
            @csrf
            <button class="px-3 py-2 rounded bg-black text-white"
              onclick="return confirm('Submit RKH ke TL? Setelah submit, jam kegiatan tidak bisa diubah.')">
              Submit ke TL
            </button>
          </form>
        @endif
      </div>

      {{-- ===== Panel Approval TLRO (hanya kalau SUBMITTED) ===== --}}
      @php
        $me = auth()->user();
        $role = method_exists($me, 'role') && $me->role() ? ($me->role()->value ?? '') : ($me->level ?? '');
        $role = strtoupper(trim((string)$role));
        $isTl = ($role === 'TLRO');
        $canReview = $isTl && ($rkh->status === 'submitted') && ((int)$rkh->user_id !== (int)($me->id ?? 0));
      @endphp

      @if($canReview)
        <div class="mt-4 p-4 border rounded bg-white">
          <div class="font-bold text-slate-900">Approval TLRO</div>
          <div class="text-sm text-slate-600 mt-1">
            Pilih item yang perlu direvisi (reject). Jika semua sudah OK, klik <b>Approve</b>.
          </div>

          <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
            {{-- Approve --}}
            <form method="POST" action="{{ route('rkh.approve', $rkh->id) }}" class="border rounded p-3">
              @csrf
              <div class="text-sm font-semibold">Approve RKH</div>
              <textarea name="approval_note" class="mt-2 w-full border rounded p-2 text-sm" rows="3"
                placeholder="Catatan approval (opsional). Misal: rute sudah searah, prioritas DPK disetujui."></textarea>

              <button class="mt-3 px-4 py-2 rounded bg-emerald-600 text-white"
                onclick="return confirm('Approve RKH ini? Setelah approve, RO bisa mulai isi LKH.')">
                Approve
              </button>
            </form>

            {{-- Reject --}}
            <form method="POST" action="{{ route('rkh.reject', $rkh->id) }}" class="border rounded p-3">
              @csrf
              <div class="text-sm font-semibold">Reject Sebagian Item</div>
              <textarea name="rejection_note" class="mt-2 w-full border rounded p-2 text-sm" rows="3"
                placeholder="Catatan global reject. Misal: area tidak searah / rute zigzag / prioritas salah."></textarea>

              <div class="mt-3 text-xs text-slate-600">
                Checklist item yang ditolak + isi catatan per item (opsional).
              </div>

              <div class="mt-2 max-h-56 overflow-auto border rounded">
                @foreach($rkh->details as $d)
                  <label class="flex items-start gap-2 p-2 border-b">
                    <input type="checkbox" name="reject_ids[]" value="{{ $d->id }}" class="mt-1">
                    <div class="flex-1">
                      <div class="text-sm font-semibold">
                        {{ substr($d->jam_mulai,0,5) }}-{{ substr($d->jam_selesai,0,5) }}
                        · {{ $d->nama_nasabah ?? '-' }}
                        @if(!empty($d->account_no)) <span class="text-slate-500">({{ $d->account_no }})</span> @endif
                      </div>
                      <div class="text-xs text-slate-600">
                        {{ $d->jenis_kegiatan }} · {{ $d->tujuan_kegiatan }} · Area: {{ $d->area ?? '-' }}
                      </div>
                      <input type="text" name="tl_note[{{ $d->id }}]"
                        class="mt-2 w-full border rounded p-2 text-sm"
                        placeholder="Catatan item ini (opsional). Misal: pindah jam / gabung area / ganti target.">
                    </div>
                  </label>
                @endforeach
              </div>

              <button class="mt-3 px-4 py-2 rounded bg-rose-600 text-white"
                onclick="return confirm('Reject item yang dicentang? RKH akan kembali ke RO untuk revisi.')">
                Reject Selected
              </button>
            </form>
          </div>
        </div>
      @endif
    </div>
  </div>

  {{-- Flash success/status/errors --}}
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

  @if($errors->any())
    <div class="mt-4 p-3 border rounded bg-rose-50 text-rose-700 text-sm">
      <div class="font-semibold mb-1">Ada error:</div>
      <ul class="list-disc ml-5">
        @foreach($errors->all() as $e)
          <li>{{ $e }}</li>
        @endforeach
      </ul>
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
            $hasAcc = !empty(trim((string)($d->account_no ?? '')));
            $isProspect = !$hasAcc && empty($d->nasabah_id);
          @endphp

          <tr class="border-t">
            <td class="p-2 whitespace-nowrap">
              {{ substr($d->jam_mulai,0,5) }}-{{ substr($d->jam_selesai,0,5) }}
            </td>

            <td class="p-2">
              <div class="font-semibold">{{ $d->nama_nasabah ?? '-' }}</div>

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
                @php $ts = $d->tl_status ?? 'pending'; @endphp
                @if($ts === 'approved')
                  <span class="px-2 py-1 rounded bg-emerald-100 text-emerald-700 text-xs">TL OK</span>
                @elseif($ts === 'rejected')
                  <span class="px-2 py-1 rounded bg-rose-100 text-rose-700 text-xs">Revisi</span>
                  @if($d->tl_note)
                    <div class="text-xs text-slate-600 mt-1 whitespace-pre-line">{{ $d->tl_note }}</div>
                  @endif
                @else
                  <span class="px-2 py-1 rounded bg-slate-100 text-slate-700 text-xs">Pending</span>
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
                @if(!$canFillLkh)
                  {{-- Belum approved TL => Disable --}}
                  <button type="button"
                          class="text-slate-400 cursor-not-allowed underline"
                          onclick="alert('LKH hanya bisa diisi setelah RKH di-APPROVE TL. Status saat ini: {{ strtoupper($rkh->status) }}');">
                    Isi LKH
                  </button>
                  <div class="text-[11px] text-slate-500 mt-1">
                    Menunggu approval TL
                  </div>
                @else
                  {{-- Approved => boleh isi --}}
                  @if($hasAcc)
                    <a class="underline text-msa-blue" href="{{ route('rkh.details.visitStart', $d->id) }}">
                      Isi LKH
                    </a>
                    <div class="text-[11px] text-slate-500 mt-1">Masuk timeline penanganan</div>
                  @else
                    <button type="button"
                            class="text-slate-400 cursor-not-allowed underline"
                            onclick="alert('Prospect belum punya account_no. Silakan link account_no dulu jika sudah jadi nasabah.');">
                      Isi LKH
                    </button>
                    <div class="mt-1">
                      <a class="text-[11px] text-msa-blue hover:underline"
                        href="{{ route('rkh.edit', $rkh->id) }}">
                        Link account_no di Edit RKH
                      </a>
                    </div>
                  @endif
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