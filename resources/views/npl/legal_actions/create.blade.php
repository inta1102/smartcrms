@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-6">
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h1 class="text-lg font-bold text-slate-900">Mulai Legal Action</h1>
            <p class="text-sm text-slate-500">
                Kasus NPL: <span class="font-semibold text-slate-700">{{ $case->id }}</span>
            </p>

            @if($legalCase)
                <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Legal Case sudah ada: <b>{{ $legalCase->legal_case_no }}</b>.
                    Aksi baru akan ditambahkan sebagai tindakan berikutnya.
                </div>
            @endif
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <div class="font-bold mb-1">Terjadi error:</div>
                <ul class="list-disc ml-5 space-y-1">
                    @foreach($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="legalForm" method="POST" action="{{ route('npl.legal-actions.store', $case) }}" class="px-5 py-5">
            @csrf
            <input type="hidden" name="proposal_id" value="{{ request('proposal_id') }}">
            <input type="hidden" name="ao_agenda_id" value="{{ request('ao_agenda_id') }}">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-slate-700">Jenis Tindakan</label>
                    <select name="action_type"
                            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">-- Pilih --</option>
                        @foreach($types as $k => $label)
                            <option value="{{ $k }}" @selected(old('action_type')===$k)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('action_type')
                        <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700">Catatan Awal (opsional)</label>
                    <textarea name="notes" rows="4"
                              class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
                <a href="{{ url()->previous() }}"
                   class="inline-flex justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Batal
                </a>
               <button type="button"
                        onclick="document.getElementById('legalForm').submit()"
                        class="inline-flex justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">
                    Buat Legal Action
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

