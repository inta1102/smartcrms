@extends('layouts.app')

@section('title', 'Detail Kasus Kredit Bermasalah')

@section('content')
@php
    $loan = $case->loanAccount;

    // ✅ akses usulkan legal (proposal)
    $canStart = auth()->user()?->can('create', \App\Models\LegalActionProposal::class);

    // ✅ syarat proses: minimal ada log penanganan + SP + NonLit (sesuaikan rule kamu)
    $hasHandlingLog = \App\Models\CaseAction::where('npl_case_id', $case->id)
        ->whereIn('action_type', ['persuasif','visit','call','negosiasi','penagihan','sp','sp1','sp2','sp3','nonlit','non_litigasi'])
        ->exists();

    $hasSp = \App\Models\CaseAction::where('npl_case_id', $case->id)
        ->where(function ($q) {
            $q->whereIn('action_type', ['sp','spak','sp1','sp2','sp3','spt','spjad'])
              ->orWhere('source_system', 'legacy_sp');
        })
        ->exists();

    $hasNonLit = \App\Models\CaseAction::where('npl_case_id', $case->id)
        ->whereIn('action_type', ['nonlit','non_litigasi','rs','restrukturisasi','ayda','lelang'])
        ->exists();

    // ✅ RULE tombol: (pilih salah satu)
    // 1) ketat: wajib SP + NonLit + ada log
    $canStart = $canStart && $hasHandlingLog && $hasSp && $hasNonLit;

    // 2) kalau mau longgar (cukup ada NonLit + log), pakai ini:
    // $canStart = $canStart && $hasHandlingLog && $hasNonLit;

    $defaultTab = request('tab', 'persuasif');
@endphp


<div class="mx-auto w-full max-w-6xl space-y-5 px-4 py-6">

    {{-- Breadcrumb --}}
    <div class="text-xs text-slate-500">
        <a href="{{ route('cases.index') }}" class="hover:underline">Daftar Kasus</a>
        <span class="mx-1">/</span>
        <span>Detail</span>
    </div>

   
    {{-- Header (ringkas, tanpa tombol eskalasi) --}}
    <div class="rounded-2xl border border-slate-100 bg-white px-4 py-3 shadow-sm">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="mb-1 text-xs text-slate-500">Rekening</p>
                <h1 class="text-xl font-semibold text-slate-800 md:text-2xl">
                    {{ $loan?->account_no ?? '-' }} — {{ $loan?->customer_name ?? '-' }}
                </h1>
                <p class="mt-1 text-xs text-slate-500">
                    CIF: <span class="font-mono">{{ $loan?->cif ?? '-' }}</span> •
                    Collector: {{ $loan?->ao_name ?? '-' }} ({{ $loan?->ao_code ?? '-' }})
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    Alamat: <span class="font-mono">{{ $loan?->alamat ?? '-' }}</span>
                </p>
            </div>

            <div class="flex flex-col gap-2 text-xs md:items-end">
                <div>
                    <span class="text-slate-500">Kolektibilitas:</span>
                    <span class="font-semibold text-slate-800">{{ $loan?->kolek ?? '-' }}</span>
                </div>
                <div>
                    <span class="text-slate-500">DPD:</span>
                    <span class="font-semibold text-slate-800">{{ $loan?->dpd ?? '-' }} hari</span>
                </div>
                <div>
                    <span class="text-slate-500">Outstanding:</span>
                    <span class="font-semibold text-slate-800">
                        {{ $loan ? 'Rp '.number_format($loan->outstanding, 0, ',', '.') : '-' }}
                    </span>
                </div>
                <div>
                    <span class="text-slate-500">Opened:</span>
                    <span class="font-semibold text-slate-800">
                        {{ $case->opened_at?->format('d-m-Y') ?? '-' }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-slate-500">Status Case:</span>
                    @if ($case->closed_at)
                        <span class="inline-flex rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold text-slate-700">
                            Closed {{ $case->closed_at?->format('d-m-Y') }}
                        </span>
                    @else
                        <span class="inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-700">
                            Open
                        </span>
                    @endif
                </div>
            </div>
            <form method="POST" action="{{ route('cases.sync-legacy-sp', $case) }}" class="inline">
                @csrf
                <button type="submit"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    🔄 Sync Legacy SP
                </button>
            </form>

            @if ($case->last_legacy_sync_at)
                <span class="ml-2 text-xs text-slate-500">
                    Last sync: {{ \Carbon\Carbon::parse($case->last_legacy_sync_at)->format('d/m/Y H:i') }}
                </span>
            @endif

        </div>
    </div>
    
    {{-- 🎯 Target Penyelesaian (Monitoring Komisaris) --}}
    <div class="rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase text-slate-500">Target Penyelesaian</p>
                <p class="mt-0.5 text-[11px] text-slate-500">
                    Monitoring komisaris (level kasus). Ditetapkan via workflow AO → TL → Kasi.
                </p>
            </div>

            @php
                $activeTarget  = $case->activeResolutionTarget;
                $pendingTarget = $case->pendingResolutionTarget;
                $displayTarget = $activeTarget ?: $pendingTarget;

                $badgeMap = function ($status) {
                    $s = strtoupper((string) $status);
                    return match ($s) {
                        'PENDING_TL'   => ['MENUNGGU TL', 'bg-amber-50 text-amber-700 border-amber-200'],
                        'PENDING_KASI' => ['MENUNGGU KASI', 'bg-indigo-50 text-indigo-700 border-indigo-200'],
                        'APPROVED_KASI','ACTIVE' => ['AKTIF', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
                        'REJECTED'     => ['DITOLAK', 'bg-rose-50 text-rose-700 border-rose-200'],
                        default        => [$s ?: 'STATUS', 'bg-slate-50 text-slate-700 border-slate-200'],
                    };
                };

                if (!$displayTarget) {
                    $badgeText  = 'BELUM ADA';
                    $badgeClass = 'bg-slate-100 text-slate-700 border-slate-200';
                } else {
                    [$badgeText, $badgeClass] = $badgeMap($displayTarget->status);
                }
            @endphp

            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-bold {{ $badgeClass }}">
                {{ $badgeText }}
            </span>
        </div>

        @php
            $activeTarget  = $case->activeResolutionTarget;
            $pendingTarget = $case->pendingResolutionTarget;
            $displayTarget = $activeTarget ?: $pendingTarget;

            // ✅ status pill class (dipakai di bawah)
            $statusPill = function ($status) {
                $s = strtoupper((string) $status);
                return match ($s) {
                    'PENDING_TL'   => 'bg-amber-50 text-amber-700 border-amber-200',
                    'PENDING_KASI' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                    'APPROVED_KASI','ACTIVE' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    'REJECTED'     => 'bg-rose-50 text-rose-700 border-rose-200',
                    default        => 'bg-slate-50 text-slate-700 border-slate-200',
                };
            };

            // ✅ header badge text + class (kalau mau dipakai di kanan atas)
            $headerBadge = function () use ($displayTarget, $statusPill) {
                if (!$displayTarget) return ['BELUM ADA', 'bg-slate-100 text-slate-700 border-slate-200'];

                $s = strtoupper((string) $displayTarget->status);
                return match ($s) {
                    'PENDING_TL'   => ['MENUNGGU TL', 'bg-amber-50 text-amber-700 border-amber-200'],
                    'PENDING_KASI' => ['MENUNGGU KASI', 'bg-indigo-50 text-indigo-700 border-indigo-200'],
                    'APPROVED_KASI','ACTIVE' => ['AKTIF', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
                    'REJECTED'     => ['DITOLAK', 'bg-rose-50 text-rose-700 border-rose-200'],
                    default        => [$s ?: 'STATUS', 'bg-slate-50 text-slate-700 border-slate-200'],
                };
            };

            [$badgeText, $badgeClass] = $headerBadge();
        @endphp

        <div class="mt-4 space-y-3 text-xs text-slate-700">
            @if($displayTarget)
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                        <div class="text-[10px] text-slate-500">Target Tanggal</div>
                        <div class="mt-0.5 font-semibold text-slate-900">
                            {{ $displayTarget->target_date?->format('d M Y') ?? '-' }}
                        </div>

                        {{-- Status pill (biar jelas pending/active) --}}
                        @if(!empty($displayTarget->status))
                            <div class="mt-2 inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $statusPill($displayTarget->status) }}">
                                {{ strtoupper($displayTarget->status) }}
                            </div>
                        @endif
                    </div>

                    <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                        <div class="text-[10px] text-slate-500">Target Kondisi</div>
                        <div class="mt-0.5 font-semibold uppercase text-slate-900">
                            {{ $displayTarget->target_outcome ? strtoupper($displayTarget->target_outcome) : '-' }}
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 col-span-2">
                        <div class="text-[10px] text-slate-500">Strategi</div>
                        <div class="mt-0.5 font-semibold capitalize text-slate-900">
                            {{ $displayTarget->strategy ?? '-' }}
                        </div>
                    </div>
                </div>

                @if($displayTarget->reason)
                    <div class="rounded-xl border border-slate-100 bg-white px-3 py-2">
                        <div class="text-[10px] text-slate-500">Catatan</div>
                        <div class="mt-0.5 text-slate-700">{{ $displayTarget->reason }}</div>
                    </div>
                @endif

                {{-- Footer info: siapa yang mengusulkan / menyetujui --}}
                <div class="text-[11px] text-slate-500">
                    @if($activeTarget)
                        Disetujui oleh:
                        <span class="font-semibold text-slate-700">
                            {{ $activeTarget->approver?->name ?? '-' }}
                        </span>
                    @else
                        Menunggu persetujuan —
                        Diusulkan oleh:
                        <span class="font-semibold text-slate-700">
                            {{ $pendingTarget?->proposer?->name ?? $pendingTarget?->createdBy?->name ?? '-' }}
                        </span>
                    @endif
                </div>

            @else
                <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-[11px] text-slate-600">
                    Belum ada target penyelesaian untuk kasus ini.
                </div>
            @endif

           {{-- Catatan Supervisi (TL & Kasi) --}}
            @php
                // Pastikan aman walaupun target belum ada
                $displayTarget = $displayTarget ?? null;

                $tlNotes   = trim((string)($displayTarget?->tl_notes ?? ''));
                $kasiNotes = trim((string)($displayTarget?->kasi_notes ?? '')); // kalau field-mu beda, sesuaikan
            @endphp

            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">

                @if($tlNotes !== '')
                    <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                        <div class="text-[10px] font-semibold text-slate-500">Catatan TL</div>
                        <div class="mt-0.5 text-sm text-slate-800 whitespace-pre-line">{{ $tlNotes }}</div>
                    </div>
                @endif

                @if($kasiNotes !== '')
                    <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                        <div class="text-[10px] font-semibold text-slate-500">Catatan KASI</div>
                        <div class="mt-0.5 text-sm text-slate-800 whitespace-pre-line">{{ $kasiNotes }}</div>
                    </div>
                @endif

            </div>

            @php
                $activeTarget = $case->activeResolutionTarget;
                $agendas = $activeTarget
                    ? $activeTarget->agendas()->orderBy('due_at')->get()
                    : collect();
            @endphp

            @if($activeTarget)
                <div class="mt-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-semibold text-slate-800">Agenda AO (Target Aktif)</div>
                            <div class="text-xs text-slate-500">Auto-created saat target ACTIVE, bisa diupdate oleh AO/PIC.</div>
                        </div>
                        <a href="{{ route('ao-agendas.index', ['case' => $case->id]) }}"
                        class="text-xs font-semibold text-indigo-600 hover:text-indigo-700">
                            Lihat Semua →
                        </a>
                    </div>

                    <div class="mt-3 space-y-2">
                        @forelse($agendas as $ag)
                            @php
                                $badge = match(strtolower($ag->status)) {
                                    'planned' => 'bg-slate-100 text-slate-700 border-slate-200',
                                    'in_progress' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                    'overdue' => 'bg-rose-50 text-rose-700 border-rose-200',
                                    'done' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                    default => 'bg-slate-100 text-slate-700 border-slate-200',
                                };
                            @endphp

                            <div class="rounded-xl border border-slate-200 bg-white p-3">
                                {{-- HEADER: Title + Badge --}}
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <div class="text-sm font-semibold text-slate-900">
                                                {{ $ag->title }}
                                            </div>

                                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-bold {{ $badge }}">
                                                {{ strtoupper($ag->status) }}
                                            </span>
                                        </div>

                                        <div class="mt-1 text-xs text-slate-500">
                                            Jatuh tempo:
                                            <span class="font-semibold">{{ optional($ag->due_at)->format('d M Y H:i') ?? '-' }}</span>

                                            @if($ag->assignee)
                                                <span class="hidden sm:inline"> • </span>
                                                <span class="block sm:inline">
                                                    PIC: <span class="font-semibold">{{ $ag->assignee->name }}</span>
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- CTA kecil: di desktop boleh kanan atas, di mobile turun tapi tetap rapi --}}
                                    <div class="shrink-0 sm:pt-0">
                                        {{-- optional: bisa taruh status kecil / ikon --}}
                                    </div>
                                </div>

                                {{-- ACTIONS: Buttons (selalu di bawah, biar mobile nggak nabrak) --}}
                                @if(in_array($ag->status, ['planned','overdue','in_progress']))
                                    @php $hasLog = $ag->actions()->exists(); @endphp

                                    <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="text-xs text-slate-500">
                                            @if(!$hasLog)
                                                Isi minimal 1 tindakan dulu
                                            @endif
                                        </div>

                                        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                                            <a href="{{ route('cases.show', $case) }}?tab=persuasif&agenda={{ $ag->id }}&preset=whatsapp#handling-panel"
                                            class="inline-flex w-full items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto">
                                                ✏️ Tambah Tindakan
                                            </a>

                                            @if($hasLog)
                                                <form method="POST" action="{{ route('ao-agendas.complete', $ag) }}">
                                                    @csrf
                                                    <button class="inline-flex w-full items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 sm:w-auto">
                                                        ✅ Tutup Agenda
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                @if($ag->completion_notes)
                                    <div class="mt-2 text-xs text-slate-600">
                                        <span class="font-semibold">Catatan:</span> {{ $ag->completion_notes }}
                                    </div>
                                @endif
                            </div>

                        @empty
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                                Belum ada agenda untuk target aktif ini.
                            </div>
                        @endforelse
                    </div>
                </div>
            @endif


        </div>

        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
            {{-- Tombol lihat detail selalu ada --}}
            <button type="button"
                    x-data
                    @click="$dispatch('open-target-modal', { mode: 'view' })"
                    class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                <span>📌</span><span>Lihat / Kelola</span>
            </button>

            {{-- AO: Set/Revisi (kalau boleh propose) --}}
            @can('propose', [\App\Models\CaseResolutionTarget::class, $case])
                <button type="button"
                        x-data
                        @click="$dispatch('open-target-modal', { mode: 'create' })"
                        class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700">
                    {{ $pendingTarget ? '✏️ Revisi Target' : '+ Set Target' }}
                </button>
            @endcan

            {{-- =========================
                ✅ APPROVAL CTA KONTEKSTUAL
                ========================= --}}
            @if($pendingTarget && !empty($pendingTarget->status))

                {{-- 1) Menunggu TL --}}
                @if(strtoupper($pendingTarget->status) === 'PENDING_TL')
                    @can('approveTl', $pendingTarget)
                        <button type="button"
                                x-data
                                @click="$dispatch('open-approve-target', { role: 'tl', id: {{ $pendingTarget->id }} })"
                                class="inline-flex items-center gap-2 rounded-xl bg-sky-600 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-700">
                            ✅ TL Approve
                        </button>
                    @endcan


                    @can('reject', $pendingTarget)
                        <form method="POST"
                            action="{{ route('resolution-targets.reject', $pendingTarget) }}"
                            class="inline-flex">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                ⛔ Tolak
                            </button>
                        </form>
                    @endcan
                @endif

                {{-- 2) Menunggu Kasi --}}
                @if(strtoupper($pendingTarget->status) === 'PENDING_KASI')
                    @can('approveKasi', $pendingTarget)
                        <button type="button"
                                x-data
                                @click="$dispatch('open-approve-target', { role: 'kasi', id: {{ $pendingTarget->id }} })"
                                class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                            ✅ Kasi Approve
                        </button>
                    @endcan

                    @can('reject', $pendingTarget)
                        <form method="POST"
                            action="{{ route('resolution-targets.reject', $pendingTarget) }}"
                            class="inline-flex">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                ⛔ Tolak
                            </button>
                        </form>
                    @endcan
                @endif
            @endif

            <a href="#timeline"
                class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                📚 Lihat Progres Penanganan
            </a>

            <a href="{{ route('ao-agendas.index', ['case_id' => $case->id]) }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                📊 Update Agenda
            </a>


            {{-- Spacer biar tidak mepet ke bawah --}}
            <div class="basis-full"></div>

            <div class="text-[11px] text-slate-500">
                Tip: approval mengikuti workflow AO → TL → Kasi.
            </div>

        </div>
    </div> {{-- ✅ tutup card Target Penyelesaian --}}

    {{-- MODAL: Approve Target (TL/Kasi) --}}
    <div
        x-data="{
            open:false,
            role:'tl',
            targetId:null,
            notes:'',
            openWith(e){
                this.role = (e.detail && e.detail.role) ? e.detail.role : 'tl';
                this.targetId = (e.detail && e.detail.id) ? e.detail.id : null;
                this.notes = '';
                this.open = true;
            },
            actionUrl(){
                if(!this.targetId) return '#';
                return this.role === 'kasi'
                    ? '{{ url('/resolution-targets') }}/' + this.targetId + '/approve-kasi'
                    : '{{ url('/resolution-targets') }}/' + this.targetId + '/approve-tl';
            },
            title(){
                return this.role === 'kasi' ? '✅ Kasi Approval Target' : '✅ TL Approval Target';
            },
            submitLabel(){
                return this.role === 'kasi' ? 'Approve (Kasi)' : 'Approve (TL)';
            }
        }"
        x-on:open-approve-target.window="openWith($event)"
        x-cloak
    >
        <div x-show="open" class="fixed inset-0 z-40 bg-black/40"></div>

        <div x-show="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900" x-text="title()"></h3>
                        <p class="mt-1 text-sm text-slate-500">
                            Catatan supervisi wajib diisi (untuk jejak audit & arahan tindak lanjut).
                        </p>
                    </div>

                    <button type="button" @click="open=false"
                            class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Tutup
                    </button>
                </div>

                <form method="POST" :action="actionUrl()" class="px-5 py-4">
                    @csrf

                    <label class="block text-sm font-semibold text-slate-700">
                        Catatan / Arahan Supervisi <span class="text-rose-600">*</span>
                    </label>

                    <textarea name="notes"
                            x-model="notes"
                            rows="3"
                            class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Contoh: Setuju strategi penagihan intensif. AO wajib lampirkan bukti WA + jadwalkan visit ulang minggu ini. Jika gagal, usulkan Non-Lit minggu depan."
                            required maxlength="500"></textarea>

                    <div class="mt-2 text-[11px] text-slate-500">
                        Maks 500 karakter. Isi dengan arahan konkrit (aksi + due date + bukti).
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                        <button type="button" @click="open=false"
                                class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Batal
                        </button>

                        <button type="submit"
                                class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">
                            <span x-text="submitLabel()"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- GRID FINAL --}}
    <div class="grid gap-5 lg:grid-cols-12">

        {{-- KIRI --}}
        <div class="space-y-5 lg:col-span-8">

            {{-- ✅ PANEL 4 TOMBOL (mengganti form penanganan + SP + eskalasi) --}}
            <div
                id="handling-panel"
                x-data="{
                    tab: @js($defaultTab),

                    init() {
                        // 1) baca URL param saat load
                        try {
                            const url = new URL(window.location.href);
                            const t = url.searchParams.get('tab');
                            if (t) this.tab = t;

                            // 2) kalau ada agenda/preset: pasti masuk persuasif
                            const agenda = url.searchParams.get('agenda');
                            const preset = url.searchParams.get('preset');
                            if (agenda || preset) this.tab = 'persuasif';

                            // 3) kalau URL ada hash (#handling-panel) pastikan scroll halus
                            if (window.location.hash === '#handling-panel') {
                                this.$nextTick(() => {
                                    document.getElementById('handling-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                });
                            }
                        } catch (e) {}
                    },

                    setTab(t){
                        this.tab = t;

                        // optional: update query param tanpa reload + set hash biar jelas posisi
                        try {
                            const url = new URL(window.location.href);
                            url.searchParams.set('tab', t);
                            url.hash = 'handling-panel';
                            window.history.replaceState({}, '', url);
                        } catch (e) {}

                        // optional: auto scroll ke panel tiap ganti tab
                        this.$nextTick(() => {
                            document.getElementById('handling-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        });
                    }
                }"
                class="rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm"
>

                <div class="mb-3">
                    <h2 class="text-base font-bold text-slate-900">🧭 Pilih Jalur Penanganan</h2>
                    <p class="mt-0.5 text-sm text-slate-500">
                        Klik tombol sesuai jalur. Form/detail akan muncul sesuai pilihan.
                    </p>
                </div>

                {{-- tombol --}}
                <div class="flex flex-wrap gap-2">
                    <button type="button"
                            @click="setTab('persuasif')"
                            class="rounded-xl border px-4 py-2 text-xs font-semibold"
                            :class="tab==='persuasif' ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 text-slate-700 hover:bg-slate-50'">
                        🛠 Penanganan Persuasif
                    </button>

                    <button type="button"
                            @click="setTab('sp')"
                            class="rounded-xl border px-4 py-2 text-xs font-semibold"
                            :class="tab==='sp' ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 text-slate-700 hover:bg-slate-50'">
                        📨 SP
                    </button>

                    <button type="button"
                            @click="setTab('nonlit')"
                            class="rounded-xl border px-4 py-2 text-xs font-semibold"
                            :class="tab==='nonlit' ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 text-slate-700 hover:bg-slate-50'">
                        🧩 Non-Litigasi
                    </button>

                    <button type="button"
                            @click="setTab('lit')"
                            class="rounded-xl border px-4 py-2 text-xs font-semibold"
                            :class="tab==='lit' ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 text-slate-700 hover:bg-slate-50'">
                        ⚖️ Litigasi
                    </button>
                </div>

               {{-- divider --}}
                <div class="mt-4 border-t border-slate-100 pt-4">

                    {{-- ✅ 1) Persuasif = form existing --}}
                    <div
                        x-show="tab==='persuasif'"
                        x-cloak
                        x-data="{
                            actionType: @js(old('action_type', request('preset'))),
                            proofTypes: ['whatsapp','wa','telpon','telepon','call'],
                            get showProof() {
                                return this.proofTypes.includes((this.actionType || '').toLowerCase());
                            },
                            clearProofInputs(){
                                const fi = this.$refs.proofs;
                                if (fi) fi.value = '';
                                const note = this.$refs.proofNote;
                                if (note) note.value = '';
                            }
                        }"
                        x-effect="!showProof && clearProofInputs()"
                    >
                        <div class="mb-3">
                            <h3 class="text-sm font-bold text-slate-900">🛠 Penanganan Persuasif</h3>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Catat setiap upaya penagihan / komunikasi. Ini yang jadi “bukti proses”.
                            </p>
                        </div>

                        @if (session('status'))
                            <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs text-emerald-700">
                                {{ session('status') }}
                            </div>
                        @endif

                        @if ($errors->any())
                            <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-xs text-red-700">
                                <ul class="list-disc list-inside">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form method="POST"
                            enctype="multipart/form-data"
                            action="{{ route('cases.actions.store', $case) }}"
                            class="grid grid-cols-1 gap-3 text-xs md:grid-cols-3">
                            @csrf

                            <input type="hidden" name="ao_agenda_id" value="{{ request('agenda') }}">

                            <div>
                                <label class="mb-1 block text-slate-600">Tanggal & Jam Tindakan</label>
                                <input type="datetime-local" name="action_at"
                                    value="{{ old('action_at') }}"
                                    class="w-full rounded-lg border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue">
                                <p class="mt-1 text-[10px] text-slate-400">Kosongkan jika sekarang.</p>
                            </div>

                            <div>
                                <label class="mb-1 block text-slate-600">Jenis Tindakan</label>

                                @php
                                    $preset   = request('preset');
                                    $selected = old('action_type', $preset);
                                @endphp

                                <select
                                    name="action_type"
                                    x-model="actionType"
                                    @change="
                                        if(($event.target.value || '').toLowerCase()==='visit'){
                                            window.location='{{ route('cases.visits.quickStart', $case) }}?agenda={{ request('agenda') }}';
                                        }
                                    "
                                    class="w-full rounded-lg border border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-2 focus:ring-msa-blue/20"
                                >
                                    <option value="">-- pilih --</option>
                                    <option value="whatsapp"  @selected($selected === 'whatsapp')>WhatsApp</option>
                                    <option value="telpon"    @selected($selected === 'telpon')>Telepon</option>
                                    <!-- <option value="call"      @selected($selected === 'call')>Call</option> -->
                                    <option value="visit"     @selected($selected === 'visit')>Kunjungan Lapangan</option>
                                    <!-- <option value="negosiasi" @selected($selected === 'negosiasi')>Negosiasi</option> -->
                                    <option value="lainnya"   @selected($selected === 'lainnya')>Lainnya</option>
                                </select>

                                <p class="mt-1 text-[10px] text-slate-400">
                                    * SP1–SP3 / SPT / SPJAD dikelola lewat tombol <b>SP</b>.
                                </p>
                            </div>

                            <div>
                                <label class="mb-1 block text-slate-600">Hasil Singkat</label>
                                <input type="text" name="result"
                                    value="{{ old('result') }}"
                                    class="w-full rounded-lg border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue"
                                    placeholder="misal: janji bayar tgl 15, tidak ketemu, dsb">
                            </div>

                            {{-- ✅ Bukti Follow-up: muncul hanya WA/CALL/TELPON --}}
                            <div class="md:col-span-3" x-show="showProof" x-cloak>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-extrabold text-slate-900">🧾 Bukti Follow-up (opsional)</div>
                                            <div class="mt-1 text-xs text-slate-600">
                                                Untuk verifikasi follow-up WA/Call. Boleh dikosongkan bila tidak tersedia.
                                                <span class="font-semibold">Maks 3 file • 2MB/file</span> (jpg/png/pdf).
                                            </div>
                                        </div>
                                        <span class="shrink-0 rounded-full border border-indigo-200 bg-white px-3 py-1 text-[11px] font-semibold text-indigo-700">
                                            Disarankan untuk WA/Call
                                        </span>
                                    </div>

                                    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                                        <div class="md:col-span-2">
                                            <label class="mb-1 block text-xs font-semibold text-slate-700">Upload Bukti</label>
                                            <input type="file"
                                                name="proofs[]"
                                                multiple
                                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                                class="block w-full rounded-xl border border-slate-300 bg-white text-xs
                                                        file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-4 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-indigo-700"
                                                x-ref="proofs">
                                            <p class="mt-1 text-[11px] text-slate-500">Tip: screenshot WA / call log / bukti panggilan.</p>
                                        </div>

                                        <div>
                                            <label class="mb-1 block text-xs font-semibold text-slate-700">Catatan Bukti (opsional)</label>
                                            <input type="text"
                                                name="evidence_note"
                                                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs"
                                                placeholder="mis: WA jam 09:10, call 2x"
                                                x-ref="proofNote">
                                            <p class="mt-1 text-[11px] text-slate-500">Akan dicatat sebagai keterangan bukti.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="md:col-span-3">
                                <label class="mb-1 block text-slate-600">Detail Tindakan / Catatan</label>
                                <textarea name="description" rows="2"
                                        class="w-full rounded-lg border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue"
                                        placeholder="Contoh: kunjungan ke alamat rumah, bertemu istri debitur, disampaikan kewajiban pembayaran...">{{ old('description') }}</textarea>
                            </div>

                            <div>
                                <label class="mb-1 block text-slate-600">Rencana Tindak Lanjut</label>
                                <input type="text" name="next_action"
                                    value="{{ old('next_action') }}"
                                    class="w-full rounded-lg border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue"
                                    placeholder="misal: kirim SP1, kunjungan ulang, ajukan restruktur">
                            </div>

                            <div>
                                <label class="mb-1 block text-slate-600">Target Tanggal Tindak Lanjut</label>
                                <input type="date" name="next_action_due"
                                    value="{{ old('next_action_due') }}"
                                    class="w-full rounded-lg border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue">
                            </div>

                            @if(request('agenda'))
                                <div class="md:col-span-3">
                                    <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                        <input type="checkbox" name="mark_agenda_done" value="1"
                                            class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-200">
                                        <span>
                                            Tutup milestone agenda setelah simpan tindakan
                                            <span class="text-slate-500">(opsional)</span>
                                        </span>
                                    </label>
                                    <p class="mt-1 text-[10px] text-slate-400">
                                        Milestone akan ditandai selesai tanpa input tambahan. Jika belum selesai, biarkan tidak dicentang.
                                    </p>
                                </div>
                            @endif

                            <div class="flex justify-end md:col-span-3">
                                <button type="submit"
                                        class="inline-flex items-center rounded-lg bg-msa-blue px-4 py-2 text-xs font-semibold text-white hover:bg-blue-900">
                                    Simpan Log Progres
                                </button>
                            </div>
                        </form>
                    </div>

                    {{-- ✅ 2) SP = stepper existing --}}
                    <div x-show="tab==='sp'" x-cloak>
                        <div class="mb-3">
                            <h3 class="text-sm font-bold text-slate-900">📨 SP / Surat Peringatan</h3>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Proses SPAK–SP3–SPT–SPJAD (Legacy) ditampilkan di sini.
                            </p>
                        </div>

                        @include('cases.partials.sp_legacy_stepper', ['case' => $case])
                    </div>

                    {{-- ✅ 3) Non-Litigasi = konten existing (sekarang cuma link ke halaman nonlit index) --}}
                    <div x-show="tab==='nonlit'" x-cloak>
                        <div class="mb-3">
                            <h3 class="text-sm font-bold text-slate-900">🧩 Non-Litigasi</h3>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Usulan non-litigasi dan proses approval.
                            </p>
                        </div>

                        <a href="{{ route('cases.nonlit.index', $case) }}"
                        class="inline-flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                            <span class="flex items-center gap-2">
                                <span>📄</span>
                                <span>Buka Modul Non-Litigasi</span>
                            </span>
                            <span class="text-xs text-slate-500">→</span>
                        </a>
                    </div>

                    {{-- ✅ 4) Litigasi = eskalasi legal existing (dipindahkan ke panel ini) --}}
                    <div x-show="tab==='lit'" x-cloak>
                        <div class="mb-3">
                            <h3 class="text-sm font-bold text-slate-900">⚖️ Litigasi / Legal</h3>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Gunakan bila penanganan biasa belum berhasil (sudah ada catatan di timeline).
                            </p>
                        </div>

                        {{-- eskalasi legal (UPDATED) --}}
                        <div x-data="{ legalOpen:false, type:'', reportOpen:false }"
                            class="rounded-2xl border border-amber-200 bg-amber-50/40 p-4">

                            @php
                                $proposal = $case->latestLegalProposal;

                                $proposalType   = strtolower((string)($proposal?->action_type ?? ''));
                                $proposalStatus = (string)($proposal?->status ?? '');

                                $isPlakat = $proposal && $proposalType === 'plakat';

                                $isApprovedKasi  = $proposal && $proposalStatus === \App\Models\LegalActionProposal::STATUS_APPROVED_KASI;
                                $isExecuted      = $proposal && $proposalStatus === \App\Models\LegalActionProposal::STATUS_EXECUTED;

                                $canReportPlakat = $isPlakat && $isApprovedKasi && !$isExecuted;
                            @endphp

                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <div class="font-semibold text-amber-900">⚠️ Eskalasi Litigasi</div>
                                        <div class="text-sm text-amber-800 mt-1">
                                            Pastikan bukti proses sudah ada (log persuasif / SP).
                                        </div>

                                        @if($proposal)
                                            <div class="mt-3 rounded-xl bg-white/70 p-3 border border-amber-200">
                                                <div class="text-sm font-semibold text-slate-900">
                                                    ✅ Usulan Legal sudah dibuat
                                                </div>

                                                <div class="mt-1 text-sm text-slate-700">
                                                    <div>Jenis: <b class="uppercase">{{ $proposal->action_type }}</b></div>
                                                    <div>Status: <b>{{ str_replace('_',' ', $proposal->status) }}</b></div>
                                                    <div>
                                                        Diusulkan oleh:
                                                        <b>{{ optional($proposal->proposer)->name ?? ('User#'.$proposal->proposed_by) }}</b>
                                                        <span class="text-slate-500">
                                                            · {{ optional($proposal->created_at)->format('d-m-Y H:i') }}
                                                        </span>
                                                    </div>
                                                </div>

                                                {{-- reason/notes --}}
                                                @if($proposal->reason || $proposal->notes)
                                                    <div class="mt-2 text-xs text-slate-600">
                                                        {{ $proposal->reason ?? '' }} {{ $proposal->notes ?? '' }}
                                                    </div>
                                                @endif

                                                {{-- ✅ jika sudah EXECUTED: tampilkan ringkasan --}}
                                                @if($isExecuted)
                                                    <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900/80">
                                                        <div class="font-semibold">✅ Sudah Dilaporkan (Executed)</div>

                                                        @if($proposal->executed_at)
                                                            <div class="mt-1">Waktu: <b>{{ \Carbon\Carbon::parse($proposal->executed_at)->format('d-m-Y H:i') }}</b></div>
                                                        @endif

                                                        @if(!empty($proposal->executed_notes))
                                                            <div class="mt-1 whitespace-pre-line">Catatan: {{ $proposal->executed_notes }}</div>
                                                        @endif

                                                        @if(!empty($proposal->executed_proof_path))
                                                            <div class="mt-2">
                                                                <a href="{{ asset($proposal->executed_proof_path) }}" target="_blank"
                                                                    class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
                                                                    👁 Lihat Bukti
                                                                </a>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        @else
                                            <div class="mt-3 text-xs text-amber-800">
                                                Tip: minimal ada log penanganan yang jelas sebelum buat legal action.
                                            </div>
                                        @endif
                                    </div>

                                    <div class="shrink-0 flex flex-col gap-2">
                                        @if($proposal)

                                            {{-- ✅ Khusus PLAKAT + sudah APPROVED_KASI => boleh lapor pemasangan --}}
                                            @if($canReportPlakat)
                                                <button type="button"
                                                    @click="reportOpen=true"
                                                    class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                                    🧾 Laporkan Pemasangan
                                                </button>
                                            @endif

                                            {{-- tombol umum: lihat daftar usulan --}}
                                            <a href="{{ route('legal.proposals.index') }}"
                                                class="inline-flex items-center justify-center rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-900 border border-slate-200 hover:bg-slate-50">
                                                Lihat Usulan
                                            </a>

                                        @else
                                            <button type="button"
                                                @click="legalOpen=true"
                                                class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                                + Usulkan Legal
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- Tip hanya tampil kalau belum ada proposal --}}
                            @if(!$proposal)
                                <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900/80">
                                    Tip: minimal ada log penanganan yang jelas sebelum buat legal action.
                                </div>
                            @endif

                            {{-- MODAL LAPOR PEMASANGAN PLAKAT --}}
                            <div x-show="reportOpen" x-cloak class="fixed inset-0 z-40 bg-black/40" @click="reportOpen=false"></div>

                            <div x-show="reportOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                                <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl">
                                    <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                                        <div>
                                            <h3 class="text-lg font-bold text-slate-900">🧾 Laporan Pemasangan Plakat</h3>
                                            <p class="mt-1 text-sm text-slate-500">
                                                Isi tanggal pemasangan, catatan, dan upload bukti (foto/PDF).
                                            </p>
                                        </div>

                                        <button type="button" @click="reportOpen=false"
                                            class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                            Tutup
                                        </button>
                                    </div>

                                    <form method="POST"
                                        enctype="multipart/form-data"
                                        action="{{ $proposal ? route('npl.legal-proposals.plakatReport', [$case, $proposal]) : '#' }}"
                                        class="px-5 py-4">
                                        @csrf

                                        <label class="block text-sm font-semibold text-slate-700">
                                            Tanggal Pemasangan <span class="text-rose-600">*</span>
                                        </label>
                                        <input type="date" name="executed_at"
                                            value="{{ old('executed_at') }}"
                                            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                                            required>

                                        @error('executed_at')
                                            <p class="mt-2 text-sm font-semibold text-rose-600">{{ $message }}</p>
                                        @enderror

                                        <label class="mt-4 block text-sm font-semibold text-slate-700">
                                            Catatan Pemasangan <span class="text-rose-600">*</span>
                                        </label>
                                        <textarea name="executed_notes" rows="4"
                                            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                                            placeholder="Contoh: Plakat dipasang di pagar depan, disaksikan RT, dokumentasi terlampir."
                                            required>{{ old('executed_notes') }}</textarea>

                                        @error('executed_notes')
                                            <p class="mt-2 text-sm font-semibold text-rose-600">{{ $message }}</p>
                                        @enderror

                                        <label class="mt-4 block text-sm font-semibold text-slate-700">
                                            Bukti (Foto/PDF) <span class="text-rose-600">*</span>
                                        </label>
                                        <input type="file" name="proof"
                                            accept="image/jpeg,image/png,application/pdf"
                                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white text-sm
                                                file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-emerald-700"
                                            required>

                                        @error('proof')
                                            <p class="mt-2 text-sm font-semibold text-rose-600">{{ $message }}</p>
                                        @enderror

                                        <div class="mt-6 flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                                            <button type="button" @click="reportOpen=false"
                                                class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                                Batal
                                            </button>

                                            <button type="submit"
                                                class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
                                                Simpan Laporan
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            {{-- =========================
                                MODAL LEGAL (existing) - tetap, hanya aku rapikan sedikit
                                ========================= --}}
                            <div x-show="legalOpen" x-cloak
                                @click="legalOpen=false; type='';"
                                class="fixed inset-0 z-40 bg-black/40"></div>

                            <div x-show="legalOpen" x-cloak
                                @keydown.escape.window="legalOpen=false; type='';"
                                class="fixed inset-0 z-50 flex items-center justify-center p-4">
                                <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl">
                                    <div class="border-b border-slate-200 px-5 py-4">
                                        <h3 class="text-lg font-bold text-slate-900">Pilih Jenis Tindakan Legal</h3>
                                        <p class="text-sm text-slate-500">Sistem akan membuat Legal Action Proposal (status: DRAFT/SUBMITTED sesuai alur kamu).</p>
                                    </div>

                                    <form method="POST" action="{{ route('npl.legal-proposals.store', $case) }}" class="px-5 py-4">
                                        @csrf

                                        <label class="block text-sm font-semibold text-slate-700">Jenis Tindakan</label>
                                        <select x-model="type" name="action_type"
                                            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">-- Pilih --</option>
                                            <option value="plakat">Pasang Plakat</option>
                                            <option value="somasi">Somasi</option>
                                            <option value="ht_execution">Eksekusi HT</option>
                                            <option value="fidusia_execution">Eksekusi Fidusia</option>
                                            <option value="civil_lawsuit">Gugatan Perdata</option>
                                            <option value="pkpu_bankruptcy">PKPU / Kepailitan</option>
                                            <option value="criminal_report">Laporan Pidana</option>
                                        </select>

                                        <div class="mt-4" x-show="type==='plakat'" x-cloak>
                                            <label class="block text-sm font-semibold text-slate-700">
                                                Rencana Pemasangan Plakat (wajib)
                                            </label>

                                            <input type="date"
                                                name="planned_at"
                                                value="{{ old('planned_at') }}"
                                                class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                :required="type==='plakat'">

                                            @error('planned_at')
                                                <p class="mt-2 text-sm font-semibold text-rose-600">{{ $message }}</p>
                                            @enderror

                                            <p class="mt-1 text-xs text-slate-500">
                                                Isi tanggal rencana pemasangan di rumah/tanah debitur.
                                            </p>
                                        </div>

                                        @error('action_type')
                                            <p class="mt-2 text-sm font-semibold text-rose-600">{{ $message }}</p>
                                        @enderror

                                        <label class="mt-4 block text-sm font-semibold text-slate-700">Alasan Usulan (wajib)</label>
                                        <textarea name="reason" rows="4"
                                            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            placeholder="Contoh: Debitur tidak kooperatif, SP sudah lengkap, upaya persuasif gagal, perlu somasi/HT..."
                                            required>{{ old('reason') }}</textarea>

                                        @error('reason')
                                            <p class="mt-2 text-sm font-semibold text-rose-600">{{ $message }}</p>
                                        @enderror

                                        <label class="mt-4 block text-sm font-semibold text-slate-700">Catatan Tambahan (opsional)</label>
                                        <textarea name="notes" rows="3"
                                            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            placeholder="Catatan internal untuk TL/Kasi/BE">{{ old('notes') }}</textarea>

                                        <div class="mt-6 flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                                            <button type="button"
                                                @click="legalOpen=false; type='';"
                                                class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                                Batal
                                            </button>

                                            <button type="submit" :disabled="!type"
                                                    class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                                                Kirim Usulan
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                        </div>

                    </div>

                </div>

            </div>
        </div>

        {{-- KANAN: ringkasan --}}
        <div class="space-y-5 lg:col-span-4">

            {{-- Ringkasan Kredit --}}
            <div class="rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm">
                <p class="mb-2 text-xs font-semibold uppercase text-slate-500">Informasi Kredit</p>
                <dl class="space-y-1 text-xs text-slate-700">
                    <div>
                        <dt class="inline text-slate-500">Produk:</dt>
                        <dd class="inline font-semibold">{{ $loan?->product_type ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="inline text-slate-500">Plafond:</dt>
                        <dd class="inline font-semibold">
                            {{ $loan ? 'Rp '.number_format($loan->plafond, 0, ',', '.') : '-' }}
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Status Kasus --}}
            <div class="rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm">
                <p class="mb-2 text-xs font-semibold uppercase text-slate-500">Status Kasus</p>
                <dl class="space-y-2 text-xs text-slate-700">
                    <div>
                        <dt class="inline text-slate-500">Prioritas:</dt>
                        <dd class="inline font-semibold">{{ strtoupper($case->priority ?? 'normal') }}</dd>
                    </div>
                    <div>
                        <dt class="block text-slate-500">Ringkasan:</dt>
                        <dd class="mt-1 text-slate-700">{{ $case->summary ?? '-' }}</dd>
                    </div>
                </dl>
            </div>

            
            {{-- Catatan --}}
            <div class="rounded-2xl border border-msa-light bg-msa-light/70 px-5 py-4">
                <p class="mb-2 text-xs font-semibold uppercase text-slate-600">Catatan</p>
                <p class="text-xs text-slate-700">
                    Timeline berisi catatan penanganan (telpon, kunjungan, SP, negosiasi, dsb).
                    Tambahkan log setiap selesai melakukan tindakan agar progres mudah dimonitor.
                </p>
            </div>
        </div>

        {{-- MODAL: Target Penyelesaian (UI lengkap) --}}
        <div
            x-data="{ open:false, mode:'view' }"
            x-on:open-target-modal.window="
                open = true;
                mode = ($event.detail && $event.detail.mode) ? $event.detail.mode : 'view';
                $nextTick(() => {
                    const id = (mode === 'create') ? 'target-form' : 'target-history';
                    const el = document.getElementById(id);
                    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            "
            x-cloak
        >

            {{-- backdrop --}}
            <div x-show="open" class="fixed inset-0 z-40 bg-black/40"></div>

            <div x-show="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="w-full max-w-4xl rounded-2xl bg-white shadow-xl">
                    <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900"
                                x-text="mode==='create' ? '🎯 Set Target Penyelesaian' : '🎯 Target Penyelesaian (Lihat & Approval)'"></h3>
                            <p class="text-sm text-slate-500">
                                Rekening: <span class="font-mono">{{ $loan?->account_no ?? '-' }}</span> •
                                Debitur: {{ $loan?->customer_name ?? '-' }}
                            </p>
                        </div>

                        <button type="button" @click="open=false"
                                class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Tutup
                        </button>
                    </div>

                    <div class="px-5 py-4">
                        @include('cases.partials.target_resolution', [
                            'case'   => $case,
                            'target' => $case->activeResolutionTarget, // atau $activeTarget kalau sudah dibuat
                        ])
                    </div>

                </div>
            </div>
        </div>

         {{-- Timeline Actions (tetap) --}}
        <div class="col-span-full lg:col-span-full">
            <div id="timeline" class="rounded-2xl border border-slate-100 bg-white px-4 py-3 shadow-sm">
                <div class="mb-3">
                    <h2 class="text-base font-bold text-slate-900">📚 Timeline Penanganan</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Riwayat tindakan yang pernah dicatat.</p>
                </div>

                @if ($case->actions->count() === 0)
                    <p class="text-xs text-slate-500">
                        Belum ada log progres. Silakan tambahkan tindakan pertama.
                    </p>
                @else

                    {{-- =========================
                        MOBILE: CARD LIST (lebih enak dibaca)
                    ========================== --}}
                    <div class="space-y-3 md:hidden">
                        @foreach ($case->actions as $action)
                            @php
                                // 1) Normalize meta (array|null)
                                $meta = $action->meta;
                                if (is_string($meta)) $meta = json_decode($meta, true);
                                if (!is_array($meta)) $meta = [];

                                // 2) Detect special sources
                                $sourceSystem = (string)($action->source_system ?? '');
                                $actionType   = strtoupper((string)($action->action_type ?? '-'));
                                $resultText   = (string)($action->result ?? '');
                                $resultUpper  = strtoupper(trim($resultText));

                                // Legacy proof
                                $isLegacy = ($sourceSystem === 'legacy_sp') && !empty($action->source_ref_id);
                                $hasProof = (bool)($meta['has_proof'] ?? false);
                                if (!$hasProof && $resultText === 'BUKTI:ADA') $hasProof = true;

                                // Non-Litigasi (submit/approve/reject)
                                $isNonLit  = \Illuminate\Support\Str::startsWith($sourceSystem, 'non_litigation');
                                $nonLitId  = $meta['non_litigation_action_id'] ?? $action->source_ref_id ?? null;
                                $nonLitUrl = $nonLitId ? route('nonlit.show', $nonLitId) : null;

                                // Legal (create/status/etc)
                                $isLegal = \Illuminate\Support\Str::startsWith($sourceSystem, 'legal_') || $actionType === 'LEGAL';

                                // Legal id resolve (untuk semua legal event)
                                if (str_starts_with($sourceSystem, 'legal_')) {
                                    $legalId = $meta['legal_action_id'] ?? $action->source_ref_id;
                                } else {
                                    $legalId = $action->source_ref_id;
                                }

                                $legalUrl = null;
                                if ($legalId) {
                                    $legal = \App\Models\LegalAction::select('id','action_type')->find($legalId);
                                    if ($legal) {
                                        if (($legal->action_type ?? '') === \App\Models\LegalAction::TYPE_HT_EXECUTION) {
                                            $legalUrl = route('legal-actions.ht.show', $legal->id) . '?tab=summary';
                                        } else {
                                            $legalUrl = route('legal-actions.show', $legal->id);
                                        }
                                    }
                                }

                                // 3) Badge styles per type
                                $badgeClass = match (true) {
                                    $isLegal => 'bg-rose-50 text-rose-700 ring-rose-200',
                                    $isNonLit => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
                                    $actionType === 'VISIT' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    in_array($actionType, ['SP1','SP2','SP3','SPT','SPJAD'], true) => 'bg-amber-50 text-amber-700 ring-amber-200',
                                    default => 'bg-slate-100 text-slate-700 ring-slate-200',
                                };

                                // Result chip style
                                $resultClass = match (true) {
                                    str_contains($resultUpper, 'APPROV') => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    str_contains($resultUpper, 'REJECT') => 'bg-rose-50 text-rose-700 ring-rose-200',
                                    str_contains($resultUpper, 'DRAFT')  => 'bg-slate-100 text-slate-700 ring-slate-200',
                                    str_contains($resultUpper, 'SUBMIT') => 'bg-blue-50 text-blue-700 ring-blue-200',
                                    str_contains($resultUpper, 'DONE')   => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    default => 'bg-slate-100 text-slate-700 ring-slate-200',
                                };

                                $timeText = $action->action_at ? $action->action_at->format('d-m-Y H:i') : '-';

                                // Fallback isi deskripsi: description -> result -> '-'
                                $descText = trim((string)($action->description ?? ''));
                                $resText  = trim((string)($action->result ?? ''));
                                $descShow = $descText !== '' ? $descText : ($resText !== '' ? ('Hasil: '.$resText) : '');

                                $descLines = $descShow !== '' ? preg_split("/\r\n|\n|\r/", $descShow) : [];

                                // Follow-up types
                                $atLower = strtolower(trim((string)($action->action_type ?? '')));
                                $isFollowUp = in_array($atLower, ['whatsapp','wa','telpon','telepon','call'], true);

                                // proofs relation (harus di-load di controller: with('proofs'))
                                $proofs = $action->proofs ?? collect();
                                $proofCount = $proofs->count();
                            @endphp

                            <div id="action-{{ $action->id }}" class="rounded-xl border border-slate-200 p-3" x-data="{ openProof:false }">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-xs text-slate-500">{{ $timeText }}</div>

                                        <div class="flex items-center gap-2">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $badgeClass }}">
                                                    {{ $actionType }}
                                                </span>

                                            @if(trim($resultText) !== '')
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $resultClass }}">
                                                    {{ $resultUpper }}
                                                </span>
                                            @endif

                                            {{-- Chip bukti follow-up --}}
                                            
                                            @if($proofCount > 0)
                                                <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold ring-1 bg-slate-50 text-slate-700 ring-slate-200"
                                                    title="Jumlah bukti follow-up">
                                                    📎 {{ $proofCount }}
                                                </span>
                                            @elseif($isFollowUp)
                                                <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold ring-1 bg-slate-50 text-slate-400 ring-slate-200"
                                                    title="Follow-up WA/Call tanpa bukti">
                                                    📎 0
                                                </span>
                                            @endif
                                            

                                        </div>

                                        @if($isLegacy)
                                            <div class="mt-1 text-[10px] text-slate-400">Imported from Legacy SP</div>
                                        @endif

                                        @if($isLegal && !empty($meta['legal_type']))
                                            <div class="mt-1 text-[10px] text-slate-400">
                                                {{ strtoupper((string)$meta['legal_type']) }}
                                            </div>
                                        @endif
                                    </div>

                                    {{-- =========================
                                        AKSI (MOBILE)
                                    ========================= --}}
                                    @php
                                        // ===== meta normalize =====
                                        $meta = $action->meta;
                                        if (is_string($meta)) $meta = json_decode($meta, true);
                                        if (!is_array($meta)) $meta = [];

                                        $metaStatus   = (string)($meta['status'] ?? '');
                                        $metaLegal    = strtolower((string)($meta['legal_type'] ?? ''));
                                        $proposalId   = isset($meta['proposal_id']) ? (int)$meta['proposal_id'] : null;
                                        $legalActionId= isset($meta['legal_action_id']) ? (int)$meta['legal_action_id'] : null;

                                        // deteksi legal row (samakan dengan desktop logic kamu)
                                        $isLegalRow = \Illuminate\Support\Str::startsWith((string)($action->source_system ?? ''), 'legal_')
                                            || strtoupper((string)($action->action_type ?? '')) === 'LEGAL';

                                        $isPlakatProposal = $isLegalRow && ($metaLegal === 'plakat') && !empty($proposalId);

                                        // route plakat report
                                        $needReportPlakat = $isPlakatProposal && ($metaStatus === \App\Models\LegalActionProposal::STATUS_APPROVED_KASI);
                                        $reportUrl = $needReportPlakat
                                            ? route('npl.legal-proposals.plakatReport', ['case' => $case->id, 'proposal' => $proposalId])
                                            : null;

                                        // ambil proposal plakat dari preload (anti N+1) kalau ada
                                        $plakatProposal = null;
                                        if ($isPlakatProposal && isset($plakatProposals) && $proposalId) {
                                            $plakatProposal = $plakatProposals[$proposalId] ?? null;
                                        }

                                        $canReportPlakat = $plakatProposal && $plakatProposal->status === \App\Models\LegalActionProposal::STATUS_APPROVED_KASI;
                                        $hasPlakatProof  = $plakatProposal && !empty($plakatProposal->executed_proof_path);
                                        $plakatProofUrl  = $hasPlakatProof ? asset($plakatProposal->executed_proof_path) : null;

                                        // ✅ detail legal url (fix param action)
                                        $legalUrl = ($legalActionId)
                                            ? route('legal-actions.ht.show', ['action' => $legalActionId])
                                            : null;
                                    @endphp

                                    <div class="flex flex-wrap items-center gap-2 justify-end">
                                        {{-- ✅ PLAKAT PRIORITAS --}}
                                        @if($isPlakatProposal)
                                            @if($canReportPlakat && $reportUrl)
                                                <button type="button"
                                                    onclick="document.getElementById('plakat-report-modal-m-{{ $action->id }}').classList.remove('hidden')"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 px-2.5 py-1.5 text-emerald-700 hover:bg-emerald-50">
                                                    <span>🧾</span>
                                                    <span class="text-[11px] font-semibold">Laporkan</span>
                                                </button>
                                            @elseif($hasPlakatProof && $plakatProofUrl)
                                                <a href="{{ $plakatProofUrl }}" target="_blank"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-slate-700 hover:bg-slate-50">
                                                    <span>👁</span>
                                                    <span class="text-[11px] font-semibold">Bukti</span>
                                                </a>
                                            @endif

                                            {{-- ✅ tetap tampilkan detail legal kalau ada --}}
                                            @if($isLegalRow && $legalUrl)
                                                <a href="{{ $legalUrl }}"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1.5 text-rose-700 hover:bg-rose-50">
                                                    <span>⚖️</span>
                                                    <span class="text-[11px] font-semibold">Detail Legal</span>
                                                </a>
                                            @endif

                                        @else
                                            {{-- =========================
                                                FALLBACK MOBILE (SAMA DENGAN LAMA)
                                            ========================== --}}
                                            @if($isLegalRow && $legalUrl)
                                                <a href="{{ $legalUrl }}"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1.5 text-rose-700 hover:bg-rose-50">
                                                    <span>⚖️</span>
                                                    <span class="text-[11px] font-semibold">Detail Legal</span>
                                                </a>

                                            @elseif($isNonLit && $nonLitUrl)
                                                <a href="{{ $nonLitUrl }}"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-indigo-200 px-2.5 py-1.5 text-indigo-700 hover:bg-indigo-50">
                                                    <span>📄</span>
                                                    <span class="text-[11px] font-semibold">Detail Usulan</span>
                                                </a>

                                            @elseif($isLegacy && $hasProof)
                                                <a href="{{ route('cases.actions.legacy_proof', [$case->id, $action->id]) }}" target="_blank">
                                                    target="_blank"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-slate-700 hover:bg-slate-50">
                                                    <span>👁</span>
                                                    <span class="text-[11px] font-semibold">Bukti</span>
                                                </a>

                                            @elseif($isLegacy)
                                                <span class="inline-flex items-center rounded-lg border border-slate-100 bg-slate-50 px-2.5 py-1.5 text-slate-400"
                                                    title="Belum ada bukti di Legacy">
                                                    <span class="text-[11px] font-semibold">Bukti: Belum</span>
                                                </span>

                                            @elseif(($proofCount ?? 0) > 0)
                                                <button type="button"
                                                    onclick="document.getElementById('proof-modal-{{ $action->id }}').classList.remove('hidden')"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-slate-700 hover:bg-slate-50">
                                                    <span>📎</span>
                                                    <span class="text-[11px] font-semibold">Bukti</span>
                                                </button>

                                            @else
                                                @if($isFollowUp)
                                                    <span class="inline-flex items-center rounded-lg border border-slate-100 bg-slate-50 px-2.5 py-1.5 text-slate-400"
                                                        title="Follow-up WA/Call tanpa bukti">
                                                        <span class="text-[11px] font-semibold">📎 0</span>
                                                    </span>
                                                @else
                                                    <span class="text-[11px] text-slate-300">-</span>
                                                @endif
                                            @endif
                                        @endif
                                    </div>

                                    {{-- ✅ MODAL LAPOR PLAKAT (MOBILE) --}}
                                    @if($isPlakatProposal && $canReportPlakat && $reportUrl)
                                        <div id="plakat-report-modal-m-{{ $action->id }}" class="hidden fixed inset-0 z-50">
                                            <div class="absolute inset-0 bg-black/40"
                                                onclick="document.getElementById('plakat-report-modal-m-{{ $action->id }}').classList.add('hidden')"></div>

                                            <div class="relative mx-auto mt-10 w-[95%] max-w-xl rounded-2xl bg-white shadow-xl">
                                                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                                                    <div>
                                                        <div class="text-sm font-bold text-slate-900">🧾 Laporan Pemasangan Plakat</div>
                                                        <div class="text-xs text-slate-500">Case #{{ $case->id }} • Proposal #{{ $proposalId }}</div>
                                                    </div>
                                                    <button type="button"
                                                        onclick="document.getElementById('plakat-report-modal-m-{{ $action->id }}').classList.add('hidden')"
                                                        class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">
                                                        Tutup
                                                    </button>
                                                </div>

                                                <form method="POST" action="{{ $reportUrl }}" enctype="multipart/form-data" class="px-5 py-4 space-y-3">
                                                    @csrf

                                                    <div>
                                                        <label class="block text-xs font-semibold text-slate-700 mb-1">Tanggal Pemasangan</label>
                                                        <input type="date" name="executed_at" required
                                                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200">
                                                    </div>

                                                    <div>
                                                        <label class="block text-xs font-semibold text-slate-700 mb-1">Catatan</label>
                                                        <textarea name="executed_notes" rows="3" required
                                                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200"
                                                            placeholder="Misal: plakat dipasang di pagar depan, disaksikan RT, dll."></textarea>
                                                    </div>

                                                    <div>
                                                        <label class="block text-xs font-semibold text-slate-700 mb-1">Bukti (JPG/PNG/PDF)</label>
                                                        <input type="file" name="proof" required accept=".jpg,.jpeg,.png,.pdf"
                                                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                                        <div class="mt-1 text-[11px] text-slate-500">Max 4MB.</div>
                                                    </div>

                                                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                                        <button type="button"
                                                            onclick="document.getElementById('plakat-report-modal-m-{{ $action->id }}').classList.add('hidden')"
                                                            class="rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 hover:bg-slate-50">
                                                            Batal
                                                        </button>
                                                        <button type="submit"
                                                            class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                                                            Simpan Laporan
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    @endif


                                </div>

                                <div class="mt-3 text-sm text-slate-700">
                                    @if (!empty($descLines))
                                        <div class="space-y-1 whitespace-pre-line break-words">
                                            @foreach($descLines as $line)
                                                @if(trim($line) !== '')
                                                    <div class="leading-relaxed">{{ $line }}</div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-slate-400">-</span>
                                    @endif
                                </div>

                                <div class="mt-3 border-t border-slate-100 pt-2">
                                    <div class="text-xs text-slate-500">Next Action</div>

                                    @if ($action->next_action)
                                        <div class="mt-0.5 text-sm text-slate-700 whitespace-pre-line break-words">
                                            {{ $action->next_action }}
                                        </div>
                                    @else
                                        <div class="mt-0.5 text-sm text-slate-300">-</div>
                                    @endif

                                    @if ($action->next_action_due)
                                        <div class="mt-1 text-[10px] text-slate-400">
                                            Target: {{ $action->next_action_due->format('d-m-Y') }}
                                        </div>
                                    @endif
                                </div>

                                {{-- MODAL BUKTI (mobile) --}}
                                @if($proofCount > 0)
                                    <div x-show="openProof" x-cloak class="fixed inset-0 z-40 bg-black/40" @click="openProof=false"></div>

                                    <div x-show="openProof" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                                        <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl">
                                            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                                                <div>
                                                    <div class="text-sm font-bold text-slate-900">📎 Bukti Follow-up</div>
                                                    <div class="text-xs text-slate-500">{{ $actionType }} • {{ $timeText }}</div>
                                                </div>
                                                <button type="button" @click="openProof=false"
                                                        class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">
                                                    Tutup
                                                </button>
                                            </div>

                                            <div class="max-h-[70vh] overflow-auto px-5 py-4 space-y-2">
                                                @foreach($proofs as $p)
                                                    @php
                                                        $url = asset($p->file_path); // tanpa symlink mode
                                                        $isImg = str_starts_with((string)$p->mime, 'image/');
                                                    @endphp

                                                    <div class="rounded-xl border border-slate-200 p-3">
                                                        <div class="flex items-start justify-between gap-3">
                                                            <div class="min-w-0">
                                                                <div class="text-xs font-semibold text-slate-800 truncate">
                                                                    {{ $p->original_name ?? ('Bukti #' . $loop->iteration) }}
                                                                </div>
                                                                @if(!empty($p->note))
                                                                    <div class="mt-1 text-[11px] text-slate-500">{{ $p->note }}</div>
                                                                @endif
                                                                <div class="mt-1 text-[10px] text-slate-400">
                                                                    {{ $p->mime ?? '-' }} • {{ $p->size ? number_format($p->size/1024,0) . ' KB' : '-' }}
                                                                </div>
                                                            </div>

                                                            <a href="{{ $url }}" target="_blank"
                                                                class="shrink-0 inline-flex items-center gap-1 rounded-lg border border-indigo-200 px-2 py-1 text-indigo-700 hover:bg-indigo-50">
                                                                <span>👁</span><span class="text-[11px] font-semibold">Buka</span>
                                                            </a>
                                                        </div>

                                                        @if($isImg)
                                                            <div class="mt-3">
                                                                <img src="{{ $url }}" alt="proof"
                                                                    class="max-h-64 w-full rounded-lg border border-slate-100 object-contain bg-slate-50">
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- =========================
                        DESKTOP/TABLET: TABLE + WRAP SAFE
                    ========================== --}}
                    <div class="hidden md:block">
                        <div class="overflow-x-auto">
                            <table class="min-w-[980px] w-full text-xs">
                                <thead class="border-b border-slate-100 bg-slate-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600 w-[160px]">Waktu</th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600 w-[120px]">Jenis</th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Deskripsi</th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600 w-[200px]">Next Action</th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600 w-[140px]">Aksi</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach ($case->actions as $action)
                                        @php
                                            // 1) Normalize meta (array|null)
                                            $meta = $action->meta;
                                            if (is_string($meta)) $meta = json_decode($meta, true);
                                            if (!is_array($meta)) $meta = [];

                                            // 2) Detect special sources
                                            $sourceSystem = (string)($action->source_system ?? '');
                                            $actionType   = strtoupper((string)($action->action_type ?? '-'));
                                            $resultText   = (string)($action->result ?? '');
                                            $resultUpper  = strtoupper(trim($resultText));

                                            // Legacy proof
                                            $isLegacy = ($sourceSystem === 'legacy_sp') && !empty($action->source_ref_id);
                                            $hasProof = (bool)($meta['has_proof'] ?? false);
                                            if (!$hasProof && $resultText === 'BUKTI:ADA') $hasProof = true;

                                            // Non-Litigasi
                                            $isNonLit  = \Illuminate\Support\Str::startsWith($sourceSystem, 'non_litigation');
                                            $nonLitId  = $action->source_ref_id ?: ($meta['non_litigation_action_id'] ?? null);
                                            $nonLitUrl = $nonLitId ? route('nonlit.show', $nonLitId) : null;

                                            // Legal
                                            $isLegal = \Illuminate\Support\Str::startsWith($sourceSystem, 'legal_') || $actionType === 'LEGAL';
                                            $legalId = $action->source_ref_id ?: ($meta['legal_action_id'] ?? null);

                                            $legalUrl = null;
                                            if ($legalId) {
                                                $legal = \App\Models\LegalAction::select('id','action_type')->find($legalId);
                                                if ($legal && ($legal->action_type ?? '') === \App\Models\LegalAction::TYPE_HT_EXECUTION) {
                                                    $legalUrl = route('legal-actions.ht.show', [
                                                        'action' => $legal->id,
                                                        'tab' => 'summary',
                                                    ]);
                                                } else {
                                                    $legalUrl = route('legal-actions.show', $legalId);
                                                }
                                            }

                                            // 3) Badge styles
                                            $badgeClass = match (true) {
                                                $isLegal => 'bg-rose-50 text-rose-700 ring-rose-200',
                                                $isNonLit => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
                                                $actionType === 'VISIT' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                                in_array($actionType, ['SP1','SP2','SP3','SPT','SPJAD'], true) => 'bg-amber-50 text-amber-700 ring-amber-200',
                                                default => 'bg-slate-100 text-slate-700 ring-slate-200',
                                            };

                                            // Follow-up types
                                            $atLower = strtolower(trim((string)($action->action_type ?? '')));
                                            $isFollowUp = in_array($atLower, ['whatsapp','wa','telpon','telepon','call'], true);

                                            // proofs relation (harus di-load)
                                            $proofs = $action->proofs ?? collect();
                                            $proofCount = $proofs->count();

                                            // Fallback deskripsi: description -> result
                                            $descText = trim((string)($action->description ?? ''));
                                            $resText  = trim((string)($action->result ?? ''));
                                            $descShow = $descText !== '' ? $descText : ($resText !== '' ? ('Hasil: '.$resText) : '');

                                            $descLines = $descShow !== '' ? preg_split("/\r\n|\n|\r/", $descShow) : [];

                                            $timeText = $action->action_at ? $action->action_at->format('d-m-Y H:i') : '-';
                                        @endphp

                                        <tr id="action-{{ $action->id }}" class="align-top border-b border-slate-100 last:border-b-0">
                                            <td class="whitespace-nowrap px-3 py-2 text-slate-700">
                                                {{ $timeText }}
                                            </td>

                                            <td class="px-3 py-2 text-slate-700">
                                                <div class="flex items-center gap-2">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $badgeClass }}">
                                                    {{ $actionType }}
                                                </span>

                                            {{-- Chip bukti follow-up --}}
                                            
                                            @if($proofCount > 0)
                                                <span class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M21.44 11.05l-8.49 8.49a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.19 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                                                    </svg>
                                                    <span>{{ $proofCount }}</span>
                                                </span>

                                            @elseif($isFollowUp)
                                                <span class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M21.44 11.05l-8.49 8.49a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.19 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                                                    </svg>
                                                    <span>{{ $proofCount }}</span>
                                                </span>
                                            @endif
                                            

                                        </div>

                                                @if($isLegacy)
                                                    <div class="mt-1 text-[10px] text-slate-400">Imported from Legacy SP</div>
                                                @endif

                                                @if($isLegal && !empty($meta['legal_type']))
                                                    <div class="mt-1 text-[10px] text-slate-400">
                                                        {{ strtoupper((string)$meta['legal_type']) }}
                                                    </div>
                                                @endif
                                            </td>

                                            <td class="px-3 py-2 text-slate-700">
                                                @if (!empty($descLines))
                                                    <div class="space-y-1 whitespace-pre-line break-words">
                                                        @foreach($descLines as $line)
                                                            @if(trim($line) !== '')
                                                                <div class="leading-relaxed">{{ $line }}</div>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <span class="text-slate-400">-</span>
                                                @endif
                                            </td>

                                            <td class="px-3 py-2 text-slate-700">
                                                @if ($action->next_action)
                                                    <div class="leading-relaxed whitespace-pre-line break-words">{{ $action->next_action }}</div>
                                                @else
                                                    <div class="text-slate-300">-</div>
                                                @endif

                                                @if ($action->next_action_due)
                                                    <div class="mt-1 text-[10px] text-slate-400">
                                                        Target: {{ $action->next_action_due->format('d-m-Y') }}
                                                    </div>
                                                @endif
                                            </td>

                                            <td class="px-3 py-2 text-slate-700">
                                                @php
                                                    // ===== meta normalize =====
                                                    $meta = $action->meta;
                                                    if (is_string($meta)) $meta = json_decode($meta, true);
                                                    if (!is_array($meta)) $meta = [];

                                                    $metaStatus = (string)($meta['status'] ?? '');
                                                    $metaLegal  = strtolower((string)($meta['legal_type'] ?? ''));
                                                    $proposalId = isset($meta['proposal_id']) ? (int)$meta['proposal_id'] : null;

                                                    // detect type row (asumsi variabel ini sudah kamu punya dari atas)
                                                    // $isLegal, $isNonLit, $isLegacy, $isFollowUp, $proofCount, $hasProof, $nonLitUrl, dll.

                                                    // ===== legal detail id (FIX 404) =====
                                                    $legalActionId = isset($meta['legal_action_id']) ? (int)$meta['legal_action_id'] : null;

                                                    $legalUrl = $legalActionId
                                                        ? route('legal-actions.ht.show', ['action' => $legalActionId])
                                                        : null;

                                                    // ===== PLAKAT decision (pakai preload) =====
                                                    $isPlakatProposal = $isLegal && ($metaLegal === 'plakat') && !empty($proposalId);
                                                    $plakatProposal   = $isPlakatProposal ? ($plakatProposals[$proposalId] ?? null) : null;

                                                    $canReportPlakat  = (bool)($plakatProposal && $plakatProposal->status === \App\Models\LegalActionProposal::STATUS_APPROVED_KASI);
                                                    $hasPlakatProof   = (bool)($plakatProposal && !empty($plakatProposal->executed_proof_path));
                                                    $plakatProofUrl   = $hasPlakatProof ? asset($plakatProposal->executed_proof_path) : null;

                                                    $reportUrl = $canReportPlakat
                                                        ? route('npl.legal-proposals.plakatReport', ['case' => $case->id, 'proposal' => $proposalId])
                                                        : null;
                                                @endphp

                                                <div class="flex flex-wrap items-center gap-2">

                                                    {{-- ✅ PLAKAT: PRIORITAS --}}
                                                    @if($canReportPlakat && $reportUrl)
                                                        <button type="button"
                                                            onclick="document.getElementById('plakat-report-modal-{{ $action->id }}').classList.remove('hidden')"
                                                            class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 px-2 py-1 text-emerald-700 hover:bg-emerald-50"
                                                            title="Laporkan pemasangan plakat">
                                                            <span>🧾</span>
                                                            <span class="text-[11px] font-semibold">Laporkan</span>
                                                        </button>

                                                    @elseif($hasPlakatProof && $plakatProofUrl)
                                                        <a href="{{ $plakatProofUrl }}" target="_blank"
                                                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-slate-700 hover:bg-slate-50"
                                                            title="Lihat bukti pemasangan plakat">
                                                            <span>👁</span>
                                                            <span class="text-[11px] font-semibold">Bukti</span>
                                                        </a>
                                                    @endif

                                                    {{-- ✅ FALLBACK: tetap tampil sesuai script lama (jangan hilang) --}}
                                                    @if(!$isPlakatProposal)
                                                        @if($isLegal && $legalUrl)
                                                            <a href="{{ $legalUrl }}"
                                                                class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2 py-1 text-rose-700 hover:bg-rose-50"
                                                                title="Buka detail tindakan legal">
                                                                <span>⚖️</span>
                                                                <span class="text-[11px] font-semibold">Detail Legal</span>
                                                            </a>

                                                        @elseif($isNonLit && $nonLitUrl)
                                                            <a href="{{ $nonLitUrl }}"
                                                                class="inline-flex items-center gap-1 rounded-lg border border-indigo-200 px-2 py-1 text-indigo-700 hover:bg-indigo-50"
                                                                title="Buka detail usulan Non-Litigasi">
                                                                <span>📄</span>
                                                                <span class="text-[11px] font-semibold">Detail Usulan</span>
                                                            </a>
                                                            @if($isLegal && !$legalUrl)
                                                                <span class="inline-flex items-center rounded-lg border border-slate-100 bg-slate-50 px-2 py-1 text-slate-400"
                                                                    title="Belum ada legal_action_id di timeline meta">
                                                                    <span class="text-[11px] font-semibold">Detail: -</span>
                                                                </span>
                                                            @endif


                                                        @elseif($isLegacy && $hasProof)
                                                            <a href="{{ route('cases.actions.legacy_proof', [$case->id, $action->id]) }}"
                                                                target="_blank"
                                                                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-slate-700 hover:bg-slate-50"
                                                                title="Lihat bukti tanda terima (Legacy)">
                                                                <span>👁</span>
                                                                <span class="text-[11px] font-semibold">Bukti</span>
                                                            </a>

                                                        @elseif($isLegacy)
                                                            {{-- ✅ INI yang kemarin hilang: harus tetap ada --}}
                                                            <span class="inline-flex items-center rounded-lg border border-slate-100 bg-slate-50 px-2 py-1 text-slate-400"
                                                                title="Belum ada bukti di Legacy">
                                                                <span class="text-[11px] font-semibold">Bukti: Belum</span>
                                                            </span>

                                                        @elseif($proofCount > 0)
                                                            <button type="button"
                                                                onclick="document.getElementById('proof-modal-{{ $action->id }}').classList.remove('hidden')"
                                                                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-slate-700 hover:bg-slate-50"
                                                                title="Lihat bukti follow-up">
                                                                <span>📎</span>
                                                                <span class="text-[11px] font-semibold">Bukti</span>
                                                            </button>
                                                        @else
                                                            @if($isFollowUp)
                                                                <span class="inline-flex items-center rounded-lg border border-slate-100 bg-slate-50 px-2 py-1 text-slate-400"
                                                                    title="Follow-up WA/Call tanpa bukti">
                                                                    <span class="text-[11px] font-semibold">📎 0</span>
                                                                </span>
                                                            @else
                                                                <span class="text-[11px] text-slate-300">-</span>
                                                            @endif
                                                        @endif
                                                    @endif

                                                </div>

                                                {{-- ✅ MODAL LAPOR PLAKAT (desktop) --}}
                                                @if($canReportPlakat && $reportUrl)
                                                    <div id="plakat-report-modal-{{ $action->id }}" class="hidden fixed inset-0 z-50">
                                                        <div class="absolute inset-0 bg-black/40"
                                                            onclick="document.getElementById('plakat-report-modal-{{ $action->id }}').classList.add('hidden')"></div>

                                                        <div class="relative mx-auto mt-10 w-[95%] max-w-xl rounded-2xl bg-white shadow-xl">
                                                            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                                                                <div>
                                                                    <div class="text-sm font-bold text-slate-900">🧾 Laporan Pemasangan Plakat</div>
                                                                    <div class="text-xs text-slate-500">Case #{{ $case->id }} • Proposal #{{ $proposalId }}</div>
                                                                </div>
                                                                <button type="button"
                                                                    onclick="document.getElementById('plakat-report-modal-{{ $action->id }}').classList.add('hidden')"
                                                                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">
                                                                    Tutup
                                                                </button>
                                                            </div>

                                                            <form method="POST" action="{{ $reportUrl }}" enctype="multipart/form-data" class="px-5 py-4 space-y-3">
                                                                @csrf
                                                                <div>
                                                                    <label class="block text-xs font-semibold text-slate-700 mb-1">Tanggal Pemasangan</label>
                                                                    <input type="date" name="executed_at" required
                                                                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200">
                                                                </div>

                                                                <div>
                                                                    <label class="block text-xs font-semibold text-slate-700 mb-1">Catatan</label>
                                                                    <textarea name="executed_notes" rows="3" required
                                                                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200"
                                                                        placeholder="Misal: plakat dipasang di pagar depan, disaksikan RT, dll."></textarea>
                                                                </div>

                                                                <div>
                                                                    <label class="block text-xs font-semibold text-slate-700 mb-1">Bukti (JPG/PNG/PDF)</label>
                                                                    <input type="file" name="proof" required accept=".jpg,.jpeg,.png,.pdf"
                                                                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                                                    <div class="mt-1 text-[11px] text-slate-500">Max 4MB.</div>
                                                                </div>

                                                                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                                                    <button type="button"
                                                                        onclick="document.getElementById('plakat-report-modal-{{ $action->id }}').classList.add('hidden')"
                                                                        class="rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 hover:bg-slate-50">
                                                                        Batal
                                                                    </button>
                                                                    <button type="submit"
                                                                        class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                                                                        Simpan Laporan
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                @endif
                                                </td>

                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                            {{-- MODAL BUKTI (desktop) --}}
                            @foreach ($case->actions as $action)
                                @php
                                    $proofs = $action->proofs ?? collect();
                                @endphp

                                @if($proofs->isNotEmpty())
                                    <div id="proof-modal-{{ $action->id }}" class="hidden fixed inset-0 z-50">
                                        <div class="absolute inset-0 bg-black/40"
                                            onclick="document.getElementById('proof-modal-{{ $action->id }}').classList.add('hidden')"></div>

                                        <div class="relative mx-auto mt-10 w-[95%] max-w-3xl rounded-2xl bg-white shadow-xl">
                                            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                                                <div>
                                                    <div class="text-sm font-bold text-slate-900">📎 Bukti Follow-up</div>
                                                    <div class="text-xs text-slate-500">Action #{{ $action->id }}</div>
                                                </div>
                                                <button type="button"
                                                        onclick="document.getElementById('proof-modal-{{ $action->id }}').classList.add('hidden')"
                                                        class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">
                                                    Tutup
                                                </button>
                                            </div>

                                            <div class="max-h-[70vh] overflow-auto px-5 py-4 space-y-3">
                                                @foreach($proofs as $p)
                                                    @php
                                                        $url = asset($p->file_path);
                                                        $isImg = str_starts_with((string)$p->mime, 'image/');
                                                    @endphp

                                                    <div class="rounded-xl border border-slate-200 p-3">
                                                        <div class="flex items-start justify-between gap-3">
                                                            <div class="min-w-0">
                                                                <div class="text-xs font-semibold text-slate-800 truncate">
                                                                    {{ $p->original_name ?? ('Bukti #' . $loop->iteration) }}
                                                                </div>
                                                                @if(!empty($p->note))
                                                                    <div class="mt-1 text-[11px] text-slate-500">{{ $p->note }}</div>
                                                                @endif
                                                            </div>
                                                            <a href="{{ $url }}" target="_blank"
                                                                class="shrink-0 inline-flex items-center gap-1 rounded-lg border border-indigo-200 px-2 py-1 text-indigo-700 hover:bg-indigo-50">
                                                                <span>👁</span><span class="text-[11px] font-semibold">Buka</span>
                                                            </a>
                                                        </div>

                                                        @if($isImg)
                                                            <div class="mt-3">
                                                                <img src="{{ $url }}" class="max-h-[420px] w-full rounded-lg border border-slate-100 object-contain bg-slate-50">
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>

    </div>
</div>
@endsection
