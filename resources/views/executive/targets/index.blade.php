@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    {{-- Header + Filter --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">
        <div class="grid grid-cols-12 gap-6 items-start">
            <div class="col-span-12 lg:col-span-5">
                <div class="text-xs font-bold tracking-wider text-slate-400 uppercase">Executive</div>
                <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold text-slate-900 leading-tight">
                    Monitoring Target KKR - NPL
                </h1>
                <p class="mt-3 text-sm sm:text-base text-slate-600 leading-relaxed">
                    Fokus pada issue target penyelesaian: belum ada target, target tanpa tindakan, dan target overdue.
                </p>
            </div>

            <div class="col-span-12 lg:col-span-7">
                <form method="GET" action="{{ url('/executive/targets') }}">
                    <div class="grid grid-cols-12 gap-3 items-end">

                        {{-- Issue --}}
                        @php
                            $issueVal = request('issue','all');
                            $issueOptions = [
                                'all' => 'Semua Issue',
                                'NO_TARGET' => 'Belum Ada Target',
                                'NO_ACTION' => 'Target Tanpa Tindakan',
                                'OVERDUE' => 'Target Overdue',
                                'OK' => 'OK',
                            ];
                        @endphp

                        <div class="col-span-12 sm:col-span-5">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Issue</label>
                            <select name="issue" class="w-full rounded-xl border-slate-200">
                                @foreach($issueOptions as $k => $lbl)
                                    <option value="{{ $k }}" @selected($issueVal == $k)>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Keyword --}}
                        <div class="col-span-12 sm:col-span-7">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Kata kunci</label>
                            <input name="q" value="{{ request('q') }}"
                                   class="w-full rounded-xl border-slate-200"
                                   placeholder="Debitur / CIF / No Rek / PIC">
                        </div>

                        {{-- Buttons --}}
                        <div class="col-span-12 flex gap-3 justify-end pt-1">
                            <button type="submit"
                                    class="rounded-xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white">
                                Terapkan
                            </button>

                            <a href="{{ url('/executive/targets') }}"
                               class="rounded-xl border border-slate-200 px-6 py-3 text-sm font-semibold text-slate-700">
                                Reset
                            </a>
                        </div>

                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- KPI Cards --}}
    @php
        $kpiCard  = "rounded-2xl border border-slate-200 bg-white shadow-sm p-5";
        $kpiTitle = "text-xs font-bold tracking-wider text-slate-400 uppercase";
        $kpiValue = "mt-3 text-4xl font-extrabold text-slate-900 leading-none";
        $kpiDesc  = "mt-3 text-sm text-slate-600 leading-relaxed";
        $pillBase = "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border";
    @endphp

    <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="{{ $kpiCard }}">
            <div class="flex items-start justify-between gap-2">
                <div class="{{ $kpiTitle }}">Belum Ada Target</div>
                <span class="{{ $pillBase }} bg-slate-50 text-slate-700 border-slate-200">NO_TARGET</span>
            </div>
            <div class="{{ $kpiValue }}">{{ number_format($noTarget ?? 0) }}</div>
            <div class="{{ $kpiDesc }}">Kasus NPL aktif yang belum memiliki target aktif.</div>
        </div>

        <div class="{{ $kpiCard }}">
            <div class="flex items-start justify-between gap-2">
                <div class="{{ $kpiTitle }}">Target Tanpa Tindakan</div>
                <span class="{{ $pillBase }} bg-amber-50 text-amber-700 border-amber-200">NO_ACTION</span>
            </div>
            <div class="{{ $kpiValue }}">{{ number_format($noAction ?? 0) }}</div>
            <div class="{{ $kpiDesc }}">Target aktif sudah dibuat, tetapi belum ada tindakan setelah target aktif.</div>
        </div>

        <div class="{{ $kpiCard }}">
            <div class="flex items-start justify-between gap-2">
                <div class="{{ $kpiTitle }}">Target Overdue</div>
                <span class="{{ $pillBase }} bg-rose-50 text-rose-700 border-rose-200">OVERDUE</span>
            </div>
            <div class="{{ $kpiValue }}">{{ number_format($overdue ?? 0) }}</div>
            <div class="{{ $kpiDesc }}">Target aktif lewat tanggal target dan status belum selesai.</div>
        </div>
    </div>

    {{-- List --}}
    <div class="mt-5 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">

        {{-- Header --}}
        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-slate-100 bg-white">
            <div>
                <div class="text-lg font-bold text-slate-900">Daftar Issue Target</div>
                <div class="text-sm text-slate-500">Klik detail untuk melihat target & timeline penanganan.</div>
            </div>
            <div class="text-sm font-medium text-slate-500">
                {{ $rows->total() }} data
            </div>
        </div>

        {{-- MOBILE: Card List --}}
        <div class="md:hidden divide-y divide-slate-100">
            @forelse($rows as $r)
                @php
                    $issue = strtoupper((string)($r->issue ?? 'OK'));

                    $issueBadge = match($issue) {
                        'NO_TARGET' => 'bg-slate-50 text-slate-700 border-slate-200',
                        'NO_ACTION' => 'bg-amber-50 text-amber-700 border-amber-200',
                        'OVERDUE'   => 'bg-rose-50 text-rose-700 border-rose-200',
                        default     => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    };

                    $pic = $r->pic_name ?? '-';

                    $targetDate = $r->target_date ? \Carbon\Carbon::parse($r->target_date)->format('d M Y') : '-';
                    $lastAction = $r->last_action_at ? \Carbon\Carbon::parse($r->last_action_at)->format('d M Y') : '-';

                    // anchor target: pakai target_anchor_at dari controller (bukan activated_at)
                    $anchor = $r->target_anchor_at ?? null;

                    $overdueDays = $r->overdue_days ?? null;

                    $noRek = $r->no_rek ?? '-';
                    $cif   = $r->cif ?? '-';
                @endphp

                <div class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ url('/executive/targets/'.$r->id) }}"
                               class="text-base font-extrabold text-sky-600 hover:text-sky-700 hover:underline break-words">
                                {{ $r->debtor_name ?? '-' }}
                            </a>
                            <div class="mt-1 text-xs text-slate-500">
                                CIF: <span class="font-semibold text-slate-700">{{ $cif }}</span>
                                <span class="mx-1">•</span>
                                Rek: <span class="font-semibold text-slate-700">{{ $noRek }}</span>
                            </div>
                        </div>

                        <span class="shrink-0 inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-bold {{ $issueBadge }}">
                            {{ $issue }}
                        </span>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3 text-xs">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-slate-500">PIC</div>
                            <div class="mt-0.5 font-semibold text-slate-800 break-words">{{ $pic }}</div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-slate-500">Target</div>
                            <div class="mt-0.5 font-semibold text-slate-800">{{ $targetDate }}</div>
                            @if($overdueDays !== null)
                                <div class="mt-1 text-[11px] font-bold text-rose-700">
                                    Overdue {{ (int)$overdueDays }} hari
                                </div>
                            @endif
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-slate-500">Last Action</div>
                            <div class="mt-0.5 font-semibold text-slate-800">{{ $lastAction }}</div>
                            @if($anchor && $r->last_action_at)
                                @php
                                    try {
                                        $lagDays = \Carbon\Carbon::parse($anchor)->diffInDays(\Carbon\Carbon::parse($r->last_action_at), false);
                                    } catch (\Throwable $e) { $lagDays = null; }
                                @endphp
                                @if($lagDays !== null)
                                    <div class="mt-1 text-[11px] text-slate-600">
                                        Selisih: <span class="font-semibold">{{ $lagDays }}</span> hari (sejak target aktif)
                                    </div>
                                @endif
                            @endif
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-white p-3 flex items-center justify-between">
                            <div class="text-slate-500">Aksi</div>
                            <a href="{{ url('/executive/targets/'.$r->id) }}"
                               class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                Detail →
                            </a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-6 py-12 text-center text-slate-500">
                    Tidak ada data untuk filter saat ini.
                </div>
            @endforelse
        </div>

        {{-- DESKTOP: Table --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 border-b border-slate-100">
                    <tr>
                        <th class="px-6 py-3 text-left">Debitur</th>
                        <th class="px-6 py-3 text-left">CIF</th>
                        <th class="px-6 py-3 text-left">PIC</th>
                        <th class="px-6 py-3 text-left">Target</th>
                        <th class="px-6 py-3 text-left">Issue</th>
                        <th class="px-6 py-3 text-left">Last Action</th>
                        <th class="px-6 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($rows as $r)
                        @php
                            $issue = strtoupper((string)($r->issue ?? 'OK'));
                            $issueBadge = match($issue) {
                                'NO_TARGET' => 'bg-slate-50 text-slate-700 border-slate-200',
                                'NO_ACTION' => 'bg-amber-50 text-amber-700 border-amber-200',
                                'OVERDUE'   => 'bg-rose-50 text-rose-700 border-rose-200',
                                default     => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            };
                            $pic = $r->pic_name ?? '-';
                            $targetDate = $r->target_date ? \Carbon\Carbon::parse($r->target_date)->format('d/m/Y') : '-';
                            $lastAction = $r->last_action_at ? \Carbon\Carbon::parse($r->last_action_at)->format('d/m/Y') : '-';
                            $overdueDays = $r->overdue_days ?? null;
                        @endphp

                        <tr class="hover:bg-slate-50">
                            <td class="px-6 py-3 align-top">
                                <a href="{{ url('/executive/targets/'.$r->id) }}"
                                   class="font-extrabold text-sky-600 hover:text-sky-700 hover:underline">
                                    {{ $r->debtor_name ?? '-' }}
                                </a>
                                <div class="text-xs text-slate-500 mt-0.5">
                                    Rek: {{ $r->no_rek ?? '-' }}
                                </div>
                            </td>

                            <td class="px-6 py-3 align-top text-slate-700">{{ $r->cif ?? '-' }}</td>

                            <td class="px-6 py-3 align-top">
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                    {{ $pic }}
                                </span>
                            </td>

                            <td class="px-6 py-3 align-top text-slate-700">
                                <div class="font-semibold">{{ $targetDate }}</div>
                                @if($overdueDays !== null)
                                    <div class="text-xs font-bold text-rose-700 mt-0.5">
                                        Overdue {{ (int)$overdueDays }} hari
                                    </div>
                                @endif
                            </td>

                            <td class="px-6 py-3 align-top">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-bold {{ $issueBadge }}">
                                    {{ $issue }}
                                </span>
                            </td>

                            <td class="px-6 py-3 align-top text-slate-700">
                                <div class="font-semibold">{{ $lastAction }}</div>
                            </td>

                            <td class="px-6 py-3 align-top text-right">
                                <a href="{{ url('/executive/targets/'.$r->id) }}"
                                   class="inline-flex items-center rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Detail →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-slate-500">
                                Tidak ada data untuk filter saat ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Footer --}}
        <div class="px-6 py-4 border-t border-slate-100 bg-white">
            {{ $rows->links() }}
        </div>
    </div>

</div>
@endsection
