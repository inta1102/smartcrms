@extends('layouts.app')

@section('title', $title . ' — ' . $loan->customer_name)

@section('content')
<div class="w-full max-w-2xl space-y-4">

    <h1 class="text-xl font-semibold text-slate-800">
        {{ $title }}
    </h1>
    <p class="text-xs text-slate-500">
        Rek: {{ $loan->account_no }} • CIF: {{ $loan->cif }} • DPD: {{ $loan->dpd }}
    </p>

    <form method="POST"
          enctype="multipart/form-data"
          action="{{ route('cases.sp.store', [$case->id, $type]) }}"
          class="bg-white rounded-2xl border border-slate-200 p-4 space-y-4">
        @csrf

        {{-- Tanggal Kirim --}}
        <div>
            <label class="text-xs font-semibold text-slate-600">Tanggal Pengiriman</label>
            <input type="date" name="sent_at"
                value="{{ old('sent_at', now()->toDateString()) }}"
                class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm">
        </div>

        {{-- Metode --}}
        <div>
            <label class="text-xs font-semibold text-slate-600">Metode Pengiriman</label>
            <select name="method"
                class="mt-1 w-full border p-2 rounded-lg text-sm">
                <option value="Dikirim via Pos">Dikirim via Pos</option>
                <option value="Dikirim via Kurir Internal">Kurir Internal</option>
                <option value="Disampaikan Langsung">Disampaikan Langsung</option>
                <option value="Dikirim via WhatsApp">WhatsApp</option>
            </select>
        </div>

        {{-- Penerima --}}
        <div>
            <label class="text-xs font-semibold text-slate-600">
                Diterima Oleh (opsional)
            </label>
            <input type="text" name="receiver"
                placeholder="Contoh: Istri Debitur / Keponakan"
                class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm">
        </div>

        {{-- Catatan --}}
        <div>
            <label class="text-xs font-semibold text-slate-600">Catatan</label>
            <textarea name="notes"
                rows="3"
                class="mt-1 w-full border border-slate-300 rounded-lg p-3 text-sm"
                placeholder="Contoh: Debitur tidak ada di rumah, surat diterima oleh ..."></textarea>
        </div>

        {{-- Bukti Foto / Scan --}}
        <div>
            <label class="text-xs font-semibold text-slate-600">Upload Bukti (opsional)</label>
            <input type="file" name="attachment" class="mt-1 text-xs">
            <p class="text-[11px] text-slate-500">
                Foto serah-terima atau bukti pengiriman. Maks 2 MB.
            </p>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('cases.show', $case) }}"
               class="px-3 py-1.5 rounded-lg border text-xs text-slate-600 hover:bg-slate-50">
                Batal
            </a>
            <button class="px-4 py-1.5 bg-msa-blue text-white rounded-lg text-xs font-semibold">
                Simpan SP
            </button>
        </div>
    </form>
</div>
@endsection
