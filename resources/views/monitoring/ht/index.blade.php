@extends('layouts.app')

@section('content')
@php
    $badge = function($s) {
        $s = strtolower((string)$s);
        return match($s) {
            'open'      => 'bg-slate-50 text-slate-700 border-slate-200',
            'prepared'  => 'bg-sky-50 text-sky-700 border-sky-200',
            'submitted' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'scheduled' => 'bg-purple-50 text-purple-700 border-purple-200',
            'executed'  => 'bg-amber-50 text-amber-700 border-amber-200',
            'settled'   => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'closed'    => 'bg-slate-900 text-white border-slate-900',
            'cancelled' => 'bg-rose-50 text-rose-700 border-rose-200',
            default     => 'bg-slate-50 text-slate-700 border-slate-200',
        };
    };

    $asOf = now()->timezone(config('app.timezone'));
    $k = fn($s) => (int) ($countsByStatus[strtolower($s)] ?? 0);

    $fmtAging = function($days){
        if ($days === null) return '-';
        return (int)$days . ' hari';
    };
@endphp

<div class="max-w-7xl mx-auto px-4 py-6">

    {{-- Header --}}
    <div class="mb-5 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
            <div class="text-2xl font-bold text-slate-900">Monitoring Lelang/Parate</div>
            <div class="mt-1 text-sm text-slate-500">
                Ringkasan status, SLA aging, dan kelengkapan dokumen/checklist untuk eksekusi HT.
            </div>
            <div class="mt-2 text-xs text-slate-500">
                As of: <span class="font-semibold text-slate-700">{{ $asOf->format('d/m/Y H:i') }}</span>
                <span class="text-slate-400">({{ config('app.timezone') }})</span>
            </div>
        </div>

        <div class="flex gap-2">
            <a href="{{ route('dashboard') }}"
               class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                ← Kembali
            </a>

            {{-- opsional kalau nanti ada export --}}
            {{-- <a href="{{ route('monitoring.ht.export', request()->query()) }}" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Export</a> --}}
        </div>
    </div>

    {{-- KPI Status --}}
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
        @foreach([
            ['Open','open','text-slate-900'],
            ['Scheduled','scheduled','text-slate-900'],
            ['Executed','executed','text-slate-900'],
            ['Settled','settled','text-slate-900'],
            ['Closed','closed','text-slate-900'],
            ['Cancelled','cancelled','text-rose-600'],
        ] as [$label,$key,$cls])
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs text-slate-500">{{ $label }}</div>
                <div class="mt-1 text-2xl font-bold {{ $cls }}">{{ $k($key) }}</div>
            </div>
        @endforeach
    </div>

    {{-- Filter --}}
    <form class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" method="GET">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            {{-- Search --}}
            <div class="md:col-span-5">
                <label class="text-xs font-semibold text-slate-600">Search</label>
                <input name="q" value="{{ $filters['q'] ?? '' }}"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    placeholder="Legal case / Debitur / Sertifikat / Owner">
            </div>

            {{-- Status --}}
            <div class="md:col-span-2">
                <label class="text-xs font-semibold text-slate-600">Status</label>
                <select name="status" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach(['prepared','submitted','scheduled','executed','settled','closed','cancelled'] as $s)
                        <option value="{{ $s }}" {{ in_array($s, $filters['statuses'] ?? [], true) ? 'selected' : '' }}>
                            {{ strtoupper($s) }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Method --}}
            <div class="md:col-span-2">
                <label class="text-xs font-semibold text-slate-600">Method</label>
                <select name="method" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="parate" {{ ($filters['method'] ?? '')==='parate'?'selected':'' }}>Parate</option>
                    <option value="bawah_tangan" {{ ($filters['method'] ?? '')==='bawah_tangan'?'selected':'' }}>Bawah Tangan</option>
                </select>
            </div>

            {{-- Aging --}}
            <div class="md:col-span-3">
                <label class="text-xs font-semibold text-slate-600">Aging (SLA)</label>
                <select name="aging" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="all"  {{ ($filters['aging'] ?? 'all')==='all'?'selected':'' }}>All</option>
                    <option value="lt7"  {{ ($filters['aging'] ?? '')==='lt7'?'selected':'' }}>&lt; 7 hari</option>
                    <option value="7_30" {{ ($filters['aging'] ?? '')==='7_30'?'selected':'' }}>7–30 hari</option>
                    <option value="31_90"{{ ($filters['aging'] ?? '')==='31_90'?'selected':'' }}>31–90 hari</option>
                    <option value="gt90" {{ ($filters['aging'] ?? '')==='gt90'?'selected':'' }}>&gt; 90 hari</option>
                </select>
            </div>

            {{-- Action buttons: bikin baris sendiri biar nggak “mecotot” --}}
            <div class="md:col-span-12 flex gap-2 justify-end pt-1">
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Terapkan
                </button>
                <a href="{{ route('monitoring.ht.index') }}"
                class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Reset
                </a>
            </div>

            {{-- Catatan audit --}}
            <div class="md:col-span-12 pt-1">
                <div class="text-xs text-slate-500">
                    Catatan audit: Aging dihitung dari <span class="font-semibold">Last Update (updated_at)</span> sebagai indikator aktivitas terbaru.
                </div>
            </div>
        </div>
    </form>

    {{-- KPI Aging --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs text-slate-500">&lt; 7 hari</div>
            <div class="mt-1 text-2xl font-bold text-slate-900">{{ (int)($agingKpi->lt7 ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs text-slate-500">7–30 hari</div>
            <div class="mt-1 text-2xl font-bold text-slate-900">{{ (int)($agingKpi->d7_30 ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs text-slate-500">31–90 hari</div>
            <div class="mt-1 text-2xl font-bold text-slate-900">{{ (int)($agingKpi->d31_90 ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs text-slate-500">&gt; 90 hari</div>
            <div class="mt-1 text-2xl font-bold text-rose-600">{{ (int)($agingKpi->gt90 ?? 0) }}</div>
        </div>
    </div>

    {{-- Table --}}
    <div class="mt-5 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="text-left px-4 py-3">Case</th>
                    <th class="text-left px-4 py-3">Debitur</th>
                    <th class="text-left px-4 py-3">Objek</th>
                    <th class="text-left px-4 py-3">Status</th>
                    <th class="text-left px-4 py-3">Aging</th>
                    <th class="text-left px-4 py-3">Last Update</th>
                    <th class="text-right px-4 py-3">Aksi</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
                @forelse($actions as $a)
                    @php
                        $lc  = $a->legalCase;
                        $npl = $lc?->nplCase;
                        $la  = $npl?->loanAccount;

                        $caseNo = $lc?->legal_case_no ?? '-';
                        $debtor = $la?->customer_name ?? '-';

                        $ao   = $la?->ao_name ?? $la?->ao_code ?? null;
                        $cif  = $la?->cif ?? null;
                        $acc  = $la?->account_no ?? $la?->loan_account_no ?? null;

                        $ht = $a->htExecution;
                        $obj = trim((string)(($ht?->land_cert_type ?? '').' '.($ht?->land_cert_no ?? '')));
                        $obj = $obj !== '' ? $obj : '-';

                        $ownerName   = $ht?->owner_name ?? '-';
                        $methodLabel = $ht?->method ?? '-';

                        $last = $a->updated_at ?? null;
                        $last = $last ? ($last instanceof \Carbon\Carbon ? $last : \Carbon\Carbon::parse($last)) : null;

                        $agingDays = $last ? $last->diffInDays(now()) : null;
                        $status    = strtolower((string)($a->status ?? ''));
                    @endphp

                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 align-top">
                            <div class="font-semibold text-slate-900">{{ $caseNo }}</div>
                            <div class="mt-1 text-xs text-slate-500">
                                ID: {{ $a->id }}
                                @if($acc) <span class="mx-1">•</span> Rek: {{ $acc }} @endif
                                @if($cif) <span class="mx-1">•</span> CIF: {{ $cif }} @endif
                                @if($ao)  <span class="mx-1">•</span> AO: {{ $ao }} @endif
                            </div>
                        </td>

                        <td class="px-4 py-3 align-top">
                            <div class="font-semibold text-slate-900">{{ $debtor }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $methodLabel }}</div>
                        </td>

                        <td class="px-4 py-3 align-top">
                            <div class="font-semibold text-slate-900">{{ $obj }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $ownerName }}</div>
                        </td>

                        <td class="px-4 py-3 align-top">
                            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold {{ $badge($status) }}">
                                {{ strtoupper($status ?: '-') }}
                            </span>
                        </td>

                        <td class="px-4 py-3 align-top">
                            <div class="font-semibold text-slate-900">{{ $fmtAging($agingDays) }}</div>
                        </td>

                        <td class="px-4 py-3 align-top">
                            <div class="font-semibold text-slate-900">{{ $last?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '-' }}</div>
                        </td>

                        <td class="px-4 py-3 text-right whitespace-nowrap align-top">
                            <a href="{{ route('legal-actions.ht.show', ['action'=>$a, 'tab'=>'summary']) }}"
                               class="inline-flex items-center rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                Buka HT
                            </a>

                            @if(in_array($status, ['settled','closed'], true))
                                <a href="{{ route('legal-actions.ht.audit_pdf', $a) }}"
                                   target="_blank"
                                   class="ml-2 inline-flex items-center rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                                    PDF
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-slate-500">
                            Tidak ada data.
                        </td>
                    </tr>
                @endforelse
            </tbody>

        </table>
    </div>

    <div class="mt-4">
        {{ $actions->links() }}
    </div>

</div>
@endsection
