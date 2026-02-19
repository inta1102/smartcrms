@extends('layouts.app')

@section('content')
@php
    /**
     * ✅ SAFETY NET:
     * Kadang controller mengirim variabel $rows (versi lama) bukan $assignments.
     * Biar view ini tetap aman, kita normalize ke $assignments.
     */
    $assignments = $assignments ?? $rows ?? null;
@endphp

<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-lg font-bold text-slate-900">Org Assignments</h1>
            <p class="text-sm text-slate-500">
                Master struktur organisasi (Staff/AO → TL → KSL → Kabag).
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('supervision.org.assignments.create') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                <span>＋</span> Tambah Assignment
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="mt-5 rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm text-slate-600">
                    Total:
                    <span class="font-semibold text-slate-900">
                        {{ $assignments?->total() ?? 0 }}
                    </span>
                </div>

                <div class="text-xs text-slate-500">
                    Urutan: Aktif → Role (KABAG, KSL, TL) → Staff
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-left text-slate-600">
                        <th class="px-5 py-3 font-semibold">Staff</th>
                        <th class="px-5 py-3 font-semibold">Atasan</th>
                        <th class="px-5 py-3 font-semibold">Role Atasan</th>
                        <th class="px-5 py-3 font-semibold">Unit</th>
                        <th class="px-5 py-3 font-semibold">Effective</th>
                        <th class="px-5 py-3 font-semibold">Status</th>
                        <th class="px-5 py-3 font-semibold text-right">Aksi</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @forelse ($assignments ?? [] as $a)
                        @php
                            $badge = $a->is_active
                                ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                : 'bg-slate-50 text-slate-700 border-slate-200';

                            $roleBadge = match(strtoupper((string)$a->leader_role)) {
                                'KABAG' => 'bg-slate-900 text-white border-slate-900',
                                'KSLU'   => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                'TL'    => 'bg-sky-50 text-sky-700 border-sky-200',
                                default => 'bg-slate-50 text-slate-700 border-slate-200',
                            };
                        @endphp

                        <tr>
                            <td class="px-5 py-3">
                                <div class="font-semibold text-slate-900">{{ $a->staff?->name ?? '-' }}</div>
                                <div class="text-xs text-slate-500">ID: {{ $a->user_id }}</div>
                            </td>

                            <td class="px-5 py-3">
                                <div class="font-semibold text-slate-900">{{ $a->leader?->name ?? '-' }}</div>
                                <div class="text-xs text-slate-500">ID: {{ $a->leader_id }}</div>
                            </td>

                            <td class="px-5 py-3">
                                <span class="inline-flex items-center rounded-full border px-2 py-1 text-xs font-semibold {{ $roleBadge }}">
                                    {{ strtoupper($a->leader_role ?? '-') }}
                                </span>
                            </td>

                            <td class="px-5 py-3">
                                <span class="text-slate-700">{{ $a->unit_code ?? '-' }}</span>
                            </td>

                            <td class="px-5 py-3">
                                <div class="text-slate-700">
                                    {{ $a->effective_from ? \Carbon\Carbon::parse($a->effective_from)->format('d/m/Y') : '-' }}
                                    —
                                    {{ $a->effective_to ? \Carbon\Carbon::parse($a->effective_to)->format('d/m/Y') : '...' }}
                                </div>
                            </td>

                            <td class="px-5 py-3">
                                <span class="inline-flex items-center rounded-full border px-2 py-1 text-xs font-semibold {{ $badge }}">
                                    {{ $a->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>

                            <td class="px-5 py-3 text-right">
                                <div class="inline-flex items-center gap-2">
                                    <a href="{{ route('supervision.org.assignments.edit', ['assignment' => $a->id]) }}"
                                       class="inline-flex items-center rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                        Edit
                                    </a>

                                    @if($a->is_active)
                                        <form action="{{ route('supervision.org.assignments.end', ['assignment' => $a->id]) }}"
                                              method="POST"
                                              onsubmit="return confirm('Yakin akhiri assignment ini?');">
                                            @csrf
                                            <button type="submit"
                                                    class="inline-flex items-center rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-50">
                                                Akhiri
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-slate-500">
                                Belum ada data Org Assignment.
                                <div class="mt-2">
                                    <a href="{{ route('supervision.org.assignments.create') }}"
                                       class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700">
                                        Tambah data pertama
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-100 px-5 py-4">
            @if ($assignments && method_exists($assignments, 'links'))
                {{ $assignments->links() }}
            @endif
        </div>
    </div>
</div>
@endsection
