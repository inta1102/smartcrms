@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="mb-5">
        <h1 class="text-2xl font-bold text-slate-900">Approval Target (TL)</h1>
        <p class="text-slate-500">Daftar target penyelesaian yang menunggu persetujuan TL.</p>
    </div>

    <div class="rounded-2xl border bg-white p-4 shadow-sm">
        <div class="flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between mb-4">
            <form class="flex gap-2 w-full sm:w-auto">
                <input name="q" value="{{ request('q') }}"
                       class="w-full sm:w-80 rounded-xl border-slate-200 px-3 py-2"
                       placeholder="Cari debitur..." />
                <button class="rounded-xl bg-slate-900 text-white px-4 py-2">Cari</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-slate-500">
                    <tr class="border-b">
                        <th class="text-left py-2">Debitur</th>
                        <th class="text-left py-2">Target Date</th>
                        <th class="text-left py-2">Strategi</th>
                        <th class="text-left py-2">AO</th>
                        <th class="text-right py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($targets as $t)
                    @php
                        $loan = $t->case?->loanAccount;
                        $debtor = $loan?->customer_name ?? $t->case?->debtor_name ?? '-';
                        $ao = $loan?->ao_name ?? '-';
                    @endphp
                    <tr class="border-b">
                        <td class="py-3">
                            <div class="font-semibold text-slate-900">{{ $debtor }}</div>
                            <div class="text-xs text-slate-500">Case #{{ $t->npl_case_id }}</div>
                        </td>
                        <td class="py-3">{{ $t->target_date }}</td>
                        <td class="py-3">{{ $t->strategy ?? '-' }}</td>
                        <td class="py-3">{{ $ao }}</td>
                        <td class="py-3 text-right">
                            <div class="flex justify-end gap-2">
                                <form method="POST" action="{{ route('supervision.tl.approvals.targets.approve', $t) }}">
                                    @csrf
                                    <button class="rounded-xl bg-emerald-600 text-white px-3 py-2">Approve</button>
                                </form>
                                <form method="POST" action="{{ route('supervision.tl.approvals.targets.reject', $t) }}">
                                    @csrf
                                    <input type="hidden" name="reason" value="Ditolak TL." />
                                    <button class="rounded-xl bg-rose-600 text-white px-3 py-2">Reject</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-8 text-center text-slate-500">Tidak ada target pending TL.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $targets->links() }}</div>
    </div>
</div>
@endsection
