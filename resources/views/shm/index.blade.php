@extends('layouts.app')

@section('content')
@php
    $isSad = $isSad ?? (auth()->user()?->can('sadAction', \App\Models\ShmCheckRequest::class) ?? false);

    // status dipilih mengikuti controller ($status). fallback ALL jika tidak ada.
    $statusSelected = old('status', $status ?? 'ALL');

    // Quick chips untuk SAD (paling sering dipakai)
    $quickChips = [
        'ALL' => 'ALL',
        \App\Models\ShmCheckRequest::STATUS_SUBMITTED => 'Submitted',
        \App\Models\ShmCheckRequest::STATUS_SENT_TO_NOTARY => 'Ke Notaris',
        \App\Models\ShmCheckRequest::STATUS_SENT_TO_BPN => 'Ke BPN',
    ];

    // tampilkan info default SAD hanya jika belum ada query status
    $showSadDefaultHint = $isSad && !request()->filled('status');

    $counts = $counts ?? [
        'ALL' => 0,
        \App\Models\ShmCheckRequest::STATUS_SUBMITTED => 0,
        \App\Models\ShmCheckRequest::STATUS_SENT_TO_NOTARY => 0,
        \App\Models\ShmCheckRequest::STATUS_SENT_TO_BPN => 0,
    ];
@endphp

<div class="mx-auto max-w-6xl px-4 py-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-bold text-slate-900">📄 Pengajuan Cek SHM</h1>
            <p class="mt-1 text-sm text-slate-500">Daftar pengajuan cek sertifikat (SHM) yang pernah dibuat.</p>
        </div>

        @can('create', \App\Models\ShmCheckRequest::class)
            <a href="{{ route('shm.create') }}"
               class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                + Buat Pengajuan
            </a>
        @endcan
    </div>

    {{-- FILTER --}}
    <div class="mt-5 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
        <form method="GET" class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-full sm:max-w-sm">
                <label class="text-xs font-semibold text-slate-600">Filter Status</label>

                <select name="status"
                        class="mt-1 mb-4 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-slate-400 focus:outline-none">
                    @foreach($statusOptions as $opt)
                        <option value="{{ $opt }}" @selected($statusSelected === $opt)>{{ $opt }}</option>
                    @endforeach
                </select>

                {{-- CHIPS --}}
                @if($isSad)
                    <div class="mt-3 mb-4">
                        <div class="text-xs font-semibold text-slate-600">Quick Filter</div>

                        <div class="relative mt-2">
                            {{-- fade hint kanan (mobile saja) - jangan nutup area klik --}}
                            <div class="pointer-events-none absolute right-0 top-0 h-full w-12 bg-gradient-to-l from-white via-white/80 to-white/0 md:hidden"></div>

                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($quickChips as $chipStatus => $label)
                                    @php
                                        $params = request()->except(['page', 'status']);
                                        $params['status'] = $chipStatus;

                                        $active = ($statusSelected === $chipStatus);

                                        $c = (int)($counts[$chipStatus] ?? 0);
                                        $hasQueue = $c > 0;

                                        $chipClass = $active
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : ($hasQueue
                                                ? 'border-amber-400 bg-amber-50 text-amber-800 hover:bg-amber-100'
                                                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                                            );

                                        $badgeClass = $active
                                            ? 'bg-white/20 text-white'
                                            : ($hasQueue
                                                ? 'bg-amber-200 text-amber-900'
                                                : 'bg-slate-100 text-slate-700'
                                            );
                                    @endphp

                                    <a href="{{ route('shm.index', $params) }}"
                                        class="inline-flex min-w-[120px] items-center justify-between gap-2 rounded-full border px-3 py-2 text-xs font-semibold {{ $chipClass }}">
                                        <span class="max-w-[90px] truncate">{{ $label }}</span>

                                        <span class="inline-flex min-w-[1.5rem] items-center justify-center rounded-full px-2 py-0.5 text-[11px] font-bold {{ $badgeClass }}">
                                            {{ $c }}
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </div>

                        @if($showSadDefaultHint)
                            <div class="mt-1 text-xs text-slate-500">
                                Default SAD:
                                <span class="font-semibold">{{ \App\Models\ShmCheckRequest::STATUS_SUBMITTED }}</span>
                                (kalau belum pilih filter).
                            </div>
                        @endif
                    </div>
                @endif
                <div class="flex gap-2 sm:justify-end">
                    <button type="submit"
                            class="w-1/2 sm:w-auto rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Terapkan
                    </button>

                    <a href="{{ route('shm.index') }}"
                    class="w-1/2 sm:w-auto rounded-xl border border-slate-200 bg-white px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    {{-- =========================
        MOBILE: CARD LIST
    ========================= --}}
    <div class="mt-4 space-y-3 md:hidden">
        @forelse($rows as $row)
            <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-sm font-bold text-slate-900 truncate">{{ $row->request_no }}</div>
                        <div class="mt-1 text-sm font-semibold text-slate-900 truncate">{{ $row->debtor_name }}</div>
                        <div class="mt-1 text-xs text-slate-500">
                            Pemohon:
                            <span class="font-semibold text-slate-700">{{ optional($row->requester)->name ?? '-' }}</span>
                        </div>
                    </div>

                    <span class="shrink-0 inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                        {{ $row->status }}
                    </span>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                        <div class="text-slate-500">No Sertifikat</div>
                        <div class="font-semibold text-slate-800 truncate">{{ $row->certificate_no ?? '-' }}</div>
                    </div>

                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                        <div class="text-slate-500">Notaris</div>
                        <div class="font-semibold text-slate-800 truncate">{{ $row->notary_name ?? '-' }}</div>
                    </div>

                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                        <div class="text-slate-500">No HP</div>
                        <div class="font-semibold text-slate-800 truncate">{{ $row->debtor_phone ?? '-' }}</div>
                    </div>

                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                        <div class="text-slate-500">Dibuat</div>
                        <div class="font-semibold text-slate-800">
                            {{ optional($row->created_at)->format('d M Y H:i') }}
                        </div>
                    </div>
                </div>

                <div class="mt-3 flex items-center justify-end">
                    <a href="{{ route('shm.show', $row) }}"
                       class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Detail
                    </a>
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-slate-100 bg-white p-6 text-center text-sm text-slate-500">
                Belum ada data pengajuan.
            </div>
        @endforelse

        <div class="rounded-2xl border border-slate-100 bg-white px-4 py-3 shadow-sm">
            {{ $rows->links() }}
        </div>
    </div>

    {{-- =========================
        DESKTOP: TABLE
    ========================= --}}
    <div class="mt-4 hidden overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm md:block">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-slate-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <th class="px-4 py-3">No</th>
                        <th class="px-4 py-3">Debitur</th>
                        <th class="px-4 py-3">No Sertifikat</th>
                        <th class="px-4 py-3">Notaris</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Dibuat</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @forelse($rows as $row)
                        <tr class="text-sm text-slate-700 hover:bg-slate-50">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-semibold text-slate-900">{{ $row->request_no }}</div>
                                <div class="text-xs text-slate-500">{{ optional($row->requester)->name ?? '-' }}</div>
                            </td>

                            <td class="px-4 py-3">
                                <div class="font-semibold text-slate-900">{{ $row->debtor_name }}</div>
                                <div class="text-xs text-slate-500">{{ $row->debtor_phone ?? '-' }}</div>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap">{{ $row->certificate_no ?? '-' }}</td>
                            <td class="px-4 py-3">{{ $row->notary_name ?? '-' }}</td>

                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                    {{ $row->status }}
                                </span>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap text-xs text-slate-600">
                                {{ optional($row->created_at)->format('d M Y H:i') }}
                            </td>

                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('shm.show', $row) }}"
                                   class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                    Detail
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">
                                Belum ada data pengajuan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-100 px-4 py-3">
            {{ $rows->links() }}
        </div>
    </div>
</div>
@endsection
