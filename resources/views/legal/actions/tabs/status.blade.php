<div class="rounded-2xl border border-slate-200 bg-white p-5">
    {{-- Header --}}
    <div class="mb-4 flex items-start justify-between gap-3">
        <div>
            <h3 class="text-sm font-bold text-slate-900">Riwayat Perubahan Status</h3>
            <p class="mt-0.5 text-xs text-slate-500">
                Menampilkan perubahan status (Dari → Ke), waktu, pelaku, dan catatan.
            </p>
        </div>

        <span class="rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold text-slate-700">
            Total: {{ $action->statusLogs->count() }}
        </span>
    </div>

    @php
        // =========================
        // 1) Urutkan log (lama -> baru) pakai changed_at kalau ada
        // =========================
        $sorted = $action->statusLogs->sortBy(function($x){
            $t = $x->changed_at ?? $x->created_at;
            return optional($t)->timestamp ?? 0;
        })->values();

        // =========================
        // 2) Siapkan rows: from/to prioritas dari kolom baru
        //    fallback: dari status log sebelumnya (legacy)
        // =========================
        $rows = [];
        foreach ($sorted as $i => $log) {
            $prev = $i > 0 ? $sorted[$i-1] : null;

            // ✅ Prioritas kolom baru
            $to   = $log->to_status   ?? $log->status ?? null; // fallback kalau dulu pakai "status"
            $from = $log->from_status ?? ($prev?->to_status ?? $prev?->status ?? null);

            $rows[] = [
                'log'  => $log,
                'from' => $from,
                'to'   => $to,
                'is_estimated' => empty($log->from_status) && $i > 0, // legacy fallback dari prev
                'is_first'     => $i === 0 && empty($log->from_status),
            ];
        }

        // =========================
        // 3) Tampilkan terbaru di atas
        // =========================
        $rows = collect($rows)->reverse()->values();
    @endphp

    {{-- Timeline --}}
    <ol class="relative space-y-5 border-l border-slate-200 pl-5">
        @forelse ($rows as $item)
            @php
                /** @var \Illuminate\Database\Eloquent\Model $log */
                $log = $item['log'];

                $from = $item['from'];
                $to   = $item['to'];

                $fromLabel = $from ? strtoupper($from) : '—';
                $toLabel   = $to ? strtoupper($to) : '—';

                $timeObj = $log->changed_at ?? $log->created_at;
                $at = $timeObj ? \Carbon\Carbon::parse($timeObj)->format('d M Y H:i') : '-';

                $isFirst = (bool) ($item['is_first'] ?? false);
                $isEstimated = (bool) ($item['is_estimated'] ?? false);

                // Badge style
                $badgeFrom = 'bg-slate-100 text-slate-700 ring-slate-200';
                $badgeTo   = 'bg-indigo-50 text-indigo-700 ring-indigo-200';

                // tag kecil untuk legacy/fallback
                $tagCls = $isEstimated
                    ? 'bg-amber-50 text-amber-800 ring-amber-200'
                    : 'bg-emerald-50 text-emerald-800 ring-emerald-200';

                $tagText = $isEstimated ? 'estimasi' : 'log';
                if ($isFirst) $tagText = 'log awal';
            @endphp

            <li class="relative">
                {{-- Dot --}}
                <span class="absolute -left-[11px] top-1 flex h-6 w-6 items-center justify-center rounded-full bg-indigo-600 text-white text-[11px] shadow-sm">
                    ✓
                </span>

                <div class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4">
                    {{-- Top row: From -> To + datetime --}}
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-[11px] font-semibold ring-1 {{ $badgeFrom }}">
                                <span class="text-slate-500">Dari</span>
                                <span class="font-bold">{{ $fromLabel }}</span>
                            </span>

                            <span class="text-slate-400 text-xs">→</span>

                            <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-[11px] font-semibold ring-1 {{ $badgeTo }}">
                                <span class="text-indigo-600">Ke</span>
                                <span class="font-bold">{{ $toLabel }}</span>
                            </span>

                            <span class="ml-1 inline-flex items-center rounded-full px-2 py-1 text-[10px] font-semibold ring-1 {{ $tagCls }}">
                                {{ $tagText }}
                            </span>
                        </div>

                        <div class="text-xs text-slate-500">
                            {{ $at }}
                        </div>
                    </div>

                    {{-- Meta --}}
                    <div class="mt-2 space-y-1 text-sm text-slate-700">
                        <div>
                            Oleh: <span class="font-semibold text-slate-900">{{ $log->changer?->name ?? '-' }}</span>
                        </div>

                        @if(!empty($log->remarks) && empty($log->notes))
                            <div class="text-sm text-slate-600">
                                Catatan: <span class="text-slate-700">{{ $log->remarks }}</span>
                            </div>
                        @endif

                        @if(!empty($log->notes))
                            <div class="text-sm text-slate-600">
                                Catatan: <span class="text-slate-700">{{ $log->notes }}</span>
                            </div>
                        @endif
                    </div>

                    {{-- Helper note for first --}}
                    @if($isFirst)
                        <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Status <b>Dari</b> pada log awal belum tersedia (data lama belum menyimpan <b>from_status</b>).
                            Log berikutnya sudah akan lebih akurat setelah kolom terisi.
                        </div>
                    @elseif($isEstimated)
                        <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Baris ini memakai <b>estimasi</b> karena <b>from_status</b> kosong.
                            “Dari” diambil dari status log sebelumnya.
                        </div>
                    @endif
                </div>
            </li>
        @empty
            <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
                Belum ada riwayat status.
            </div>
        @endforelse
    </ol>

    {{-- Tip kecil --}}
    <div class="mt-5 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-500">
        💡 Riwayat ini memakai <b>from_status</b> & <b>to_status</b> jika tersedia.
        Jika data lama masih kosong, sistem akan fallback (estimasi) dari log sebelumnya.
    </div>
</div>
