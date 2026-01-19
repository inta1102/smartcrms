@extends('layouts.app')

@section('content')
<div class="space-y-6"
     x-data="{
        openApprove:false,
        openReject:false,
        selectedId:null,
        selectedName:'',
        approveUrl:'',
        rejectUrl:'',
     }"
>
  <div>
    <h1 class="text-xl font-bold">Approval Non-Litigasi (TL)</h1>
    <p class="text-sm text-slate-500">
      Daftar usulan non-litigasi yang menunggu persetujuan TL.
    </p>
  </div>

  {{-- Flash --}}
  @if(session('success'))
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
      {{ session('success') }}
    </div>
  @endif
  @if(session('error'))
    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
      {{ session('error') }}
    </div>
  @endif

  {{-- Search --}}
  <form method="GET" class="flex gap-2 max-w-md">
    <input type="text" name="q" value="{{ request('q') }}"
           placeholder="Cari debitur / rekening"
           class="w-full rounded-xl border border-slate-200 p-2 text-sm">
    <button class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm">
      Cari
    </button>
  </form>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600">
        <tr>
          <th class="px-4 py-3 text-left">Debitur</th>
          <th class="px-4 py-3">Tipe</th>
          <th class="px-4 py-3">Pengusul</th>
          <th class="px-4 py-3">Tanggal</th>
          <th class="px-4 py-3 text-right">Aksi</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($items as $nonLit)
          @php
            $customer = $nonLit->nplCase->loanAccount->customer_name ?? '-';
            $accNo    = $nonLit->nplCase->loanAccount->account_no ?? '';
            $approveUrl = route('tl.approvals.nonlit.approve', $nonLit);
            $rejectUrl  = route('tl.approvals.nonlit.reject',  $nonLit);
          @endphp

          <tr class="border-t">
            <td class="px-4 py-3">
              <div class="font-semibold">{{ $customer }}</div>
              <div class="text-xs text-slate-500">{{ $accNo }}</div>
            </td>

            <td class="px-4 py-3 text-center">
              <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                {{ strtoupper($nonLit->action_type) }}
              </span>
            </td>

            <td class="px-4 py-3 text-center">
              {{ $nonLit->proposed_by_name ?? '-' }}
            </td>

            <td class="px-4 py-3 text-center">
              {{ optional($nonLit->proposal_at)->format('d-m-Y H:i') }}
            </td>

            <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
              <a href="{{ route('nonlit.show', $nonLit) }}"
                 class="inline-flex items-center px-3 py-1.5 rounded-xl border border-slate-200 text-xs hover:bg-slate-50">
                Detail
              </a>

              {{-- Approve TL --}}
              <button type="button"
                      class="inline-flex items-center px-3 py-1.5 rounded-xl bg-slate-900 text-white text-xs hover:bg-slate-800"
                      @click="
                        selectedId={{ $nonLit->id }};
                        selectedName='{{ addslashes($customer) }}';
                        approveUrl='{{ $approveUrl }}';
                        openApprove=true;
                      ">
                Approve
              </button>

              {{-- Reject TL --}}
              <button type="button"
                      class="inline-flex items-center px-3 py-1.5 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 text-xs hover:bg-rose-100"
                      @click="
                        selectedId={{ $nonLit->id }};
                        selectedName='{{ addslashes($customer) }}';
                        rejectUrl='{{ $rejectUrl }}';
                        openReject=true;
                      ">
                Reject
              </button>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="px-4 py-8 text-center text-slate-500">
              Tidak ada usulan non-litigasi menunggu approval TL.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{ $items->links() }}

  {{-- =========================
      MODAL APPROVE TL
  ========================== --}}
  <div x-show="openApprove" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-black/40" @click="openApprove=false"></div>

    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
          <div class="text-sm font-bold text-slate-900">Approve Non-Litigasi (TL)</div>
          <div class="text-xs text-slate-500">
            Debitur: <span class="font-semibold" x-text="selectedName"></span>
          </div>
        </div>

        <form method="POST" :action="approveUrl" class="p-5 space-y-4">
          @csrf

          <div>
            <label class="text-xs font-semibold text-slate-700">Catatan TL (opsional)</label>
            <textarea name="notes" rows="3"
                      class="mt-1 w-full rounded-xl border border-slate-200 p-2 text-sm"
                      placeholder="misal: lanjutkan ke KASI, catatan verifikasi, dll"></textarea>
            <div class="mt-1 text-[11px] text-slate-400">
              Approve TL akan meneruskan ke inbox KASI (bukan final approve).
            </div>
          </div>

          <div class="flex items-center justify-end gap-2">
            <button type="button"
                    class="px-4 py-2 rounded-xl border border-slate-200 text-sm"
                    @click="openApprove=false">
              Batal
            </button>
            <button type="submit"
                    class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm">
              Approve &amp; Teruskan
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  {{-- =========================
      MODAL REJECT TL
  ========================== --}}
  <div x-show="openReject" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-black/40" @click="openReject=false"></div>

    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
          <div class="text-sm font-bold text-rose-700">Reject Non-Litigasi (TL)</div>
          <div class="text-xs text-slate-500">
            Debitur: <span class="font-semibold" x-text="selectedName"></span>
          </div>
        </div>

        <form method="POST" :action="rejectUrl" class="p-5 space-y-4">
          @csrf

          <div>
            <label class="text-xs font-semibold text-slate-700">Alasan penolakan <span class="text-rose-600">*</span></label>
            <textarea name="reason" rows="4" required
                      class="mt-1 w-full rounded-xl border border-slate-200 p-2 text-sm"
                      placeholder="misal: data belum lengkap, skema tidak sesuai, perlu revisi komitmen, dll"></textarea>
          </div>

          <div class="flex items-center justify-end gap-2">
            <button type="button"
                    class="px-4 py-2 rounded-xl border border-slate-200 text-sm"
                    @click="openReject=false">
              Batal
            </button>
            <button type="submit"
                    class="px-4 py-2 rounded-xl bg-rose-600 text-white text-sm">
              Reject
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

</div>
@endsection
