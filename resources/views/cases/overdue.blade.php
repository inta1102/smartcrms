@extends('layouts.app')

@section('title', 'Next Action Overdue')

@section('content')
    <div class="w-full max-w-6xl space-y-4">

        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-slate-800">
                    Next Action Overdue
                </h1>
                <p class="text-xs text-slate-500 mt-1">
                    Daftar kasus yang tanggal tindak lanjut (next action due) sudah lewat,
                    sementara case masih berstatus <strong>open</strong>.
                </p>
            </div>

            <a href="{{ route('dashboard') }}"
               class="hidden sm:inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-xs text-slate-700 hover:bg-slate-50">
                Kembali ke Dashboard
            </a>
        </div>

        {{-- Mobile: card list --}}
        <div class="md:hidden space-y-3">
            @forelse ($cases as $case)
                @php
                    $loan = $case->loanAccount;
                    $lastOverdue = $case->latestDueAction;
                @endphp
                <div class="bg-white rounded-2xl shadow-sm border border-amber-100 px-4 py-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="text-[11px] text-slate-500">Rekening</div>
                            <div class="font-mono text-sm font-semibold text-slate-800">
                                {{ $loan?->account_no ?? '-' }}
                            </div>
                            <div class="text-[10px] text-slate-400">
                                CIF: {{ $loan?->cif ?? '-' }}
                            </div>
                        </div>
                        <div class="text-right text-[11px]">
                            <div class="text-slate-500">Kolek</div>
                            <div class="font-semibold text-slate-800">
                                {{ $loan?->kolek ?? '-' }}
                            </div>
                            <div class="mt-1 text-slate-500">DPD</div>
                            <div class="font-semibold text-slate-800">
                                {{ $loan?->dpd ?? '-' }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-2 text-xs text-slate-700">
                        <div class="font-semibold">{{ $loan?->customer_name ?? '-' }}</div>
                        <div class="text-[11px] text-slate-500">
                            Cabang: {{ $loan?->branch_name ?? '-' }}
                        </div>
                    </div>

                    @if($lastOverdue)
                        <div class="mt-2 text-[11px] text-slate-700">
                            <div>Next action: <span class="font-semibold">{{ $lastOverdue->next_action ?? '-' }}</span></div>
                            <div>Due: <span class="font-semibold text-amber-700">
                                {{ $lastOverdue->next_action_due?->format('d-m-Y') }}
                            </span></div>
                            <!-- @if($lastOverdue->result)
                                <div class="mt-1 text-slate-500">
                                    Hasil terakhir: {{ $lastOverdue->result }}
                                </div>
                            @endif -->
                        </div>
                    @endif

                    <div class="mt-3 flex justify-between items-center text-[10px] text-slate-400">
                        <span>Opened: {{ $case->opened_at?->format('d-m-Y') ?? '-' }}</span>
                        <a href="{{ route('cases.show', $case) }}"
                           class="inline-flex items-center px-3 py-1.5 rounded-lg border border-msa-blue text-[11px] font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                            Detail
                        </a>
                    </div>
                </div>
            @empty
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-4 py-5 text-center text-sm text-slate-500">
                    Tidak ada kasus dengan next action overdue. 👍
                </div>
            @endforelse

            @if ($cases->hasPages())
                <div class="mt-2">
                    {{ $cases->links() }}
                </div>
            @endif
        </div>

        {{-- Desktop: tabel --}}
        <div class="bg-white rounded-2xl shadow-sm border border-amber-100 overflow-hidden hidden md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full text-xs md:text-sm">
                    <thead class="bg-amber-50 border-b border-amber-100">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600">Rekening</th>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600">Debitur</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Kolek</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">DPD</th>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600">Next Action</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Due Date</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($cases as $case)
                            @php
                                $loan = $case->loanAccount;
                                $lastOverdue = $case->latestDueAction;
                            @endphp
                            <tr class="border-b last:border-b-0 border-slate-100 hover:bg-amber-50/40">
                                <td class="px-3 py-2">
                                    <div class="font-mono text-xs md:text-sm text-slate-800">
                                        {{ $loan?->account_no ?? '-' }}
                                    </div>
                                    <div class="text-[10px] text-slate-400">
                                        CIF: {{ $loan?->cif ?? '-' }} |
                                        AO: {{ $loan?->ao_code ?? '-' }}
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="text-xs md:text-sm font-medium text-slate-800">
                                        {{ $loan?->customer_name ?? '-' }}
                                    </div>
                                    <div class="text-[10px] text-slate-400">
                                        {{ $loan?->ao_name ?? '-' }}
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    {{ $loan?->kolek ?? '-' }}
                                </td>
                                <td class="px-3 py-2 text-center">
                                    {{ $loan?->dpd ?? '-' }}
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-800">
                                    {{ $lastOverdue->next_action ?? '-' }}
                                    <!-- @if($lastOverdue->result)
                                        <div class="text-[10px] text-slate-500 mt-0.5">
                                            Hasil terakhir: {{ $lastOverdue->result }}
                                        </div>
                                    @endif -->
                                </td>
                                <td class="px-3 py-2 text-center text-xs font-semibold text-amber-700">
                                    {{ $lastOverdue->next_action_due?->format('d-m-Y') ?? '-' }}
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <a href="{{ route('cases.show', $case) }}"
                                       class="inline-flex items-center px-3 py-1.5 rounded-lg border border-msa-blue text-xs font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                                        Detail
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-5 text-center text-sm text-slate-500">
                                    Tidak ada kasus dengan next action overdue. 👍
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($cases->hasPages())
                <div class="px-4 py-3 border-t border-slate-100">
                    {{ $cases->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
