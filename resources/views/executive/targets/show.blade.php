@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    {{-- HEADER --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
            <div class="min-w-0">
                <div class="text-xs font-bold tracking-wider text-slate-400 uppercase">Executive</div>
                <h1 class="mt-2 text-2xl sm:text-3xl font-extrabold text-slate-900 leading-tight">
                    Detail Target & Timeline Penanganan
                </h1>
                <p class="mt-2 text-sm text-slate-600">
                    Ringkasan target penyelesaian dan progres tindakan pada kasus NPL.
                </p>
            </div>

            {{-- di mobile: tombol full width biar gampang dipencet --}}
            <div class="w-full md:w-auto">
                <a href="{{ route('executive.targets.index') }}"
                   class="inline-flex w-full md:w-auto items-center justify-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    ← Kembali
                </a>
            </div>
        </div>
    </div>

    @php
        $debtor = $case->debtor_name ?? $case->loanAccount->debtor_name ?? $case->loanAccount->customer_name ?? '-';
        $cif    = $case->cif ?? $case->loanAccount->cif ?? '-';
        $noRek  = $case->loanAccount->account_no ?? $case->loanAccount->no_rek ?? $case->loanAccount->norek ?? '-';
        $status = strtoupper((string)($case->status ?? '-'));

        $dpd   = $case->dpd ?? $case->loanAccount->dpd ?? '-';
        $kolek = $case->kolek ?? $case->loanAccount->kolek ?? '-';

        // OS: beberapa tempat nyimpen "outstanding" atau "os"
        $osRaw = $case->os ?? $case->loanAccount->os ?? $case->loanAccount->outstanding ?? null;
        $osVal = is_numeric($osRaw) ? number_format((float)$osRaw, 0, ',', '.') : ($osRaw ?? '-');

        // badge status case (optional)
        $caseChip = match(strtolower((string)($case->status ?? ''))) {
            'open' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'closed' => 'bg-slate-100 text-slate-700 border-slate-200',
            'legal' => 'bg-amber-50 text-amber-700 border-amber-200',
            default => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        };
    @endphp

    {{-- CASE SUMMARY --}}
    <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- KASUS --}}
        <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white shadow-sm p-4 sm:p-5">
            <div class="text-xs font-bold tracking-wider text-slate-400 uppercase">Kasus</div>

            <div class="mt-2 text-xl sm:text-2xl font-extrabold text-slate-900 break-words">
                {{ $debtor }}
            </div>

            <div class="mt-3">
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-extrabold {{ $caseChip }}">
                    {{ $status }}
                </span>
            </div>

            {{-- GRID UTAMA: mobile 2 kolom, sm 3 kolom --}}
            <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="text-[11px] font-semibold text-slate-500">CIF</div>
                    <div class="mt-1 font-extrabold text-slate-900 break-all leading-tight">{{ $cif }}</div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 min-w-0">
                    <div class="text-[11px] font-semibold text-slate-500">No Rek</div>
                    <div class="mt-1 font-extrabold text-slate-900 break-all leading-tight">{{ $noRek }}</div>
                </div>

                {{-- di mobile, kotak ke-3 akan turun ke baris ke-2 (tetap rapi) --}}
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="text-[11px] font-semibold text-slate-500">Status Case</div>
                    <div class="mt-1 font-extrabold text-slate-900 leading-tight">{{ $status }}</div>
                </div>
            </div>

            {{-- GRID OPSIONAL --}}
            <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                <div class="rounded-xl border border-slate-200 p-3">
                    <div class="text-[11px] font-semibold text-slate-500">DPD</div>
                    <div class="mt-1 font-extrabold text-slate-900 leading-tight">{{ $dpd }}</div>
                </div>

                <div class="rounded-xl border border-slate-200 p-3">
                    <div class="text-[11px] font-semibold text-slate-500">Kolek</div>
                    <div class="mt-1 font-extrabold text-slate-900 leading-tight">{{ $kolek }}</div>
                </div>

                <div class="rounded-xl border border-slate-200 p-3 min-w-0">
                    <div class="text-[11px] font-semibold text-slate-500">OS</div>
                    <div class="mt-1 font-extrabold text-slate-900 leading-tight truncate">{{ $osVal }}</div>
                </div>
            </div>
        </div>

        {{-- ACTIVE TARGET --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-4 sm:p-5">
            <div class="flex items-center justify-between">
                <div class="text-xs font-bold tracking-wider text-slate-400 uppercase">Target Aktif</div>

                @if($activeTarget)
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700">
                        ACTIVE
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                        NONE
                    </span>
                @endif
            </div>

            @if($activeTarget)
                @php
                    $tDate   = $activeTarget->target_date ? \Carbon\Carbon::parse($activeTarget->target_date)->format('d/m/Y') : '-';
                    $tStatus = strtoupper((string)($activeTarget->status ?? '-'));
                    // catatan: di controller kamu sudah bikin target_anchor_at, tapi di show ini kita pakai object langsung.
                    // kalau activated_at tidak ada (kolom tidak ada), fallback ke created_at.
                    $tActAt  = ($activeTarget->activated_at ?? null) ?: ($activeTarget->created_at ?? null);
                @endphp

                <div class="mt-3">
                    <div class="text-[11px] font-semibold text-slate-500">Target date</div>
                    <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $tDate }}</div>
                </div>

                {{-- MOBILE: jangan "justify-between" untuk value panjang --}}
                <div class="mt-4 space-y-3 text-sm">
                    <div>
                        <div class="text-[11px] font-semibold text-slate-500">Status</div>
                        <div class="mt-1 font-extrabold text-slate-900">{{ $tStatus }}</div>
                    </div>

                    <div>
                        <div class="text-[11px] font-semibold text-slate-500">Strategy</div>
                        <div class="mt-1 font-semibold text-slate-900 break-words">{{ $activeTarget->strategy ?? '-' }}</div>
                    </div>

                    <div>
                        <div class="text-[11px] font-semibold text-slate-500">Outcome</div>
                        <div class="mt-1 font-semibold text-slate-900 break-words">{{ $activeTarget->target_outcome ?? '-' }}</div>
                    </div>

                    <div>
                        <div class="text-[11px] font-semibold text-slate-500">Aktif sejak</div>
                        <div class="mt-1 font-semibold text-slate-900">
                            {{ $tActAt ? \Carbon\Carbon::parse($tActAt)->format('d/m/Y H:i') : '-' }}
                        </div>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                    <div class="text-[11px] font-bold text-slate-500 uppercase">Catatan TL/Kasi</div>

                    <div class="mt-2 space-y-2">
                        <div>
                            <div class="text-[11px] font-semibold text-slate-500">TL</div>
                            <div class="mt-0.5 font-semibold text-slate-900 break-words">{{ $activeTarget->tl_notes ?? '-' }}</div>
                        </div>

                        <div>
                            <div class="text-[11px] font-semibold text-slate-500">Kasi</div>
                            <div class="mt-0.5 font-semibold text-slate-900 break-words">{{ $activeTarget->kasi_notes ?? '-' }}</div>
                        </div>
                    </div>
                </div>
            @else
                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                    Belum ada target aktif pada kasus ini.
                </div>
            @endif
        </div>
    </div>

    {{-- TARGET HISTORY + TIMELINE --}}
    <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- TARGET HISTORY --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="px-4 sm:px-5 py-4 border-b border-slate-100">
                <div class="text-sm font-bold text-slate-900">Riwayat Target</div>
                <div class="text-xs text-slate-500">Semua target yang pernah dibuat.</div>
            </div>

            <div class="divide-y divide-slate-100">
                @forelse($targets as $t)
                    @php
                        $isActive = (int)($t->is_active ?? 0) === 1;
                        $tDate    = $t->target_date ? \Carbon\Carbon::parse($t->target_date)->format('d/m/Y') : '-';
                        $tStatus  = strtoupper((string)($t->status ?? '-'));
                        $chip1    = $isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700';
                    @endphp

                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-extrabold text-slate-900">{{ $tDate }}</div>

                                {{-- mobile: biarkan wrap, desktop: boleh truncate --}}
                                <div class="text-xs text-slate-500 mt-1 sm:truncate break-words">
                                    Strategy: {{ $t->strategy ?? '-' }} • Outcome: {{ $t->target_outcome ?? '-' }}
                                </div>

                                <div class="text-xs text-slate-500 mt-1">
                                    Dibuat: {{ $t->created_at ? \Carbon\Carbon::parse($t->created_at)->format('d/m/Y H:i') : '-' }}
                                </div>
                            </div>

                            <div class="shrink-0 text-right space-y-1">
                                <div class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold {{ $chip1 }}">
                                    {{ $isActive ? 'ACTIVE' : 'HISTORY' }}
                                </div>
                                <div class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                                    {{ $tStatus }}
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-5 text-sm text-slate-600">
                        Belum ada riwayat target.
                    </div>
                @endforelse
            </div>
        </div>

        {{-- TIMELINE --}}
        <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="px-4 sm:px-5 py-4 border-b border-slate-100">
                <div class="text-sm font-bold text-slate-900">Timeline Tindakan</div>
                <div class="text-xs text-slate-500">Log tindakan (case_actions) terbaru.</div>
            </div>

            <div class="p-4 sm:p-5">
                @if(($actions ?? collect())->count() === 0)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        Belum ada tindakan tercatat.
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach($actions as $a)
                            @php
                                $at      = $a->action_at ? \Carbon\Carbon::parse($a->action_at)->format('d/m/Y H:i') : '-';
                                $type    = strtoupper((string)($a->action_type ?? 'ACTION'));
                                $result  = $a->result ?? null;
                                $desc    = $a->description ?? null;
                                $next    = $a->next_action ?? null;
                                $nextDue = $a->next_action_due ? \Carbon\Carbon::parse($a->next_action_due)->format('d/m/Y') : null;
                            @endphp

                            <div class="rounded-2xl border border-slate-200 p-4">
                                {{-- mobile: stack, md: row --}}
                                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-bold text-indigo-700">
                                                {{ $type }}
                                            </span>
                                            <span class="text-sm font-extrabold text-slate-900">
                                                {{ $at }}
                                            </span>
                                        </div>

                                        @if($desc)
                                            <div class="mt-2 text-sm text-slate-700 break-words">
                                                {{ $desc }}
                                            </div>
                                        @endif

                                        @if($result || $next)
                                            <div class="mt-2 text-xs text-slate-600 space-y-1">
                                                @if($result)
                                                    <div>
                                                        <span class="font-semibold text-slate-700">Hasil:</span>
                                                        <span class="break-words">{{ $result }}</span>
                                                    </div>
                                                @endif

                                                @if($next)
                                                    <div>
                                                        <span class="font-semibold text-slate-700">Next:</span>
                                                        <span class="break-words">{{ $next }}</span>
                                                        @if($nextDue)
                                                            <span class="text-slate-400">• Due {{ $nextDue }}</span>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    </div>

                                    {{-- tombol bukti: di mobile full width biar enak --}}
                                    @if(!empty($a->proof_url))
                                        <div class="md:text-right">
                                            <a href="{{ $a->proof_url }}"
                                               target="_blank"
                                               class="inline-flex w-full md:w-auto items-center justify-center rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                Bukti →
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>
    </div>

</div>
@endsection
