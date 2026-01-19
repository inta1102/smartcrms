@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-6"
     x-data="{
        tab: new URLSearchParams(window.location.search).get('tab') || 'overview',
        setTab(t) {
            this.tab = t;
            const url = new URL(window.location);
            url.searchParams.set('tab', t);
            history.replaceState(null, '', url);
        }
     }"
>

    {{-- Flash message --}}
    @if(session('success'))
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            <div class="font-semibold">Ada error:</div>
            <ul class="mt-2 list-disc pl-5 space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif


    @php
        $progress = $progress ?? [];

        // status yang ditampilkan di header (untuk somasi pakai derived state)
        $headerStatus = (($action->action_type ?? '') === 'somasi' && !empty($progress['state']))
            ? $progress['state']
            : ($action->status ?? 'draft');

        // ✅ LOCK FLAG (untuk header + tombol)
        $isSomasi = (($action->action_type ?? '') === 'somasi');
        $somasiStateForHeader = strtolower((string) $headerStatus);
        $isSomasiLocked = $isSomasi && in_array($somasiStateForHeader, ['completed','failed','cancelled'], true);
    @endphp

    {{-- Header --}}
    @include('legal.actions.partials.header', [
        'action'        => $action,
        'allowed'       => $allowed,
        'headerStatus'  => $headerStatus,
        'isSomasiLocked'=> $isSomasiLocked
    ])

    @if(($action->action_type ?? '') === 'somasi')
        @php
            $progress = $progress ?? [];

            $sentAt      = $progress['sent_at'] ?? null;
            $receivedAt  = $progress['received_at'] ?? null;
            $deadlineAt  = $progress['deadline_at'] ?? null;

            $respondedAt   = $progress['responded_at'] ?? null;
            $noResponseAt  = $progress['no_response_at'] ?? null;

            $deadlineIsBlocking = false; // jangan jadikan cancelled sebagai blocker

            $hasDraft = array_key_exists('has_draft_doc', $progress) ? (bool) $progress['has_draft_doc'] : true;

            $receiptStatus  = $progress['receipt_status'] ?? null; // received|delivered_tracking|returned|unknown
            $deliveryMethod = $progress['delivery_method'] ?? ($action->meta['somasi']['delivery_method'] ?? null);

            // ✅ Step state
            $isSent = !is_null($sentAt);

            // opsi B (praktis): delivered_tracking dianggap "done" juga
            $isReceived = in_array($receiptStatus, ['received','delivered_tracking'], true)
                && (!is_null($receivedAt) || $receiptStatus === 'delivered_tracking');

            $isFinalResponded  = !is_null($respondedAt);
            $isFinalNoResponse = !is_null($noResponseAt);

            // waiting: kalau sudah sent & received (atau tracking) dan belum final
            $isWaiting = $isSent && $isReceived && !$isFinalResponded && !$isFinalNoResponse;

            $fmt = fn($dt) => $dt ? \Carbon\Carbon::parse($dt)->format('d M Y H:i') : null;

            $chip = function(bool $done, bool $active = false, bool $danger = false) {
                if ($danger) return 'bg-rose-50 text-rose-700 ring-rose-200';
                if ($active) return 'bg-indigo-50 text-indigo-700 ring-indigo-200';
                if ($done)   return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
                return 'bg-slate-50 text-slate-500 ring-slate-200';
            };

            $activeDraft    = $hasDraft && !$isSent;
            $activeSent     = $isSent && !$isReceived;
            $activeReceived = $isReceived && !$isFinalResponded && !$isFinalNoResponse;
            $activeWaiting  = $isWaiting && !$isFinalResponded && !$isFinalNoResponse;

            $isOverdue = false;
            if ($deadlineAt && $isWaiting) {
                $isOverdue = \Carbon\Carbon::parse($deadlineAt)->isPast();
            }

            // badge ringkas (kecil)
            $badgeText = '📝 DRAFT';
            $badgeCls  = 'bg-slate-50 text-slate-700 ring-slate-200';
            if ($isFinalResponded) { $badgeText='✅ RESPON DITERIMA'; $badgeCls='bg-emerald-50 text-emerald-700 ring-emerald-200'; }
            elseif ($isFinalNoResponse) { $badgeText='⛔ TIDAK ADA RESPON'; $badgeCls='bg-rose-50 text-rose-700 ring-rose-200'; }
            elseif ($isOverdue) { $badgeText='⚠️ LEWAT DEADLINE'; $badgeCls='bg-amber-50 text-amber-700 ring-amber-200'; }
            elseif ($activeSent) { $badgeText='📤 DALAM PENGIRIMAN'; $badgeCls='bg-indigo-50 text-indigo-700 ring-indigo-200'; }
            elseif ($isWaiting) { $badgeText='⏳ MENUNGGU RESPON'; $badgeCls='bg-indigo-50 text-indigo-700 ring-indigo-200'; }

            // =========================
            // Progress bar % (berdasar STATE dari controller)
            // =========================
            $state = strtolower((string)($progress['state'] ?? ''));

            // mapping step 1..5
            $stepIndex = match ($state) {
                'completed' => 5,
                'waiting'   => 4,
                'received'  => 3,
                'sent'      => 2,
                default     => 1, // draft / unknown
            };

            $totalSteps = 5;
            $percent = (int) round(($stepIndex / $totalSteps) * 100);

            // ✅ kalau sudah final (respond/no_response), paksa 100% (extra safety)
            if (($isFinalResponded ?? false) || ($isFinalNoResponse ?? false)) {
                $percent = 100;
            }

            // meta draft
            $som = (array) (($action->meta['somasi'] ?? []) ?? []);

            // ===== STEP 4 (Respon Debitur) - data event =====
            $evDeadline = $action->events->firstWhere('event_type', 'somasi_deadline');
            $evResponse = $action->events->firstWhere('event_type', 'somasi_responded');
            $evNoResp   = $action->events->firstWhere('event_type', 'somasi_no_response');

            $deadlineAtEv     = $evDeadline?->event_at ? \Carbon\Carbon::parse($evDeadline->event_at) : null;
            $deadlineStatusEv = $evDeadline?->status ?? null;

            // status action (lebih “resmi” untuk step 4)
            $state = strtolower((string)($progress['state'] ?? ''));

            // ✅ sync lock dari state progress (lebih akurat)
            $isSomasiLocked = in_array($state, ['completed','failed','cancelled'], true);

            $isWaitingStatus = ($state === 'waiting') || (($isWaiting ?? false) === true);

            // Step 4 done kalau ada response/no_response
            $step4Done = $isFinalResponded || $isFinalNoResponse;
        @endphp

        <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            {{-- Header --}}
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-semibold text-slate-800">Progres Somasi</div>
                    <div class="mt-1 text-xs text-slate-500">
                        Draft → Kirim → Diterima → Menunggu → Selesai
                    </div>
                </div>

                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 {{ $badgeCls }}">
                    {{ $badgeText }}
                </span>
            </div>

            {{-- Progress bar --}}
            <div class="mt-4">
                <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-indigo-600" style="width: {{ $percent }}%"></div>
                </div>
                <div class="mt-2 text-[11px] text-slate-500">
                    Progres: <span class="font-semibold text-slate-700">{{ $percent }}%</span>

                    @if(($progress['state'] ?? null) !== 'completed' && $deadlineAt)
                        • Deadline: <span class="font-semibold">{{ \Carbon\Carbon::parse($deadlineAt)->format('d M Y H:i') }}</span>
                    @endif
                </div>
            </div>

            {{-- Info locked --}}
            @if($isSomasiLocked)
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    Somasi sudah <b>ditutup</b>. Step sebelumnya dikunci (read-only).
                </div>
            @endif

            {{-- Content grid --}}
            <div class="mt-5 grid gap-4 lg:grid-cols-12">
                {{-- Left: ringkasan --}}
                <div class="lg:col-span-4 rounded-2xl border border-slate-100 p-4">
                    <div class="text-sm font-semibold text-slate-800">Ringkasan</div>

                    <dl class="mt-3 space-y-2 text-xs">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500">Dikirim</dt>
                            <dd class="text-slate-800 font-semibold text-right">{{ $fmt($sentAt) ?? '-' }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500">Penerimaan</dt>
                            <dd class="text-right">
                                @if($receiptStatus === 'received')
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">✅ Diterima</span>
                                @elseif($receiptStatus === 'delivered_tracking')
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold bg-amber-50 text-amber-700 ring-1 ring-amber-200">⚠️ Delivered (Tracking)</span>
                                @elseif($receiptStatus === 'returned')
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold bg-rose-50 text-rose-700 ring-1 ring-rose-200">❌ Return</span>
                                @elseif($receiptStatus === 'unknown')
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold bg-slate-50 text-slate-600 ring-1 ring-slate-200">❓ Tidak terkonfirmasi</span>
                                @else
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold bg-slate-50 text-slate-600 ring-1 ring-slate-200">-</span>
                                @endif
                                <div class="mt-1 text-[11px] text-slate-500">{{ $fmt($receivedAt) ?? '' }}</div>
                            </dd>
                        </div>

                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500">Metode kirim</dt>
                            <dd class="text-slate-800 font-semibold text-right">{{ $deliveryMethod ?? '-' }}</dd>
                        </div>

                        @if(!empty($progress['courier_name']) || !empty($progress['tracking_no']))
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-slate-500">Ekspedisi</dt>
                                <dd class="text-slate-800 font-semibold text-right">
                                    {{ $progress['courier_name'] ?? '-' }}
                                    <div class="text-[11px] text-slate-500">{{ $progress['tracking_no'] ?? '' }}</div>
                                </dd>
                            </div>
                        @endif
                    </dl>

                    <div class="mt-4 border-t border-slate-100 pt-4">
                        <div class="text-xs font-semibold text-slate-700">Catatan</div>
                        <div class="mt-1 text-xs text-slate-600">
                            {{ $progress['shipping_note'] ?? $progress['received_note'] ?? '-' }}
                        </div>

                        {{-- ✅ Tombol Modal Upload Dokumen Somasi --}}
                        <button
                            type="button"
                            x-data
                            @click="$dispatch('open-somasi-doc-modal')"
                            @disabled($isSomasiLocked)
                            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 {{ $isSomasiLocked ? 'opacity-50 cursor-not-allowed' : '' }}">
                            📎 Upload Dokumen Somasi
                        </button>

                        <div class="mt-1 text-[11px] text-slate-500">
                            Untuk POD / Return / Screenshot tracking (bukan resi pengiriman).
                        </div>
                    </div>
                </div>

                {{-- Middle: Pengiriman --}}
                <div class="lg:col-span-4 rounded-2xl border border-slate-100 bg-white p-5">
                    {{-- shipping form livewire --}}
                    @livewire('legal-action.shipping-form', ['action' => $action, 'locked' => $isSomasiLocked])
                </div>

                {{-- Right: Penerimaan --}}
                <div class="lg:col-span-4 rounded-2xl border border-slate-100 p-4">
                    <div class="flex items-center justify-between">
                        <div class="text-sm font-semibold text-slate-800">Penerimaan</div>
                        <span class="text-[11px] text-slate-500">Step 3</span>
                    </div>

                    <div class="mt-1 text-xs text-slate-500">
                        Untuk ekspedisi: bisa pakai <span class="font-semibold">Delivered (Tracking)</span> dulu.
                    </div>

                    <form method="POST" action="{{ route('legal-actions.somasi.receipt', $action) }}" class="mt-3 space-y-3">
                        @csrf

                        <div>
                            <label class="text-xs font-semibold text-slate-600">Status penerimaan</label>
                            <select name="receipt_status" class="mt-1 w-full rounded-xl border-slate-200 text-sm" @disabled($isSomasiLocked)>
                                <option value="">-- pilih --</option>
                                <option value="received" {{ $receiptStatus==='received'?'selected':'' }}>✅ Diterima</option>
                                <option value="delivered_tracking" {{ $receiptStatus==='delivered_tracking'?'selected':'' }}>⚠️ Delivered (Tracking)</option>
                                <option value="returned" {{ $receiptStatus==='returned'?'selected':'' }}>❌ Gagal / Return</option>
                                <option value="unknown" {{ $receiptStatus==='unknown'?'selected':'' }}>❓ Tidak terkonfirmasi</option>
                            </select>
                            @error('receipt_status') <div class="text-xs text-rose-600 mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="text-xs font-semibold text-slate-600">Tanggal / jam</label>
                                <input
                                    type="datetime-local"
                                    name="received_at"
                                    value="{{ old('received_at', $progress['received_at'] ? \Carbon\Carbon::parse($progress['received_at'])->format('Y-m-d\TH:i') : '') }}"
                                    class="mt-1 w-full rounded-xl border-slate-200 text-sm"
                                    @disabled($isSomasiLocked)
                                >
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-slate-600">Nama penerima</label>
                                <input
                                    name="receiver_name"
                                    value="{{ old('receiver_name', $som['receiver_name'] ?? '') }}"
                                    class="mt-1 w-full rounded-xl border-slate-200 text-sm"
                                    placeholder="Debitur/Istri/RT..."
                                    @disabled($isSomasiLocked)
                                >
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="text-xs font-semibold text-slate-600">Relasi</label>
                                <input
                                    name="receiver_relation"
                                    value="{{ old('receiver_relation', $som['receiver_relation'] ?? '') }}"
                                    class="mt-1 w-full rounded-xl border-slate-200 text-sm"
                                    placeholder="Debitur / Istri / Keluarga"
                                    @disabled($isSomasiLocked)
                                >
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-slate-600">Alasan return</label>
                                <input
                                    name="return_reason"
                                    value="{{ old('return_reason', $som['return_reason'] ?? '') }}"
                                    class="mt-1 w-full rounded-xl border-slate-200 text-sm"
                                    placeholder="alamat tidak ditemukan, menolak..."
                                    @disabled($isSomasiLocked)
                                >
                            </div>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-600">Catatan</label>
                            <input
                                name="received_note"
                                value="{{ old('received_note', $progress['received_note'] ?? '') }}"
                                class="mt-1 w-full rounded-xl border-slate-200 text-sm"
                                placeholder="misal: POD belum diminta / delivered tracking"
                                @disabled($isSomasiLocked)
                            >
                        </div>

                        <button
                            class="w-full rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 {{ $isSomasiLocked ? 'opacity-50 cursor-not-allowed' : '' }}"
                            @disabled($isSomasiLocked)
                        >
                            Simpan Status Penerimaan
                        </button>
                    </form>
                </div>
            </div>

            {{-- STEP 4: Respon Debitur --}}
            <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                @php
                    // ✅ sumber utama: state dari progress (hasil show())
                    $state = strtolower((string)($progress['state'] ?? ''));

                    // ✅ waiting ditentukan dari state/progress, bukan action->status
                    // fallback: pakai hitungan lokal $isWaiting kalau state belum tersedia
                    $isWaitingStatus = ($state === 'waiting') || (($isWaiting ?? false) === true);

                    // ✅ Samakan event type dengan controller markResponse/markNoResponse (umumnya 'somasi_response')
                    // NOTE: kalau controller kamu sudah diubah jadi 'somasi_responded', ini tetap aman karena kita pakai fallback chain
                    $evDeadline = $action->events->firstWhere('event_type', 'somasi_deadline');

                    $evResponse =
                        $action->events->firstWhere('event_type', 'somasi_responded')
                        ?? $action->events->firstWhere('event_type', 'somasi_response');

                    $evNoResp   = $action->events->firstWhere('event_type', 'somasi_no_response');

                    $deadlineAtEv     = $evDeadline?->event_at ? \Carbon\Carbon::parse($evDeadline->event_at) : null;
                    $deadlineStatusEv = $evDeadline?->status ?? null;

                    // ✅ Step 4 dianggap done jika derived progress sudah final
                    $step4Done = ($isFinalResponded ?? false) || ($isFinalNoResponse ?? false);
                @endphp

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <div class="text-sm font-bold text-slate-900">Respon Debitur</div>
                            <span class="rounded-full bg-slate-50 px-2 py-0.5 text-[11px] font-semibold text-slate-700 ring-1 ring-slate-200">
                                Step 4
                            </span>

                            @if($step4Done)
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                    ✅ Selesai
                                </span>
                            @elseif($isWaitingStatus)
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200">
                                    ⏳ Menunggu
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200">
                                    ⛔ Belum pada tahap ini
                                </span>
                            @endif
                        </div>

                        <div class="mt-1 text-xs text-slate-500">
                            Catat apakah debitur merespons, atau tidak ada respons sampai dicek.
                        </div>
                    </div>

                    {{-- Ringkasan deadline --}}
                    <div class="min-w-[180px] rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                        <div class="text-[11px] font-semibold text-slate-600">Deadline Respons</div>

                        <div class="mt-1 text-sm font-semibold text-slate-900">
                            {{ $deadlineAtEv?->format('d M Y H:i') ?? '-' }}
                        </div>

                        <div class="mt-0.5 text-[11px] text-slate-500">
                            Status:
                            <span class="font-semibold">
                                {{ $deadlineStatusEv ?? '-' }}
                            </span>
                        </div>
                    </div>
                </div>

                {{-- Kalau sudah ada hasil Step 4, tampilkan ringkasannya --}}
                @if($step4Done)
                    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                        @if($evResponse && $evResponse->status === 'done')
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                                <div class="text-xs font-semibold text-emerald-800">✅ Debitur Merespons</div>
                                <div class="mt-1 text-sm font-semibold text-emerald-900">
                                    {{ $evResponse->event_at ? \Carbon\Carbon::parse($evResponse->event_at)->format('d M Y H:i') : '-' }}
                                </div>
                                @if($evResponse->notes)
                                    <div class="mt-2 whitespace-pre-line text-xs text-emerald-800/90">
                                        {{ $evResponse->notes }}
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if($evNoResp && $evNoResp->status === 'done')
                            <div class="rounded-xl border border-rose-200 bg-rose-50 p-3">
                                <div class="text-xs font-semibold text-rose-800">❌ Tidak Ada Respons</div>
                                <div class="mt-1 text-sm font-semibold text-rose-900">
                                    {{ $evNoResp->event_at ? \Carbon\Carbon::parse($evNoResp->event_at)->format('d M Y H:i') : '-' }}
                                </div>
                                @if($evNoResp->notes)
                                    <div class="mt-2 whitespace-pre-line text-xs text-rose-800/90">
                                        {{ $evNoResp->notes }}
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="mt-3 text-xs text-slate-500">
                        Step 4 sudah tercatat. Jika ingin revisi, nanti kita tambah tombol “Edit” khusus event.
                    </div>

                @else
                    {{-- Form hanya tampil kalau memang sudah tahap waiting --}}
                    @if($isWaitingStatus)
                        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">

                            {{-- FORM A: Debitur Merespons --}}
                            @can('update', $action)
                            <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-bold text-slate-900">✅ Debitur Merespons</div>
                                        <div class="mt-0.5 text-xs text-slate-500">Catat tanggal, channel, dan catatan respons.</div>
                                    </div>
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                        Aksi
                                    </span>
                                </div>

                                <form method="POST" action="{{ route('legal-actions.somasi.markResponse', $action) }}" class="mt-4 space-y-3">
                                    @csrf

                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div>
                                            <label class="text-xs font-semibold text-slate-600">Tanggal / Jam</label>
                                            <input
                                                type="datetime-local"
                                                name="response_at"
                                                value="{{ now()->format('Y-m-d\TH:i') }}"
                                                class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-emerald-400 focus:ring-emerald-400"
                                                required
                                            />
                                            @error('response_at') <div class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</div> @enderror
                                        </div>

                                        <div>
                                            <label class="text-xs font-semibold text-slate-600">Channel</label>
                                            <select
                                                name="response_channel"
                                                class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-emerald-400 focus:ring-emerald-400"
                                            >
                                                <option value="">- pilih -</option>
                                                <option value="datang">Datang</option>
                                                <option value="surat">Surat</option>
                                                <option value="wa">WA</option>
                                                <option value="telepon">Telepon</option>
                                                <option value="kuasa_hukum">Kuasa Hukum</option>
                                                <option value="lainnya">Lainnya</option>
                                            </select>
                                            @error('response_channel') <div class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</div> @enderror
                                        </div>
                                    </div>

                                    <div>
                                        <label class="text-xs font-semibold text-slate-600">Catatan (opsional)</label>
                                        <textarea
                                            name="notes"
                                            rows="3"
                                            placeholder="misal: debitur meminta restrukturisasi / janji bayar / keberatan, dll"
                                            class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-emerald-400 focus:ring-emerald-400"
                                        >{{ old('notes') }}</textarea>
                                        @error('notes') <div class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</div> @enderror
                                    </div>

                                    <button
                                        type="submit"
                                        @disabled($isSomasiLocked)
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700 {{ $isSomasiLocked ? 'opacity-50 cursor-not-allowed' : '' }}"
                                    >
                                        ✅ Simpan Respons
                                    </button>

                                    <div class="text-[11px] text-slate-500">
                                        Aksi ini akan menandai deadline sebagai <b>done</b> dan somasi sebagai <b>selesai</b>.
                                    </div>
                                </form>
                            </div>
                            @endcan

                            {{-- FORM B: Tidak Ada Respons --}}
                            @can('update', $action)
                            <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-bold text-slate-900">❌ Tidak Ada Respons</div>
                                        <div class="mt-0.5 text-xs text-slate-500">Gunakan saat sudah dicek dan debitur tidak merespons.</div>
                                    </div>
                                    <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-rose-200">
                                        Aksi
                                    </span>
                                </div>

                                <form method="POST" action="{{ route('legal-actions.somasi.markNoResponse', $action) }}" class="mt-4 space-y-3">
                                    @csrf

                                    <div>
                                        <label class="text-xs font-semibold text-slate-600">Dicek pada</label>
                                        <input
                                            type="datetime-local"
                                            name="checked_at"
                                            value="{{ now()->format('Y-m-d\TH:i') }}"
                                            class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-rose-400 focus:ring-rose-400"
                                            required
                                            @disabled($isSomasiLocked)
                                        />
                                        @error('checked_at') <div class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</div> @enderror
                                    </div>

                                    <div>
                                        <label class="text-xs font-semibold text-slate-600">Catatan (opsional)</label>
                                        <textarea
                                            name="notes"
                                            rows="3"
                                            placeholder="misal: sudah coba hubungi, tidak ada jawaban / alamat tidak ditemui, dll"
                                            class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-rose-400 focus:ring-rose-400"
                                            @disabled($isSomasiLocked)
                                        >{{ old('notes') }}</textarea>
                                        @error('notes') <div class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</div> @enderror
                                    </div>

                                    <button
                                        type="submit"
                                        @disabled($isSomasiLocked)
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-rose-600 px-4 py-3 text-sm font-semibold text-white hover:bg-rose-700 {{ $isSomasiLocked ? 'opacity-50 cursor-not-allowed' : '' }}"
                                    >
                                        ❌ Tandai No Response
                                    </button>

                                    <div class="text-[11px] text-slate-500">
                                        Aksi ini akan menutup deadline dan menandai somasi sebagai <b>selesai</b> (tanpa respons).
                                    </div>
                                </form>
                            </div>
                            @endcan

                        </div>
                    @else
                        <div class="mt-4 rounded-xl border border-slate-100 bg-slate-50 p-3 text-sm text-slate-600">
                            Step 4 aktif setelah status somasi masuk <b>Menunggu Respon</b>.
                        </div>
                    @endif
                @endif
            </div>

            {{-- Chips --}}
            <div class="mt-5 grid gap-2 md:grid-cols-5">
                {{-- 1 Draft --}}
                <div class="rounded-xl border border-slate-100 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $chip($hasDraft, $activeDraft) }}">
                            1) Draft
                        </span>
                        @if($hasDraft)<span class="text-[11px] text-slate-500">✅</span>@endif
                    </div>
                    <div class="mt-2 text-[11px] text-slate-500">
                        {{ !empty($progress['draft_at']) ? \Carbon\Carbon::parse($progress['draft_at'])->format('d M Y H:i') : 'Dokumen draft / isi data' }}
                    </div>
                </div>

                {{-- 2 Dikirim --}}
                <div class="rounded-xl border border-slate-100 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $chip($isSent, $activeSent) }}">
                            2) Dikirim
                        </span>
                        @if($isSent)<span class="text-[11px] text-slate-500">✅</span>@endif
                    </div>
                    <div class="mt-2 text-[11px] text-slate-500">{{ $fmt($sentAt) ?? 'Belum dikirim' }}</div>
                </div>

                {{-- 3 Diterima --}}
                <div class="rounded-xl border border-slate-100 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $chip($isReceived, $activeReceived, $receiptStatus==='returned') }}">
                            3) Diterima
                        </span>
                        @if($isReceived)<span class="text-[11px] text-slate-500">✅</span>@endif
                    </div>
                    <div class="mt-2 text-[11px] text-slate-500">
                        @if($receiptStatus === 'delivered_tracking')
                            Delivered (tracking)
                        @elseif($receiptStatus === 'returned')
                            Return / gagal kirim
                        @else
                            {{ $fmt($receivedAt) ?? 'Belum ditandai diterima' }}
                        @endif
                    </div>
                </div>

                {{-- 4 Menunggu --}}
                <div class="rounded-xl border border-slate-100 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $chip($isWaiting, $activeWaiting, $isOverdue) }}">
                            4) Menunggu
                        </span>
                        @if($isOverdue)
                            <span class="text-[11px] text-rose-600 font-semibold">Overdue</span>
                        @elseif($isWaiting)
                            <span class="text-[11px] text-slate-500">⏳</span>
                        @endif
                    </div>
                    <div class="mt-2 text-[11px] text-slate-500">
                        {{ $deadlineAt ? 'Sampai ' . \Carbon\Carbon::parse($deadlineAt)->format('d M Y H:i') : 'Menunggu jawaban debitur' }}
                    </div>
                </div>

                {{-- 5 Selesai --}}
                <div class="rounded-xl border border-slate-100 p-3">
                    @php
                        $finalDone = $isFinalResponded || $isFinalNoResponse;
                        $finalDanger = $isFinalNoResponse;
                        $finalLabel = $isFinalResponded ? '✅ Respon' : ($isFinalNoResponse ? '⛔ No Respon' : '5) Selesai');
                        $finalChipClass = $finalDone
                            ? ($finalDanger ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200')
                            : 'bg-slate-50 text-slate-500 ring-slate-200';
                    @endphp

                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $finalChipClass }}">
                            {{ $finalLabel }}
                        </span>
                        @if($finalDone)<span class="text-[11px] text-slate-500">✅</span>@endif
                    </div>

                    <div class="mt-2 text-[11px] text-slate-500">
                        @if($isFinalResponded)
                            {{ $fmt($respondedAt) ?? 'Respon diterima' }}
                        @elseif($isFinalNoResponse)
                            {{ $fmt($noResponseAt) ?? 'Tidak ada respon' }}
                        @else
                            Menunggu keputusan akhir
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div
        x-data="{ open:false }"
        @open-somasi-doc-modal.window="open=true"
        x-show="open"
        x-cloak
        x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
    >

        <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl"
            @click.outside="open=false">

            <div class="flex items-center justify-between">
                <div class="text-sm font-semibold text-slate-800">Upload Bukti Somasi</div>
                <button class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100" @click="open=false">✕</button>
            </div>

            <form method="POST" action="{{ route('legal-actions.somasi.uploadDocument', $action) }}"
                enctype="multipart/form-data" class="mt-4 space-y-3">

                @csrf

                <div>
                    <label class="text-xs font-semibold text-slate-600">Jenis bukti</label>
                    <select name="doc_type" class="mt-1 w-full rounded-xl border-slate-200 text-sm" required @disabled($isSomasiLocked ?? false)>
                        <option value="">-- pilih --</option>

                        <option value="somasi_tracking_screenshot">Screenshot Tracking</option>
                        <option value="somasi_pod">POD / Tanda Terima</option>
                        <option value="somasi_return_proof">Return / Gagal Kirim</option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-600">File</label>
                    <input type="file" name="file" required
                        class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                        @disabled($isSomasiLocked ?? false)>
                    <div class="mt-1 text-[11px] text-slate-500">JPG/PNG/PDF • max 5MB</div>
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-600">Judul (opsional)</label>
                    <input name="title" class="mt-1 w-full rounded-xl border-slate-200 text-sm"
                        placeholder="misal: Resi POS 20 Des 2025"
                        @disabled($isSomasiLocked ?? false)>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button type="button" @click="open=false"
                            class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Batal
                    </button>
                    <button type="submit"
                        @disabled($isSomasiLocked ?? false)
                        class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 {{ ($isSomasiLocked ?? false) ? 'opacity-50 cursor-not-allowed' : '' }}">
                        Upload
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Tabs + Contents --}}
    @include('legal.actions.partials.tabs', ['action'=>$action, 'allowed'=>$allowed])

    {{-- Modal edit non-status --}}
    @include('legal.actions.partials.edit_form', ['action'=>$action])

</div>
@endsection
