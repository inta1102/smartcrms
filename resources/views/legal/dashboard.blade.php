{{-- resources/views/legal/dashboard.blade.php --}}
@extends('layouts.app')

@php
    /**
     * Helpers: badge status + SLA warning
     */
    $statusLabel = function (?string $s) {
        $s = strtolower((string)$s);
        return match ($s) {
            'draft'     => 'Draft',
            'submitted' => 'Submitted',
            'scheduled' => 'Scheduled',
            'in_progress' => 'In Progress',
            'done'      => 'Done',
            'cancelled' => 'Cancelled',
            default     => strtoupper($s ?: '-'),
        };
    };

    $statusClass = function (?string $s) {
        $s = strtolower((string)$s);
        return match ($s) {
            'draft'       => 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200',
            'submitted'   => 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-200',
            'scheduled'   => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200',
            'in_progress' => 'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-200',
            'done'        => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
            'cancelled'   => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200',
            default       => 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200',
        };
    };

    $isOpenStatus = function (?string $s) {
        $s = strtolower((string)$s);
        return !in_array($s, ['done','cancelled'], true);
    };

    $fmtDateTime = function ($dt) {
        if (!$dt) return '-';
        try {
            return \Carbon\Carbon::parse($dt)->format('d/m/Y H:i');
        } catch (\Throwable $e) {
            return (string)$dt;
        }
    };

    $fmtDate = function ($dt) {
        if (!$dt) return '-';
        try {
            return \Carbon\Carbon::parse($dt)->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string)$dt;
        }
    };

    $fmtMoney = function ($n) {
        if ($n === null || $n === '') return '-';
        return 'Rp ' . number_format((float)$n, 0, ',', '.');
    };

    // ambil query filter utk re-populate
    $fStatus = request('status', '');
    $fType   = request('type', '');
    $fQ      = request('q', '');
    $fFrom   = request('from', '');
    $fTo     = request('to', '');
@endphp

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">

    {{-- =========================
        HERO / HEADER
    ========================== --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Legal</div>
        <div class="mt-1 text-3xl font-extrabold text-slate-900 leading-tight">
            Dashboard<br class="hidden sm:block"/> Legal Actions
        </div>
        <div class="mt-2 text-slate-600 max-w-2xl">
            Monitoring pekerjaan legal (khusus BE) + ringkasan status & tindakan cepat.
        </div>

        {{-- =========================
            FILTERS (rapi)
        ========================== --}}
        <form method="GET" class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5 items-end">
            <div>
                <label class="text-sm font-semibold text-slate-700">Status</label>
                <select name="status" class="mt-1 w-full rounded-xl border-slate-200 focus:border-slate-400 focus:ring-slate-200">
                    <option value="">Semua</option>
                    @foreach(['draft','submitted','scheduled','in_progress','done','cancelled'] as $st)
                        <option value="{{ $st }}" @selected($fStatus===$st)>{{ ucfirst(str_replace('_',' ',$st)) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-sm font-semibold text-slate-700">Tipe</label>
                <select name="type" class="mt-1 w-full rounded-xl border-slate-200 focus:border-slate-400 focus:ring-slate-200">
                    <option value="">Semua</option>
                    @foreach($types ?? [] as $t)
                        @if($t)
                            <option value="{{ $t }}" @selected($fType===$t)>{{ $t }}</option>
                        @endif
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label class="text-sm font-semibold text-slate-700">Kata kunci</label>
                <input name="q" value="{{ $fQ }}" placeholder="CIF / Debitur / No Rek / Ref / Ringkasan"
                       class="mt-1 w-full rounded-xl border-slate-200 focus:border-slate-400 focus:ring-slate-200" />
            </div>

            <div class="grid grid-cols-2 gap-3 lg:col-span-5">
                <div>
                    <label class="text-sm font-semibold text-slate-700">Dari</label>
                    <input type="date" name="from" value="{{ $fFrom }}"
                           class="mt-1 w-full rounded-xl border-slate-200 focus:border-slate-400 focus:ring-slate-200" />
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700">Sampai</label>
                    <input type="date" name="to" value="{{ $fTo }}"
                           class="mt-1 w-full rounded-xl border-slate-200 focus:border-slate-400 focus:ring-slate-200" />
                </div>
            </div>

            <div class="lg:col-span-5 flex flex-wrap gap-2 pt-1">
                <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Terapkan Filter
                </button>

                <a href="{{ url()->current() }}"
                   class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Reset
                </a>

                {{-- (opsional) nanti: tombol create --}}
                {{-- <a href="{{ route('legal-actions.create') }}" class="ml-auto rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">+ Tambah</a> --}}
            </div>
        </form>
    </div>

    {{-- =========================
        SUMMARY CARDS
    ========================== --}}
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @php
            $card = function($title, $value) {
                return '
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-xs font-bold uppercase tracking-wider text-slate-400">'.$title.'</div>
                    <div class="mt-2 text-4xl font-extrabold text-slate-900">'.e($value).'</div>
                </div>';
            };
        @endphp

        {!! $card('Total', (int)($stats['total'] ?? 0)) !!}
        {!! $card('Open', (int)($stats['open'] ?? 0)) !!}
        {!! $card('Submitted', (int)($stats['submitted'] ?? 0)) !!}
        {!! $card('Scheduled', (int)($stats['scheduled'] ?? 0)) !!}
    </div>

    {{-- =========================
        TABLE
    ========================== --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <div class="text-sm font-extrabold text-slate-900">Daftar Legal Actions</div>
                <div class="text-xs text-slate-500">Gunakan filter untuk mempersempit pencarian.</div>
            </div>

            <div class="text-xs text-slate-500">
                Total tampil: <span class="font-semibold text-slate-700">{{ $actions->total() ?? 0 }}</span>
            </div>
        </div>

        @if(($actions->count() ?? 0) === 0)
            <div class="p-6 text-sm text-slate-500">
                Tidak ada data sesuai filter. 🙏
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">Case</th>
                            <th class="px-4 py-3 text-left font-bold">Tipe</th>
                            <th class="px-4 py-3 text-left font-bold">Status</th>
                            <th class="px-4 py-3 text-left font-bold">Jadwal</th>
                            <th class="px-4 py-3 text-left font-bold">Handler</th>
                            <th class="px-4 py-3 text-left font-bold">Ringkasan</th>
                            <th class="px-4 py-3 text-right font-bold">Recovery</th>
                            <th class="px-4 py-3 text-right font-bold">Aksi</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">
                        @foreach($actions as $a)
                            @php
                                $st = $a->status ?? '';
                                $open = $isOpenStatus($st);

                                // SLA warning: end_at sudah lewat, masih open
                                $slaLate = false;
                                try {
                                    if ($open && $a->end_at) {
                                        $slaLate = \Carbon\Carbon::parse($a->end_at)->isPast();
                                    }
                                } catch (\Throwable $e) {
                                    $slaLate = false;
                                }

                                $handlerLine = trim(($a->handler_name ?? '')) ?: '-';
                                $subHandler  = trim(($a->law_firm_name ?? '')) ?: trim(($a->external_institution ?? '')) ?: '';

                                $scheduleText = '-';
                                if ($a->start_at || $a->end_at) {
                                    $scheduleText = ($a->start_at ? $fmtDate($a->start_at) : '-') . ' → ' . ($a->end_at ? $fmtDate($a->end_at) : '-');
                                }

                                // NOTE: detail route bisa kamu sesuaikan.
                                // kalau belum ada show route, sementara biarin ke index/detail nanti.
                                $detailUrl = route('legal-actions.show', $a->id ?? 0);
                                $editUrl   = route('legal-actions.edit', $a->id ?? 0);
                            @endphp

                            <tr class="{{ $slaLate ? 'bg-rose-50/40' : 'bg-white' }}">
                                {{-- Case --}}
                                <td class="px-4 py-3 align-top">
                                    <div class="font-semibold text-slate-900">
                                        #{{ $a->legal_case_id }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Ref: {{ $a->external_ref_no ?? '-' }}
                                    </div>
                                </td>

                                {{-- Tipe --}}
                                <td class="px-4 py-3 align-top">
                                    <div class="font-semibold text-slate-900">{{ $a->action_type ?? '-' }}</div>
                                    <div class="text-xs text-slate-500">Seq: {{ $a->sequence_no ?? 1 }}</div>
                                </td>

                                {{-- Status --}}
                                <td class="px-4 py-3 align-top">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold {{ $statusClass($st) }}">
                                        {{ $statusLabel($st) }}
                                    </span>

                                    @if($slaLate)
                                        <div class="mt-2 text-xs font-semibold text-rose-700">
                                            ⚠ Melewati target
                                        </div>
                                    @endif
                                </td>

                                {{-- Jadwal --}}
                                <td class="px-4 py-3 align-top">
                                    <div class="text-slate-900 font-semibold">{{ $scheduleText }}</div>
                                    <div class="text-xs text-slate-500">
                                        Mulai: {{ $fmtDateTime($a->start_at) }}<br>
                                        Selesai: {{ $fmtDateTime($a->end_at) }}
                                    </div>
                                </td>

                                {{-- Handler --}}
                                <td class="px-4 py-3 align-top">
                                    <div class="font-semibold text-slate-900">{{ $handlerLine }}</div>
                                    @if($subHandler)
                                        <div class="text-xs text-slate-500">{{ $subHandler }}</div>
                                    @endif
                                    <div class="text-xs text-slate-500">
                                        {{ $a->handler_phone ?? '' }}
                                    </div>
                                </td>

                                {{-- Ringkasan --}}
                                <td class="px-4 py-3 align-top">
                                    <div class="text-slate-900">
                                        {{ $a->summary ?? '-' }}
                                    </div>
                                    @if(!empty($a->notes))
                                        <div class="mt-1 text-xs text-slate-500 line-clamp-2">
                                            {{ $a->notes }}
                                        </div>
                                    @endif
                                </td>

                                {{-- Recovery --}}
                                <td class="px-4 py-3 align-top text-right">
                                    <div class="font-bold text-slate-900">{{ $fmtMoney($a->recovery_amount) }}</div>
                                    <div class="text-xs text-slate-500">{{ $fmtDate($a->recovery_date) }}</div>
                                    @if(!empty($a->result_type))
                                        <div class="mt-1 text-xs text-slate-500">
                                            Hasil: <span class="font-semibold text-slate-700">{{ $a->result_type }}</span>
                                        </div>
                                    @endif
                                </td>

                                {{-- Aksi --}}
                                <td class="px-4 py-3 align-top text-right">
                                    <div class="inline-flex flex-col gap-2 items-end">
                                        <a href="{{ $detailUrl }}"
                                           class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50">
                                            Detail
                                        </a>

                                        <a href="{{ $editUrl }}"
                                           class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-800">
                                            Update
                                        </a>

                                        {{-- Next step: tombol Done/Cancel/Reschedule (pakai form + policy) --}}
                                        {{-- <button>Done</button> --}}
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="px-5 py-4 border-t border-slate-100">
                {{ $actions->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
