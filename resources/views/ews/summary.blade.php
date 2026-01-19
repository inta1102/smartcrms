@extends('layouts.app')

@section('content')
@php
    $fmt = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
@endphp

<div class="max-w-7xl mx-auto px-4 py-6 space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">EWS Executive Summary</h1>
            <!-- <p class="text-sm text-slate-500">Posisi: <span class="font-semibold">{{ $filters['positionDate'] }}</span></p> -->
            <p class="text-xs text-slate-500">
                Posisi: <span class="font-semibold">{{ $filters['positionDate'] }}</span>
                <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                    Scope: {{ $scope }}
                </span>
            </p>
        </div>

        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label class="block text-xs text-slate-500">Position Date</label>
                <input type="date" name="position_date" value="{{ $filters['positionDate'] }}"
                       class="rounded-lg border-slate-200 text-sm">
            </div>

            <div>
                <label class="block text-xs text-slate-500">Branch Code</label>
                <input type="text" name="branch_code" value="{{ $filters['branchCode'] }}"
                       class="rounded-lg border-slate-200 text-sm" placeholder="opsional">
            </div>

            <div>
                <label class="block text-xs text-slate-500">AO Code</label>
                <input type="text" name="ao_code" value="{{ $filters['aoCode'] }}"
                       class="rounded-lg border-slate-200 text-sm" placeholder="opsional">
            </div>

            <button class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white">
                Terapkan
            </button>
        </form>
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($cards as $c)
            <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                <div class="text-xs text-slate-500">{{ $c['label'] }}</div>

                <div class="mt-1 text-2xl font-bold text-slate-900">
                    @if(str_contains($c['label'], 'OS') || $c['label'] === 'Total OS')
                        {{ $fmt($c['value']) }}
                    @else
                        {{ number_format((int)$c['value'], 0, ',', '.') }}
                    @endif
                </div>

                <div class="mt-2 text-xs text-slate-500">
                    {{ $c['hint'] ?? '' }}
                    @if(isset($c['meta']['ratio']))
                        <span class="ml-1 text-slate-400">
                            ({{ number_format($c['meta']['ratio'] * 100, 2, ',', '.') }}%)
                        </span>
                    @endif
                </div>

            </div>
        @endforeach
    
        @php
            $macetMeta = $macetMeta ?? null;
            $warn = (int) data_get($macetMeta, 'warn_count', 0);

            $cardClass = $warn > 0
                ? 'border-rose-200 bg-rose-50'
                : 'border-slate-200 bg-white';

            $titleClass = $warn > 0 ? 'text-rose-700' : 'text-slate-500';
            $valueClass = $warn > 0 ? 'text-rose-700' : 'text-slate-900';
        @endphp

        <div class="rounded-2xl border p-5 shadow-sm {{ $cardClass }}">
            <div class="flex items-center justify-between">
                <div class="text-sm {{ $titleClass }}">
                    Usia Macet (Kolek 5)
                </div>

                @if($warn > 0)
                    <span class="inline-flex items-center rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-semibold text-white">
                        ⚠ {{ $warn }}
                    </span>
                @endif
            </div>

            <div class="mt-2 text-3xl font-extrabold {{ $valueClass }}">
                {{ number_format((int) data_get($macetMeta, 'total.rek', 0)) }}
            </div>

            <div class="mt-1 text-sm {{ $warn > 0 ? 'text-rose-600' : 'text-slate-600' }}">
                OS:
                <span class="font-semibold">
                    Rp {{ number_format((float) data_get($macetMeta, 'total.os', 0), 0, ',', '.') }}
                </span>
            </div>

            <div class="mt-3 text-xs text-slate-600 space-y-1">
                <div>Agunan 6 (20–24 bln): <b>{{ number_format((int)data_get($macetMeta,'ag6.rek',0)) }}</b></div>
                <div>Agunan 9 (9–12 bln): <b>{{ number_format((int)data_get($macetMeta,'ag9.rek',0)) }}</b></div>
            </div>

            <div class="mt-4">
                <a class="text-sm font-semibold {{ $warn > 0 ? 'text-rose-700' : 'text-slate-700' }} hover:underline"
                href="{{ route('ews.macet') }}">
                    Lihat Detail →
                </a>
            </div>
        </div>
    </div>
    
    {{-- Distribution --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- DPD Distribution --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Distribusi DPD</h2>
                    <p class="text-xs text-slate-500">Jumlah rekening & porsi OS per bucket.</p>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500">
                            <th class="py-2 pr-3">Bucket</th>
                            <th class="py-2 pr-3">Rek</th>
                            <th class="py-2 pr-3">OS</th>
                            <th class="py-2">%</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($dpdDist as $r)
                            <tr>
                                <td class="py-2 pr-3 font-semibold text-slate-700">{{ $r['bucket'] }}</td>
                                <td class="py-2 pr-3 text-slate-700">{{ number_format($r['count'], 0, ',', '.') }}</td>
                                <td class="py-2 pr-3 text-slate-900 font-semibold">{{ $fmt($r['os']) }}</td>
                                <td class="py-2 text-slate-600">
                                    {{ number_format(($r['ratio'] ?? 0) * 100, 2, ',', '.') }}%
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- KOLEK Distribution --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Distribusi Kolektibilitas</h2>
                    <p class="text-xs text-slate-500">Jumlah rekening & porsi OS per kolek.</p>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500">
                            <th class="py-2 pr-3">Kolek</th>
                            <th class="py-2 pr-3">Rek</th>
                            <th class="py-2 pr-3">OS</th>
                            <th class="py-2">%</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($kolekDist as $r)
                            <tr>
                                <td class="py-2 pr-3 font-semibold text-slate-700">{{ $r['bucket'] }}</td>
                                <td class="py-2 pr-3 text-slate-700">{{ number_format($r['count'], 0, ',', '.') }}</td>
                                <td class="py-2 pr-3 text-slate-900 font-semibold">{{ $fmt($r['os']) }}</td>
                                <td class="py-2 text-slate-600">
                                    {{ number_format(($r['ratio'] ?? 0) * 100, 2, ',', '.') }}%
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Top Risk Accounts --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Top 10 Rekening Risiko</h2>
                    <p class="text-xs text-slate-500">Urutan berdasarkan score (kolek, dpd, restruk, OS).</p>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500">
                            <th class="py-2 pr-3">#</th>
                            <th class="py-2 pr-3">Rekening</th>
                            <th class="py-2 pr-3">Nasabah</th>
                            <th class="py-2 pr-3">AO</th>
                            <th class="py-2 pr-3">Kolek</th>
                            <th class="py-2 pr-3">DPD</th>
                            <th class="py-2 pr-3">Restruk</th>
                            <th class="py-2 pr-3">OS</th>
                            <th class="py-2">Score</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">
                        @forelse($topRiskAccounts as $i => $r)
                            @php
                                $kolek = (int)$r['kolek'];
                                $dpd   = (int)$r['dpd'];
                                $rs    = (bool)$r['is_restructured'];

                                $badge = function($txt, $tone='slate') {
                                    $map = [
                                        'red' => 'bg-rose-50 text-rose-700 border-rose-200',
                                        'amber' => 'bg-amber-50 text-amber-800 border-amber-200',
                                        'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'slate' => 'bg-slate-50 text-slate-700 border-slate-200',
                                        'indigo' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                    ];
                                    $cls = $map[$tone] ?? $map['slate'];
                                    return "<span class=\"inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {$cls}\">{$txt}</span>";
                                };

                                $kolekTone = $kolek >= 3 ? 'red' : ($kolek == 2 ? 'amber' : 'emerald');
                                $dpdTone   = $dpd >= 60 ? 'red' : ($dpd >= 30 ? 'amber' : 'slate');
                                $rsTone    = $rs ? 'indigo' : 'slate';
                            @endphp
                            <tr>
                                <td class="py-2 pr-3 text-slate-500">{{ $i + 1 }}</td>
                                <td class="py-2 pr-3 font-mono text-slate-800">{{ $r['account_no'] }}</td>
                                <td class="py-2 pr-3 text-slate-900 font-semibold">{{ $r['customer_name'] }}</td>
                                <td class="py-2 pr-3 text-slate-700">{{ $r['ao'] }}</td>

                                <td class="py-2 pr-3">{!! $badge('K'.$kolek, $kolekTone) !!}</td>
                                <td class="py-2 pr-3">{!! $badge((string)$dpd, $dpdTone) !!}</td>

                                <td class="py-2 pr-3">
                                    @if($rs)
                                        {!! $badge('RS ('.$r['restructure_freq'].')', $rsTone) !!}
                                    @else
                                        {!! $badge('No', 'slate') !!}
                                    @endif
                                </td>

                                <td class="py-2 pr-3 text-slate-900 font-semibold">{{ $fmt($r['os']) }}</td>
                                <td class="py-2 text-slate-700">{{ number_format($r['risk_score'], 2, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-4 text-center text-sm text-slate-500">
                                    Tidak ada data untuk ditampilkan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Top Exposure Risk Accounts --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Top 10 Exposure Risiko</h2>
                    <p class="text-xs text-slate-500">OS terbesar dengan indikator risiko (DPD/Restruk/Kolek).</p>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500">
                            <th class="py-2 pr-3">#</th>
                            <th class="py-2 pr-3">Rekening</th>
                            <th class="py-2 pr-3">Nasabah</th>
                            <th class="py-2 pr-3">AO</th>
                            <th class="py-2 pr-3">Kolek</th>
                            <th class="py-2 pr-3">DPD</th>
                            <th class="py-2 pr-3">Restruk</th>
                            <th class="py-2 pr-3">Reason</th>
                            <th class="py-2">OS</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">
                        @forelse($topExposureRiskAccounts as $i => $r)
                            @php
                                $kolek = (int)$r['kolek'];
                                $dpd   = (int)$r['dpd'];
                                $rs    = (bool)$r['is_restructured'];
                                $reason= $r['reason'] ?? 'MONITOR';

                                $badge = function($txt, $tone='slate') {
                                    $map = [
                                        'red' => 'bg-rose-50 text-rose-700 border-rose-200',
                                        'amber' => 'bg-amber-50 text-amber-800 border-amber-200',
                                        'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'slate' => 'bg-slate-50 text-slate-700 border-slate-200',
                                        'indigo' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                        'purple' => 'bg-purple-50 text-purple-700 border-purple-200',
                                    ];
                                    $cls = $map[$tone] ?? $map['slate'];
                                    return "<span class=\"inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {$cls}\">{$txt}</span>";
                                };

                                $kolekTone = $kolek >= 3 ? 'red' : ($kolek == 2 ? 'amber' : 'emerald');
                                $dpdTone   = $dpd >= 60 ? 'red' : ($dpd >= 30 ? 'amber' : ($dpd > 15 ? 'purple' : 'slate'));
                                $rsTone    = $rs ? 'indigo' : 'slate';

                                $reasonTone = match($reason) {
                                    'KOLEK>=3' => 'red',
                                    'DPD>15'   => 'purple',
                                    'RESTRUK'  => 'indigo',
                                    'KOLEK=2'  => 'amber',
                                    default    => 'slate',
                                };
                            @endphp

                            <tr>
                                <td class="py-2 pr-3 text-slate-500">{{ $i + 1 }}</td>
                                <td class="py-2 pr-3 font-mono text-slate-800">{{ $r['account_no'] }}</td>
                                <td class="py-2 pr-3 text-slate-900 font-semibold">{{ $r['customer_name'] }}</td>
                                <td class="py-2 pr-3 text-slate-700">{{ $r['ao'] }}</td>

                                <td class="py-2 pr-3">{!! $badge('K'.$kolek, $kolekTone) !!}</td>
                                <td class="py-2 pr-3">{!! $badge((string)$dpd, $dpdTone) !!}</td>

                                <td class="py-2 pr-3">
                                    @if($rs)
                                        {!! $badge('RS ('.$r['restructure_freq'].')', $rsTone) !!}
                                    @else
                                        {!! $badge('No', 'slate') !!}
                                    @endif
                                </td>

                                <td class="py-2 pr-3">{!! $badge($reason, $reasonTone) !!}</td>
                                <td class="py-2 text-slate-900 font-semibold">{{ $fmt($r['os']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-4 text-center text-sm text-slate-500">
                                    Tidak ada data untuk ditampilkan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Top RS HighRisk--}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Top 10 RS HighRisk</h2>
                    <p class="text-xs text-slate-500">Restrukturisasi Beresiko Tinggi (R0-30 & DPD > 0).</p>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500">
                            <th class="py-2 pr-3">#</th>
                            <th class="py-2 pr-3">Rekening</th>
                            <th class="py-2 pr-3">Nasabah</th>
                            <th class="py-2 pr-3">AO</th>
                            <th class="py-2 pr-3">Kolek</th>
                            <th class="py-2 pr-3">DPD</th>
                            <th class="py-2 pr-3">Restruk</th>
                            <th class="py-2 pr-3">Reason</th>
                            <th class="py-2">OS</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">
                        @forelse($top10RestrukRisk as $i => $r)
                            @php
                                $kolek = (int)$r['kolek'];
                                $dpd   = (int)$r['dpd'];
                                $rs    = (bool)$r['is_restructured'];
                                $reason= $r['reason'] ?? 'MONITOR';

                                $badge = function($txt, $tone='slate') {
                                    $map = [
                                        'red' => 'bg-rose-50 text-rose-700 border-rose-200',
                                        'amber' => 'bg-amber-50 text-amber-800 border-amber-200',
                                        'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'slate' => 'bg-slate-50 text-slate-700 border-slate-200',
                                        'indigo' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                        'purple' => 'bg-purple-50 text-purple-700 border-purple-200',
                                    ];
                                    $cls = $map[$tone] ?? $map['slate'];
                                    return "<span class=\"inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {$cls}\">{$txt}</span>";
                                };

                                $kolekTone = $kolek >= 3 ? 'red' : ($kolek == 2 ? 'amber' : 'emerald');
                                $dpdTone   = $dpd >= 60 ? 'red' : ($dpd >= 30 ? 'amber' : ($dpd > 15 ? 'purple' : 'slate'));
                                $rsTone    = $rs ? 'indigo' : 'slate';

                                $reasonTone = match($reason) {
                                    'KOLEK>=3' => 'red',
                                    'DPD>15'   => 'purple',
                                    'RESTRUK'  => 'indigo',
                                    'KOLEK=2'  => 'amber',
                                    default    => 'slate',
                                };
                            @endphp

                            <tr>
                                <td class="py-2 pr-3 text-slate-500">{{ $i + 1 }}</td>
                                <td class="py-2 pr-3 font-mono text-slate-800">{{ $r['account_no'] }}</td>
                                <td class="py-2 pr-3 text-slate-900 font-semibold">{{ $r['customer_name'] }}</td>
                                <td class="py-2 pr-3 text-slate-700">{{ $r['ao'] }}</td>

                                <td class="py-2 pr-3">{!! $badge('K'.$kolek, $kolekTone) !!}</td>
                                <td class="py-2 pr-3">{!! $badge((string)$dpd, $dpdTone) !!}</td>

                                <td class="py-2 pr-3">
                                    @if($rs)
                                        {!! $badge('RS ('.$r['restructure_freq'].')', $rsTone) !!}
                                    @else
                                        {!! $badge('No', 'slate') !!}
                                    @endif
                                </td>

                                <td class="py-2 pr-3">{!! $badge($reason, $reasonTone) !!}</td>
                                <td class="py-2 text-slate-900 font-semibold">{{ $fmt($r['os']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-4 text-center text-sm text-slate-500">
                                    Tidak ada data untuk ditampilkan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection
