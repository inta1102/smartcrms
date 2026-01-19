@extends('layouts.app')

@section('title', "Agenda AO $aoName")

@section('content')

@php
function schedule_label($type) {
    return match($type) {
        'sp1'   => 'SP1',
        'sp2'   => 'SP2',
        'sp3'   => 'SP3',
        'spt'   => 'SPT',
        'spjad' => 'SPJAD',
        default => ucfirst($type),
    };
}

function schedule_color($type) {
    return match($type) {
        'sp1'   => 'bg-red-50 text-red-700 border-red-200',
        'sp2'   => 'bg-orange-50 text-orange-700 border-orange-200',
        'sp3'   => 'bg-amber-50 text-amber-700 border-amber-300',
        'spt'   => 'bg-rose-50 text-rose-700 border-rose-300',
        'spjad' => 'bg-purple-50 text-purple-700 border-purple-300',
        default => 'bg-slate-50 text-slate-700 border-slate-200',
    };
}

// helper kecil biar SP detect case-insensitive & rapi
function is_sp_type($type): bool {
    return in_array(strtolower((string)$type), ['sp1','sp2','sp3','spt','spjad'], true);
}

function find_sp_action_for_schedule($sch) {
    $type = strtolower((string)($sch->type ?? ''));
    $actions = $sch->nplCase?->actions;

    if (!$actions) return null;

    // actions kadang 'SP1'/'sp1' -> samakan
    return $actions->filter(function($a) use ($type) {
            return strtolower((string)($a->action_type ?? '')) === $type;
        })
        ->sortByDesc('action_at')
        ->first();
}

function action_meta_array($action): array {
    $meta = $action?->meta;
    if (is_string($meta)) $meta = json_decode($meta, true);
    return is_array($meta) ? $meta : [];
}

function action_has_legacy_proof($action, array $meta): bool {
    $isLegacy = (($action?->source_system ?? '') === 'legacy_sp') && !empty($action?->source_ref_id);
    if (!$isLegacy) return false;

    $hasProof = (bool)($meta['has_proof'] ?? false);
    if (!$hasProof && strtoupper(trim((string)($action?->result ?? ''))) === 'BUKTI:ADA') $hasProof = true;

    return $hasProof;
}
@endphp

<div class="w-full max-w-6xl space-y-4">

    {{-- HEADER --}}
    <div class="flex items-center justify-between gap-3">
        <div>
            <h1 class="text-xl md:text-2xl font-semibold text-slate-800">
                Agenda Tindakan — AO {{ $aoName ?? $aoCode }}
            </h1>
            <p class="text-xs md:text-sm text-slate-500 mt-1">
                Daftar tugas follow-up dan kunjungan berdasarkan jadwal sistem (action_schedules).
            </p>
        </div>

        @if(!empty($aoCode))
            <a href="{{ route('dashboard.ao.show', ['aoCode' => $aoCode]) }}"
            class="hidden sm:inline-flex px-3 py-1.5 rounded-lg border border-slate-300 text-xs text-slate-700 hover:bg-slate-50">
                Kembali ke Kasus AO
            </a>
        @endif

    </div>

    {{-- RINGKASAN --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="bg-white rounded-2xl border border-slate-100 p-3">
            <div class="text-[11px] text-slate-500">Overdue</div>
            <div class="text-2xl font-semibold text-red-600">
                {{ $overdue->count() }}
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-100 p-3">
            <div class="text-[11px] text-slate-500">
                Hari Ini ({{ $today->format('d M Y') }})
            </div>
            <div class="text-2xl font-semibold text-amber-600">
                {{ $todayList->count() }}
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-100 p-3">
            <div class="text-[11px] text-slate-500">14 Hari ke Depan</div>
            <div class="text-2xl font-semibold text-slate-800">
                {{ $upcoming->count() }}
            </div>
        </div>
    </div>

    {{-- ========== SECTION OVERDUE ========== --}}
    <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
        <div class="px-4 py-2 border-b border-slate-100 flex items-center justify-between">
            <div class="text-sm font-semibold text-red-700">
                Overdue
            </div>
            <span class="text-[11px] text-slate-500">
                Jadwal sebelum hari ini yang belum diselesaikan.
            </span>
        </div>

        {{-- MOBILE --}}
        <div class="md:hidden divide-y divide-slate-100">
            @forelse($overdue as $sch)
                @php
                    $loan = $sch->nplCase->loanAccount;

                    // SP action checker (case-insensitive)
                    $spAction = is_sp_type($sch->type) ? find_sp_action_for_schedule($sch) : null;
                    $meta = action_meta_array($spAction);
                    $hasLegacyProof = action_has_legacy_proof($spAction, $meta);
                @endphp

                <div class="px-4 py-3">
                    <div class="flex items-center justify-between">
                        <div class="text-[11px] font-semibold text-red-700">
                            {{ $sch->scheduled_at->format('d M Y') }}
                        </div>
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-red-50 text-red-700 text-[10px] font-semibold">
                            Overdue
                        </span>
                    </div>

                    <div class="mt-1">
                        <div class="text-sm font-semibold text-slate-800">
                            {{ $sch->title ?? ucfirst($sch->type) }}
                        </div>
                        <div class="text-[11px] text-slate-500">
                            {{ $sch->notes }}
                        </div>
                    </div>

                    <div class="mt-2 text-[11px] text-slate-600">
                        @php
                            $caseId = $sch->npl_case_id ?? $sch->nplCase?->id;
                            $debtor = $sch->debtor_name
                                ?? $sch->nplCase?->debtor_name
                                ?? $sch->nplCase?->loanAccount?->debtor_name
                                ?? $sch->nplCase?->loanAccount?->customer_name
                                ?? 'DEBITUR';
                        @endphp

                        @if($caseId)
                            <a href="{{ route('cases.show', $caseId) }}"
                            class="font-extrabold text-sky-600 hover:text-sky-700 hover:underline transition">
                                {{ $debtor }}
                            </a>
                        @else
                            <span class="font-extrabold text-slate-900">{{ $debtor }}</span>
                        @endif
                        <div>
                            Rek: {{ $loan->account_no }} • CIF: {{ $loan->cif }}
                        </div>
                        <div>DPD: <span class="font-semibold">{{ $loan->dpd }}</span></div>
                    </div>

                    <div class="mt-2">
                        @if (is_sp_type($sch->type))
                            @if ($spAction)
                                {{-- Kalau sudah ada action SP, jangan "Mulai" lagi --}}
                                @if($hasLegacyProof)
                                    <a href="{{ route('cases.actions.legacy_proof', [$sch->nplCase->id, $spAction->id]) }}"
                                    target="_blank"
                                    class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                        Lihat Bukti {{ strtoupper($sch->type) }}
                                    </a>
                                @else
                                    <a href="{{ route('cases.show', $sch->nplCase->id) }}#timeline"
                                    class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                        Lihat Riwayat {{ strtoupper($sch->type) }}
                                    </a>
                                @endif
                            @else
                                {{-- Belum ada action SP, baru boleh mulai --}}
                                <a href="{{ route('cases.sp.form', [$sch->nplCase->id, strtolower($sch->type)]) }}"
                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-red-500 text-[11px] font-semibold text-red-600 hover:bg-red-600 hover:text-white">
                                    Mulai {{ strtoupper($sch->type) }}
                                </a>
                            @endif

                        @elseif (strtolower((string)$sch->type) === 'visit')
                            <a href="{{ route('visits.start', $sch->id) }}"
                            class="inline-flex items-center px-3 py-1.5 rounded-lg border border-emerald-600 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-600 hover:text-white">
                                Mulai Visit
                            </a>

                        @else
                            <a href="{{ route('cases.show', $sch->nplCase->id) }}"
                            class="inline-flex items-center px-3 py-1.5 rounded-lg border border-msa-blue text-[11px] font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                                Buka Kasus
                            </a>
                        @endif
                    </div>

                </div>
            @empty
                <div class="px-4 py-3 text-xs text-slate-500">
                    Tidak ada jadwal overdue. Good job 👍
                </div>
            @endforelse
        </div>

        {{-- DESKTOP --}}
        <div class="hidden md:block">
            @if ($overdue->isEmpty())
                <div class="px-4 py-3 text-xs text-slate-500">
                    Tidak ada jadwal overdue. Good job 👍
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs md:text-sm">
                        <thead class="bg-red-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">Tanggal</th>
                                <th class="px-3 py-2 text-left font-semibold">Tugas</th>
                                <th class="px-3 py-2 text-left font-semibold">Debitur</th>
                                <th class="px-3 py-2 text-center font-semibold">DPD</th>
                                <th class="px-3 py-2 text-center font-semibold">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($overdue as $sch)
                                @php
                                    $loan = $sch->nplCase->loanAccount;

                                    $spAction = is_sp_type($sch->type) ? find_sp_action_for_schedule($sch) : null;
                                    $meta = action_meta_array($spAction);
                                    $hasLegacyProof = action_has_legacy_proof($spAction, $meta);
                                @endphp

                                <tr class="border-b border-slate-100 hover:bg-red-50/40">
                                    <td class="px-3 py-2 text-[11px] md:text-xs text-red-700 font-semibold">
                                        {{ $sch->scheduled_at->format('d M Y') }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="font-semibold text-slate-800">
                                            {{ $sch->title ?? ucfirst($sch->type) }}
                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            {{ $sch->notes }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="font-semibold text-slate-800">
                                            @php
                                                $caseId = $sch->npl_case_id ?? $sch->nplCase?->id;
                                                $debtor = $sch->debtor_name
                                                    ?? $sch->nplCase?->debtor_name
                                                    ?? $sch->nplCase?->loanAccount?->debtor_name
                                                    ?? $sch->nplCase?->loanAccount?->customer_name
                                                    ?? 'DEBITUR';
                                            @endphp

                                            @if($caseId)
                                                <a href="{{ route('cases.show', $caseId) }}"
                                                class="font-extrabold text-sky-600 hover:text-sky-700 hover:underline transition">
                                                    {{ $debtor }}
                                                </a>
                                            @else
                                                <span class="font-extrabold text-slate-900">{{ $debtor }}</span>
                                            @endif

                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            Rek: {{ $loan->account_no }} | CIF: {{ $loan->cif }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-center text-[11px] font-semibold">
                                        {{ $loan->dpd }}
                                    </td>

                                    {{-- DESKTOP ACTION BUTTON: rapi + konsisten + anti dobel --}}
                                    <td class="px-3 py-2 text-center">
                                        <div class="inline-flex items-center justify-center gap-2">
                                            @if (is_sp_type($sch->type))
                                                @if ($spAction)
                                                    @if($hasLegacyProof)
                                                        <a href="{{ route('cases.actions.legacy_proof', [$sch->nplCase->id, $spAction->id]) }}"
                                                        target="_blank"
                                                        class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                                            Lihat Bukti {{ strtoupper($sch->type) }}
                                                        </a>
                                                    @else
                                                        <a href="{{ route('cases.show', $sch->nplCase->id) }}#timeline"
                                                        class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                                            Lihat Riwayat {{ strtoupper($sch->type) }}
                                                        </a>
                                                    @endif
                                                @else
                                                    <a href="{{ route('cases.sp.form', [$sch->nplCase->id, strtolower($sch->type)]) }}"
                                                    class="inline-flex items-center px-3 py-1.5 rounded-lg border border-red-500 text-[11px] font-semibold text-red-600 hover:bg-red-600 hover:text-white">
                                                        Mulai {{ strtoupper($sch->type) }}
                                                    </a>
                                                @endif

                                            @elseif (strtolower((string)$sch->type) === 'visit')
                                                <a href="{{ route('visits.start', $sch->id) }}"
                                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-emerald-600 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-600 hover:text-white">
                                                    Mulai Visit
                                                </a>

                                            @else
                                                <a href="{{ route('cases.show', $sch->nplCase->id) }}"
                                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-msa-blue text-[11px] font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                                                    Buka Kasus
                                                </a>
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
    </div>

    {{-- ========== SECTION HARI INI ========== --}}
    <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
        <div class="px-4 py-2 border-b border-slate-100 flex items-center justify-between">
            <div class="text-sm font-semibold text-amber-700">
                Hari Ini
            </div>
            <span class="text-[11px] text-slate-500">
                Agenda tindakan pada tanggal {{ $today->format('d M Y') }}.
            </span>
        </div>

        {{-- MOBILE --}}
        <div class="md:hidden divide-y divide-slate-100">
            @forelse($todayList as $sch)
                @php
                    $loan = $sch->nplCase->loanAccount;

                    $spAction = is_sp_type($sch->type) ? find_sp_action_for_schedule($sch) : null;
                    $meta = action_meta_array($spAction);
                    $hasLegacyProof = action_has_legacy_proof($spAction, $meta);
                @endphp

                <div class="px-4 py-3">
                    <div class="flex items-center justify-between">
                        <div class="text-[11px] font-semibold">
                            {{ $sch->scheduled_at->format('H:i') }}
                        </div>
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 text-[10px] font-semibold">
                            Hari Ini
                        </span>
                    </div>

                    <div class="mt-1">
                        <div class="text-sm font-semibold text-slate-800">
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded border text-[11px] font-semibold {{ schedule_color(strtolower($sch->type)) }}">
                                {{ schedule_label(strtolower($sch->type)) }}
                            </span>

                            <span class="block text-[11px] text-slate-700 font-normal">
                                {{ $sch->title }}
                            </span>
                        </div>
                        <div class="text-[11px] text-slate-500">
                            {{ $sch->notes }}
                        </div>
                    </div>

                    <div class="mt-2 text-[11px] text-slate-600">
                        <div class="font-semibold">{{ $loan->customer_name }}</div>
                        <div>
                            Rek: {{ $loan->account_no }} • CIF: {{ $loan->cif }}
                        </div>
                        <div>DPD: <span class="font-semibold">{{ $loan->dpd }}</span></div>
                    </div>

                    <div class="mt-2">
                        @if (is_sp_type($sch->type))
                            @if ($spAction)
                                @if($hasLegacyProof)
                                    <a href="{{ route('cases.actions.legacy_proof', [$sch->nplCase->id, $spAction->id]) }}"
                                    target="_blank"
                                    class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                        Lihat Bukti {{ strtoupper($sch->type) }}
                                    </a>
                                @else
                                    <a href="{{ route('cases.show', $sch->nplCase->id) }}#timeline"
                                    class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                        Lihat Riwayat {{ strtoupper($sch->type) }}
                                    </a>
                                @endif
                            @else
                                <a href="{{ route('cases.sp.form', [$sch->nplCase->id, strtolower($sch->type)]) }}"
                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-red-500 text-[11px] font-semibold text-red-600 hover:bg-red-600 hover:text-white">
                                    Mulai {{ strtoupper($sch->type) }}
                                </a>
                            @endif

                        @elseif (strtolower((string)$sch->type) === 'visit')
                            <a href="{{ route('visits.start', $sch->id) }}"
                            class="inline-flex items-center px-3 py-1.5 rounded-lg border border-emerald-600 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-600 hover:text-white">
                                Mulai Visit
                            </a>

                        @else
                            <a href="{{ route('cases.show', $sch->nplCase->id) }}"
                            class="inline-flex items-center px-3 py-1.5 rounded-lg border border-msa-blue text-[11px] font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                                Buka Kasus
                            </a>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-4 py-3 text-xs text-slate-500">
                    Tidak ada agenda untuk hari ini.
                </div>
            @endforelse
        </div>

        {{-- DESKTOP --}}
        <div class="hidden md:block">
            @if ($todayList->isEmpty())
                <div class="px-4 py-3 text-xs text-slate-500">
                    Tidak ada agenda untuk hari ini.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs md:text-sm">
                        <thead class="bg-amber-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">Jam</th>
                                <th class="px-3 py-2 text-left font-semibold">Tugas</th>
                                <th class="px-3 py-2 text-left font-semibold">Debitur</th>
                                <th class="px-3 py-2 text-center font-semibold">DPD</th>
                                <th class="px-3 py-2 text-center font-semibold">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($todayList as $sch)
                                @php
                                    $loan = $sch->nplCase->loanAccount;

                                    $spAction = is_sp_type($sch->type) ? find_sp_action_for_schedule($sch) : null;
                                    $meta = action_meta_array($spAction);
                                    $hasLegacyProof = action_has_legacy_proof($spAction, $meta);
                                @endphp

                                <tr class="border-b border-slate-100 hover:bg-amber-50/40">
                                    <td class="px-3 py-2 text-[11px] md:text-xs font-semibold">
                                        {{ $sch->scheduled_at->format('H:i') }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="font-semibold text-slate-800">
                                            {{ $sch->title ?? ucfirst($sch->type) }}
                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            {{ $sch->notes }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="font-semibold text-slate-800">
                                            {{ $loan->customer_name }}
                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            Rek: {{ $loan->account_no }} | CIF: {{ $loan->cif }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-center text-[11px] font-semibold">
                                        {{ $loan->dpd }}
                                    </td>

                                    {{-- DESKTOP ACTION BUTTON: konsisten --}}
                                    <td class="px-3 py-2 text-center">
                                        <div class="inline-flex items-center justify-center gap-2">
                                            @if (is_sp_type($sch->type))
                                                @if ($spAction)
                                                    @if($hasLegacyProof)
                                                        <a href="{{ route('cases.actions.legacy_proof', [$sch->nplCase->id, $spAction->id]) }}"
                                                        target="_blank"
                                                        class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                                            Lihat Bukti {{ strtoupper($sch->type) }}
                                                        </a>
                                                    @else
                                                        <a href="{{ route('cases.show', $sch->nplCase->id) }}#timeline"
                                                        class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                                            Lihat Riwayat {{ strtoupper($sch->type) }}
                                                        </a>
                                                    @endif
                                                @else
                                                    <a href="{{ route('cases.sp.form', [$sch->nplCase->id, strtolower($sch->type)]) }}"
                                                    class="inline-flex items-center px-3 py-1.5 rounded-lg border border-red-500 text-[11px] font-semibold text-red-600 hover:bg-red-600 hover:text-white">
                                                        Mulai {{ strtoupper($sch->type) }}
                                                    </a>
                                                @endif

                                            @elseif (strtolower((string)$sch->type) === 'visit')
                                                <a href="{{ route('visits.start', $sch->id) }}"
                                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-emerald-600 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-600 hover:text-white">
                                                    Mulai Visit
                                                </a>

                                            @else
                                                <a href="{{ route('cases.show', $sch->nplCase->id) }}"
                                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-msa-blue text-[11px] font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                                                    Buka Kasus
                                                </a>
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
    </div>

    {{-- ========== SECTION UPCOMING ========== --}}
    <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
        <div class="px-4 py-2 border-b border-slate-100 flex items-center justify-between">
            <div class="text-sm font-semibold text-slate-700">
                14 Hari ke Depan
            </div>
            <span class="text-[11px] text-slate-500">
                Agenda yang sudah dijadwalkan untuk 2 minggu ke depan.
            </span>
        </div>

        {{-- MOBILE --}}
        <div class="md:hidden divide-y divide-slate-100">
            @forelse($upcoming as $sch)
                @php
                    $loan = $sch->nplCase->loanAccount;

                    $spAction = is_sp_type($sch->type) ? find_sp_action_for_schedule($sch) : null;
                    $meta = action_meta_array($spAction);
                    $hasLegacyProof = action_has_legacy_proof($spAction, $meta);
                @endphp

                <div class="px-4 py-3">
                    <div class="text-[11px] font-semibold text-slate-600">
                        {{ $sch->scheduled_at->format('d M Y') }}
                    </div>

                    <div class="mt-1">
                        <div class="text-sm font-semibold text-slate-800">
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded border text-[11px] font-semibold {{ schedule_color(strtolower($sch->type)) }}">
                                {{ schedule_label(strtolower($sch->type)) }}
                            </span>
                            <span class="block text-[11px] text-slate-700 font-normal">
                                {{ $sch->title ?? ucfirst($sch->type) }}
                            </span>
                        </div>
                        <div class="text-[11px] text-slate-500">
                            {{ $sch->notes }}
                        </div>
                    </div>

                    <div class="mt-2 text-[11px] text-slate-600">
                        <div class="font-semibold">{{ $loan->customer_name }}</div>
                        <div>
                            Rek: {{ $loan->account_no }} • CIF: {{ $loan->cif }}
                        </div>
                        <div>DPD: <span class="font-semibold">{{ $loan->dpd }}</span></div>
                    </div>

                    <div class="mt-2 flex flex-wrap gap-2">
                        @if (is_sp_type($sch->type))
                            @if ($spAction)
                                @if($hasLegacyProof)
                                    <a href="{{ route('cases.actions.legacy_proof', [$sch->nplCase->id, $spAction->id]) }}"
                                    target="_blank"
                                    class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                        Lihat Bukti {{ strtoupper($sch->type) }}
                                    </a>
                                @else
                                    <a href="{{ route('cases.show', $sch->nplCase->id) }}#timeline"
                                    class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                        Lihat Riwayat {{ strtoupper($sch->type) }}
                                    </a>
                                @endif
                            @else
                                <a href="{{ route('cases.sp.form', [$sch->nplCase->id, strtolower($sch->type)]) }}"
                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-red-500 text-[11px] font-semibold text-red-600 hover:bg-red-600 hover:text-white">
                                    Mulai {{ strtoupper($sch->type) }}
                                </a>
                            @endif

                        @elseif (strtolower((string)$sch->type) === 'visit')
                            <a href="{{ route('visits.start', $sch->id) }}"
                            class="inline-flex items-center px-3 py-1.5 rounded-lg border border-emerald-600 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-600 hover:text-white">
                                Mulai Visit
                            </a>

                        @else
                            <a href="{{ route('cases.show', $sch->nplCase->id) }}"
                            class="inline-flex items-center px-3 py-1.5 rounded-lg border border-msa-blue text-[11px] font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                                Buka Kasus
                            </a>
                        @endif

                        {{-- tombol selesai: tetap --}}
                        <form action="{{ route('schedules.complete', $sch->id) }}" method="POST" class="flex-1 min-w-[140px]">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    class="w-full inline-flex items-center justify-center px-3 py-1.5 rounded-lg border border-emerald-500 text-[11px] font-semibold text-emerald-600 hover:bg-emerald-500 hover:text-white">
                                Selesai
                            </button>
                        </form>
                    </div>

                </div>
            @empty
                <div class="px-4 py-3 text-xs text-slate-500">
                    Belum ada jadwal di 14 hari ke depan.
                </div>
            @endforelse
        </div>

        {{-- DESKTOP --}}
        <div class="hidden md:block">
            @if ($upcoming->isEmpty())
                <div class="px-4 py-3 text-xs text-slate-500">
                    Belum ada jadwal di 14 hari ke depan.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs md:text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">Tanggal</th>
                                <th class="px-3 py-2 text-left font-semibold">Tugas</th>
                                <th class="px-3 py-2 text-left font-semibold">Debitur</th>
                                <th class="px-3 py-2 text-center font-semibold">DPD</th>
                                <th class="px-3 py-2 text-center font-semibold">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($upcoming as $sch)
                                @php
                                    $loan = $sch->nplCase->loanAccount;

                                    $spAction = is_sp_type($sch->type) ? find_sp_action_for_schedule($sch) : null;
                                    $meta = action_meta_array($spAction);
                                    $hasLegacyProof = action_has_legacy_proof($spAction, $meta);
                                @endphp

                                <tr class="border-b border-slate-100 hover:bg-slate-50">
                                    <td class="px-3 py-2 text-[11px] md:text-xs">
                                        {{ $sch->scheduled_at->format('d M Y') }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="font-semibold text-slate-800">
                                            {{ $sch->title ?? ucfirst($sch->type) }}
                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            {{ $sch->notes }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="font-semibold text-slate-800">
                                            {{ $loan->customer_name }}
                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            Rek: {{ $loan->account_no }} | CIF: {{ $loan->cif }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-center text-[11px] font-semibold">
                                        {{ $loan->dpd }}
                                    </td>

                                    <td class="px-3 py-2 text-center">
                                        <div class="inline-flex flex-wrap items-center justify-center gap-2">
                                            @if (is_sp_type($sch->type))
                                                @if ($spAction)
                                                    @if($hasLegacyProof)
                                                        <a href="{{ route('cases.actions.legacy_proof', [$sch->nplCase->id, $spAction->id]) }}"
                                                        target="_blank"
                                                        class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                                            Lihat Bukti {{ strtoupper($sch->type) }}
                                                        </a>
                                                    @else
                                                        <a href="{{ route('cases.show', $sch->nplCase->id) }}#timeline"
                                                        class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                                            Lihat Riwayat {{ strtoupper($sch->type) }}
                                                        </a>
                                                    @endif
                                                @else
                                                    <a href="{{ route('cases.sp.form', [$sch->nplCase->id, strtolower($sch->type)]) }}"
                                                    class="inline-flex items-center px-3 py-1.5 rounded-lg border border-red-500 text-[11px] font-semibold text-red-600 hover:bg-red-600 hover:text-white">
                                                        Mulai {{ strtoupper($sch->type) }}
                                                    </a>
                                                @endif

                                            @elseif (strtolower((string)$sch->type) === 'visit')
                                                <a href="{{ route('visits.start', $sch->id) }}"
                                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-emerald-600 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-600 hover:text-white">
                                                    Mulai Visit
                                                </a>

                                            @else
                                                <a href="{{ route('cases.show', $sch->nplCase->id) }}"
                                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-msa-blue text-[11px] font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                                                    Buka Kasus
                                                </a>
                                            @endif

                                            <form action="{{ route('schedules.complete', $sch->id) }}" method="POST" class="inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit"
                                                        class="inline-flex items-center px-3 py-1.5 rounded-lg border border-emerald-500 text-[11px] font-semibold text-emerald-600 hover:bg-emerald-500 hover:text-white">
                                                    Tandai Selesai
                                                </button>
                                            </form>
                                        </div>
                                    </td>

                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

</div>
@endsection
