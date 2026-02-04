@extends('layouts.app')

@section('title', 'Import Data Kredit')

@section('content')
<div class="w-full max-w-2xl">

    <div class="bg-white shadow-sm rounded-2xl border border-slate-100 px-6 py-6">

        <h2 class="text-xl font-semibold text-slate-800 mb-1">
            Import Data Kredit dari Excel
        </h2>
        <p class="text-xs text-slate-500 mb-4">
            File yang didukung: <span class="font-semibold">.xls, .xlsx</span>
            Pastikan header kolom sudah sesuai template.
        </p>

        {{-- ALERT: ERROR / STATUS --}}
        @if ($errors->any())
            <div class="mb-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <div class="font-semibold mb-1">Terjadi error</div>
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li class="text-[13px]">{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('status'))
            <div class="mb-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif

        {{-- INFO: IMPORT TERAKHIR --}}
        <div class="mb-4 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold text-slate-700">Import Terakhir</div>
                    <div class="mt-0.5 text-[11px] text-slate-500">
                        Untuk memastikan posisi yang sudah masuk (audit trail).
                    </div>
                </div>

                @if (!empty($lastImport))
                    @php
                        $status = strtolower((string)($lastImport->status ?? ''));
                        $badge = $status === 'success'
                            ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
                            : ($status === 'failed'
                                ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
                                : 'bg-slate-100 text-slate-700 ring-1 ring-slate-200');
                    @endphp
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $badge }}">
                        {{ $status ? strtoupper($status) : 'TERCATAT' }}
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold bg-slate-100 text-slate-700 ring-1 ring-slate-200">
                        BELUM PERNAH
                    </span>
                @endif
            </div>

            @php
                $pos = $lastImport->position_date ?? null;
                $at  = $lastImport->created_at ?? null;

                $posText = $pos ? \Carbon\Carbon::parse($pos)->format('d M Y') : '-';
                $atText  = $at ? \Carbon\Carbon::parse($at)->format('d M Y H:i') : '-';

                $byName  = $lastImport->importer->name
                    ?? $lastImport->created_by_name
                    ?? $lastImport->created_by
                    ?? null;

                $fileName = $lastImport->file_name
                    ?? $lastImport->filename
                    ?? $lastImport->original_name
                    ?? null;
            @endphp

            <div class="mt-3 grid grid-cols-2 gap-3 text-[11px]">
                <div class="rounded-xl border border-slate-100 bg-white px-3 py-2">
                    <div class="text-slate-500">Posisi Data</div>
                    <div class="font-semibold text-slate-800">
                        {{ !empty($lastImport) ? $posText : '-' }}
                    </div>
                </div>

                <div class="rounded-xl border border-slate-100 bg-white px-3 py-2">
                    <div class="text-slate-500">Waktu Import</div>
                    <div class="font-semibold text-slate-800">
                        {{ !empty($lastImport) ? $atText : '-' }}
                    </div>
                </div>

                <div class="rounded-xl border border-slate-100 bg-white px-3 py-2 col-span-2">
                    <div class="text-slate-500">Diimport oleh</div>
                    <div class="font-semibold text-slate-800">
                        {{ !empty($lastImport) ? ($byName ?: '-') : '-' }}
                    </div>
                </div>

                @if (!empty($lastImport) && !empty($fileName))
                    <div class="rounded-xl border border-slate-100 bg-white px-3 py-2 col-span-2">
                        <div class="text-slate-500">File</div>
                        <div class="font-semibold text-slate-800 break-words">
                            {{ $fileName }}
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- NOTE URUTAN --}}
        <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800">
            <div class="text-sm font-semibold">⚠️ Proses wajib berurutan</div>
            <div class="text-xs mt-1">
                1) <b>Import Data</b> → 2) <b>Legacy Sync</b> → 3) <b>Update Jadwal</b>.
                Update Jadwal membaca status SP dari hasil Legacy Sync.
            </div>
        </div>

        @php
            // =============================
            // POSISI YANG DIPAKAI STEP 2/3
            // =============================
            $posForStep = old('position_date') ?: ($lastImport->position_date ?? null);
            $posForStepStr = $posForStep ? \Carbon\Carbon::parse($posForStep)->format('Y-m-d') : null;
            $posHuman = $posForStepStr ? \Carbon\Carbon::parse($posForStepStr)->format('d M Y') : '-';

            // =============================
            // VALIDASI POSISI (STRICT)
            // =============================
            $importOkForPos = !empty($lastImport)
                && strtolower((string)($lastImport->status ?? '')) === 'success'
                && !empty($posForStepStr)
                && \Carbon\Carbon::parse($lastImport->position_date)->format('Y-m-d') === $posForStepStr;

            $legacyOkForPos = !empty($lastLegacy)
                && strtolower((string)($lastLegacy->status ?? '')) === 'success'
                && !empty($posForStepStr)
                && \Carbon\Carbon::parse($lastLegacy->position_date)->format('Y-m-d') === $posForStepStr;

            $scheduleOkForPos = !empty($lastSchedule)
                && strtolower((string)($lastSchedule->status ?? '')) === 'success'
                && !empty($posForStepStr)
                && \Carbon\Carbon::parse($lastSchedule->position_date)->format('Y-m-d') === $posForStepStr;
        @endphp

        {{-- =========================
            STEP 1 - IMPORT
        ========================= --}}
        <div class="rounded-2xl border border-slate-100 bg-white px-5 py-4">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-sm font-semibold text-slate-800">Step 1 — Import Data</div>
                    <div class="text-xs text-slate-500">Upload file Excel untuk mengisi/ubah data loan_accounts & case.</div>
                </div>
                <span class="text-[11px] px-2 py-1 rounded-full bg-slate-100 text-slate-700 ring-1 ring-slate-200">
                    WAJIB
                </span>
            </div>

            <form method="POST" action="{{ route('loans.import.process') }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Tanggal Posisi Data
                    </label>
                    <input
                        type="date"
                        name="position_date"
                        required
                        value="{{ old('position_date', $posForStepStr) }}"
                        class="block w-full rounded-lg border-slate-300 focus:border-msa-blue focus:ring-msa-blue text-sm px-3 py-2.5">
                    <div class="mt-1 text-[11px] text-slate-500">
                        Posisi ini akan dipakai juga untuk Step 2 & 3.
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Pilih File Excel</label>
                    <input
                        type="file"
                        name="file"
                        accept=".xls,.xlsx"
                        required
                        class="block w-full text-sm text-slate-700
                               file:mr-4 file:py-2 file:px-4
                               file:rounded-md file:border-0
                               file:text-sm file:font-semibold
                               file:bg-msa-blue file:text-white
                               hover:file:bg-blue-900">
                </div>

                <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="reimport" value="1" {{ old('reimport') ? 'checked' : '' }}>
                        <span class="font-semibold">Re-import</span>
                        <span class="text-slate-500 text-[12px]">(koreksi posisi yang sama)</span>
                    </label>

                    <div class="mt-2">
                        <textarea
                            name="reimport_reason"
                            rows="2"
                            placeholder="Alasan koreksi (wajib jika re-import)"
                            class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-msa-blue focus:ring-msa-blue"
                        >{{ old('reimport_reason') }}</textarea>
                        <div class="mt-1 text-[11px] text-slate-500">
                            Isi alasan koreksi untuk kebutuhan audit & SOP.
                        </div>
                    </div>
                </div>

                <button
                    type="submit"
                    class="w-full inline-flex justify-center items-center px-4 py-2.5
                           text-sm font-semibold rounded-lg
                           bg-msa-blue text-white
                           hover:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-msa-blue">
                    Import Sekarang
                </button>
            </form>
        </div>

        {{-- =========================
            STEP 1B - IMPORT INSTALLMENTS
        ========================= --}}
        <div class="mt-4 rounded-2xl border border-slate-100 bg-white px-5 py-4">
        <div class="flex items-start justify-between">
            <div>
            <div class="text-sm font-semibold text-slate-800">Step 1B — Import Installments</div>
            <div class="text-xs text-slate-500">Upload file Excel installment (angsuran). Tidak termasuk disbursement.</div>
            </div>
            <span class="text-[11px] px-2 py-1 rounded-full bg-slate-100 text-slate-700 ring-1 ring-slate-200">
            WAJIB (untuk KPI RR)
            </span>
        </div>

        <form method="POST"
                action="{{ route('loans.installments.import') }}"
                enctype="multipart/form-data"
                class="mt-4 space-y-4">
            @csrf

            {{-- pakai posisi yang sama --}}
            <input type="hidden" name="position_date" value="{{ old('position_date', $posForStepStr) }}"/>

            <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Pilih File Installments (.xls/.xlsx)</label>
            <input type="file"
                    name="file_installments"
                    accept=".xls,.xlsx"
                    required
                    class="block w-full text-sm text-slate-700
                            file:mr-4 file:py-2 file:px-4
                            file:rounded-md file:border-0
                            file:text-sm file:font-semibold
                            file:bg-msa-blue file:text-white
                            hover:file:bg-blue-900">
            <div class="mt-1 text-[11px] text-slate-500">
                Pastikan header sesuai: nofas, angske, tglval, tglbayar, nlpokok, nlbunga, nldenda, komisi, komisi_baru, status, dll.
            </div>
            </div>

            {{-- reimport ikut aturan yang sama --}}
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="reimport" value="1" {{ old('reimport') ? 'checked' : '' }}>
                <span class="font-semibold">Re-import</span>
                <span class="text-slate-500 text-[12px]">(koreksi posisi yang sama)</span>
            </label>

            <div class="mt-2">
                <textarea name="reimport_reason" rows="2"
                placeholder="Alasan koreksi (wajib jika re-import)"
                class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-msa-blue focus:ring-msa-blue"
                >{{ old('reimport_reason') }}</textarea>
            </div>
            </div>

            <button type="submit"
            class="w-full inline-flex justify-center items-center px-4 py-2.5
                    text-sm font-semibold rounded-lg
                    bg-msa-blue text-white
                    hover:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-msa-blue">
            Import Installments
            </button>
        </form>
        </div>

        {{-- =========================
            STEP 2 - LEGACY SYNC (dengan progress)
        ========================= --}}
        <div class="mt-4 rounded-2xl border border-slate-100 bg-white px-5 py-4" id="step2Card">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-sm font-semibold text-slate-800">Step 2 — Legacy Sync</div>
                    <div class="text-xs text-slate-500">
                        Tarik status SP/aksi dari legacy untuk menentukan tahap terakhir.
                    </div>
                    <div class="mt-1 text-[11px] text-slate-600">
                        Posisi yang akan diproses: <b>{{ $posHuman }}</b>
                    </div>
                </div>

                @php
                    $ready2 = $importOkForPos;
                    $badge2 = $ready2
                        ? 'bg-sky-50 text-sky-700 ring-1 ring-sky-200'
                        : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200';
                @endphp
                <span class="text-[11px] px-2 py-1 rounded-full {{ $badge2 }}">
                    {{ $ready2 ? 'READY' : 'IMPORT DULU' }}
                </span>
            </div>

            <form method="POST" action="{{ route('loans.legacy.sync') }}" class="mt-4 grid grid-cols-1 gap-2">
                @csrf
                <input type="hidden" name="position_date" value="{{ $posForStepStr }}"/>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                    <input type="text" name="ao" placeholder="AO code (opsional)" class="rounded-lg border-slate-300 px-3 py-2 text-sm" />
                    <input type="number" name="chunk" placeholder="Chunk (default 150)" class="rounded-lg border-slate-300 px-3 py-2 text-sm" />
                    <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-[11px] text-slate-600 flex items-center">
                        Disarankan jalankan via queue.
                    </div>
                </div>

                <button type="submit"
                    {{ ($ready2 && $posForStepStr) ? '' : 'disabled' }}
                    class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold
                    {{ ($ready2 && $posForStepStr) ? 'bg-sky-600 text-white hover:bg-sky-700' : 'bg-slate-200 text-slate-500 cursor-not-allowed' }}">
                    Jalankan Legacy Sync
                </button>

                @if(!empty($lastLegacy))
                    @php
                        $st = strtolower((string)($lastLegacy->status ?? ''));
                        $b = $st === 'success'
                            ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
                            : ($st === 'failed'
                                ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
                                : 'bg-amber-50 text-amber-800 ring-1 ring-amber-200');

                        $legacyPosText = !empty($lastLegacy->position_date)
                            ? \Carbon\Carbon::parse($lastLegacy->position_date)->format('d M Y')
                            : '-';
                        $legacyAtText = !empty($lastLegacy->created_at)
                            ? \Carbon\Carbon::parse($lastLegacy->created_at)->format('d M Y H:i')
                            : '-';
                    @endphp

                    <div class="mt-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 text-[12px]" id="legacyStatusBox">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-slate-800 font-semibold">Status Legacy Sync Terakhir</div>
                                <div class="mt-1 text-[11px] text-slate-600" id="legacyMeta">
                                    Posisi: <b>{{ $legacyPosText }}</b> — Waktu: <b>{{ $legacyAtText }}</b>
                                </div>
                            </div>

                            <div class="flex flex-col items-end gap-1">
                                <span id="legacyBadge" class="text-[11px] px-2 py-1 rounded-full {{ $b }}">
                                    {{ strtoupper($st ?: 'N/A') }}
                                </span>
                                <div class="text-[10px] text-slate-500" id="legacyLastCheck">Last check: -</div>
                            </div>
                        </div>

                        <div class="mt-2 text-slate-600" id="legacyMsg">
                            {{ $lastLegacy->message ?? '' }}
                        </div>

                        {{-- Progress (tampil saat ada data; auto di-show oleh JS) --}}
                        <div class="mt-3 hidden" id="legacyProgress">
                            <div class="flex items-center justify-between">
                                <div class="text-[11px] font-semibold text-slate-700">Progress</div>
                                <div class="text-[11px] text-slate-600">
                                    <span id="legacyCount">0/0</span>
                                    <span class="mx-1 text-slate-300">•</span>
                                    Failed: <span id="legacyFailed">0</span>
                                    <span class="mx-1 text-slate-300">•</span>
                                    <span id="legacyPct">0%</span>
                                </div>
                            </div>

                            <div class="mt-2 w-full bg-slate-200 rounded-full h-2.5 overflow-hidden">
                                <div
                                    id="legacyProgressBar"
                                    class="h-2.5 rounded-full transition-all duration-500 bg-sky-600"
                                    style="width:0%">
                                </div>
                            </div>

                            <div class="mt-2 flex items-start justify-between gap-3">
                                <div class="text-[11px] text-slate-600" id="legacyNote">-</div>
                                <div class="text-[10px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1 hidden" id="legacyHint">
                                    -
                                </div>
                            </div>
                        </div>

                    </div>

                @endif
            </form>
        </div>

        {{-- =========================
            STEP 3 - UPDATE JADWAL (dengan progress)
        ========================= --}}
        <div class="mt-4 rounded-2xl border border-slate-100 bg-white px-5 py-4" id="step3Card">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-sm font-semibold text-slate-800">Step 3 — Update Jadwal</div>
                    <div class="text-xs text-slate-500">
                        Bangun agenda WA/Telp/SP berdasarkan DPD + status SP legacy.
                    </div>
                    <div class="mt-1 text-[11px] text-slate-600">
                        Posisi yang akan diproses: <b>{{ $posHuman }}</b>
                    </div>
                </div>

                @php
                    $ready3 = $importOkForPos && $legacyOkForPos;
                    $badge3 = $ready3
                        ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
                        : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200';
                @endphp
                <span id="step3ReadyBadge" class="text-[11px] px-2 py-1 rounded-full {{ $badge3 }}">
                    {{ $ready3 ? 'READY' : 'SYNC DULU' }}
                </span>

            </div>

            <form method="POST" action="{{ route('loans.jadwal.update') }}" class="mt-4 grid grid-cols-1 gap-2">
                @csrf
                <input type="hidden" name="position_date" value="{{ $posForStepStr }}"/>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                    <input type="text" name="ao" placeholder="AO code (opsional)" class="rounded-lg border-slate-300 px-3 py-2 text-sm" />
                    <input type="number" name="chunk" placeholder="Chunk (default 200)" class="rounded-lg border-slate-300 px-3 py-2 text-sm" />
                    <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-[11px] text-slate-600 flex items-center">
                        Dibangun per batch.
                    </div>
                </div>

                <button
                    id="btnUpdateJadwal"
                    type="submit"
                    {{ ($ready3 && $posForStepStr) ? '' : 'disabled' }}
                    class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold
                    {{ ($ready3 && $posForStepStr) ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-slate-200 text-slate-500 cursor-not-allowed' }}">
                    Update Jadwal
                </button>

                @if(!empty($lastSchedule))
                    @php
                        $st = strtolower((string)($lastSchedule->status ?? ''));
                        $b = $st === 'success'
                            ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
                            : ($st === 'failed'
                                ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
                                : 'bg-amber-50 text-amber-800 ring-1 ring-amber-200');

                        $schedPosText = !empty($lastSchedule->position_date)
                            ? \Carbon\Carbon::parse($lastSchedule->position_date)->format('d M Y')
                            : '-';
                        $schedAtText = !empty($lastSchedule->created_at)
                            ? \Carbon\Carbon::parse($lastSchedule->created_at)->format('d M Y H:i')
                            : '-';
                    @endphp

                    <div class="mt-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 text-[12px]" id="jadwalStatusBox">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-slate-800 font-semibold">Status Update Jadwal Terakhir</div>
                                <div class="mt-1 text-[11px] text-slate-600" id="jadwalMeta">
                                    Posisi: <b>{{ $schedPosText }}</b> — Waktu: <b>{{ $schedAtText }}</b>
                                </div>
                            </div>

                            <div class="flex flex-col items-end gap-1">
                                <span id="jadwalBadge" class="text-[11px] px-2 py-1 rounded-full {{ $b }}">
                                    {{ strtoupper($st ?: 'N/A') }}
                                </span>
                                <div class="text-[10px] text-slate-500" id="jadwalLastCheck">Last check: -</div>
                            </div>
                        </div>

                        <div class="mt-2 text-slate-600" id="jadwalMsg">
                            {{ $lastSchedule->message ?? '' }}
                        </div>

                        <div class="mt-3 hidden" id="jadwalProgress">
                            <div class="flex items-center justify-between">
                                <div class="text-[11px] font-semibold text-slate-700">Progress</div>
                                <div class="text-[11px] text-slate-600">
                                    <span id="jadwalCount">0/0</span>
                                    <span class="mx-1 text-slate-300">•</span>
                                    Failed: <span id="jadwalFailed">0</span>
                                    <span class="mx-1 text-slate-300">•</span>
                                    <span id="jadwalPct">0%</span>
                                </div>
                            </div>

                            <div class="mt-2 w-full bg-slate-200 rounded-full h-2.5 overflow-hidden">
                                <div
                                    id="jadwalProgressBar"
                                    class="h-2.5 rounded-full transition-all duration-500 bg-emerald-600"
                                    style="width:0%">
                                </div>
                            </div>

                            <div class="mt-2 flex items-start justify-between gap-3">
                                <div class="text-[11px] text-slate-600" id="jadwalNote">-</div>
                                <div class="text-[10px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1 hidden" id="jadwalHint">
                                    -
                                </div>
                            </div>
                        </div>

                    </div>
                @endif
            </form>
        </div>

        <!-- {{-- =========================
            STEP 4 - IMPORT INSTALLMENT
        ========================= --}}
        <div class="mt-4 rounded-2xl border border-slate-100 bg-white px-5 py-4" id="step4Card">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-sm font-semibold text-slate-800">Step 4 — Import Installment</div>
                    <div class="text-xs text-slate-500">
                        Import data pembayaran angsuran (pokok/bunga/denda) untuk kebutuhan KPI (Repayment Rate, dll).
                    </div>
                    <div class="mt-1 text-[11px] text-slate-600">
                        Posisi (audit): <b>{{ $posHuman }}</b>
                    </div>
                </div>

                @php
                    // default: boleh jalan setelah Step 1 sukses pada posisi yg sama
                    $ready4 = $importOkForPos; // kalau mau lebih ketat: && $legacyOkForPos
                    $badge4 = $ready4
                        ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
                        : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200';
                @endphp
                <span class="text-[11px] px-2 py-1 rounded-full {{ $badge4 }}">
                    {{ $ready4 ? 'READY' : 'IMPORT KREDIT DULU' }}
                </span>
            </div>

            {{-- INFO: IMPORT TERAKHIR INSTALLMENT --}}
            <div class="mt-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-semibold text-slate-700">Import Terakhir (Installment)</div>
                        <div class="mt-0.5 text-[11px] text-slate-500">
                            Untuk audit trail pembayaran.
                        </div>
                    </div>

                    @if (!empty($lastInstallment))
                        @php
                            $st = strtolower((string)($lastInstallment->status ?? ''));
                            $b = $st === 'success'
                                ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
                                : ($st === 'failed'
                                    ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
                                    : 'bg-amber-50 text-amber-800 ring-1 ring-amber-200');
                        @endphp
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $b }}">
                            {{ strtoupper($st ?: 'TERCATAT') }}
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold bg-slate-100 text-slate-700 ring-1 ring-slate-200">
                            BELUM PERNAH
                        </span>
                    @endif
                </div>

                @php
                    $ipos = $lastInstallment->position_date ?? null;
                    $iat  = $lastInstallment->created_at ?? null;

                    $iposText = $ipos ? \Carbon\Carbon::parse($ipos)->format('d M Y') : '-';
                    $iatText  = $iat ? \Carbon\Carbon::parse($iat)->format('d M Y H:i') : '-';

                    $ibyName  = $lastInstallment->importer->name
                        ?? $lastInstallment->created_by_name
                        ?? $lastInstallment->created_by
                        ?? null;

                    $ifileName = $lastInstallment->file_name
                        ?? $lastInstallment->filename
                        ?? $lastInstallment->original_name
                        ?? null;
                @endphp

                <div class="mt-3 grid grid-cols-2 gap-3 text-[11px]">
                    <div class="rounded-xl border border-slate-100 bg-white px-3 py-2">
                        <div class="text-slate-500">Posisi Data</div>
                        <div class="font-semibold text-slate-800">
                            {{ !empty($lastInstallment) ? $iposText : '-' }}
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-100 bg-white px-3 py-2">
                        <div class="text-slate-500">Waktu Import</div>
                        <div class="font-semibold text-slate-800">
                            {{ !empty($lastInstallment) ? $iatText : '-' }}
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-100 bg-white px-3 py-2 col-span-2">
                        <div class="text-slate-500">Diimport oleh</div>
                        <div class="font-semibold text-slate-800">
                            {{ !empty($lastInstallment) ? ($ibyName ?: '-') : '-' }}
                        </div>
                    </div>

                    @if (!empty($lastInstallment) && !empty($ifileName))
                        <div class="rounded-xl border border-slate-100 bg-white px-3 py-2 col-span-2">
                            <div class="text-slate-500">File</div>
                            <div class="font-semibold text-slate-800 break-words">
                                {{ $ifileName }}
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <form method="POST"
                action="{{ route('loans.installments.import') }}"
                enctype="multipart/form-data"
                class="mt-4 space-y-4">
                @csrf

                {{-- pakai posisi yg sama biar konsisten audit --}}
                <input type="hidden" name="position_date" value="{{ $posForStepStr }}"/>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Pilih File Excel Installment</label>
                    <input
                        type="file"
                        name="file_installments"
                        accept=".xls,.xlsx"
                        required
                        class="block w-full text-sm text-slate-700
                            file:mr-4 file:py-2 file:px-4
                            file:rounded-md file:border-0
                            file:text-sm file:font-semibold
                            file:bg-msa-blue file:text-white
                            hover:file:bg-blue-900">
                    <div class="mt-1 text-[11px] text-slate-500">
                        Kolom kunci: <b>nofas</b> + <b>angske</b> (unique). Tanggal bayar: <b>tglbayar</b>.
                    </div>
                </div>

                <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="reimport" value="1" {{ old('reimport_installment') ? 'checked' : '' }}>
                        <span class="font-semibold">Re-import</span>
                        <span class="text-slate-500 text-[12px]">(koreksi posisi yang sama)</span>
                    </label>

                    <div class="mt-2">
                        <textarea
                            name="reimport_reason"
                            rows="2"
                            placeholder="Alasan koreksi (wajib jika re-import)"
                            class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-msa-blue focus:ring-msa-blue"
                        >{{ old('reimport_reason') }}</textarea>
                        <div class="mt-1 text-[11px] text-slate-500">
                            Isi alasan koreksi untuk audit.
                        </div>
                    </div>
                </div>

                <button type="submit"
                        {{ ($ready4 && $posForStepStr) ? '' : 'disabled' }}
                        class="w-full inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold
                        {{ ($ready4 && $posForStepStr) ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'bg-slate-200 text-slate-500 cursor-not-allowed' }}">
                    Import Angsuran
                </button>
            </form>
        </div> -->

    </div>
</div>

{{-- =========================================================
    POLLING STATUS STEP 2 & 3 (AUTO UPDATE)
    - Poll hanya saat status RUNNING
    - Update badge, message, dan progress bar
========================================================= --}}
<script>
(function () {
  const pos = @json($posForStepStr);
  if (!pos) return;

  function nowStr() { return new Date().toLocaleString('id-ID'); }

  function setBadge(el, status) {
    if (!el) return;
    const st = (status || '').toLowerCase();
    el.textContent = (st || 'N/A').toUpperCase();
    el.className = 'text-[11px] px-2 py-1 rounded-full';

    if (st === 'success') el.classList.add('bg-emerald-50','text-emerald-700','ring-1','ring-emerald-200');
    else if (st === 'failed') el.classList.add('bg-rose-50','text-rose-700','ring-1','ring-rose-200');
    else if (st === 'running') el.classList.add('bg-amber-50','text-amber-800','ring-1','ring-amber-200');
    else el.classList.add('bg-slate-100','text-slate-700','ring-1','ring-slate-200');
  }

  function showHint(hintEl, show, text) {
    if (!hintEl) return;
    if (!show) { hintEl.classList.add('hidden'); hintEl.textContent = '-'; return; }
    hintEl.classList.remove('hidden');
    hintEl.textContent = text || '-';
  }

  function applyProgress(prefix, data) {
    const wrap     = document.getElementById(prefix + 'Progress');
    const pctEl    = document.getElementById(prefix + 'Pct');
    const countEl  = document.getElementById(prefix + 'Count');
    const failedEl = document.getElementById(prefix + 'Failed');
    const barEl    = document.getElementById(prefix + 'ProgressBar');
    const noteEl   = document.getElementById(prefix + 'Note');
    const hintEl   = document.getElementById(prefix + 'Hint');

    const progress = data.progress || null;
    if (!wrap || !progress) return;

    wrap.classList.remove('hidden');

    const total     = Number(progress.total ?? 0);
    const processed = Number(progress.processed ?? 0);
    const failed    = Number(progress.failed ?? 0);
    const pct       = Number(progress.percent ?? 0);
    const note      = progress.note ?? null;

    if (pctEl) pctEl.textContent = `${pct}%`;
    if (countEl) countEl.textContent = `${processed}/${total}`;
    if (failedEl) failedEl.textContent = `${failed}`;
    if (barEl) barEl.style.width = `${pct}%`;
    if (noteEl) noteEl.textContent = note ? note : '-';

    const st = (data.status || '').toLowerCase();

    if (st === 'running' && total > 0 && processed === 0) {
      showHint(hintEl, true, 'RUNNING tapi processed masih 0. Biasanya worker belum pick job / batch stuck.');
    } else {
      showHint(hintEl, false);
    }

    if (barEl) {
      barEl.classList.remove('bg-sky-600','bg-emerald-600','bg-rose-600','bg-slate-500');
      if (st === 'failed') barEl.classList.add('bg-rose-600');
      else if (st === 'success') barEl.classList.add('bg-emerald-600');
      else barEl.classList.add(prefix === 'legacy' ? 'bg-sky-600' : 'bg-emerald-600');
    }
  }

  async function poll(url, els, prefix) {
    try {
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      if (els.lastCheck) els.lastCheck.textContent = 'Last check: ' + nowStr();

      // kalau response bukan json / 500, jangan crash
      if (!res.ok) {
        if (els.msg) els.msg.textContent = `Gagal cek status (${res.status})`;
        return { done: false };
      }

      const data = await res.json();

      if (!data || !data.found) return { done: false };

      setBadge(els.badge, data.status);

      if (els.msg && typeof data.message === 'string') {
        els.msg.textContent = data.message;
      }

      applyProgress(prefix, data);

      const st = (data.status || '').toLowerCase();

      // unlock step 3 kalau legacy success
      if (prefix === 'legacy' && st === 'success') enableUpdateJadwal();

      return { done: (st === 'success' || st === 'failed') };
    } catch (e) {
      if (els.lastCheck) els.lastCheck.textContent = 'Last check: ' + nowStr();
      if (els.msg) els.msg.textContent = 'Error polling: ' + (e?.message || e);
      return { done: false };
    }
  }

  const legacyUrl = `{{ route('loans.legacy.status') }}?position_date=${encodeURIComponent(pos)}`;
  const jadwalUrl = `{{ route('loans.jadwal.status') }}?position_date=${encodeURIComponent(pos)}`;

  const legacyEls = {
    badge: document.getElementById('legacyBadge'),
    msg: document.getElementById('legacyMsg'),
    lastCheck: document.getElementById('legacyLastCheck'),
  };

  const jadwalEls = {
    badge: document.getElementById('jadwalBadge'),
    msg: document.getElementById('jadwalMsg'),
    lastCheck: document.getElementById('jadwalLastCheck'),
  };

  // ✅ polling awal 1x
  poll(legacyUrl, legacyEls, 'legacy');
  poll(jadwalUrl, jadwalEls, 'jadwal');

  // ✅ polling interval: jangan tergantung badge awal “running”
  // biar kalau status berubah (setelah klik) tetap ke-check
  const intervalMs = 2000;
  setInterval(() => {
    poll(legacyUrl, legacyEls, 'legacy');
    poll(jadwalUrl, jadwalEls, 'jadwal');
  }, intervalMs);

})();

function enableUpdateJadwal() {
  const btn = document.getElementById('btnUpdateJadwal');
  if (!btn) return;

  btn.disabled = false;
  btn.classList.remove('bg-slate-200','text-slate-500','cursor-not-allowed');
  btn.classList.add('bg-emerald-600','text-white','hover:bg-emerald-700');

  const badge = document.getElementById('step3ReadyBadge');
  if (badge) {
    badge.textContent = 'READY';
    badge.className = 'text-[11px] px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200';
  }
}
</script>

@endsection
