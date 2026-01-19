@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    {{-- Header + Filter (rapi) --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">
        <div class="grid grid-cols-12 gap-6 items-start">
            {{-- KIRI: Judul --}}
            <div class="col-span-12 lg:col-span-5">
                <div class="text-xs font-bold tracking-wider text-slate-400 uppercase">Legal</div>
                <h1 class="mt-2 text-4xl font-extrabold text-slate-900 leading-tight">
                    Dashboard Legal<br>Actions
                </h1>
                <p class="mt-3 text-base text-slate-600 leading-relaxed">
                    Monitoring pekerjaan legal (khusus BE) + ringkasan status & tindakan cepat.
                </p>
            </div>

            {{-- KANAN: Filter --}}
            <div class="col-span-12 lg:col-span-7">
                <form method="GET" action="{{ route('legal-actions.index') }}">
                    <div class="grid grid-cols-12 gap-4 items-end">
                        {{-- Status --}}
                        <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Status</label>
                            <select name="status" class="w-full rounded-xl border-slate-200">
                                {{-- options --}}
                            </select>
                        </div>

                        {{-- Type --}}
                        <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Tipe</label>
                            <select name="type" class="w-full rounded-xl border-slate-200">
                                {{-- options --}}
                            </select>
                        </div>

                        {{-- Handler --}}
                        <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Handler</label>
                            <select name="handler" class="w-full rounded-xl border-slate-200">
                                {{-- options --}}
                            </select>
                        </div>

                        {{-- Urut --}}
                        <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Urut</label>
                            <select name="sort" class="w-full rounded-xl border-slate-200">
                                {{-- options --}}
                            </select>
                        </div>

                        {{-- Kata kunci --}}
                        <div class="col-span-12 lg:col-span-6">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Kata kunci</label>
                            <input name="q" value="{{ request('q') }}"
                                class="w-full rounded-xl border-slate-200"
                                placeholder="CIF / Debitur / No Rek / Ref">
                        </div>

                        {{-- Periode --}}
                        <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Dari</label>
                            <input type="date" name="from" value="{{ request('from') }}"
                                class="w-full rounded-xl border-slate-200">
                        </div>

                        <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Sampai</label>
                            <input type="date" name="to" value="{{ request('to') }}"
                                class="w-full rounded-xl border-slate-200">
                        </div>

                        {{-- Tombol --}}
                        <div class="col-span-12 flex gap-3 justify-end pt-1">
                            <button type="submit"
                                    class="rounded-xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white">
                                Terapkan
                            </button>

                            <a href="{{ route('legal-actions.index') }}"
                            class="rounded-xl border border-slate-200 px-6 py-3 text-sm font-semibold text-slate-700">
                                Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Summary Cards --}}
    @php
        $kpiCard  = "rounded-2xl border border-slate-200 bg-white shadow-sm p-5";
        $kpiTitle = "text-xs font-bold tracking-wider text-slate-400 uppercase";
        $kpiValue = "mt-3 text-4xl font-extrabold text-slate-900 leading-none";
        $kpiDesc  = "mt-3 text-sm text-slate-600 leading-relaxed break-words min-h-[44px]";
    @endphp


    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        {{-- TOTAL --}}
        <div class="{{ $kpiCard }}">
            <div class="{{ $kpiTitle }}">Total</div>
            <div class="{{ $kpiValue }}">{{ number_format($stats['total'] ?? 0) }}</div>
            <div class="{{ $kpiDesc }}">Semua legal actions sesuai filter</div>
        </div>

        {{-- OPEN --}}
        <div class="{{ $kpiCard }}">
            <div class="{{ $kpiTitle }}">Open</div>
            <div class="{{ $kpiValue }}">{{ number_format($stats['open'] ?? 0) }}</div>
            <div class="{{ $kpiDesc }}">
                Draft • Prepared • Submitted • Progress • Waiting
            </div>
        </div>

        {{-- SUBMITTED --}}
        <div class="{{ $kpiCard }}">
            <div class="{{ $kpiTitle }}">Submitted</div>
            <div class="{{ $kpiValue }}">{{ number_format($stats['submitted'] ?? 0) }}</div>
            <div class="{{ $kpiDesc }}">Sudah diajukan</div>
        </div>

        {{-- SCHEDULED --}}
        <div class="{{ $kpiCard }}">
            <div class="{{ $kpiTitle }}">Scheduled</div>
            <div class="{{ $kpiValue }}">{{ number_format($stats['scheduled'] ?? 0) }}</div>
            <div class="{{ $kpiDesc }}">Sudah dijadwalkan</div>
        </div>

        {{-- CLOSED --}}
        <div class="{{ $kpiCard }}">
            <div class="{{ $kpiTitle }}">Closed</div>
            <div class="{{ $kpiValue }}">{{ number_format($stats['closed'] ?? 0) }}</div>
            <div class="{{ $kpiDesc }}">Sudah ditutup</div>
        </div>
    </div>

    {{-- Ringkasan per Tipe --}}
    <div class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-xs font-bold tracking-wider text-slate-400 uppercase">Ringkasan</div>
                    <div class="mt-1 text-lg font-extrabold text-slate-900">Per Tipe Legal Action</div>
                    <div class="mt-1 text-sm text-slate-500">Mengikuti filter aktif di atas.</div>
                </div>
                <div class="text-sm font-semibold text-slate-600">
                    Total: {{ number_format($stats['total'] ?? 0) }}
                </div>
            </div>

            <div class="mt-4 space-y-3">
                @foreach($typeSummary as $row)
                    @php
                        $count = (int) ($row['count'] ?? 0);
                        $pct = $typeMax ? round(($count / $typeMax) * 100) : 0;
                    @endphp

                    <div class="flex items-center gap-3">
                        <div class="w-40 text-sm font-semibold text-slate-700 truncate">
                            {{ $row['label'] }}
                        </div>

                        <div class="flex-1">
                            <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                                <div class="h-2 rounded-full bg-slate-900" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>

                        <div class="w-14 text-right text-sm font-bold text-slate-900">
                            {{ $count }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Opsional: Ringkasan status cepat (kalau mau sekalian) --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            <div class="text-xs font-bold tracking-wider text-slate-400 uppercase">Ringkasan</div>
            <div class="mt-1 text-lg font-extrabold text-slate-900">Status Cepat</div>

            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs font-bold text-slate-400 uppercase">Open</div>
                    <div class="mt-2 text-3xl font-extrabold text-slate-900">{{ number_format($stats['open'] ?? 0) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs font-bold text-slate-400 uppercase">Closed</div>
                    <div class="mt-2 text-3xl font-extrabold text-slate-900">{{ number_format($stats['closed'] ?? 0) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs font-bold text-slate-400 uppercase">Submitted</div>
                    <div class="mt-2 text-3xl font-extrabold text-slate-900">{{ number_format($stats['submitted'] ?? 0) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs font-bold text-slate-400 uppercase">Scheduled</div>
                    <div class="mt-2 text-3xl font-extrabold text-slate-900">{{ number_format($stats['scheduled'] ?? 0) }}</div>
                </div>
            </div>

            <div class="mt-3 text-xs text-slate-500">
                Catatan: definisi kategori mengikuti mapping status di controller.
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="mt-5 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        {{-- Header --}}
        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-slate-100 bg-white">
            <div>
                <div class="text-lg font-bold text-slate-900">Daftar Legal Actions</div>
                <div class="text-sm text-slate-500">Klik baris untuk masuk detail.</div>
            </div>

            <div class="text-sm font-medium text-slate-500">
                {{ $actions->total() }} data
            </div>
        </div>

        {{-- Table --}}
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 border-b border-slate-100">
                    <tr>
                        <th class="px-6 py-3 text-left">Ref</th>
                        <th class="px-6 py-3 text-left">Debitur</th>
                        <th class="px-6 py-3 text-left">CIF</th>
                        <th class="px-6 py-3 text-left">No Rek</th>
                        <th class="px-6 py-3 text-left">Tipe</th>
                        <th class="px-6 py-3 text-left">Status</th>
                        <th class="px-6 py-3 text-left">Start</th>
                        <th class="px-6 py-3 text-left">Handler</th>
                        <th class="px-6 py-3 text-right">Aksi</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($actions as $a)
                        @php
                            $loan = $a->legalCase?->nplCase?->loanAccount;
                            $debtor = $loan->debtor_name ?? $loan->customer_name ?? '-';
                            $cif    = $loan->cif ?? '-';
                            $norek  = $loan->account_no ?? $loan->no_rek ?? $loan->norek ?? '-';

                            $typeLabel = match($a->action_type) {
                                \App\Models\LegalAction::TYPE_SOMASI => 'Somasi',
                                \App\Models\LegalAction::TYPE_HT_EXECUTION => 'HT Execution',
                                \App\Models\LegalAction::TYPE_FIDUSIA_EXEC => 'Fidusia',
                                \App\Models\LegalAction::TYPE_CIVIL_LAWSUIT => 'Gugatan Perdata',
                                \App\Models\LegalAction::TYPE_PKPU_BANKRUPTCY => 'PKPU/Pailit',
                                \App\Models\LegalAction::TYPE_CRIMINAL_REPORT => 'Pidana',
                                default => strtoupper((string)$a->action_type),
                            };

                            $status = strtolower((string) $a->status);

                            $badge = match($status) {
                                'draft' => 'bg-slate-200 text-slate-700',
                                'prepared' => 'bg-indigo-100 text-indigo-700',
                                'submitted' => 'bg-blue-100 text-blue-700',
                                'scheduled' => 'bg-amber-100 text-amber-700',
                                'in_progress' => 'bg-teal-100 text-teal-700',
                                'waiting' => 'bg-violet-100 text-violet-700',
                                'executed', 'settled', 'completed' => 'bg-emerald-100 text-emerald-700',
                                'closed' => 'bg-emerald-200 text-emerald-800',
                                'failed' => 'bg-rose-100 text-rose-700',
                                'cancelled' => 'bg-slate-100 text-slate-500',
                                default => 'bg-slate-100 text-slate-700',
                            };

                            $ref = $a->external_ref_no ?: ('LA-' . $a->id);
                            $startAt = $a->start_at ? $a->start_at->format('d/m/Y') : ($a->created_at?->format('d/m/Y') ?? '-');
                            $handlerText = $a->handler_type ? strtoupper($a->handler_type) : '-';
                        @endphp

                        <tr class="hover:bg-slate-50">
                            <td class="px-6 py-3 align-top font-semibold text-slate-900">
                                <a class="hover:underline" href="{{ route('legal-actions.show', $a->id) }}">
                                    {{ $ref }}
                                </a>
                                @if($a->external_institution)
                                    <div class="text-xs text-slate-500 mt-0.5">{{ $a->external_institution }}</div>
                                @endif
                            </td>

                            <td class="px-6 py-3 align-top text-slate-700">
                                <div class="font-semibold">{{ $debtor }}</div>
                            </td>

                            <td class="px-6 py-3 align-top text-slate-700">{{ $cif }}</td>
                            <td class="px-6 py-3 align-top text-slate-700">{{ $norek }}</td>

                            <td class="px-6 py-3 align-top">
                                <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                    {{ $typeLabel }}
                                </span>
                            </td>

                            <td class="px-6 py-3 align-top">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold {{ $badge }}">
                                    {{ strtoupper($status) }}
                                </span>
                            </td>

                            <td class="px-6 py-3 align-top text-slate-700">{{ $startAt }}</td>

                            <td class="px-6 py-3 align-top text-slate-700">
                                <div class="font-semibold">{{ $handlerText }}</div>
                                @if($a->handler_name)
                                    <div class="text-xs text-slate-500 mt-0.5">{{ $a->handler_name }}</div>
                                @elseif($a->law_firm_name)
                                    <div class="text-xs text-slate-500 mt-0.5">{{ $a->law_firm_name }}</div>
                                @endif
                            </td>

                            <td class="px-6 py-3 align-top text-right">
                                <a href="{{ route('legal-actions.show', $a->id) }}"
                                class="inline-flex items-center rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Detail →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-slate-500">
                                Tidak ada data legal actions untuk filter saat ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Footer (pagination area) --}}
        <div class="px-6 py-4 border-t border-slate-100 bg-white">
            {{ $actions->links() }}
        </div>
    </div>


        {{-- Pagination --}}
        <!-- <div class="px-5 py-4 border-t border-slate-100">
            {{ $actions->links() }}
        </div> -->
    </div>

</div>
@endsection
