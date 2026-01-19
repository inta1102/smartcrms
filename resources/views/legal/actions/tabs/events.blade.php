<div
    x-data="{
        openRes:false,
        targetId:null,
        eventAt:'',
        notes:'',
        openResModal(id, eventAt, notes){
            this.openRes = true;
            this.targetId = id;
            this.eventAt = eventAt;
            this.notes = notes || '';
        }
    }"
    class="rounded-2xl border border-slate-200 bg-white p-4"
>

    @php
        // Milestone event: status "done" = kejadian sudah tercatat (bukan tugas selesai)
        $milestones = ['somasi_sent', 'somasi_received'];

        $countScheduled = $action->events->where('status','scheduled')->count();
        $countDone      = $action->events->where('status','done')->count();
        $countCancelled = $action->events->where('status','cancelled')->count();

        $fmt = fn($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->format('d M Y H:i') : '-';

        $statusUi = function ($ev) use ($milestones) {
            $status = (string) ($ev->status ?? '');
            $type   = (string) ($ev->event_type ?? '');

            // override label untuk milestone
            if ($status === 'done' && in_array($type, $milestones, true)) {
                return [
                    'label' => '✅ Tercatat',
                    'cls'   => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                ];
            }

            return match($status) {
                'scheduled' => ['label' => 'scheduled', 'cls' => 'bg-amber-50 text-amber-700 border-amber-200'],
                'done'      => ['label' => 'done',      'cls' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
                'cancelled' => ['label' => 'cancelled', 'cls' => 'bg-slate-100 text-slate-600 border-slate-200'],
                default     => ['label' => $status ?: '-', 'cls' => 'bg-slate-100 text-slate-600 border-slate-200'],
            };
        };

        $typeUi = function ($type) {
            $type = (string) $type;

            // boleh kamu tambah mapping lain
            return match($type) {
                'somasi_received'  => ['label' => 'somasi_received',  'hint' => 'Somasi sudah diterima/terkonfirmasi'],
                'somasi_deadline'  => ['label' => 'somasi_deadline',  'hint' => 'Batas akhir respon debitur'],
                default            => ['label' => $type ?: '-',       'hint' => null],
            };
        };
    @endphp

    {{-- Header --}}
    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="text-sm font-bold text-slate-900">Event & Deadline</div>
            <div class="mt-0.5 text-xs text-slate-500">
                Scheduled: <span class="font-semibold text-slate-700">{{ $countScheduled }}</span>
                • Done: <span class="font-semibold text-slate-700">{{ $countDone }}</span>
                • Cancelled: <span class="font-semibold text-slate-700">{{ $countCancelled }}</span>
            </div>
        </div>

        <div class="text-xs text-slate-400">
            Milestone (mis: somasi diterima) akan tampil sebagai <span class="font-semibold">“Tercatat”</span>.
        </div>
    </div>

    {{-- ===== MOBILE: Cards ===== --}}
    <div class="space-y-3 md:hidden">
        @forelse($action->events as $ev)
            @php
                $st = $statusUi($ev);
                $tp = $typeUi($ev->event_type);
            @endphp

            <div class="rounded-2xl border border-slate-200 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="inline-flex items-center gap-2">
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700">
                                {{ $tp['label'] }}
                            </span>
                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $st['cls'] }}">
                                {{ $st['label'] }}
                            </span>
                        </div>

                        <div class="mt-2 text-sm font-semibold text-slate-900">
                            {{ $ev->title }}
                        </div>

                        @if(!empty($tp['hint']))
                            <div class="mt-0.5 text-xs text-slate-500">{{ $tp['hint'] }}</div>
                        @endif

                        @if($ev->notes)
                            <div class="mt-2 text-xs text-slate-600">
                                {{ $ev->notes }}
                            </div>
                        @endif
                    </div>

                    <div class="text-right text-xs text-slate-600">
                        <div class="font-semibold">Jadwal</div>
                        <div>{{ $fmt($ev->event_at) }}</div>
                    </div>
                </div>

                <div class="mt-3 rounded-xl border border-slate-100 bg-slate-50 p-3 text-xs text-slate-700">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-semibold text-slate-700">Reminder</div>
                            @if($ev->remind_at)
                                <div class="mt-0.5">{{ $fmt($ev->remind_at) }}</div>
                                <div class="mt-0.5 text-[11px] text-slate-500">
                                    @if($ev->reminded_at)
                                        terkirim: {{ $fmt($ev->reminded_at) }}
                                    @else
                                        belum terkirim
                                    @endif
                                </div>
                            @else
                                <div class="mt-0.5 text-slate-400">-</div>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            @can('update', $action)
                                @if($ev->status === 'scheduled')
                                    <form method="POST" action="{{ route('legal-actions.events.done', [$action, $ev]) }}">
                                        @csrf
                                        <button class="rounded-xl border border-emerald-200 bg-white px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
                                            ✅ Done
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('legal-actions.events.cancel', [$action, $ev]) }}"
                                          onsubmit="return confirm('Batalkan event ini?')">
                                        @csrf
                                        <button class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                            ✖ Cancel
                                        </button>
                                    </form>

                                    <button
                                        type="button"
                                        @click="openResModal(
                                            {{ $ev->id }},
                                            '{{ \Illuminate\Support\Carbon::parse($ev->event_at)->format('Y-m-d\TH:i') }}',
                                            {!! json_encode($ev->notes ?? '') !!}
                                        )"
                                        class="rounded-xl border border-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-50"
                                    >
                                        ⏰ Reschedule
                                    </button>
                                @endif
                            @endcan
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-slate-200 p-8 text-center text-sm text-slate-500">
                Belum ada event.
            </div>
        @endforelse
    </div>

    {{-- ===== DESKTOP/TABLE: md+ ===== --}}
    <div class="hidden overflow-hidden rounded-xl border border-slate-200 md:block">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-600">
                <tr>
                    <th class="px-3 py-2">Tipe</th>
                    <th class="px-3 py-2">Judul</th>
                    <th class="px-3 py-2">Jadwal</th>
                    <th class="px-3 py-2">Reminder</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2 text-right">Aksi</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
                @forelse($action->events as $ev)
                    @php
                        $st = $statusUi($ev);
                        $tp = $typeUi($ev->event_type);
                    @endphp

                    <tr class="hover:bg-slate-50">
                        <td class="px-3 py-2 align-top">
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                {{ $tp['label'] }}
                            </span>
                            @if(!empty($tp['hint']))
                                <div class="mt-1 text-[11px] text-slate-500">{{ $tp['hint'] }}</div>
                            @endif
                        </td>

                        <td class="px-3 py-2 align-top">
                            <div class="font-semibold text-slate-900">{{ $ev->title }}</div>
                            @if($ev->notes)
                                <div class="mt-0.5 text-xs text-slate-500 line-clamp-2">{{ $ev->notes }}</div>
                            @endif
                        </td>

                        <td class="px-3 py-2 align-top text-slate-700">
                            {{ $fmt($ev->event_at) }}
                        </td>

                        <td class="px-3 py-2 align-top text-slate-700">
                            @if($ev->remind_at)
                                <div>{{ $fmt($ev->remind_at) }}</div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    @if($ev->reminded_at)
                                        terkirim: {{ $fmt($ev->reminded_at) }}
                                    @else
                                        belum terkirim
                                    @endif
                                </div>
                            @else
                                <span class="text-xs text-slate-400">-</span>
                            @endif
                        </td>

                        <td class="px-3 py-2 align-top">
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {{ $st['cls'] }}">
                                {{ $st['label'] }}
                            </span>
                        </td>

                        <td class="px-3 py-2 align-top text-right">
                            <div class="inline-flex items-center gap-2">
                                @can('update', $action)
                                    @if($ev->status === 'scheduled')
                                        <form method="POST" action="{{ route('legal-actions.events.done', [$action, $ev]) }}">
                                            @csrf
                                            <button class="rounded-lg border border-emerald-200 px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
                                                ✅ Done
                                            </button>
                                        </form>

                                        <form method="POST" action="{{ route('legal-actions.events.cancel', [$action, $ev]) }}"
                                              onsubmit="return confirm('Batalkan event ini?')">
                                            @csrf
                                            <button class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                                ✖ Cancel
                                            </button>
                                        </form>

                                        <button
                                            type="button"
                                            @click="openResModal(
                                                {{ $ev->id }},
                                                '{{ \Illuminate\Support\Carbon::parse($ev->event_at)->format('Y-m-d\TH:i') }}',
                                                {!! json_encode($ev->notes ?? '') !!}
                                            )"
                                            class="rounded-lg border border-indigo-200 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-50"
                                        >
                                            ⏰ Reschedule
                                        </button>
                                    @else
                                        <span class="text-xs text-slate-400">-</span>
                                    @endif
                                @else
                                    <span class="text-xs text-slate-400">-</span>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-3 py-8 text-center text-sm text-slate-500">
                            Belum ada event.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal Reschedule --}}
    @can('update', $action)
        <div x-show="openRes" x-cloak>
            <div class="fixed inset-0 z-40 bg-black/40" @click="openRes=false"></div>

            <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                        <div>
                            <h3 class="text-base font-bold text-slate-900">Reschedule Event</h3>
                            <p class="mt-0.5 text-sm text-slate-500">Ubah jadwal, reminder akan dihitung ulang.</p>
                        </div>
                        <button class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100" @click="openRes=false">✕</button>
                    </div>

                    <form
                        method="POST"
                        :action="'{{ url('/legal-actions/'.$action->id.'/events') }}/' + targetId + '/reschedule'"
                        class="px-5 py-4"
                    >
                        @csrf

                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <label class="text-sm font-semibold text-slate-700">Event At</label>
                                <input
                                    type="datetime-local"
                                    name="event_at"
                                    x-model="eventAt"
                                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-indigo-400"
                                    required
                                >
                            </div>

                            <div>
                                <label class="text-sm font-semibold text-slate-700">Catatan (opsional)</label>
                                <textarea
                                    name="notes"
                                    x-model="notes"
                                    rows="3"
                                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:ring-indigo-400"
                                ></textarea>
                            </div>

                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                Reminder dihitung otomatis sesuai jenis event.
                                Kalau perlu manual override, nanti kita tambah field <span class="font-semibold">remind_at</span> di modal.
                            </div>
                        </div>

                        <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4">
                            <button
                                type="button"
                                @click="openRes=false"
                                class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                            >
                                Simpan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan
</div>
