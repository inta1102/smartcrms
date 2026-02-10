@php
    $exec   = $action->htExecution;
    $locked = (bool) optional($exec)->locked_at;

    $docs = $action->htDocuments->sortByDesc('created_at');

    $user = auth()->user();

    // ✅ Verifikator dokumen: TL atau KASI (karena tidak semua AO punya TL)
    // Sesuaikan role kalau di sistemmu nama role-nya beda.
    $isVerifier = $user && method_exists($user, 'hasAnyRole')
        ? $user->hasAnyRole(['TL','TLL','TLR','TLRO','TLSO','TLFE','TLBE','TLUM','TLF','KSL','KSR'])
        : in_array(strtolower((string)($user?->level)), ['tl','ksl','ksr'], true);

    // Kalau kamu SUDAH bereskan Policy verifyDocument buat TL/Kasi,
    // kamu bisa balik pakai @can saja dan hapus $isVerifier.
    $badge = function($st){
        return match($st){
            'draft'     => 'bg-slate-100 text-slate-700 border-slate-200',
            'uploaded'  => 'bg-blue-50 text-blue-700 border-blue-200',
            'verified'  => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'rejected'  => 'bg-rose-50 text-rose-700 border-rose-200',
            default     => 'bg-slate-100 text-slate-700 border-slate-200',
        };
    };
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="text-sm font-semibold text-slate-900">Dokumen & Checklist</div>
            <div class="mt-1 text-sm text-slate-600">
                Dokumen required harus <span class="font-semibold">VERIFIED</span> (TL/Kasi) sebelum status bisa
                <span class="font-semibold">SUBMITTED</span>.
            </div>
        </div>
        @if($locked)
            <span class="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800">
                🔒 Terkunci (tambah/hapus dokumen dibatasi)
            </span>
        @endif
    </div>

    {{-- Add Document --}}
    <div class="mt-5 rounded-xl border border-slate-200 p-4">
        <div class="text-sm font-semibold text-slate-900">Tambah Dokumen</div>

        @if($locked)
            <div class="mt-2 text-xs text-slate-500">
                Dokumen baru tidak bisa ditambahkan saat terkunci.
                (Jika perlu, rollback ke prepared oleh supervisor)
            </div>
        @else
            <form action="{{ route('legal-actions.ht.documents.store', $action) }}"
                method="POST"
                enctype="multipart/form-data"
                class="mt-4 space-y-4">
                @csrf

                {{-- ROW 1 --}}
                <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
                    <div class="md:col-span-2">
                        <label class="text-xs text-slate-500">Doc Type</label>
                        <input type="text" name="doc_type"
                            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                            placeholder="mis: sertifikat_tanah">
                    </div>

                    <div>
                        <label class="text-xs text-slate-500">No Dok</label>
                        <input type="text" name="doc_no"
                            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="text-xs text-slate-500">Tgl</label>
                        <input type="date" name="doc_date"
                            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    </div>

                    {{-- File --}}
                    <div class="md:col-span-2" x-data="{ fileName: '' }">
                        <label class="text-xs text-slate-500">File (opsional)</label>

                        <div class="mt-1 flex items-center gap-2">
                            <input type="file" name="file" class="hidden"
                                x-ref="file"
                                @change="fileName = $refs.file.files?.[0]?.name || ''">

                            <button type="button"
                                    class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                    @click="$refs.file.click()">
                                📎 Pilih
                            </button>

                            <div class="flex-1 truncate rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                <span x-text="fileName || 'Belum ada file dipilih'"></span>
                            </div>
                        </div>

                        <p class="mt-1 text-xs text-slate-400">PDF / JPG / PNG</p>
                    </div>
                </div>

                {{-- ROW 2 --}}
                <div>
                    <label class="text-xs text-slate-500">Keterangan</label>
                    <input type="text" name="remarks"
                        class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                        placeholder="catatan singkat...">
                </div>

                {{-- ACTION ROW --}}
                <div class="flex items-center justify-between pt-2 border-t border-slate-100">
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_required" value="1"
                            class="rounded border-slate-300">
                        <span class="text-xs text-slate-700 font-semibold">Required</span>
                    </label>

                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-white/20">+</span>
                        Tambah
                    </button>
                </div>
            </form>
        @endif
    </div>

    {{-- List --}}
    <div class="mt-5">
        <div class="text-sm font-semibold text-slate-900">Daftar Dokumen</div>

        @if($docs->isEmpty())
            <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                Belum ada dokumen.
            </div>
        @else
            <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Doc Type</th>
                            <th class="px-4 py-3 text-left">No / Tgl</th>
                            <th class="px-4 py-3 text-left">Required</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">File</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach($docs as $doc)
                            <tr class="bg-white">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-900">{{ $doc->doc_type }}</div>
                                    @if($doc->remarks)
                                        <div class="text-xs text-slate-500">{{ $doc->remarks }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-slate-800">{{ $doc->doc_no ?? '-' }}</div>
                                    <div class="text-xs text-slate-500">{{ $doc->doc_date?->format('d/m/Y') ?? '-' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    @if($doc->is_required)
                                        <span class="inline-flex items-center rounded-lg bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800 border border-amber-200">YES</span>
                                    @else
                                        <span class="text-xs text-slate-500">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-lg border px-2 py-1 text-xs font-semibold {{ $badge($doc->status) }}">
                                        {{ strtoupper($doc->status) }}
                                    </span>
                                    @if($doc->status === 'rejected' && $doc->verify_notes)
                                        <div class="mt-1 text-xs text-rose-600">{{ $doc->verify_notes }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if($doc->file_path)
                                        <a class="text-indigo-600 hover:underline text-sm"
                                        href="{{ route('legal-actions.ht.documents.view', [$action, $doc]) }}"
                                        target="_blank" rel="noopener">
                                            Lihat
                                        </a>
                                    @else
                                        <span class="text-xs text-slate-500">-</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right">
                                    @php
                                        $isVerified = strtolower((string)($doc->status ?? '')) === 'verified';
                                        $canEditDoc = (! $locked) && (! $isVerified); // edit/hapus hanya jika tidak locked & belum verified
                                    @endphp

                                    <div class="flex flex-wrap gap-2 justify-end items-center">

                                        {{-- Update file/meta (only when can edit) --}}
                                        @if($canEditDoc)
                                            <div class="inline-flex items-center gap-2">
                                                {{-- FORM: Ganti file --}}
                                                <form action="{{ route('legal-actions.ht.documents.update', [$action, $doc]) }}"
                                                    method="POST" enctype="multipart/form-data"
                                                    x-data="{ fileName: '' }"
                                                    class="inline-flex items-center gap-2">
                                                    @csrf
                                                    @method('PUT')

                                                    <input type="file"
                                                        name="file"
                                                        class="hidden"
                                                        x-ref="file"
                                                        @change="fileName = $refs.file.files?.[0]?.name || ''">

                                                    <button type="button"
                                                            class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                                            @click="$refs.file.click()">
                                                        📎 <span>Pilih File</span>
                                                    </button>

                                                    <div class="max-w-[220px] truncate rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-600"
                                                        x-text="fileName || 'Tidak ada file dipilih'">
                                                    </div>

                                                    <button type="submit"
                                                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                                            :disabled="!fileName">
                                                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-md bg-white/15">↻</span>
                                                        Ganti File
                                                    </button>
                                                </form>

                                                {{-- FORM: Hapus --}}
                                                <form action="{{ route('legal-actions.ht.documents.delete', [$action, $doc]) }}"
                                                    method="POST" class="inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            onclick="return confirm('Hapus dokumen ini?')"
                                                            class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                                        🗑️ Hapus
                                                    </button>
                                                </form>
                                            </div>
                                        @elseif($isVerified)
                                            <span title="Dokumen terkunci (sudah verified)"
                                                class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                                🔒 Verified
                                            </span>
                                        @endif

                                        {{-- ✅ Verify / Reject (TL/Kasi verifikator) --}}
                                        @if($isVerifier)
                                            <div class="inline-flex items-center gap-2">
                                                {{-- VERIFY --}}
                                                @if (! $isVerified)
                                                    <form action="{{ route('legal-actions.ht.documents.verify', [$action, $doc]) }}"
                                                        method="POST" class="inline-flex items-center">
                                                        @csrf
                                                        <input type="hidden" name="status" value="verified">
                                                        <button type="submit"
                                                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                                            ✔ Verify
                                                        </button>
                                                    </form>
                                                @else
                                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                                                        ✔ Sudah Diverifikasi
                                                    </span>
                                                @endif

                                                {{-- REJECT (hanya jika belum verified) --}}
                                                @if (! $isVerified)
                                                    <form action="{{ route('legal-actions.ht.documents.verify', [$action, $doc]) }}"
                                                        method="POST"
                                                        class="inline-flex items-center gap-2">
                                                        @csrf
                                                        <input type="hidden" name="status" value="rejected">

                                                        <input type="text"
                                                            name="verify_notes"
                                                            class="w-44 rounded-lg border border-slate-200 px-2 py-1 text-xs"
                                                            placeholder="Alasan reject"
                                                            required>

                                                        <button type="submit"
                                                                class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">
                                                            Reject
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endif

                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- B. Checklist Administratif (TL/Kasi) --}}
    @include('legal.checklists.partials.tl_checklist')
</div>
