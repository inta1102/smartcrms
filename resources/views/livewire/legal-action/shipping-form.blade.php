<div class="space-y-6">

    @php
        // ✅ pastikan selalu ada nilai boolean
        $locked = (bool) ($locked ?? false);
    @endphp

    {{-- Header --}}
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="text-base font-semibold text-slate-900">Pengiriman</div>
            <div class="mt-0.5 text-xs text-slate-500">Step 2 • Pengiriman Somasi</div>
        </div>

        <span class="inline-flex items-center rounded-full bg-slate-50 px-3 py-1 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200">
            📦 Shipping
        </span>

        @if($bottleneckLevel === 'warning')
            <div class="mb-3 rounded-xl bg-yellow-50 border border-yellow-200 p-3 text-sm text-yellow-800">
                <div class="font-semibold">⚠ Bottleneck</div>
                <div class="text-xs mt-1">{{ $bottleneckLabel }}</div>
            </div>
        @elseif($bottleneckLevel === 'danger')
            <div class="mb-3 rounded-xl bg-red-50 border border-red-200 p-3 text-sm text-red-800">
                <div class="font-semibold">🚨 Bottleneck</div>
                <div class="text-xs mt-1">{{ $bottleneckLabel }}</div>
            </div>
        @endif
    </div>

    {{-- ✅ Banner read-only --}}
    @if($locked)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <div class="font-semibold">🔒 Read-only</div>
            <div class="mt-1 text-xs">
                Somasi sudah ditutup (completed/cancelled/failed). Form pengiriman dikunci.
            </div>
        </div>
    @endif

    {{-- Form --}}
    <form wire:submit.prevent="saveShipping" class="space-y-6">

        {{-- Delivery channel --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <label class="text-xs font-semibold text-slate-700">Dikirim melalui</label>

            <div>
                <select
                    wire:model.live="delivery_channel"
                    @disabled($locked)
                    class="mt-2 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm focus:border-indigo-400 focus:ring-indigo-400 disabled:bg-slate-50 disabled:text-slate-500"
                >
                    <option value="">-- pilih --</option>
                    <option value="pos">POS / Ekspedisi</option>
                    <option value="petugas_bank">Petugas Bank</option>
                    <option value="kuasa_hukum">Kuasa Hukum</option>
                    <option value="ao">AO</option>
                    <option value="lainnya">Lainnya</option>
                </select>

                @error('delivery_channel')
                    <div class="mt-2 text-xs text-rose-600">{{ $message }}</div>
                @enderror

                <div class="mt-2 text-[11px] text-slate-500">
                    Pilih <span class="font-semibold">POS/Ekspedisi</span> jika ada resi. Selain itu, resi & bukti bersifat opsional.
                </div>

                <div class="text-xs text-slate-500 mt-2">
                    debug channel: <span class="font-semibold">{{ $delivery_channel }}</span>
                </div>
            </div>
        </div>

        {{-- Expedition + resi (only for POS) --}}
        @if(($delivery_channel ?? '') === 'pos')
            <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="text-sm font-semibold text-slate-900">Detail Ekspedisi</div>
                    <span class="text-[11px] text-slate-500">Wajib untuk POS</span>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-slate-700">Nama ekspedisi</label>
                        <input
                            type="text"
                            wire:model.defer="expedition_name"
                            @disabled($locked)
                            placeholder="POS / JNE / J&T"
                            class="mt-2 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm focus:border-indigo-400 focus:ring-indigo-400 disabled:bg-slate-50 disabled:text-slate-500"
                        />
                        @error('expedition_name') <div class="mt-2 text-xs text-rose-600">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-700">No Resi</label>
                        <input
                            type="text"
                            wire:model.defer="receipt_no"
                            @disabled($locked)
                            placeholder="PX123..."
                            class="mt-2 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm focus:border-indigo-400 focus:ring-indigo-400 disabled:bg-slate-50 disabled:text-slate-500"
                        />
                        @error('receipt_no') <div class="mt-2 text-xs text-rose-600">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        @endif

        {{-- Upload section --}}
        <div class="rounded-2xl border border-slate-100 bg-gradient-to-b from-slate-50 to-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-sm font-semibold text-slate-900">Bukti Pengiriman</div>
                    <div class="mt-0.5 text-[11px] text-slate-500">PDF/JPG/PNG • Maks 5MB</div>
                </div>

                @if($receipt_path)
                    <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                        ✅ 1 file terunggah
                    </span>
                @else
                    <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                        📎 Belum ada file
                    </span>
                @endif
            </div>

            <div class="mt-4">
                @if($receipt_path)
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <a
                            href="{{ asset(ltrim($receipt_path, '/')) }}"
                            target="_blank"
                            class="inline-flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50"
                            title="{{ $receipt_original ?? 'SHIPPING_RECEIPT' }}"
                        >
                            📎
                            <span class="max-w-[240px] truncate sm:max-w-[360px]">
                                {{ $receipt_original ?? 'SHIPPING_RECEIPT' }}
                            </span>
                        </a>

                        <div class="flex items-center gap-2">
                            @if(!$locked)
                                <button
                                    type="button"
                                    wire:click="removeReceipt"
                                    wire:loading.attr="disabled"
                                    wire:target="removeReceipt"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 ring-1 ring-rose-200 hover:bg-rose-100 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="removeReceipt">🗑️ Hapus</span>
                                    <span wire:loading wire:target="removeReceipt">Menghapus...</span>
                                </button>
                            @else
                                <span class="text-[11px] text-slate-500">Terkunci</span>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-[1fr_auto] md:items-center">
                        <input
                            type="file"
                            wire:model="receipt_file"
                            @disabled($locked)
                            class="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm
                                   file:mr-3 file:rounded-lg file:border-0 file:bg-slate-50 file:px-3 file:py-2
                                   file:text-xs file:font-semibold file:text-slate-700 file:ring-1 file:ring-slate-200
                                   hover:file:bg-slate-100 disabled:bg-slate-50 disabled:text-slate-500"
                        />

                        <button
                            type="button"
                            wire:click="uploadReceipt"
                            wire:loading.attr="disabled"
                            wire:target="uploadReceipt,receipt_file"
                            @disabled($locked || !$receipt_file)
                            class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60 md:w-auto"
                        >
                            <span wire:loading.remove wire:target="uploadReceipt,receipt_file">⬆️ Upload</span>
                            <span wire:loading wire:target="uploadReceipt,receipt_file">Uploading...</span>
                        </button>
                    </div>

                    @error('receipt_file')
                        <div class="mt-2 text-xs text-rose-600">{{ $message }}</div>
                    @enderror

                    <div class="mt-2 text-[11px] text-slate-500">
                        Tips: gunakan foto resi / screenshot tracking / POD.
                    </div>
                @endif
            </div>
        </div>

        {{-- Notes --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <label class="text-xs font-semibold text-slate-700">Catatan pengiriman</label>
            <textarea
                wire:model.defer="notes"
                @disabled($locked)
                rows="3"
                placeholder="misal: dikirim via POS, resi terlampir"
                class="mt-2 w-full rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm focus:border-indigo-400 focus:ring-indigo-400 disabled:bg-slate-50 disabled:text-slate-500"
            ></textarea>
            @error('notes') <div class="mt-2 text-xs text-rose-600">{{ $message }}</div> @enderror
        </div>

        {{-- Save --}}
        <div class="space-y-3">
            @if(!$locked)
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveShipping"
                    @disabled(!$this->canSave)
                    class="w-full rounded-2xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="saveShipping">Simpan Pengiriman</span>
                    <span wire:loading wire:target="saveShipping">Menyimpan...</span>
                </button>

                <div class="text-center text-[11px] text-slate-500">
                    @if(($delivery_channel ?? '') === 'pos')
                        Tombol aktif jika <span class="font-semibold">ekspedisi, resi,</span> dan <span class="font-semibold">bukti</span> sudah diisi.
                    @else
                        Tombol aktif setelah memilih <span class="font-semibold">metode pengiriman</span>.
                    @endif
                </div>
            @else
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm font-semibold text-slate-600">
                    Form terkunci. Tidak bisa melakukan perubahan pengiriman.
                </div>
            @endif

            @if($saved)
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700">
                    ✅ Pengiriman tersimpan.
                </div>
            @endif
        </div>

    </form>
</div>
