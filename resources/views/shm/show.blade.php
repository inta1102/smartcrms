@extends('layouts.app') 

@section('content')
<div class="mx-auto max-w-6xl px-4 py-6">
    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <a href="{{ route('shm.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">← Kembali</a>
            <h1 class="mt-2 text-xl font-bold text-slate-900">Detail Pengajuan: {{ $req->request_no }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                Status:
                <span class="font-semibold text-slate-800">{{ $req->status }}</span>
            </p>
        </div>

        <div class="flex gap-2">
            <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                Dibuat: {{ optional($req->created_at)->format('d M Y H:i') }}
            </span>
        </div>
    </div>

    @if(session('status'))
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    {{-- =========================
        ACTION BUTTONS
    ========================= --}}
    <div class="mb-4 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
        <h2 class="text-sm font-bold text-slate-900">⚙️ Tindakan</h2>

        <div class="mt-3 flex flex-wrap gap-2">

            {{-- =====================
                KBO / KSA ACTIONS
            ====================== --}}
            @can('sadAction', \App\Models\ShmCheckRequest::class)

                {{-- SUBMITTED -> modal pilih notaris --}}
                @if($req->status === \App\Models\ShmCheckRequest::STATUS_SUBMITTED)
                    <button type="button"
                        onclick="openModal('sentToNotaryModal')"
                        class="btn-primary">
                        ➡️ Teruskan ke Notaris
                    </button>
                @endif

                {{-- Upload SP/SK hanya SENT_TO_NOTARY --}}
                @if($req->status === \App\Models\ShmCheckRequest::STATUS_SENT_TO_NOTARY)
                    <button type="button"
                        onclick="openModal('uploadSpSkModal')"
                        class="btn-primary">
                        ⬆️ Upload SP & SK
                    </button>
                @endif

                {{-- Serahkan ke BPN hanya HANDED_TO_SAD --}}
                @if($req->status === \App\Models\ShmCheckRequest::STATUS_HANDED_TO_SAD)
                    <form method="POST" action="{{ route('shm.sentToBpn', $req) }}">
                        @csrf
                        <button type="submit" class="btn-primary">➡️ Serahkan ke BPN</button>
                    </form>
                @endif

                {{-- Upload hasil hanya SENT_TO_BPN --}}
                @if($req->status === \App\Models\ShmCheckRequest::STATUS_SENT_TO_BPN)
                    <button type="button"
                        onclick="openModal('uploadResultModal')"
                        class="btn-primary">
                        ⬆️ Upload Hasil Cek
                    </button>
                @endif

            @endcan

            {{-- =====================
                AO ACTIONS
            ====================== --}}
            {{-- AO Upload Signed hanya SP_SK_UPLOADED --}}
            @can('aoSignedUpload', $req)
                @if($req->status === \App\Models\ShmCheckRequest::STATUS_SP_SK_UPLOADED)
                    <button type="button"
                        onclick="openModal('uploadSignedModal')"
                        class="btn-secondary">
                        ✍️ Upload SP & SK Bertandatangan
                    </button>
                @endif
            @endcan

            {{-- AO Serahkan fisik hanya SIGNED_UPLOADED --}}
            @can('aoSignedUpload', $req)
                @if($req->status === \App\Models\ShmCheckRequest::STATUS_SIGNED_UPLOADED)
                    <form method="POST" action="{{ route('shm.handedToSad', $req) }}">
                        @csrf
                        <button type="submit" class="btn-secondary">
                            📦 Serahkan Fisik SP & SK ke KSA/KBO
                        </button>
                    </form>
                @endif
            @endcan

        </div>
    </div>

    @php
        /**
        * Helper buka modal otomatis kalau ada error validasi dari modal tertentu.
        * - uploadSpSk: sp_file / sk_file / spdd_file (baru)
        * - uploadSigned: signed_sp_file / signed_sk_file / signed_spdd_file (baru)
        * - uploadResult: result_file
        * - sentToNotary: notary_name / notes
        */
        $openSpSk   = $errors->has('sp_file') || $errors->has('sk_file') || $errors->has('spdd_file');
        $openSigned = $errors->has('signed_sp_file') || $errors->has('signed_sk_file') || $errors->has('signed_spdd_file');
        $openResult = $errors->has('result_file');
        $openSentToNotary = $errors->has('notary_name') || $errors->has('notes');
    @endphp

    @php
        // class helper
        $modalBackdrop = "fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4";
        $modalCard = "w-full max-w-lg rounded-2xl bg-white shadow-xl";
        $modalHead = "flex items-center justify-between border-b border-slate-100 px-5 py-4";
        $modalBody = "px-5 py-4";
        $modalFoot = "flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-4";
    @endphp

    {{-- =========================
    MODAL: Send to Notaris (KSA/KBO)
    ========================= --}}
    <div
        x-data="{ open: {{ $openSentToNotary ? 'true' : 'false' }} }"
        x-init="
            window.addEventListener('open-modal', e => { if(e.detail==='sentToNotaryModal') open=true })
            window.addEventListener('close-modal', e => { if(e.detail==='sentToNotaryModal') open=false })
        "
        x-show="open"
        x-cloak
        class="{{ $modalBackdrop }}"
        x-transition.opacity
        x-trap.noscroll="open"
        @keydown.escape.window="open=false"
    >
        <div class="{{ $modalCard }}" @click.away="open=false">
            <div class="{{ $modalHead }}">
                <div>
                    <div class="text-sm font-bold text-slate-900">➡️ Teruskan ke Notaris</div>
                    <div class="mt-0.5 text-xs text-slate-500">Pilih notaris tujuan sebelum meneruskan.</div>
                </div>
                <button type="button" class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100" @click="open=false">✕</button>
            </div>

            <form method="POST" action="{{ route('shm.sentToNotary', $req) }}">
                @csrf
                <div class="{{ $modalBody }}">
                    <div class="grid gap-4">
                        <div>
                            <label class="text-xs font-semibold text-slate-600">
                                Nama Notaris <span class="text-rose-600">*</span>
                            </label>

                            <select
                                name="notary_name"
                                required
                                class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                            >
                                <option value="">-- Pilih Notaris --</option>

                                @foreach (\App\Models\ShmCheckRequest::NOTARIES as $notaris)
                                    <option
                                        value="{{ $notaris }}"
                                        @selected(old('notary_name', $req->notary_name) === $notaris)
                                    >
                                        {{ $notaris }}
                                    </option>
                                @endforeach
                            </select>

                            @error('notary_name')
                                <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-600">Catatan (opsional)</label>
                            <textarea
                                name="notes"
                                rows="3"
                                class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                                placeholder="Catatan untuk notaris / internal">{{ old('notes') }}</textarea>
                            @error('notes')
                                <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            Notaris ditetapkan oleh KSA/KBO (bukan AO).
                        </div>
                    </div>
                </div>

                <div class="{{ $modalFoot }}">
                    <button type="button" class="btn-secondary" @click="open=false">Batal</button>
                    <button type="submit" class="btn-primary">Teruskan</button>
                </div>
            </form>
        </div>
    </div>

    {{-- =========================
    MODAL: Upload SP & SK (KSA/KBO)
    ========================= --}}
    <div
        x-data="{ open: {{ $openSpSk ? 'true' : 'false' }} }"
        x-init="
            window.addEventListener('open-modal', e => { if(e.detail==='uploadSpSkModal') open=true })
            window.addEventListener('close-modal', e => { if(e.detail==='uploadSpSkModal') open=false })
        "
        x-show="open"
        x-cloak
        class="{{ $modalBackdrop }}"
        x-transition.opacity
        x-trap.noscroll="open"
        @keydown.escape.window="open=false"
    >
        <div class="{{ $modalCard }}" @click.away="open=false">
            <div class="{{ $modalHead }}">
                <div>
                    <div class="text-sm font-bold text-slate-900">⬆️ Upload SP & SK</div>
                    <div class="mt-0.5 text-xs text-slate-500">Upload dokumen dari notaris (pdf).</div>
                </div>
                <button type="button" class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100" @click="open=false">✕</button>
            </div>

            <form method="POST" action="{{ route('shm.uploadSpSk', $req) }}" enctype="multipart/form-data">
                @csrf
                <div class="{{ $modalBody }}">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="text-xs font-semibold text-slate-600">File SP <span class="text-rose-600">*</span></label>
                            <input type="file" name="sp_file" accept="application/pdf"
                                class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                            @error('sp_file')
                                <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-600">File SK <span class="text-rose-600">*</span></label>
                            <input type="file" name="sk_file" accept="application/pdf"
                                class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                            @error('sk_file')
                                <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                            @enderror
                        </div>

                        @if($req->is_jogja)
                        <div>
                            <label class="text-xs font-semibold text-slate-600">Surat Perlindungan Data Diri (SPDD) - optional (PDF)</label>
                            <input type="file" name="spdd_file" accept="application/pdf"
                                class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                            @error('spdd_file')
                                <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                            @enderror
                        </div>
                        @endif

                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-slate-600">Catatan</label>
                            <textarea name="notes" rows="3"
                                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                                    placeholder="Catatan (opsional)">{{ old('notes') }}</textarea>
                            @error('notes')
                                <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        Dokumen tersimpan di <span class="font-semibold">storage/app</span> dan diunduh via sistem.
                    </div>
                </div>

                <div class="{{ $modalFoot }}">
                    <button type="button" class="btn-secondary" @click="open=false">Batal</button>
                    <button type="submit" class="btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>

    {{-- =========================
    MODAL: Upload Signed SP & SK (AO)
    ========================= --}}
    <div
        x-data="{ open: {{ $openSigned ? 'true' : 'false' }} }"
        x-init="
            window.addEventListener('open-modal', e => { if(e.detail==='uploadSignedModal') open=true })
            window.addEventListener('close-modal', e => { if(e.detail==='uploadSignedModal') open=false })
        "
        x-show="open"
        x-cloak
        class="{{ $modalBackdrop }}"
        x-transition.opacity
        x-trap.noscroll="open"
        @keydown.escape.window="open=false"
    >
        <div class="{{ $modalCard }}" @click.away="open=false">
            <div class="{{ $modalHead }}">
                <div>
                    <div class="text-sm font-bold text-slate-900">✍️ Upload SP & SK Bertandatangan</div>
                    <div class="mt-0.5 text-xs text-slate-500">Upload dokumen yang sudah ditandatangani debitur.</div>
                </div>
                <button type="button" class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100" @click="open=false">✕</button>
            </div>

            <form method="POST" action="{{ route('shm.uploadSigned', $req) }}" enctype="multipart/form-data">
                @csrf
                <div class="{{ $modalBody }}">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="text-xs font-semibold text-slate-600">Signed SP <span class="text-rose-600">*</span></label>
                            <input type="file" name="signed_sp_file" accept=".pdf,.jpg,.jpeg,.png"
                                class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                            @error('signed_sp_file')
                                <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-600">Signed SK <span class="text-rose-600">*</span></label>
                            <input type="file" name="signed_sk_file" accept=".pdf,.jpg,.jpeg,.png"
                                class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                            @error('signed_sk_file')
                                <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- ✅ Signed SPDD (Jogja only) --}}
                        @if($req->is_jogja)
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-slate-600">Signed SPDD (optional, PDF)</label>
                                <input type="file" name="signed_spdd_file" accept="application/pdf"
                                    class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                @error('signed_spdd_file')
                                    <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                                @enderror
                            </div>
                        @endif

                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-slate-600">Catatan</label>
                            <textarea name="notes" rows="3"
                                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                                    placeholder="Catatan (opsional)">{{ old('notes') }}</textarea>
                            @error('notes')
                                <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="{{ $modalFoot }}">
                    <button type="button" class="btn-secondary" @click="open=false">Batal</button>
                    <button type="submit" class="btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>

    {{-- =========================
    MODAL: Upload Hasil Cek (KSA/KBO)
    ========================= --}}
    <div
        x-data="{ open: {{ $openResult ? 'true' : 'false' }} }"
        x-init="
            window.addEventListener('open-modal', e => { if(e.detail==='uploadResultModal') open=true })
            window.addEventListener('close-modal', e => { if(e.detail==='uploadResultModal') open=false })
        "
        x-show="open"
        x-cloak
        class="{{ $modalBackdrop }}"
        x-transition.opacity
        x-trap.noscroll="open"
        @keydown.escape.window="open=false"
    >
        <div class="{{ $modalCard }}" @click.away="open=false">
            <div class="{{ $modalHead }}">
                <div>
                    <div class="text-sm font-bold text-slate-900">⬆️ Upload Hasil Cek SHM</div>
                    <div class="mt-0.5 text-xs text-slate-500">Upload hasil dari notaris/BPN.</div>
                </div>
                <button type="button" class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100" @click="open=false">✕</button>
            </div>

            <form method="POST" action="{{ route('shm.uploadResult', $req) }}" enctype="multipart/form-data">
                @csrf
                <div class="{{ $modalBody }}">
                    <div>
                        <label class="text-xs font-semibold text-slate-600">File Hasil <span class="text-rose-600">*</span></label>
                        <input type="file" name="result_file" accept=".pdf,.jpg,.jpeg,.png"
                            class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        @error('result_file')
                            <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mt-4">
                        <label class="text-xs font-semibold text-slate-600">Catatan</label>
                        <textarea name="notes" rows="3"
                                class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none"
                                placeholder="Catatan (opsional)">{{ old('notes') }}</textarea>
                        @error('notes')
                            <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="{{ $modalFoot }}">
                    <button type="button" class="btn-secondary" @click="open=false">Batal</button>
                    <button type="submit" class="btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- LEFT --}}
        <div class="lg:col-span-2 space-y-4">
            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <h2 class="text-base font-bold text-slate-900">🧾 Data Debitur & Agunan</h2>

                <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold text-slate-500">Nama Debitur</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $req->debtor_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold text-slate-500">No HP</dt>
                        <dd class="mt-1 text-sm text-slate-700">{{ $req->debtor_phone ?? '-' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold text-slate-500">Alamat Agunan</dt>
                        <dd class="mt-1 text-sm text-slate-700">{{ $req->collateral_address ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold text-slate-500">No Sertifikat</dt>
                        <dd class="mt-1 text-sm text-slate-700">{{ $req->certificate_no ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold text-slate-500">Notaris</dt>
                        <dd class="mt-1 text-sm text-slate-700">{{ $req->notary_name ?? '-' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold text-slate-500">Catatan</dt>
                        <dd class="mt-1 text-sm text-slate-700 whitespace-pre-line">{{ $req->notes ?? '-' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- FILES --}}
            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold text-slate-900">📎 Dokumen</h2>
                    <div class="text-xs text-slate-500">Unduh via sistem</div>
                </div>

                @php
                    $labels = [
                        'ktp' => 'KTP',
                        'shm' => 'SHM',
                        'sp' => 'Srt Pengantar (Notaris)',
                        'sk' => 'Srt Kuasa (Notaris)',

                        // ✅ SPDD hanya untuk Jogja (akan kita filter juga di loop)
                        'spdd' => 'Surat Perlindungan Data Diri (SPDD)',

                        'signed_sp' => 'Srt Pengantar Bertandatangan',
                        'signed_sk' => 'Srt Kuasa Bertandatangan',

                        // ✅ SPDD Bertandatangan (Jogja only)
                        'signed_spdd' => 'Surat Perlindungan Data Diri (SPDD) Bertandatangan',

                        'result' => 'Hasil Cek SHM',
                    ];
                @endphp

                <div class="mt-4 space-y-4">
                    @forelse($labels as $type => $label)

                        {{-- ✅ hide card SPDD jika bukan Jogja --}}
                        @if(in_array($type, ['spdd','signed_spdd'], true) && !(bool)($req->is_jogja ?? false))
                            @continue
                        @endif

                        @php $files = $filesByType[$type] ?? collect(); @endphp
                        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                            <div class="flex items-center justify-between">
                                <div class="font-semibold text-slate-900">{{ $label }}</div>
                                <div class="text-xs text-slate-500">{{ $files->count() }} file</div>
                            </div>

                            @if($files->isEmpty())
                                <div class="mt-2 text-sm text-slate-500">Belum ada file.</div>
                            @else
                                <div class="mt-3 divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-100 bg-white">
                                    @foreach($files as $f)
                                        <div class="flex flex-col gap-2 px-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-semibold text-slate-900">
                                                    {{ $f->original_name ?? basename($f->file_path) }}
                                                </div>
                                                <div class="mt-0.5 text-xs text-slate-500">
                                                    Upload oleh: {{ optional($f->uploader)->name ?? '-' }}
                                                    • {{ optional($f->uploaded_at)->format('d M Y H:i') }}
                                                </div>
                                                @if($f->notes)
                                                    <div class="mt-1 text-xs text-slate-600">
                                                        Catatan: {{ $f->notes }}
                                                    </div>
                                                @endif
                                            </div>

                                            <div class="flex shrink-0 items-center gap-2">
                                                <a href="{{ route('shm.file.download', $f) }}"
                                                   class="inline-flex items-center rounded-xl bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-800">
                                                    Download
                                                </a>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-sm text-slate-500">Belum ada dokumen.</div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- RIGHT --}}
        <div class="space-y-4">
            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <h2 class="text-base font-bold text-slate-900">🧭 Riwayat / Log</h2>
                <p class="mt-1 text-sm text-slate-500">Aktivitas status dan tindakan pengguna.</p>

                <div class="mt-4 space-y-3">
                    @forelse($req->logs as $log)
                        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-slate-900">
                                        {{ $log->message ?? $log->action }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-500">
                                        Oleh: {{ optional($log->actor)->name ?? '-' }}
                                        • {{ optional($log->created_at)->format('d M Y H:i') }}
                                    </div>
                                </div>
                            </div>

                            <div class="mt-2 text-xs text-slate-600">
                                Dari: <span class="font-semibold">{{ $log->from_status ?? '-' }}</span>
                                → Ke: <span class="font-semibold">{{ $log->to_status ?? '-' }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-500">Belum ada riwayat.</div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <h2 class="text-base font-bold text-slate-900">👤 Pemohon</h2>
                <div class="mt-3 text-sm text-slate-700">
                    {{ optional($req->requester)->name ?? '-' }}
                </div>
                <div class="mt-1 text-xs text-slate-500">
                    Cabang: {{ $req->branch_code ?? '-' }} • AO: {{ $req->ao_code ?? '-' }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function openModal(id) {
        window.dispatchEvent(new CustomEvent('open-modal', { detail: id }));
    }
    function closeModal(id) {
        window.dispatchEvent(new CustomEvent('close-modal', { detail: id }));
    }
</script>
@endpush
