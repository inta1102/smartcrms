@extends('layouts.app')

@section('content')
@php
    use App\Models\AoAgenda;

    $pill = function(string $status) {
        $s = strtolower($status);
        return match($s) {
            'overdue'     => 'bg-rose-50 text-rose-700 border-rose-200',
            'in_progress' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'planned'     => 'bg-slate-50 text-slate-700 border-slate-200',
            'done'        => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'cancelled'   => 'bg-amber-50 text-amber-800 border-amber-200',
            default       => 'bg-slate-50 text-slate-700 border-slate-200',
        };
    };

    $fmtDT = fn($dt) => $dt ? $dt->format('d-m-Y H:i') : '-';
@endphp

<div class="mx-auto w-full max-w-6xl space-y-5 px-4 py-6">

    <div class="rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-lg font-bold text-slate-900">Agenda Kerja AO</h1>
                <p class="text-sm text-slate-500">Tombol aksi untuk Mulai / Selesai / Reschedule / Batalkan.</p>
            </div>

            <div class="text-xs text-slate-500">
                Total tampil: <span class="font-semibold text-slate-800">{{ $agendas->total() }}</span>
            </div>
        </div>

{{-- FILTER --}}
<form method="GET" action="{{ route('ao-agendas.index') }}" class="mt-4">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

        {{-- MOBILE (<= sm): STACK 1 kolom, tombol full --}}
        <div class="grid grid-cols-1 gap-3 sm:hidden">

            <div>
                <label class="block text-xs font-semibold text-slate-600">Status</label>
                <select name="status"
                        class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                    <option value="">(default: open)</option>
                    @foreach(['planned','in_progress','overdue','done','cancelled'] as $st)
                        <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>
                            {{ strtoupper($st) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600">Agenda Type</label>
                <input type="text" name="agenda_type" value="{{ $filters['agenda_type'] ?? '' }}"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                       placeholder="misal: wa / visit / evaluation">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600">Case ID</label>
                <input type="number" name="case_id" value="{{ $filters['case_id'] ?? '' }}"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                       placeholder="contoh: 1025">
            </div>

            <div class="grid grid-cols-2 gap-2 pt-1">
                <button type="submit"
                        class="w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Terapkan
                </button>
                <a href="{{ route('ao-agendas.index') }}"
                   class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Reset
                </a>
            </div>
        </div>

        {{-- DESKTOP (>= sm): GRID 4 kolom --}}
        <div class="hidden sm:grid sm:grid-cols-4 sm:gap-3 sm:items-end">

            <div>
                <label class="block text-xs font-semibold text-slate-600">Status</label>
                <select name="status"
                        class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                    <option value="">(default: open)</option>
                    @foreach(['planned','in_progress','overdue','done','cancelled'] as $st)
                        <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>
                            {{ strtoupper($st) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600">Agenda Type</label>
                <input type="text" name="agenda_type" value="{{ $filters['agenda_type'] ?? '' }}"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                       placeholder="misal: wa / visit / evaluation">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600">Case ID</label>
                <input type="number" name="case_id" value="{{ $filters['case_id'] ?? '' }}"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                       placeholder="contoh: 1025">
            </div>

            <div class="flex gap-2 justify-end">
                <button type="submit"
                        class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Terapkan
                </button>
                <a href="{{ route('ao-agendas.index') }}"
                   class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Reset
                </a>
            </div>
        </div>

    </div>
</form>

        @if(session('success'))
            <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if($errors->any())
            <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800 text-sm">
                <ul class="list-disc pl-5">
                    @foreach($errors->all() as $e) <li>{{ $e }}</li> @endforeach
                </ul>
            </div>
        @endif
    </div>

    {{-- LIST --}}
    <div class="rounded-2xl border border-slate-100 bg-white shadow-sm overflow-hidden">
        <div class="hidden md:block">
            <div class="overflow-x-auto">
                <table class="min-w-[980px] w-full text-xs">
                    <thead class="border-b border-slate-100 bg-slate-50">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600 w-[120px]">Status</th>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600 w-[170px]">Due</th>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600">Agenda</th>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600 w-[220px]">Kasus</th>
                            <th class="px-3 py-2 text-left font-semibold text-slate-600 w-[260px]">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($agendas as $a)
                            @php
                                $status = strtolower($a->status ?? 'planned');
                                $case = $a->case;
                                $loan = $case?->loanAccount;

                                $dueText = $fmtDT($a->due_at);
                                $isOverdue = $status === 'overdue';

                                $title = $a->title ?? ($a->agenda_type ? strtoupper($a->agenda_type) : 'AGENDA');
                                $sub = $a->agenda_type ? strtoupper($a->agenda_type) : '-';

                                $caseLabel = $loan
                                    ? (($loan->account_no ?? '-') . ' — ' . ($loan->customer_name ?? '-'))
                                    : ('Case #' . ($a->npl_case_id ?? '-'));
                            @endphp

                            <tr class="border-b border-slate-100 last:border-b-0 align-top">
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-bold {{ $pill($status) }}">
                                        {{ strtoupper($status) }}
                                    </span>
                                    @if($a->evidence_required)
                                        <div class="mt-2 inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-800">
                                            Evidence wajib
                                        </div>
                                    @endif
                                </td>

                                <td class="px-3 py-2 text-slate-700">
                                    <div class="{{ $isOverdue ? 'font-extrabold text-rose-700' : 'font-semibold text-slate-800' }}">
                                        {{ $dueText }}
                                    </div>
                                    @if($a->planned_at)
                                        <div class="mt-1 text-[11px] text-slate-500">
                                            Planned: {{ $fmtDT($a->planned_at) }}
                                        </div>
                                    @endif
                                </td>

                                <td class="px-3 py-2 text-slate-700">
                                    <div class="font-semibold text-slate-900">{{ $title }}</div>
                                    <div class="mt-1 text-[11px] text-slate-500">
                                        Type: <span class="font-mono">{{ $sub }}</span>
                                    </div>
                                    @if($a->notes)
                                        <div class="mt-2 text-[11px] text-slate-600 line-clamp-2">
                                            {{ $a->notes }}
                                        </div>
                                    @endif
                                </td>

                                <td class="px-3 py-2 text-slate-700">
                                    <div class="font-semibold text-slate-900">{{ $caseLabel }}</div>
                                    @if($loan)
                                        <div class="mt-1 text-[11px] text-slate-500">
                                            CIF: <span class="font-mono">{{ $loan->cif ?? '-' }}</span> • AO: {{ $loan->ao_name ?? '-' }}
                                        </div>
                                    @endif
                                </td>

                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        {{-- EDIT --}}
                                        @can('update', $a)
                                            <a href="{{ route('ao-agendas.edit', $a) }}"
                                                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"
                                                title="Edit agenda">
                                                    ✏️ <span>Edit</span>
                                            </a>
                                        @endcan

                                        {{-- START --}}
                                        @can('start', $a)
                                            @if(in_array($a->status, ['planned','overdue'], true))
                                                <form method="POST" action="{{ route('ao-agendas.start', $a) }}" class="inline">
                                                    @csrf
                                                    <button type="submit"
                                                        class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5
                                                            text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                                        title="Mulai & catat tindakan">
                                                        ▶ <span>Mulai</span>
                                                    </button>
                                                </form>
                                            @endif
                                        @endcan

                                        {{-- COMPLETE (modal) --}}
                                        @php
                                            $hasLog = \App\Models\CaseAction::where('ao_agenda_id', $a->id)->exists();
                                        @endphp

                                        <form method="POST" action="{{ route('ao-agendas.complete', $a) }}">
                                            @csrf
                                            <button type="submit"
                                                class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-semibold
                                                        {{ $hasLog ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100' }}">
                                                ✅ Selesai
                                            </button>
                                        </form>

                                        {{-- RESCHEDULE (modal) --}}
                                        @can('reschedule', $a)
                                            @if($status !== 'done')
                                                <button type="button"
                                                    onclick="document.getElementById('resch-{{ $a->id }}').showModal()"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-indigo-200 px-2.5 py-1.5 text-[11px] font-semibold text-indigo-700 hover:bg-indigo-50"
                                                    title="Reschedule agenda">
                                                    🗓️ <span>Reschedule</span>
                                                </button>
                                            @endif
                                        @endcan

                                        {{-- CANCEL (modal) --}}
                                        @can('cancel', $a)
                                            @if($status !== 'done')
                                                <button type="button"
                                                    onclick="document.getElementById('cancel-{{ $a->id }}').showModal()"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1.5 text-[11px] font-semibold text-rose-700 hover:bg-rose-50"
                                                    title="Batalkan agenda">
                                                    ✖ <span>Batalkan</span>
                                                </button>
                                            @endif
                                        @endcan
                                    </div>

                                    {{-- MODALS (desktop) --}}
                                    @if(!in_array($status, ['done','cancelled'], true))
                                        <dialog id="complete-{{ $a->id }}" class="rounded-2xl p-0 shadow-xl backdrop:bg-black/30">
                                            <form method="POST" action="{{ route('ao-agendas.complete', $a) }}" class="w-[min(560px,92vw)] bg-white p-5">
                                                @csrf
                                                <div class="flex items-start justify-between gap-3">
                                                    <div>
                                                        <div class="text-lg font-bold text-slate-900">Selesaikan Agenda</div>
                                                        <div class="text-sm text-slate-500">{{ $title }}</div>
                                                    </div>
                                                    <button type="button" onclick="document.getElementById('complete-{{ $a->id }}').close()"
                                                            class="rounded-lg border px-2 py-1 text-sm">Tutup</button>
                                                </div>

                                                <div class="mt-4 space-y-3">
                                                    <div>
                                                        <label class="text-xs font-semibold text-slate-600">Result Summary</label>
                                                        <textarea name="result_summary" rows="2" class="mt-1 w-full rounded-xl border-slate-200"></textarea>
                                                    </div>
                                                    <div>
                                                        <label class="text-xs font-semibold text-slate-600">Result Detail</label>
                                                        <textarea name="result_detail" rows="4" class="mt-1 w-full rounded-xl border-slate-200"></textarea>
                                                    </div>

                                                    @if($a->evidence_required)
                                                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800 text-sm">
                                                            Evidence wajib. Upload file/isi evidence notes lewat halaman <b>Edit</b> sebelum konfirmasi selesai.
                                                        </div>
                                                    @endif
                                                </div>

                                                <div class="mt-5 flex items-center justify-end gap-2">
                                                    <button type="button" onclick="document.getElementById('complete-{{ $a->id }}').close()"
                                                            class="rounded-xl border px-4 py-2 text-sm">Batal</button>
                                                    <button type="submit"
                                                            class="rounded-xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold hover:bg-emerald-700">
                                                        Konfirmasi
                                                    </button>
                                                </div>
                                            </form>
                                        </dialog>
                                    @endif

                                    @if($status !== 'done')
                                        <dialog id="resch-{{ $a->id }}" class="rounded-2xl p-0 shadow-xl backdrop:bg-black/30">
                                            <form method="POST" action="{{ route('ao-agendas.reschedule', $a) }}" class="w-[min(560px,92vw)] bg-white p-5">
                                                @csrf
                                                <div class="flex items-start justify-between gap-3">
                                                    <div>
                                                        <div class="text-lg font-bold text-slate-900">Reschedule Agenda</div>
                                                        <div class="text-sm text-slate-500">{{ $title }}</div>
                                                    </div>
                                                    <button type="button" onclick="document.getElementById('resch-{{ $a->id }}').close()"
                                                            class="rounded-lg border px-2 py-1 text-sm">Tutup</button>
                                                </div>

                                                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                    <div>
                                                        <label class="text-xs font-semibold text-slate-600">Due At Baru</label>
                                                        <input type="datetime-local" name="due_at" class="mt-1 w-full rounded-xl border-slate-200">
                                                    </div>
                                                    <div>
                                                        <label class="text-xs font-semibold text-slate-600">Alasan</label>
                                                        <input type="text" name="reason" class="mt-1 w-full rounded-xl border-slate-200" placeholder="contoh: debitur minta jadwal ulang">
                                                    </div>
                                                </div>

                                                <div class="mt-5 flex items-center justify-end gap-2">
                                                    <button type="button" onclick="document.getElementById('resch-{{ $a->id }}').close()"
                                                            class="rounded-xl border px-4 py-2 text-sm">Batal</button>
                                                    <button type="submit"
                                                            class="rounded-xl bg-indigo-600 text-white px-4 py-2 text-sm font-semibold hover:bg-indigo-700">
                                                        Simpan
                                                    </button>
                                                </div>
                                            </form>
                                        </dialog>

                                        <dialog id="cancel-{{ $a->id }}" class="rounded-2xl p-0 shadow-xl backdrop:bg-black/30">
                                            <form method="POST" action="{{ route('ao-agendas.cancel', $a) }}" class="w-[min(560px,92vw)] bg-white p-5">
                                                @csrf
                                                <div class="flex items-start justify-between gap-3">
                                                    <div>
                                                        <div class="text-lg font-bold text-slate-900">Batalkan Agenda</div>
                                                        <div class="text-sm text-slate-500">{{ $title }}</div>
                                                    </div>
                                                    <button type="button" onclick="document.getElementById('cancel-{{ $a->id }}').close()"
                                                            class="rounded-lg border px-2 py-1 text-sm">Tutup</button>
                                                </div>

                                                <div class="mt-4">
                                                    <label class="text-xs font-semibold text-slate-600">Alasan Pembatalan</label>
                                                    <input type="text" name="reason" class="mt-1 w-full rounded-xl border-slate-200" placeholder="contoh: target berubah, agenda diganti">
                                                </div>

                                                <div class="mt-5 flex items-center justify-end gap-2">
                                                    <button type="button" onclick="document.getElementById('cancel-{{ $a->id }}').close()"
                                                            class="rounded-xl border px-4 py-2 text-sm">Batal</button>
                                                    <button type="submit"
                                                            class="rounded-xl bg-rose-600 text-white px-4 py-2 text-sm font-semibold hover:bg-rose-700">
                                                        Konfirmasi
                                                    </button>
                                                </div>
                                            </form>
                                        </dialog>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">
                                    Tidak ada agenda sesuai filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- MOBILE --}}
        <div class="md:hidden p-4 space-y-3">
            @forelse($agendas as $a)
                @php
                    $status = strtolower($a->status ?? 'planned');
                    $case = $a->case;
                    $loan = $case?->loanAccount;
                    $title = $a->title ?? ($a->agenda_type ? strtoupper($a->agenda_type) : 'AGENDA');
                    $caseLabel = $loan
                        ? (($loan->account_no ?? '-') . ' — ' . ($loan->customer_name ?? '-'))
                        : ('Case #' . ($a->npl_case_id ?? '-'));
                @endphp

                <div class="rounded-2xl border border-slate-200 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-900 break-words">{{ $title }}</div>
                            <div class="mt-1 text-xs text-slate-500 break-words">{{ $caseLabel }}</div>
                            <div class="mt-2 text-xs text-slate-600">
                                Due: <span class="font-semibold">{{ $fmtDT($a->due_at) }}</span>
                            </div>
                        </div>
                        <span class="shrink-0 inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-bold {{ $pill($status) }}">
                            {{ strtoupper($status) }}
                        </span>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('ao-agendas.edit', $a) }}"
                           class="rounded-lg border px-3 py-2 text-xs font-semibold hover:bg-slate-50">
                            ✏️ Edit
                        </a>

                        @if(!in_array($status, ['done','cancelled'], true))
                            <form method="POST" action="{{ route('ao-agendas.start', $a) }}">
                                @csrf
                                <button class="rounded-lg border px-3 py-2 text-xs font-semibold hover:bg-slate-50">▶️ Mulai</button>
                            </form>

                            <button type="button"
                                onclick="document.getElementById('complete-{{ $a->id }}').showModal()"
                                class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                                ✅ Selesai
                            </button>

                            <button type="button"
                                onclick="document.getElementById('resch-{{ $a->id }}').showModal()"
                                class="rounded-lg border border-indigo-200 px-3 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-50">
                                🗓️ Reschedule
                            </button>

                            <button type="button"
                                onclick="document.getElementById('cancel-{{ $a->id }}').showModal()"
                                class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                ✖ Batalkan
                            </button>
                        @endif
                    </div>
                </div>

                {{-- reuse modals from desktop section (already rendered in loop above table)
                    but on mobile table isn't rendered; we need them here too --}}
                @if(!in_array($status, ['done','cancelled'], true))
                    <dialog id="complete-{{ $a->id }}" class="rounded-2xl p-0 shadow-xl backdrop:bg-black/30">
                        <form method="POST" action="{{ route('ao-agendas.complete', $a) }}" class="w-[min(560px,92vw)] bg-white p-5">
                            @csrf
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-lg font-bold text-slate-900">Selesaikan Agenda</div>
                                    <div class="text-sm text-slate-500">{{ $title }}</div>
                                </div>
                                <button type="button" onclick="document.getElementById('complete-{{ $a->id }}').close()"
                                        class="rounded-lg border px-2 py-1 text-sm">Tutup</button>
                            </div>

                            <div class="mt-4 space-y-3">
                                <div>
                                    <label class="text-xs font-semibold text-slate-600">Result Summary</label>
                                    <textarea name="result_summary" rows="2" class="mt-1 w-full rounded-xl border-slate-200"></textarea>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-600">Result Detail</label>
                                    <textarea name="result_detail" rows="4" class="mt-1 w-full rounded-xl border-slate-200"></textarea>
                                </div>

                                @if($a->evidence_required)
                                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800 text-sm">
                                        Evidence wajib. Upload file/isi evidence notes lewat halaman <b>Edit</b> sebelum konfirmasi selesai.
                                    </div>
                                @endif
                            </div>

                            <div class="mt-5 flex items-center justify-end gap-2">
                                <button type="button" onclick="document.getElementById('complete-{{ $a->id }}').close()"
                                        class="rounded-xl border px-4 py-2 text-sm">Batal</button>
                                <button type="submit"
                                        class="rounded-xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold hover:bg-emerald-700">
                                    Konfirmasi
                                </button>
                            </div>
                        </form>
                    </dialog>

                    <dialog id="resch-{{ $a->id }}" class="rounded-2xl p-0 shadow-xl backdrop:bg-black/30">
                        <form method="POST" action="{{ route('ao-agendas.reschedule', $a) }}" class="w-[min(560px,92vw)] bg-white p-5">
                            @csrf
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-lg font-bold text-slate-900">Reschedule Agenda</div>
                                    <div class="text-sm text-slate-500">{{ $title }}</div>
                                </div>
                                <button type="button" onclick="document.getElementById('resch-{{ $a->id }}').close()"
                                        class="rounded-lg border px-2 py-1 text-sm">Tutup</button>
                            </div>

                            <div class="mt-4 grid grid-cols-1 gap-3">
                                <div>
                                    <label class="text-xs font-semibold text-slate-600">Due At Baru</label>
                                    <input type="datetime-local" name="due_at" class="mt-1 w-full rounded-xl border-slate-200">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-600">Alasan</label>
                                    <input type="text" name="reason" class="mt-1 w-full rounded-xl border-slate-200">
                                </div>
                            </div>

                            <div class="mt-5 flex items-center justify-end gap-2">
                                <button type="button" onclick="document.getElementById('resch-{{ $a->id }}').close()"
                                        class="rounded-xl border px-4 py-2 text-sm">Batal</button>
                                <button type="submit"
                                        class="rounded-xl bg-indigo-600 text-white px-4 py-2 text-sm font-semibold hover:bg-indigo-700">
                                    Simpan
                                </button>
                            </div>
                        </form>
                    </dialog>

                    <dialog id="cancel-{{ $a->id }}" class="rounded-2xl p-0 shadow-xl backdrop:bg-black/30">
                        <form method="POST" action="{{ route('ao-agendas.cancel', $a) }}" class="w-[min(560px,92vw)] bg-white p-5">
                            @csrf
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-lg font-bold text-slate-900">Batalkan Agenda</div>
                                    <div class="text-sm text-slate-500">{{ $title }}</div>
                                </div>
                                <button type="button" onclick="document.getElementById('cancel-{{ $a->id }}').close()"
                                        class="rounded-lg border px-2 py-1 text-sm">Tutup</button>
                            </div>

                            <div class="mt-4">
                                <label class="text-xs font-semibold text-slate-600">Alasan Pembatalan</label>
                                <input type="text" name="reason" class="mt-1 w-full rounded-xl border-slate-200">
                            </div>

                            <div class="mt-5 flex items-center justify-end gap-2">
                                <button type="button" onclick="document.getElementById('cancel-{{ $a->id }}').close()"
                                        class="rounded-xl border px-4 py-2 text-sm">Batal</button>
                                <button type="submit"
                                        class="rounded-xl bg-rose-600 text-white px-4 py-2 text-sm font-semibold hover:bg-rose-700">
                                    Konfirmasi
                                </button>
                            </div>
                        </form>
                    </dialog>
                @endif

            @empty
                <div class="rounded-2xl border border-slate-200 p-6 text-center text-sm text-slate-500">
                    Tidak ada agenda sesuai filter.
                </div>
            @endforelse
        </div>

        <div class="border-t border-slate-100 bg-white px-4 py-3">
            {{ $agendas->links() }}
        </div>
    </div>

</div>
@endsection
