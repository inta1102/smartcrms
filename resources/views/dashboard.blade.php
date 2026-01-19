@extends('layouts.app')

@section('title', 'Dashboard CRMS')

@section('content')
    <div class="w-full max-w-4xl space-y-6">

        {{-- Greeting Card --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-6 py-5 flex items-center justify-between">
            <div>
                <p class="text-sm text-slate-500 mb-1">Selamat datang di</p>
                <h1 class="text-2xl font-semibold text-slate-800">
                    Dashboard Credit Recovery Monitoring
                </h1>
                <p class="mt-2 text-sm text-slate-500">
                    Halo, <span class="font-semibold">{{ auth()->user()->name }}</span>.  
                    Berikut ringkasan posisi penanganan kredit bermasalah hari ini.
                </p>
            </div>
        </div>

        {{-- Summary cards --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-5 py-4">
                <p class="text-[11px] font-semibold text-slate-500 tracking-wide uppercase mb-2">
                    Total Kasus Kredit Kualitas Rendah (KKR)
                </p>
                <p class="text-3xl font-semibold text-msa-blue">
                    {{ number_format($totalCases, 0, ',', '.') }}
                </p>
                <p class="mt-1 text-[11px] text-slate-400">
                    Termasuk open dan closed sepanjang waktu.
                </p>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-5 py-4">
                <p class="text-[11px] font-semibold text-slate-500 tracking-wide uppercase mb-2">
                    Masih Dalam Proses
                </p>
                <p class="text-3xl font-semibold text-amber-500">
                    {{ number_format($openCases, 0, ',', '.') }}
                </p>
                <p class="mt-1 text-[11px] text-slate-400">
                    Kasus yang belum ditutup (open).
                </p>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-5 py-4">
                <p class="text-[11px] font-semibold text-slate-500 tracking-wide uppercase mb-2">
                    Selesai Bulan Ini
                </p>
                <p class="text-3xl font-semibold text-emerald-600">
                    {{ number_format($closedThisMonth, 0, ',', '.') }}
                </p>
                <p class="mt-1 text-[11px] text-slate-400">
                    Case yang berstatus closed di bulan berjalan.
                </p>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-5 py-4">
                <p class="text-[11px] font-semibold text-slate-500 tracking-wide uppercase mb-2">
                    OS Kasus Open
                </p>
                <p class="text-xl font-semibold text-slate-800">
                    Rp {{ number_format($totalOutstandingOpen, 0, ',', '.') }}
                </p>
                <p class="mt-1 text-[11px] text-slate-400">
                    Total outstanding kredit bermasalah yang masih open.
                </p>
            </div>
        </div>

        {{-- Reminder Next Action Overdue --}}
        @if ($overdueCount > 0)
            <div class="bg-white rounded-2xl shadow-sm border border-amber-100 px-5 py-4 space-y-2">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-amber-700 uppercase">
                            Reminder: Next Action Overdue
                        </p>
                        <p class="text-xs text-slate-600">
                            Ada <span class="font-semibold text-amber-700">{{ $overdueCount }}</span> kasus yang
                            tanggal tindak lanjutnya sudah lewat, namun case masih open.
                        </p>
                    </div>
                    <div class="hidden sm:block">
                        <a href="{{ route('cases.overdue') }}"
                        class="inline-flex items-center px-3 py-1.5 rounded-lg border border-amber-500 text-xs font-semibold text-amber-700 hover:bg-amber-50">
                            Lihat semua
                        </a>
                    </div>
                </div>

                <div class="divide-y divide-slate-100 text-xs">
                    @foreach ($overdueSamples as $case)
                        @php
                            $loan = $case->loanAccount;
                            // ambil 1 action overdue terbaru untuk display due date
                            $latestOverdue = \App\Models\CaseAction::where('npl_case_id', $case->id)
                                ->whereNotNull('next_action_due')
                                ->whereDate('next_action_due', '<', now()->toDateString())
                                ->orderBy('next_action_due', 'desc')
                                ->first();
                        @endphp
                        <div class="py-2 flex items-start justify-between gap-3">
                            <div class="flex-1">
                                <div class="font-semibold text-slate-800">
                                    {{ $loan?->customer_name ?? '-' }}
                                </div>
                                <div class="text-[11px] text-slate-500">
                                    Rek: <span class="font-mono">{{ $loan?->account_no ?? '-' }}</span>
                                    • Collector: {{ $loan?->ao_name ?? '-' }}
                                </div>
                                @if($latestOverdue)
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        Next action: <span class="font-semibold">{{ $latestOverdue->next_action ?? '-' }}</span>
                                    </div>
                                @endif
                            </div>
                            <div class="text-right text-[11px]">
                                @if($latestOverdue)
                                    <div class="text-amber-700 font-semibold">
                                        Jatuh tempo:
                                        {{ \Carbon\Carbon::parse($latestOverdue->next_action_due)->format('d-m-Y') }}
                                    </div>
                                @endif
                                <a href="{{ route('cases.show', $case) }}"
                                class="inline-flex mt-1 items-center px-2 py-1 rounded-lg border border-msa-blue text-[10px] font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                                    Detail
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="sm:hidden pt-1">
                    <a href="{{ route('cases.overdue') }}"
                    class="inline-flex items-center text-[11px] font-semibold text-amber-700 hover:underline">
                        Lihat semua kasus overdue
                    </a>
                </div>
            </div>
        @else
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-5 py-3 text-xs text-slate-500">
                Tidak ada kasus dengan next action overdue. 👍
            </div>
        @endif

        {{-- Next steps message --}}
        <div class="bg-msa-light/60 border border-msa-light rounded-2xl px-5 py-4 text-sm text-slate-700">
            <!-- <p class="font-semibold mb-1">Langkah selanjutnya</p>
            <ul class="list-disc list-inside space-y-1 text-sm">
                <li>Perluas filter dan grafik tren (per cabang, segmen, kolektibilitas).</li>
                <li>Monitoring kepatuhan tindak lanjut (next action vs due date).</li>
                <li>Integrasi laporan export (Excel/PDF) untuk manajemen dan komite.</li>
            </ul> -->

            <p class="mt-3">
                <a href="{{ route('cases.index') }}"
                   class="inline-flex items-center text-sm font-medium text-msa-blue hover:underline">
                    ➜ Lihat daftar kredit bermasalah
                </a>
                <!-- <span class="mx-2 text-slate-400">|</span>
                <a href="{{ route('loans.import.form') }}"
                   class="inline-flex items-center text-sm font-medium text-msa-blue hover:underline">
                    ➜ Import data kredit dari Excel
                </a> -->
            </p>
            <!-- <p class="mt-2 text-xs text-slate-500">
                Lihat juga:
                <a href="{{ route('dashboard.ao.index') }}" class="text-msa-blue font-semibold hover:underline">
                    Dashboard kinerja per AO
                </a>
            </p> -->

        </div>
    </div>
@endsection
