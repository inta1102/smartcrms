@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">
    <div class="mb-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Approval Target KPI Marketing</h1>
                <p class="text-sm text-slate-500">Inbox target yang disubmit AO.</p>
            </div>

            {{-- info inbox status (dikunci oleh role di controller) --}}
            @php
                $inboxLabel = $status === \App\Models\MarketingKpiTarget::STATUS_PENDING_TL
                    ? 'Inbox: Pending TL'
                    : 'Inbox: Pending Kasi';

                $inboxBadge = $status === \App\Models\MarketingKpiTarget::STATUS_PENDING_TL
                    ? 'bg-sky-100 text-sky-800'
                    : 'bg-violet-100 text-violet-800';
            @endphp
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $inboxBadge }}">
                {{ $inboxLabel }}
            </span>
        </div>

        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <form method="GET" action="{{ route('kpi.marketing.approvals.index') }}" class="flex flex-wrap gap-3 items-end">
                {{-- period --}}
                <div>
                    <label class="text-sm font-medium">Periode (Bulan)</label>
                    <select name="period" class="form-select" onchange="this.form.submit()">
                        <option value="">Semua</option>
                        @foreach($periodOptions as $p)
                            <option value="{{ $p }}" @selected(($period ?? '') === $p)>
                                {{ \Carbon\Carbon::parse($p)->translatedFormat('M Y') }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <button class="btn btn-primary">Terapkan</button>
            </form>
        </div>
    </div>

    @if(session('status'))
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="text-left px-4 py-3">Periode</th>
                    <th class="text-left px-4 py-3">AO</th>
                    <th class="text-right px-4 py-3">OS Growth</th>
                    <th class="text-right px-4 py-3">NOA</th>
                    <th class="text-left px-4 py-3">Status</th>
                    <th class="text-right px-4 py-3">Aksi</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
                @forelse($targets as $t)
                    @php
                        $rowStatus = strtoupper((string)$t->status);

                        $badge = match($rowStatus) {
                            'DRAFT'        => 'bg-slate-100 text-slate-700',
                            'PENDING_TL'   => 'bg-sky-100 text-sky-800',
                            'PENDING_KASI' => 'bg-violet-100 text-violet-800',
                            'APPROVED'     => 'bg-emerald-100 text-emerald-800',
                            'REJECTED'     => 'bg-rose-100 text-rose-800',
                            default        => 'bg-slate-100 text-slate-700',
                        };

                        $label = match($rowStatus) {
                            'PENDING_TL'   => 'Pending TL',
                            'PENDING_KASI' => 'Pending Kasi',
                            default        => $rowStatus,
                        };
                    @endphp

                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 whitespace-nowrap font-semibold text-slate-900">
                            {{ \Carbon\Carbon::parse($t->period)->translatedFormat('M Y') }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-semibold text-slate-900">{{ $t->user?->name }}</div>
                            <div class="text-xs text-slate-500">AO Code: {{ $t->user?->ao_code ?? '-' }}</div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            Rp {{ number_format($t->target_os_growth,0,',','.') }}
                        </td>
                        <td class="px-4 py-3 text-right">{{ number_format($t->target_noa) }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $badge }}">
                                {{ $label }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('kpi.marketing.approvals.show', $t) }}"
                               class="rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                Review
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                            Tidak ada data.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $targets->links() }}
    </div>
</div>
@endsection
