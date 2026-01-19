@can('update', $action)
<div
    x-data="{
        open: false,
        handlerType: @js(old('handler_type', $action->handler_type)),
    }"
    x-on:open-edit-legal-action.window="open=true"
    x-on:close-edit-legal-action.window="open=false"
    x-on:keydown.escape.window="open=false"
>

    {{-- Backdrop --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-40 bg-black/50"></div>

    {{-- Modal wrapper --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-2 sm:p-4">
        <div
            class="w-full sm:max-w-3xl rounded-2xl bg-white shadow-xl overflow-hidden
                   max-h-[95vh] sm:max-h-[90vh] flex flex-col"
            @click.outside="open=false"
        >

            {{-- Header (sticky) --}}
            <div class="shrink-0 border-b border-slate-200 px-4 sm:px-5 py-4 bg-white">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Edit Data Tindakan Legal</h3>
                        <p class="text-sm text-slate-500">Tidak mengubah status.</p>
                    </div>

                    <button type="button" @click="open=false"
                        class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                        Tutup
                    </button>
                </div>
            </div>

            <form method="POST" action="{{ route('legal-actions.update', $action) }}" class="flex-1 overflow-y-auto">
                @csrf
                @method('PUT')
                @php
                    $iBase = "mt-1 w-full rounded-xl border bg-white px-3 py-2.5 text-sm focus:outline-none";
                    $iOk   = "border-slate-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200";
                    $iErr  = "border-rose-400 focus:border-rose-500 focus:ring-2 focus:ring-rose-200";
                    $help  = "mt-1 text-xs text-slate-500";
                    $errTx = "mt-1 text-xs font-semibold text-rose-600";
                @endphp

                {{-- Body (scrollable) --}}
                <div class="px-4 sm:px-5 py-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

                        {{-- External --}}
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">External Ref No</label>

                            <input
                                type="text"
                                name="external_ref_no"
                                value="{{ old('external_ref_no', $action->external_ref_no) }}"
                                class="{{ $iBase }} {{ $errors->has('external_ref_no') ? $iErr : $iOk }}"
                                placeholder="Nomor surat / register eksternal"
                            >

                            @error('external_ref_no')
                                <div class="{{ $errTx }}">{{ $message }}</div>
                            @else
                                <div class="{{ $help }}">Isi jika ada nomor surat / register dari pihak eksternal.</div>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Institusi Eksternal</label>
                            <input
                                type="text"
                                name="external_institution"
                                value="{{ old('external_institution', $action->external_institution) }}"
                                class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm
                                       focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Pengadilan / Kejaksaan / Law Firm"
                            >
                        </div>

                        {{-- Handler Type --}}
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Handler Type</label>

                            <select
                                name="handler_type"
                                x-model="handlerType"
                                class="{{ $iBase }} {{ $errors->has('handler_type') ? $iErr : $iOk }}"
                            >
                                <option value="">-- Pilih --</option>
                                <option value="internal">Internal</option>
                                <option value="external">External</option>
                            </select>

                            @error('handler_type')
                                <div class="{{ $errTx }}">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Law Firm (conditional) --}}
                        <div x-show="handlerType === 'external'" x-cloak>
                            <label class="block text-sm font-semibold text-slate-700">
                                Law Firm <span class="text-rose-600">*</span>
                            </label>
                            <input
                                type="text"
                                name="law_firm_name"
                                value="{{ old('law_firm_name', $action->law_firm_name) }}"
                                class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm
                                       focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Nama kantor hukum / advokat"
                            >
                            <p class="mt-1 text-xs text-slate-500">Wajib diisi jika penanganan oleh pihak eksternal.</p>
                        </div>

                        {{-- Nama penangan --}}
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Nama Penangan</label>
                            <input
                                type="text"
                                name="handler_name"
                                value="{{ old('handler_name', $action->handler_name) }}"
                                class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm
                                       focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Nama petugas/penanggung jawab"
                            >
                        </div>

                        {{-- HP penangan --}}
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">No. HP Penangan</label>
                            <input
                                type="text"
                                name="handler_phone"
                                value="{{ old('handler_phone', $action->handler_phone) }}"
                                class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm
                                       focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="08xxxxxxxxxx"
                            >
                        </div>

                        {{-- Summary --}}
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-slate-700">Ringkasan</label>

                            <textarea
                                name="summary"
                                rows="3"
                                class="{{ $iBase }} {{ $errors->has('summary') ? $iErr : $iOk }}"
                                placeholder="Ringkasan tindakan / progres"
                            >{{ old('summary', $action->summary) }}</textarea>

                            @error('summary')
                                <div class="{{ $errTx }}">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Notes --}}
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-slate-700">Catatan</label>
                            <textarea
                                name="notes"
                                rows="3"
                                class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm
                                       focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Catatan internal"
                            >{{ old('notes', $action->notes) }}</textarea>
                        </div>

                        {{-- Result (FULL WIDTH + RESPONSIVE) --}}
                        <div class="md:col-span-2">
                            <div class="mt-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-sm font-bold text-slate-800">Hasil</h4>
                                    <span class="text-xs font-semibold text-slate-500">Opsional</span>
                                </div>

                                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700">Tipe Hasil</label>
                                        <select
                                            name="result_type"
                                            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm
                                                   focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option value="">-- Pilih --</option>
                                            <option value="paid" @selected(old('result_type',$action->result_type)==='paid')>Lunas / Bayar</option>
                                            <option value="partial" @selected(old('result_type',$action->result_type)==='partial')>Parsial</option>
                                            <option value="reject" @selected(old('result_type',$action->result_type)==='reject')>Menolak</option>
                                            <option value="no_response" @selected(old('result_type',$action->result_type)==='no_response')>Tidak ada respon</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700">Tanggal Recovery</label>
                                        <input
                                            type="date"
                                            name="recovery_date"
                                            value="{{ old('recovery_date', $action->recovery_date) }}"
                                            class="{{ $iBase }} {{ $errors->has('recovery_date') ? $iErr : $iOk }}"
                                        >
                                        @error('recovery_date')
                                            <div class="{{ $errTx }}">{{ $message }}</div>
                                        @enderror
                                    </div>

                                   <div>
                                        <label class="block text-sm font-semibold text-slate-700">Nominal Recovery</label>

                                        <div class="relative mt-1">
                                            <span class="absolute inset-y-0 left-3 flex items-center text-sm font-semibold text-slate-500">Rp</span>

                                            <input
                                                type="number"
                                                name="recovery_amount"
                                                step="0.01"
                                                value="{{ old('recovery_amount', $action->recovery_amount) }}"
                                                class="w-full rounded-xl border bg-white pl-10 pr-3 py-2.5 text-sm text-right focus:outline-none
                                                    {{ $errors->has('recovery_amount') ? $iErr : $iOk }}"
                                                placeholder="0"
                                            >
                                        </div>

                                        @error('recovery_amount')
                                            <div class="{{ $errTx }}">{{ $message }}</div>
                                        @enderror
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                {{-- Footer (sticky) --}}
                <div class="shrink-0 border-t border-slate-200 bg-white px-4 sm:px-5 py-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-end gap-3">
                        <button
                            type="button"
                            @click="open=false"
                            class="w-full sm:w-auto rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            Batal
                        </button>

                        <button
                            type="submit"
                            class="w-full sm:w-auto rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700
                                   focus:outline-none focus:ring-2 focus:ring-indigo-400"
                        >
                            Simpan
                        </button>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>
@endcan
