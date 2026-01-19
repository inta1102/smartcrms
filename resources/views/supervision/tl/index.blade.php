@extends('layouts.app')

@section('content')
@php
    // $rows = paginator (LengthAwarePaginator) yang isinya array row
    $rows = $rows ?? null;
    $kpi = $kpi ?? [];
    $attention = $attention ?? [];

    $badge = function(string $status) {
        $s = strtolower($status);
        return match($s) {
            'done'      => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'cancelled' => 'bg-slate-100 text-slate-600 border-slate-200',
            'overdue'   => 'bg-rose-50 text-rose-700 border-rose-200',
            'in_progress' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'planned'   => 'bg-sky-50 text-sky-700 border-sky-200',
            default     => 'bg-slate-50 text-slate-700 border-slate-200',
        };
    };

    $typeBadge = function(?string $type) {
        $t = strtolower((string)$type);
        return match($t) {
            'wa' => 'bg-green-50 text-green-700 border-green-200',
            'visit' => 'bg-amber-50 text-amber-800 border-amber-200',
            'evaluation' => 'bg-purple-50 text-purple-700 border-purple-200',
            default => 'bg-slate-50 text-slate-700 border-slate-200',
        };
    };

    $fmt = fn($v) => number_format((int)($v ?? 0), 0, ',', '.');
@endphp

<div class="max-w-7xl mx-auto px-4 py-6 space-y-6">

    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900">Dashboard Supervisi TL</h1>
            <p class="mt-1 text-sm text-slate-500">Pantau agenda AO, overdue, stagnan, dan in-progress tanpa tindakan.</p>
        </div>
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-xs text-slate-500">Active Cases</div>
            <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $fmt($kpi['active_cases'] ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-xs text-slate-500">Active Agendas</div>
            <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $fmt($kpi['active_agendas'] ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-xs text-slate-500">Overdue</div>
            <div class="mt-1 text-2xl font-extrabold text-rose-700">{{ $fmt($kpi['overdue'] ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-xs text-slate-500">In Progress (No Action)</div>
            <div class="mt-1 text-2xl font-extrabold text-indigo-700">{{ $fmt($kpi['in_progress_no_action'] ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-xs text-slate-500">Visits (This Week)</div>
            <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $fmt($kpi['visits_week'] ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-xs text-slate-500">Stagnant ≥ 7d</div>
            <div class="mt-1 text-2xl font-extrabold text-amber-700">{{ $fmt($kpi['stagnant_7d'] ?? 0) }}</div>
        </div>
    </div>

    {{-- Filter --}}
    <div class="rounded-2xl border bg-white p-5 shadow-sm">
        @php
            $status = request('status');
            $type   = request('type');
            $dueFrom = request('due_from');
            $dueTo   = request('due_to');
            $onlyOverdue = request()->boolean('only_overdue');
            $emptyInprogress = request()->boolean('empty_inprogress');

            $label = "text-[11px] font-bold text-slate-500 uppercase tracking-wide";
            $help  = "mt-1 text-[11px] text-slate-400";
            $field = "mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm
                    text-slate-900 shadow-sm outline-none
                    focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100";
        @endphp

        <form method="GET" action="{{ url()->current() }}"
            class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-sm">

            {{-- header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <div class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-50 text-indigo-700">
                            <span class="text-base">🔎</span>
                        </div>
                        <div>
                            <div class="text-base font-extrabold text-slate-900">Filter Agenda</div>
                            <div class="text-xs text-slate-500">Fokus agenda prioritas TL, cepat & jelas.</div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <a href="{{ url()->current() }}"
                    class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Reset
                    </a>
                    <button type="submit"
                            class="rounded-xl bg-slate-900 px-5 py-2 text-sm font-extrabold text-white hover:bg-slate-800">
                        Terapkan
                    </button>
                </div>
            </div>

            {{-- fields --}}
            <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-6">
                {{-- Status --}}
                <div>
                    <label class="{{ $label }}">Status</label>
                    <select name="status" class="{{ $field }}">
                        <option value="">(all)</option>
                        @foreach(['planned','in_progress','done','overdue','cancelled'] as $st)
                            <option value="{{ $st }}" @selected($status === $st)>{{ strtoupper($st) }}</option>
                        @endforeach
                    </select>
                    <div class="{{ $help }}">Pilih status agenda.</div>
                </div>

                {{-- Type --}}
                <div>
                    <label class="{{ $label }}">Type</label>
                    <select name="type" class="{{ $field }}">
                        <option value="">(all)</option>
                        @foreach(['wa','visit','evaluation'] as $t)
                            <option value="{{ $t }}" @selected($type === $t)>{{ strtoupper($t) }}</option>
                        @endforeach
                    </select>
                    <div class="{{ $help }}">WA / Visit / Evaluation.</div>
                </div>

                {{-- Due From --}}
                <div>
                    <label class="{{ $label }}">Due From</label>
                    <input type="date" name="due_from" value="{{ $dueFrom }}" class="{{ $field }}">
                    <div class="{{ $help }}">Mulai rentang due.</div>
                </div>

                {{-- Due To --}}
                <div>
                    <label class="{{ $label }}">Due To</label>
                    <input type="date" name="due_to" value="{{ $dueTo }}" class="{{ $field }}">
                    <div class="{{ $help }}">Akhir rentang due.</div>
                </div>

                {{-- Flags (chip style) --}}
                <div class="sm:col-span-2 xl:col-span-2">
                    <label class="{{ $label }}">Flags</label>

                    <div class="mt-1 flex flex-col gap-2 sm:flex-row">
                        {{-- Overdue chip --}}
                        <label class="group flex flex-1 cursor-pointer items-start gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2
                                    hover:bg-white hover:border-slate-300">
                            <input type="checkbox" name="only_overdue" value="1" @checked($onlyOverdue)
                                class="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-200">
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-slate-800 leading-tight">Only Overdue</div>
                                <div class="text-[11px] text-slate-500 leading-tight">
                                    Belum done/cancelled & lewat due.
                                </div>
                            </div>
                        </label>

                        {{-- In progress empty chip --}}
                        <label class="group flex flex-1 cursor-pointer items-start gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2
                                    hover:bg-white hover:border-slate-300">
                            <input type="checkbox" name="empty_inprogress" value="1" @checked($emptyInprogress)
                                class="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-200">
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-slate-800 leading-tight">In Progress (No Action)</div>
                                <div class="text-[11px] text-slate-500 leading-tight">
                                    In progress tapi belum ada tindakan.
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="{{ $help }}">Gunakan flags untuk prioritas supervisi.</div>
                </div>
            </div>
        </form>

    </div>

    {{-- Attention --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-sm font-extrabold text-slate-900">🔥 Overdue berat</div>
            <div class="mt-3 space-y-2">
                @forelse(($attention['overdue_heavy'] ?? []) as $it)
                    <a class="block rounded-xl border border-rose-100 bg-rose-50/40 p-3 hover:bg-rose-50"
                       href="{{ route('cases.show', $it['case_id']) }}">
                        <div class="text-xs font-bold text-rose-800">{{ $it['debtor'] }}</div>
                        <div class="text-xs text-slate-700 mt-1">{{ $it['agenda_title'] }}</div>
                        <div class="text-[11px] text-slate-500 mt-1">Due: {{ $it['due'] ?? '-' }}</div>
                    </a>
                @empty
                    <div class="text-xs text-slate-500">Aman, belum ada overdue berat.</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-sm font-extrabold text-slate-900">⚠️ In Progress tanpa tindakan</div>
            <div class="mt-3 space-y-2">
                @forelse(($attention['in_progress_empty'] ?? []) as $it)
                    <a class="block rounded-xl border border-indigo-100 bg-indigo-50/40 p-3 hover:bg-indigo-50"
                       href="{{ route('cases.show', $it['case_id']) }}">
                        <div class="text-xs font-bold text-indigo-800">{{ $it['debtor'] }}</div>
                        <div class="text-xs text-slate-700 mt-1">{{ $it['agenda_title'] }}</div>
                        <div class="text-[11px] text-slate-500 mt-1">Started: {{ $it['started_at'] ?? '-' }}</div>
                    </a>
                @empty
                    <div class="text-xs text-slate-500">Tidak ada agenda in-progress yang kosong.</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-sm font-extrabold text-slate-900">🕒 Kasus stagnan</div>
            <div class="mt-3 space-y-2">
                @forelse(($attention['stagnant'] ?? []) as $it)
                    <a class="block rounded-xl border border-amber-100 bg-amber-50/40 p-3 hover:bg-amber-50"
                       href="{{ route('cases.show', $it['case_id']) }}">
                        <div class="text-xs font-bold text-amber-800">{{ $it['debtor'] }}</div>
                        <div class="text-[11px] text-slate-500 mt-1">Last action: {{ $it['last_action_at'] ?? '-' }}</div>
                    </a>
                @empty
                    <div class="text-xs text-slate-500">Tidak ada kasus stagnan yang terdeteksi.</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden"
        x-data="supervisionTL()">

        <div class="px-5 py-4 border-b">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-extrabold text-slate-900">Daftar Agenda</div>
                    <div class="text-xs text-slate-500">Klik Detail untuk melihat ringkasan & last actions.</div>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-600">
                <tr>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-left">Agenda</th>
                    <th class="px-4 py-3 text-left">Kasus</th>
                    <th class="px-4 py-3 text-left">Due</th>
                    <th class="px-4 py-3 text-left">Last Action</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
                </thead>
                <tbody class="divide-y">
                @forelse($rows as $r)
                    <tr class="hover:bg-slate-50/50">
                        <td class="px-4 py-3">
                            <div class="inline-flex items-center gap-2">
                                <span class="rounded-full border px-2 py-1 text-[11px] font-bold {{ $badge($r['status']) }}">
                                    {{ strtoupper($r['status']) }}
                                </span>
                                <span class="rounded-full border px-2 py-1 text-[11px] font-bold {{ $typeBadge($r['agenda_type']) }}">
                                    {{ strtoupper($r['agenda_type']) }}
                                </span>
                                @if(!empty($r['overdue_days']))
                                    <span class="rounded-full border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-bold text-rose-700">
                                        {{ $r['overdue_days'] }}d
                                    </span>
                                @endif
                            </div>
                        </td>

                        <td class="px-4 py-3">
                            <div class="font-bold text-slate-900">{{ $r['agenda_title'] }}</div>
                            <div class="text-xs text-slate-500">Evidence: {{ $r['evidence_required'] ? 'Wajib' : 'Tidak' }}</div>
                        </td>

                        <td class="px-4 py-3">
                            <a href="{{ route('cases.show', $r['case_id']) }}" class="font-bold text-indigo-700 hover:underline">
                                #{{ $r['case_id'] }} — {{ $r['debtor'] }}
                            </a>
                            <div class="text-xs text-slate-500">AO: {{ $r['ao_name'] }}</div>
                        </td>

                        <td class="px-4 py-3">
                            <div class="font-semibold text-slate-900">{{ $r['due_at'] ?? '-' }}</div>
                            <div class="text-xs text-slate-500">Planned: {{ $r['planned_at'] ?? '-' }}</div>
                        </td>

                        <td class="px-4 py-3">
                            @if($r['last_action'])
                                <div class="text-xs text-slate-700">{{ $r['last_action'] }}</div>
                            @else
                                <div class="text-xs text-slate-500">Belum ada tindakan.</div>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex items-center gap-2">
                                <button type="button"
                                        class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                        @click="openDetail(@js($r))">
                                    Detail
                                </button>

                                <a href="{{ route('cases.show', $r['case_id']) }}"
                                class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                                    Buka Case →
                                </a>
                            </div>
                        </td>

                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">Tidak ada data.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if(method_exists($rows, 'links'))
        <div class="px-5 py-4 border-t">
            {{ $rows->links() }}
        </div>
    @endif

    {{-- MODAL DETAIL --}}
    <div x-cloak x-show="isOpen"
         class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
        {{-- overlay --}}
        <div class="absolute inset-0 bg-black/40" @click="close()"></div>

        {{-- panel --}}
        <div class="relative w-full sm:max-w-2xl bg-white rounded-t-3xl sm:rounded-3xl shadow-xl border border-slate-200
                    max-h-[85vh] overflow-y-auto">
            <div class="p-5 border-b flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs text-slate-500">Detail Agenda</div>
                    <div class="mt-1 text-base font-extrabold text-slate-900" x-text="row?.agenda_title || '-'"></div>
                    <div class="mt-1 text-xs text-slate-600">
                        <span class="font-semibold" x-text="'#'+(row?.case_id ?? '-')"></span>
                        <span class="mx-1">—</span>
                        <span x-text="row?.debtor || '-'"></span>
                    </div>
                </div>

                <button class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold hover:bg-slate-50"
                        @click="close()">
                    ✕
                </button>
            </div>

            <div class="p-5 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="rounded-2xl border bg-slate-50 p-4">
                        <div class="text-xs font-semibold text-slate-600">Status</div>
                        <div class="mt-1 text-sm font-bold text-slate-900" x-text="row?.status || '-'"></div>
                        <div class="mt-2 text-xs text-slate-600">
                            Due: <span class="font-semibold" x-text="row?.due_at || '-'"></span>
                        </div>
                        <div class="mt-1 text-xs text-slate-600">
                            Planned: <span class="font-semibold" x-text="row?.planned_at || '-'"></span>
                        </div>
                    </div>

                    <div class="rounded-2xl border bg-slate-50 p-4">
                        <div class="text-xs font-semibold text-slate-600">Keterangan</div>
                        <div class="mt-1 text-xs text-slate-700">
                            Type: <span class="font-semibold" x-text="row?.agenda_type || '-'"></span>
                        </div>
                        <div class="mt-1 text-xs text-slate-700">
                            Evidence: <span class="font-semibold" x-text="row?.evidence_required ? 'Wajib' : 'Tidak'"></span>
                        </div>
                        <div class="mt-2 text-xs text-slate-700">
                            AO: <span class="font-semibold" x-text="row?.ao_name || '-'"></span>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border p-4">
                    <div class="text-sm font-extrabold text-slate-900">Last Action</div>

                    <template x-if="row?.last_action_type">
                        <div class="mt-2 text-xs text-slate-600 space-y-1">
                            <div><span class="font-semibold">Type:</span> <span x-text="row.last_action_type"></span></div>
                            <div><span class="font-semibold">At:</span> <span x-text="row.last_action_at || '-'"></span></div>
                            <div><span class="font-semibold">Result:</span> <span x-text="row.last_action_result || '-'"></span></div>
                            <div class="pt-2 text-sm text-slate-800 whitespace-pre-line"
                                x-text="row.last_action_desc || '-'"></div>
                        </div>
                    </template>

                    <template x-if="!row?.last_action_type">
                        <div class="mt-2 text-xs text-slate-500">Belum ada tindakan.</div>
                    </template>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <a :href="caseUrl()"
                       class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50">
                        Buka Case
                    </a>

                    <a :href="caseUrlWithAgenda()"
                       class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">
                        Buka + Fokus Agenda
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function supervisionTL() {
        return {
            isOpen: false,
            row: null,

            openDetail(r) {
                this.row = r;
                this.isOpen = true;
                document.body.classList.add('overflow-hidden');
            },
            close() {
                this.isOpen = false;
                this.row = null;
                document.body.classList.remove('overflow-hidden');
            },
            caseUrl() {
                if (!this.row?.case_id) return '#';
                return `{{ url('/cases') }}/${this.row.case_id}`;
            },
            caseUrlWithAgenda() {
                if (!this.row?.case_id) return '#';
                const qs = new URLSearchParams({
                    tab: 'persuasif',
                    agenda: this.row?.agenda_id || ''
                });
                return `{{ url('/cases') }}/${this.row.case_id}?` + qs.toString();
            }
        }
    }
</script>
@endpush

@endsection
