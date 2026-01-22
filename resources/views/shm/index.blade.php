@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-bold text-slate-900">📄 Pengajuan Cek SHM</h1>
            <p class="mt-1 text-sm text-slate-500">
                Daftar pengajuan cek sertifikat (SHM) yang pernah dibuat.
            </p>
        </div>

        @can('create', \App\Models\ShmCheckRequest::class)
            <a href="{{ route('shm.create') }}"
               class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                + Buat Pengajuan
            </a>
        @endcan
    </div>

    {{-- Filter --}}
    <div class="mt-5 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
        <form method="GET" class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-full sm:max-w-sm">
                <label class="text-xs font-semibold text-slate-600">Filter Status</label>
                <select name="status"
                        class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-slate-400 focus:outline-none">
                    @foreach($statusOptions as $opt)
                        <option value="{{ $opt }}" @selected(request('status', 'ALL') === $opt)>
                            {{ $opt }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex gap-2">
                <button type="submit"
                        class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Terapkan
                </button>

                <a href="{{ route('shm.index') }}"
                   class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Reset
                </a>
            </div>
        </form>
    </div>

    {{-- Table --}}
    <div class="mt-4 overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
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
                        <tr class="text-sm text-slate-700">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-semibold text-slate-900">{{ $row->request_no }}</div>
                                <div class="text-xs text-slate-500">
                                    {{ optional($row->requester)->name ?? '-' }}
                                </div>
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
