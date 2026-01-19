{{-- TAB: Documents --}}
<div
    x-data="{
        open: {{ $errors->any() ? 'true' : 'false' }},
        close(){ this.open=false },
        show(){ this.open=true }
    }"
    class="rounded-2xl border border-slate-200 bg-white p-4"
>
    {{-- Header --}}
    <div class="mb-4 flex items-start justify-between gap-3">
        <div>
            <div class="text-sm font-bold text-slate-900">Dokumen</div>
            <div class="mt-0.5 text-xs text-slate-500">Total: {{ $action->documents->count() }}</div>
        </div>

        @can('update', $action)
            <button
                type="button"
                @click="show()"
                class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-slate-800"
            >
                <span class="text-sm">＋</span>
                Upload
            </button>
        @endcan
    </div>

    {{-- List / Table wrapper --}}
    <div class="overflow-hidden rounded-xl border border-slate-200">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-600">
                    <tr>
                        <th class="px-3 py-2">Tipe</th>
                        <th class="px-3 py-2">Judul</th>
                        <th class="px-3 py-2">File</th>
                        <th class="px-3 py-2">Uploader</th>
                        <th class="px-3 py-2">Tanggal</th>
                        <th class="px-3 py-2 text-right">Aksi</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($action->documents as $doc)
                        @php
                            $kb = $doc->file_size ? (int) round($doc->file_size / 1024) : null;

                            // badge style by doc_type (simple mapping)
                            $type = (string) $doc->doc_type;
                            $badgeCls = 'bg-slate-100 text-slate-700 ring-slate-200';
                            if (str_starts_with($type, 'somasi_')) $badgeCls = 'bg-indigo-50 text-indigo-700 ring-indigo-200';
                            if (str_contains($type, 'bukti') || str_contains($type, 'receipt')) $badgeCls = 'bg-emerald-50 text-emerald-700 ring-emerald-200';
                        @endphp

                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-3 align-top">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $badgeCls }}">
                                    {{ $doc->doc_type_label }}
                                </span>
                            </td>

                            <td class="px-3 py-3 align-top">
                                <div class="font-semibold text-slate-900">
                                    {{ $doc->title }}
                                </div>

                                @if(!empty($doc->hash_sha256))
                                    <div class="mt-1 text-[11px] text-slate-500">
                                        <span class="font-mono">
                                            sha256:
                                            {{ substr($doc->hash_sha256, 0, 12) }}…{{ substr($doc->hash_sha256, -12) }}
                                        </span>
                                    </div>
                                @endif
                            </td>

                            <td class="px-3 py-3 align-top">
                                <div class="font-semibold text-slate-900">
                                    {{ $doc->file_name }}
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{ $doc->mime_type ?? '-' }}
                                    •
                                    {{ $kb ? number_format($kb).' KB' : '-' }}
                                </div>
                            </td>

                            <td class="px-3 py-3 align-top text-slate-700">
                                {{ $doc->uploader?->name ?? '-' }}
                            </td>

                            <td class="px-3 py-3 align-top text-slate-600 whitespace-nowrap">
                                {{ optional($doc->uploaded_at)->format('d M Y H:i') }}
                            </td>

                            <td class="px-3 py-3 align-top">
                                <div class="flex items-center justify-end gap-2">
                                    <a
                                        href="{{ route('legal-actions.documents.download', [$action, $doc]) }}"
                                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                    >
                                        ⬇️ <span class="hidden sm:inline">Download</span>
                                        <span class="sm:hidden">Down</span>
                                    </a>

                                    @can('update', $action)
                                        <form
                                            method="POST"
                                            action="{{ route('legal-actions.documents.destroy', [$action, $doc]) }}"
                                            onsubmit="return confirm('Hapus dokumen ini?')"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                class="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100"
                                            >
                                                🗑️ <span class="hidden sm:inline">Hapus</span>
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-10 text-center text-sm text-slate-500">
                                Belum ada dokumen.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal Upload --}}
    @can('update', $action)
        <div
            x-show="open"
            x-cloak
            x-trap.noscroll="open"
            class="relative z-50"
            aria-label="Upload Dokumen Modal"
            role="dialog"
            aria-modal="true"
        >
            {{-- Overlay --}}
            <div class="fixed inset-0 bg-black/40" @click="close()"></div>

            {{-- Panel --}}
            <div class="fixed inset-0 flex items-center justify-center p-4">
                <div class="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-black/5">
                    <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                        <div>
                            <h3 class="text-base font-bold text-slate-900">Upload Dokumen</h3>
                            <p class="mt-0.5 text-sm text-slate-500">PDF/JPG/PNG maksimal 10MB.</p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100"
                            @click="close()"
                            aria-label="Tutup"
                        >✕</button>
                    </div>

                    <form
                        method="POST"
                        action="{{ route('legal-actions.documents.store', $action) }}"
                        enctype="multipart/form-data"
                        class="px-5 py-4"
                    >
                        @csrf

                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <label class="text-sm font-semibold text-slate-700">Tipe Dokumen</label>
                                <select
                                    name="doc_type"
                                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-indigo-400"
                                    required
                                >
                                    <option value="" disabled @selected(old('doc_type')=='' )>Pilih tipe</option>
                                    <option value="somasi" @selected(old('doc_type')=='somasi')>somasi</option>
                                    <option value="surat_kuasa" @selected(old('doc_type')=='surat_kuasa')>surat_kuasa</option>
                                    <option value="putusan" @selected(old('doc_type')=='putusan')>putusan</option>
                                    <option value="bukti_bayar" @selected(old('doc_type')=='bukti_bayar')>bukti_bayar</option>
                                    <option value="lainnya" @selected(old('doc_type')=='lainnya')>lainnya</option>
                                </select>
                                @error('doc_type')
                                    <div class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</div>
                                @enderror
                            </div>

                            <div>
                                <label class="text-sm font-semibold text-slate-700">Judul</label>
                                <input
                                    name="title"
                                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-indigo-400"
                                    placeholder="Contoh: Somasi ke-1"
                                    value="{{ old('title') }}"
                                    required
                                >
                                @error('title')
                                    <div class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</div>
                                @enderror
                            </div>

                            <div>
                                <label class="text-sm font-semibold text-slate-700">File</label>
                                <input
                                    type="file"
                                    name="file"
                                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-slate-800"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    required
                                >
                                @error('file')
                                    <div class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mt-5 flex flex-col-reverse gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
                            <button
                                type="button"
                                @click="close()"
                                class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                            >
                                Upload
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan
</div>
