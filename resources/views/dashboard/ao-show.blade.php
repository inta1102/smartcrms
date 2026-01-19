@extends('layouts.app')

@section('title', "Detail Kasus AO $aoName")

@section('content')

<div class="w-full max-w-6xl space-y-4">

    @php
        $activeFilter = request('filter', 'all');
        $baseUrl = route('dashboard.ao.show', $aoCode);
        $exportUrl = route('dashboard.ao.export', $aoCode) . '?' . http_build_query([
            'filter' => $activeFilter !== 'all' ? $activeFilter : null,
            'q'      => request('q') ?: null,
        ]);
        $pillBase = 'inline-flex items-center px-3 py-1.5 rounded-full text-[11px] md:text-xs border transition';
    @endphp

    {{-- Header --}}
    <div class="space-y-2">
        <div class="flex items-center justify-between gap-3">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl md:text-2xl font-semibold text-slate-800">
                        Kasus Kredit Bermasalah — AO {{ $aoName ?? $aoCode }}
                    </h1>

                    {{-- 🔴 Badge kasus belum pernah ditangani --}}
                    @isset($noActionCount)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-rose-50 text-rose-700 text-[11px] md:text-xs font-semibold border border-rose-100">
                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500 mr-1.5"></span>
                            {{ $noActionCount }} kasus belum pernah ditangani
                        </span>
                    @endisset

                    {{-- 🟠 Badge kasus stale --}}
                    @isset($staleCount)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 text-[11px] md:text-xs font-semibold border border-amber-100">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1.5"></span>
                            {{ $staleCount }} kasus tidak diupdate ≥ {{ $staleDays ?? 7 }} hari
                        </span>
                    @endisset
                </div>

                <p class="text-xs md:text-sm text-slate-500 mt-1">
                    Menampilkan daftar kasus yang ditangani oleh AO ini.
                </p>
            </div>

            <a href="{{ route('dashboard.ao.index') }}"
            class="hidden sm:inline-flex px-3 py-1.5 rounded-lg border border-slate-300 text-xs text-slate-700 hover:bg-slate-50">
                Kembali
            </a>
        </div>

        {{-- 🔽 Filter bar --}}
        <div class="flex flex-wrap items-center gap-2">
            @php
                $baseUrl = route('dashboard.ao.show', $aoCode);
                $pillBase = 'inline-flex items-center px-3 py-1.5 rounded-full text-[11px] md:text-xs border transition';
            @endphp

            {{-- Semua --}}
            <a href="{{ $baseUrl }}"
            class="{{ $pillBase }} {{ $activeFilter === 'all'
                    ? 'bg-slate-800 text-white border-slate-800'
                    : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
                Semua
            </a>

            {{-- Belum pernah ditangani --}}
            <a href="{{ $baseUrl }}?filter=no-action"
            class="{{ $pillBase }} {{ $activeFilter === 'no-action'
                    ? 'bg-rose-600 text-white border-rose-600'
                    : 'bg-white text-rose-700 border-rose-300 hover:bg-rose-50' }}">
                Belum Pernah Ditangani
            </a>

            {{-- Tidak diupdate ≥ 7 hari --}}
            <a href="{{ $baseUrl }}?filter=stale"
            class="{{ $pillBase }} {{ $activeFilter === 'stale'
                    ? 'bg-amber-500 text-white border-amber-500'
                    : 'bg-white text-amber-700 border-amber-300 hover:bg-amber-50' }}">
                Tidak Diupdate ≥ {{ $staleDays ?? 7 }} Hari
            </a>

            {{-- Overdue next action --}}
            <a href="{{ $baseUrl }}?filter=overdue"
            class="{{ $pillBase }} {{ $activeFilter === 'overdue'
                    ? 'bg-red-600 text-white border-red-600'
                    : 'bg-white text-red-700 border-red-300 hover:bg-red-50' }}">
                Overdue Next Action
            </a>

            {{-- Open saja --}}
            <a href="{{ $baseUrl }}?filter=open"
            class="{{ $pillBase }} {{ $activeFilter === 'open'
                    ? 'bg-msa-blue text-white border-msa-blue'
                    : 'bg-white text-msa-blue border-msa-blue/40 hover:bg-slate-50' }}">
                Hanya Open
            </a>
        </div>
        
        {{-- Tombol Export Excel --}}
        <a href="{{ $exportUrl }}"
        class="inline-flex items-center px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-[11px] md:text-xs font-semibold hover:bg-emerald-700 shadow-sm">
            Export Excel
        </a>
    </div>

    @php
        $q = request('q');
    @endphp

    <div class="flex flex-col sm:flex-row sm:items-center gap-2">
        <form method="GET" action="{{ route('dashboard.ao.show', $aoCode) }}" class="flex-1">
            {{-- pertahankan filter --}}
            <input type="hidden" name="filter" value="{{ request('filter','all') }}">

            <div class="flex items-center gap-2">
                <div class="relative flex-1">
                    <input
                        type="text"
                        name="q"
                        value="{{ $q }}"
                        placeholder="Cari nama debitur / rekening / CIF..."
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-msa-blue/40"
                    >
                </div>

                <button
                    type="submit"
                    class="inline-flex items-center px-3 py-2 rounded-lg bg-slate-800 text-white text-sm font-semibold hover:bg-slate-900"
                    title="Cari"
                >
                    Cari
                </button>

                @if($q)
                    <a
                        href="{{ route('dashboard.ao.show', $aoCode) . (request('filter','all') !== 'all' ? '?filter='.request('filter') : '') }}"
                        class="inline-flex items-center px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-700 hover:bg-slate-50"
                        title="Reset pencarian"
                    >
                        Reset
                    </a>
                @endif
            </div>
        </form>

        {{-- (opsional) tampilkan info hasil --}}
        @if($q)
            <div class="text-xs text-slate-500">
                Keyword: <span class="font-semibold text-slate-700">{{ $q }}</span>
            </div>
        @endif
    </div>

    {{-- 📱 MOBILE LIST --}}
    <div class="md:hidden space-y-3">
        @foreach ($cases as $case)
            @php
                $next       = $case->actions->sortByDesc('action_at')->first();
                $overdue    = $case->isOverdueNextAction();
                $hasAction  = $case->actions->isNotEmpty();
                $lastAction = $case->actions->sortByDesc('action_at')->first();
                $staleLimit = now()->subDays($staleDays ?? 7);
                $stale      = ! $case->closed_at && $lastAction && $lastAction->action_at < $staleLimit;
            @endphp

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-3">

                {{-- Rekening + Debitur --}}
                <div class="font-semibold text-slate-800 text-sm">
                    {{ $case->loanAccount->customer_name }}
                </div>
                <div class="text-[11px] text-slate-500 mt-1">
                    Rek: {{ $case->loanAccount->account_no }} <br>
                    CIF: {{ $case->loanAccount->cif }}
                </div>

                {{-- Badge Status --}}
                <div class="mt-2 flex flex-wrap items-center gap-1">
                    @if ($case->closed_at)
                        <span class="text-[11px] px-2 py-0.5 rounded bg-emerald-100 text-emerald-700">
                            Closed
                        </span>
                    @elseif ($overdue)
                        <span class="text-[11px] px-2 py-0.5 rounded bg-red-100 text-red-600">
                            Overdue Next Action
                        </span>
                    @else
                        <span class="text-[11px] px-2 py-0.5 rounded bg-amber-100 text-amber-700">
                            Open
                        </span>
                    @endif

                    @if (! $hasAction && is_null($case->closed_at))
                        <span class="text-[11px] px-2 py-0.5 rounded bg-rose-100 text-rose-700">
                            Belum Pernah Ditangani
                        </span>
                    @endif

                    @if ($stale)
                        <span class="text-[11px] px-2 py-0.5 rounded bg-amber-100 text-amber-700">
                            Tidak diupdate ≥ {{ $staleDays ?? 7 }} hari
                        </span>
                    @endif
                </div>

                {{-- Kolek + DPD --}}
                <div class="grid grid-cols-2 gap-3 mt-2 text-[11px]">
                    <div>
                        Kolek:<br>
                        <span class="font-semibold">{{ $case->loanAccount->kolek }}</span>
                    </div>
                    <div>
                        DPD:<br>
                        <span class="font-semibold">{{ $case->loanAccount->dpd }}</span>
                    </div>
                </div>

                {{-- Next Action --}}
                @if ($next)
                    <div class="text-[11px] mt-2">
                        Next Action: <strong>{{ $next?->next_action ?? '-' }}</strong><br>
                        Due: {{ $next?->next_action_due?->format('d M Y') ?? '-' }}
                    </div>
                @else
                    <div class="text-[11px] mt-2 text-slate-500">
                        Belum ada rencana tindakan (next action) yang tercatat.
                    </div>
                @endif

                {{-- Button Detail --}}
                <div class="mt-3">
                    <a href="{{ route('cases.show', $case->id) }}"
                       class="inline-flex items-center px-3 py-1.5 rounded-lg border border-msa-blue text-xs font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                        Lihat Detail
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    {{-- 💻 DESKTOP TABLE --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden hidden md:block">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-100">
                <tr>
                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Rekening / Debitur</th>
                    <th class="px-3 py-2 text-center font-semibold text-slate-600">Kolek</th>
                    <th class="px-3 py-2 text-center font-semibold text-slate-600">DPD</th>
                    <th class="px-3 py-2 text-center font-semibold text-slate-600">Next Action</th>
                    <th class="px-3 py-2 text-center font-semibold text-slate-600">Due Date</th>
                    <th class="px-3 py-2 text-center font-semibold text-slate-600">Status</th>
                    <th class="px-3 py-2 text-center font-semibold text-slate-600">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cases as $case)
                    @php
                        $next       = $case->actions->sortByDesc('action_at')->first();
                        $overdue    = $case->isOverdueNextAction();
                        $hasAction  = $case->actions->isNotEmpty();
                        $lastAction = $case->actions->sortByDesc('action_at')->first();
                        $staleLimit = now()->subDays($staleDays ?? 7);
                        $stale      = ! $case->closed_at && $lastAction && $lastAction->action_at < $staleLimit;
                    @endphp

                    <tr class="border-b last:border-b-0 border-slate-100 hover:bg-slate-50">
                        <td class="px-3 py-2">
                            <div class="font-semibold">{{ $case->loanAccount->customer_name }}</div>
                            <div class="text-xs text-slate-500">
                                Rek: {{ $case->loanAccount->account_no }} |
                                CIF: {{ $case->loanAccount->cif }}
                            </div>
                        </td>

                        <td class="px-3 py-2 text-center">{{ $case->loanAccount->kolek }}</td>
                        <td class="px-3 py-2 text-center">{{ $case->loanAccount->dpd }}</td>

                        <td class="px-3 py-2 text-center text-[13px]">
                            {{ $next?->next_action ?? '-' }}
                        </td>

                        <td class="px-3 py-2 text-center text-[13px] {{ $overdue ? 'text-red-600 font-semibold' : '' }}">
                            {{ $next?->next_action_due?->format('d M Y') ?? '-' }}
                        </td>

                        <td class="px-3 py-2 text-center">
                            <div class="flex flex-col items-center gap-1">
                                @if ($case->closed_at)
                                    <span class="px-2 py-1 rounded text-xs bg-emerald-100 text-emerald-700">Closed</span>
                                @elseif($overdue)
                                    <span class="px-2 py-1 rounded text-xs bg-red-100 text-red-700">Overdue</span>
                                @else
                                    <span class="px-2 py-1 rounded text-xs bg-amber-100 text-amber-700">Open</span>
                                @endif

                                @if (! $hasAction && is_null($case->closed_at))
                                    <span class="px-2 py-1 rounded text-[11px] bg-rose-100 text-rose-700">
                                        Belum Pernah Ditangani
                                    </span>
                                @endif

                                @if ($stale)
                                    <span class="px-2 py-1 rounded text-[11px] bg-amber-100 text-amber-700">
                                        Tidak diupdate ≥ {{ $staleDays ?? 7 }} hari
                                    </span>
                                @endif
                            </div>
                        </td>

                        <td class="px-3 py-2 text-center">
                            <a href="{{ route('cases.show', $case->id) }}"
                               class="inline-flex items-center px-3 py-1.5 rounded-lg border border-msa-blue text-xs font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                                Detail
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- PAGINATION 🚀 --}}
    <div>
        {{ $cases->links() }}
    </div>

</div>

@endsection
