@extends('layouts.app')

@section('title', 'Dashboard Kinerja AO')

@section('content')
    <div class="w-full max-w-6xl space-y-4">

        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-slate-800">
                    Dashboard Kinerja Account Officer
                </h1>
                <p class="text-xs text-slate-500 mt-1">
                    Ringkasan jumlah kasus, status penanganan, dan OS kredit bermasalah per AO.
                </p>
            </div>

            <a href="{{ route('dashboard') }}"
               class="hidden sm:inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-xs text-slate-700 hover:bg-slate-50">
                Kembali ke Dashboard
            </a>
        </div>

        {{-- Mobile: card list --}}
        <div class="md:hidden space-y-3">
            @foreach ($stats as $row)
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-semibold text-slate-800">
                                {{ $row->ao_name ?? 'Tanpa AO' }}
                            </div>
                            <div class="text-[11px] text-slate-500">
                                Kode: {{ $row->ao_code ?? '-' }}
                            </div>
                        </div>
                        <div class="mt-2 flex gap-2">
                            <a href="{{ route('dashboard.ao.show', $row->ao_code) }}"
                            class="inline-flex flex-1 items-center justify-center px-3 py-1.5 rounded-lg border border-msa-blue text-[11px] font-semibold text-msa-blue hover:bg-msa-blue hover:text-white">
                                Detail
                            </a>
                            <a href="{{ route('dashboard.ao.agenda', $row->ao_code) }}"
                            class="inline-flex flex-1 items-center justify-center px-3 py-1.5 rounded-lg border border-amber-500 text-[11px] font-semibold text-amber-600 hover:bg-amber-500 hover:text-white">
                                Agenda
                            </a>
                        </div>
                    </div>

                    <div class="mt-2 grid grid-cols-2 gap-2 text-[11px] text-slate-600">
                        <div>
                            <div>Total kasus</div>
                            <div class="text-lg font-semibold text-slate-800">
                                {{ $row->total_cases }}
                            </div>
                        </div>
                        <div>
                            <div>Open</div>
                            <div class="text-lg font-semibold text-amber-600">
                                {{ $row->open_cases }}
                            </div>
                        </div>
                        <div>
                            <div>Closed bulan ini</div>
                            <div class="text-lg font-semibold text-emerald-600">
                                {{ $row->closed_this_month }}
                            </div>
                        </div>
                        <div>
                            <div>Overdue next action</div>
                            <div class="text-lg font-semibold text-red-600">
                                {{ $row->overdue_cases }}
                            </div>
                        </div>
                        <div>
                            <div>Belum pernah ditangani</div>
                            <div class="text-lg font-semibold text-rose-600">
                                {{ $row->no_action_cases }}
                            </div>
                        </div>
                        <div>
                            <div>% tanpa penanganan (dari open)</div>
                            <div class="text-lg font-semibold text-slate-800">
                                {{ $row->no_action_pct }}%
                            </div>
                        </div>
                    </div>

                    <div class="mt-2 text-[11px] text-slate-600">
                        <div>OS kasus open:</div>
                        <div class="font-semibold text-slate-800">
                            Rp {{ number_format($row->os_open, 0, ',', '.') }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Desktop: tabel --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden hidden md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full text-xs md:text-sm">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600">AO</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Total Kasus</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Open</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Closed</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Closed Bulan Ini</th>
                            <th class="px-3 py-2 text-right font-semibold text-slate-600">OS Kasus Open</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Overdue Next Action</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Kasus Tanpa Penanganan</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">% Tanpa Penanganan<br class="hidden md:block"><span class="font-normal text-[11px]">(dari Open)</span></th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Risk</th>
                            <th class="px-3 py-2 text-center font-semibold text-slate-600">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $risk = $row->risk_level ?? 'LOW';

                            $riskClass = match($risk) {
                                'CRITICAL' => 'bg-rose-600 text-white',
                                'HIGH'     => 'bg-orange-500 text-white',
                                'MEDIUM'   => 'bg-amber-400 text-slate-900',
                                default    => 'bg-emerald-100 text-emerald-800',
                            };

                            $riskLabel = match($risk) {
                                'CRITICAL' => 'KRITIS',
                                'HIGH'     => 'TINGGI',
                                'MEDIUM'   => 'SEDANG',
                                default    => 'RENDAH',
                            };
                        @endphp

                        @foreach ($stats as $row)
                            <tr class="border-b last:border-b-0 border-slate-100 hover:bg-slate-50/60">
                                <td class="px-3 py-2">
                                    <div class="font-semibold text-slate-800">
                                        {{ $row->ao_name ?? 'Tanpa AO' }}
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        Kode: {{ $row->ao_code ?? '-' }}
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    {{ $row->total_cases }}
                                </td>
                                <td class="px-3 py-2 text-center text-amber-600 font-semibold">
                                    {{ $row->open_cases }}
                                </td>
                                <td class="px-3 py-2 text-center text-slate-700">
                                    {{ $row->closed_cases }}
                                </td>
                                <td class="px-3 py-2 text-center text-emerald-600 font-semibold">
                                    {{ $row->closed_this_month }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    Rp {{ number_format($row->os_open, 0, ',', '.') }}
                                </td>
                                <td class="px-3 py-2 text-center text-red-600 font-semibold">
                                    {{ $row->overdue_cases }}
                                </td>
                                <td class="px-3 py-2 text-center text-rose-600 font-semibold">
                                    {{ $row->no_action_cases }}
                                </td>
                                <td class="px-3 py-2 text-center">
                                    {{ $row->no_action_pct }}%
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold {{ $riskClass }}">
                                        {{ $riskLabel }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-center gap-2">

                                        {{-- Detail Kasus --}}
                                        <a href="{{ route('dashboard.ao.show', $row->ao_code) }}"
                                        title="Lihat detail seluruh kasus AO ini"
                                        class="inline-flex items-center justify-center w-8 h-8
                                                rounded-full border border-msa-blue
                                                text-msa-blue hover:bg-msa-blue hover:text-white
                                                transition">
                                            {{-- icon: document --}}
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4"
                                                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M9 12h6m-6 4h6m2 4H7a2 2 0 01-2-2V6a2 2 0 012-2h5l5 5v11a2 2 0 01-2 2z" />
                                            </svg>
                                        </a>

                                        {{-- Agenda AO --}}
                                        <a href="{{ route('ao-agendas.ao', ['aoCode' => $row->ao_code]) }}"
                                        title="Lihat agenda tindakan AO (follow up, visit, SP)"
                                        class="inline-flex items-center justify-center w-8 h-8
                                                rounded-full border border-amber-500
                                                text-amber-600 hover:bg-amber-500 hover:text-white
                                                transition">
                                            {{-- icon: calendar --}}
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4"
                                                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                        </a>

                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

    </div>
@endsection
