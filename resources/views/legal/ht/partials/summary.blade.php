@php
    $exec = $action->htExecution;

    $methodLabel = function($m){
        return match($m){
            'parate' => 'Parate Eksekusi (Lelang KPKNL)',
            'pn' => 'Eksekusi via PN (Fiat + Lelang)',
            'bawah_tangan' => 'Penjualan di Bawah Tangan',
            default => '-',
        };
    };

    $status = strtolower((string) ($action->status ?? 'draft'));

    $required = $action->htDocuments->where('is_required', true);
    $reqTotal = $required->count();
    $reqVerified = $required->where('status','verified')->count();

    $auctionLaku = $action->htAuctions->where('auction_result','laku')->first();
    $sale = $action->htUnderhandSale;

    $statusRoute = route('legal-actions.update-status', $action);

    $nextStatusesAll = match($status) {
        'draft' => ['prepared', 'cancelled'],
        'prepared' => ['submitted', 'draft', 'cancelled'],
        'submitted' => ['in_progress', 'prepared', 'cancelled'],
        'in_progress' => ['executed', 'cancelled'],
        'executed' => ['settled'],
        'settled' => ['closed'],
        default => [],
    };

    $labelStatus = function(string $s){
        return match($s){
            'prepared' => 'Mark Prepared',
            'submitted' => 'Submit',
            'in_progress' => 'Mark In Progress',
            'executed' => 'Mark Executed',
            'settled' => 'Mark Settled',
            'closed' => 'Close',
            'cancelled' => 'Cancel',
            'draft' => 'Rollback to Draft',
            default => strtoupper($s),
        };
    };

    $btnClass = function(string $s){
        return match($s){
            'prepared' => 'bg-indigo-600 hover:bg-indigo-700 text-white',
            'submitted' => 'bg-amber-600 hover:bg-amber-700 text-white',
            'in_progress' => 'bg-blue-600 hover:bg-blue-700 text-white',
            'executed' => 'bg-emerald-600 hover:bg-emerald-700 text-white',
            'settled' => 'bg-emerald-600 hover:bg-emerald-700 text-white',
            'closed' => 'bg-slate-800 hover:bg-slate-900 text-white',
            'cancelled' => 'bg-rose-600 hover:bg-rose-700 text-white',
            'draft' => 'border border-slate-200 hover:bg-slate-50 text-slate-700',
            default => 'border border-slate-200 hover:bg-slate-50 text-slate-700',
        };
    };

    $hintFor = function(string $target) use ($exec, $reqTotal, $reqVerified){
        if ($target === 'prepared') {
            if (!$exec) return 'Lengkapi Data Objek & Dasar HT dulu.';
            return 'Pastikan field minimal terisi (method, sertifikat, owner, alamat, nilai).';
        }
        if ($target === 'submitted') {
            if ($reqTotal > 0 && $reqVerified < $reqTotal) return "Dokumen wajib belum verified ({$reqVerified}/{$reqTotal}).";
            return 'Siap diajukan (dokumen wajib verified).';
        }
        if ($target === 'executed') return 'Pastikan ada lelang LAKU (parate/PN) atau penjualan bawah tangan lengkap.';
        if ($target === 'settled') return 'Pastikan nilai realisasi (sold_value/sale_value) sudah ada.';
        if ($target === 'draft' || $target === 'prepared') return 'Rollback hanya untuk supervisor.';
        return null;
    };

    $checklistTotal   = $checklist->count();
    $checklistChecked = $checklist->whereNotNull('checked_at')->count();

    if ($checklistChecked === 0) {
        $checklistStatus = 'empty';
        $checklistLabel  = 'Belum diverifikasi TL/Kasi';
        $checklistClass  = 'bg-rose-50 text-rose-700 border-rose-200';
        $checklistIcon   = '⛔';
    } elseif ($checklistChecked < $checklistTotal) {
        $checklistStatus = 'partial';
        $checklistLabel  = "Belum lengkap ({$checklistChecked}/{$checklistTotal})";
        $checklistClass  = 'bg-amber-50 text-amber-800 border-amber-200';
        $checklistIcon   = '⚠️';
    } else {
        $checklistStatus = 'complete';
        $checklistLabel  = 'Lengkap';
        $checklistClass  = 'bg-emerald-50 text-emerald-700 border-emerald-200';
        $checklistIcon   = '✅';
    }


    $lastChecklist = $checklist
        ->whereNotNull('checked_at')
        ->sortByDesc('checked_at')
        ->first();

    $svc = app(\App\Services\Legal\HtExecutionStatusService::class);
    $allowed = $svc->allowedTransitions($action);

@endphp


<div
    x-data="{
        modalOpen: false,
        toStatus: '',
        label: '',
        hint: '',
        remarks: '',
        btnClass: '',

        showModal(to, label, hint, btnClass){
            this.toStatus = to;
            this.label = label || '';
            this.hint = hint || '';
            this.btnClass = btnClass || '';
            this.remarks = '';
            this.modalOpen = true;

            this.$nextTick(() => {
                const el = document.getElementById('ht-remarks-input');
                if (el) el.focus();
            });
        },

        closeModal(){
            this.modalOpen = false;
        }
    }"
    @keydown.escape.window="closeModal()"
>
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-sm font-semibold text-slate-900">Ringkasan Eksekusi HT</div>
                
            </div>

            @if($readOnly)
                <span class="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800">
                    🔒 Data terkunci
                </span>
            @endif
        </div>

        {{-- Final Audit Summary (SETTLED/CLOSED) --}}
        @include('legal.ht.partials.final_audit_summary', ['action' => $action])
        <!-- <div class="flex items-center gap-2">
            <a href="{{ route('legal-actions.ht.audit_pdf', $action) }}"
            class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold hover:bg-slate-50">
                📄 Download PDF
            </a>

            @if(strtolower((string)$action->status) === \App\Models\LegalAction::STATUS_SETTLED)
                <form method="POST" action="{{ route('legal-actions.ht.close', $action) }}">
                    @csrf
                    <button class="inline-flex items-center rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-black">
                        ✅ CLOSE
                    </button>
                </form>
            @endif
        </div> -->


        {{-- CTA STATUS --}}
        @php
            $svc = app(\App\Services\Legal\HtExecutionStatusService::class);
            $allowed = $svc->allowedTransitions($action);

            $btn = fn($kind) => match($kind) {
                'danger'  => 'bg-rose-600 text-white hover:bg-rose-700 border-rose-600',
                'primary' => 'bg-indigo-600 text-white hover:bg-indigo-700 border-indigo-600',
                default   => 'bg-white text-slate-700 hover:bg-slate-50 border-slate-300',
            };

            $label = fn($to) => match($to) {
                \App\Models\LegalAction::STATUS_PREPARED  => 'Mark Prepared (Berkas siap)',
                \App\Models\LegalAction::STATUS_SUBMITTED => 'Submit to KPKNL (Ajukan lelang)',
                \App\Models\LegalAction::STATUS_SCHEDULED => 'Mark Scheduled (Jadwal keluar)',
                \App\Models\LegalAction::STATUS_EXECUTED  => 'Mark Executed (Hasil lelang)',
                \App\Models\LegalAction::STATUS_CLOSED    => 'Close (Tutup kasus)',
                \App\Models\LegalAction::STATUS_CANCELLED => 'Cancel (Batalkan proses)',
                default => strtoupper($to),
            };

            $hint = fn($to) => match($to) {
                \App\Models\LegalAction::STATUS_PREPARED  => 'Pastikan: method, sertifikat, owner, alamat, nilai taksasi sudah terisi.',
                \App\Models\LegalAction::STATUS_SUBMITTED => 'Menandai permohonan lelang resmi diajukan ke KPKNL. Setelah ini biasanya data dikunci.',
                \App\Models\LegalAction::STATUS_SCHEDULED => 'Isi tanggal/jadwal lelang dari KPKNL di timeline/event.',
                \App\Models\LegalAction::STATUS_EXECUTED  => 'Isi hasil lelang: laku/tidak laku + nilai.',
                \App\Models\LegalAction::STATUS_CLOSED    => 'Mengunci seluruh proses. Tidak bisa diubah lagi.',
                \App\Models\LegalAction::STATUS_CANCELLED => 'Batalkan proses (wajib isi alasan).',
                default => '',
            };
        @endphp

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            @php
                use App\Models\LegalAction;
                use App\Models\Legal\LegalActionHtAuction;
                use App\Models\Legal\LegalActionHtDocument;

                $status = strtolower((string) ($action->status ?? ''));

                $isScheduled = $status === LegalAction::STATUS_SCHEDULED;
                $isExecuted  = $status === LegalAction::STATUS_EXECUTED;
                $isSettled   = $status === LegalAction::STATUS_SETTLED;
                $isClosed    = $status === LegalAction::STATUS_CLOSED;
                $isCancelled = $status === LegalAction::STATUS_CANCELLED;

                $canExportPdf = in_array($status, [
                    LegalAction::STATUS_EXECUTED,
                    LegalAction::STATUS_SETTLED,
                    LegalAction::STATUS_CLOSED,
                ], true);

                // Badge kecil yang kamu minta
                $statusHint = null;
                if ($isExecuted) $statusHint = ['EXECUTED', 'Belum Settlement', 'bg-amber-50 text-amber-700 border-amber-200'];
                if ($isSettled)  $statusHint = ['SETTLED',  'Siap Close',      'bg-emerald-50 text-emerald-700 border-emerald-200'];
            @endphp

            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-base font-semibold text-slate-900">Aksi Status</div>
                    <div class="text-sm text-slate-500 mt-1">
                        Klik tombol → isi catatan (remarks) bila perlu → submit.
                    </div>

                    @if ($errors->has('to_status'))
                        <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            {{ $errors->first('to_status') }}
                        </div>
                    @endif
                </div>

                <div class="text-sm text-slate-600 text-right">
                    <div class="flex items-center justify-end gap-2">
                        @if($canExportPdf)
                            <a href="{{ route('legal-actions.ht.audit_pdf', $action) }}"
                            target="_blank"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            title="Export Final Audit Summary ke PDF">
                                📄 Download PDF
                            </a>
                        @endif

                        <div>
                            Status saat ini: <span class="font-semibold">{{ $action->status_label }}</span>
                        </div>
                    </div>

                    @if($statusHint)
                        <div class="mt-2">
                            <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold {{ $statusHint[2] }}">
                                <span>{{ $statusHint[0] }}</span>
                                <span class="opacity-80">({{ $statusHint[1] }})</span>
                            </span>
                        </div>
                    @endif
                </div>

            </div>

            {{-- remarks (dipakai untuk semua transisi, terutama cancel) --}}
            <div class="mt-4">
                <label class="block text-sm font-medium text-slate-700">Catatan / Remarks (opsional, wajib untuk Cancel)</label>
                <textarea id="ht_remarks" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        rows="2" placeholder="Contoh: berkas lengkap, sudah dikirim ke KPKNL. / Cancel karena debitur bayar."></textarea>
            </div>

            @php
                // =========================
                // Dokumen wajib (Verified)
                // =========================
                $requiredDocTotal = $action->htDocuments()
                    ->where('is_required', true)
                    ->count();

                $requiredDocVerified = $action->htDocuments()
                    ->where('is_required', true)
                    ->where('status', LegalActionHtDocument::STATUS_VERIFIED)
                    ->count();

                $canSubmitDocs = ($requiredDocTotal > 0) && ($requiredDocVerified >= $requiredDocTotal);

                // =========================
                // Checklist TL
                // =========================
                $tlRequiredTotal = \App\Models\LegalAdminChecklist::where('legal_action_id', $action->id)
                    ->where('is_required', 1)
                    ->count();

                $tlCheckedTotal = \App\Models\LegalAdminChecklist::where('legal_action_id', $action->id)
                    ->where('is_required', 1)
                    ->where('is_checked', 1)
                    ->count();

                $tlComplete = ($tlRequiredTotal > 0) && ($tlCheckedTotal >= $tlRequiredTotal);

                // FINAL syarat submit
                $canSubmit = $tlComplete && $canSubmitDocs;

                // =========================
                // Scheduled guard: harus ada penetapan_jadwal dan tidak lebih awal dari submit_kpknl
                // =========================
                $submitEvent = $action->htEvents()
                    ->where('event_type', 'submit_kpknl')
                    ->whereNotNull('event_at')
                    ->orderByDesc('event_at')
                    ->first();

                $scheduleEvent = $action->htEvents()
                    ->where('event_type', 'penetapan_jadwal')
                    ->whereNotNull('event_at')
                    ->orderByDesc('event_at')
                    ->first();

                $canMarkScheduled = (bool) $scheduleEvent
                    && (!$submitEvent || $scheduleEvent->event_at >= $submitEvent->event_at);

                $markScheduledReason = !$scheduleEvent
                    ? "Belum ada Timeline 'Penetapan jadwal lelang'. Isi dulu di tab Proses & Timeline."
                    : (($submitEvent && $scheduleEvent->event_at < $submitEvent->event_at)
                        ? "Tanggal Penetapan Jadwal tidak boleh lebih awal dari Submit ke KPKNL."
                        : null);

                // =========================
                // FILTER tombol berdasarkan status HT (UX)
                // =========================
                // SCHEDULED: hanya EXECUTED + CANCELLED
                // EXECUTED : hanya SETTLED
                // SETTLED  : hanya CLOSED
                // lainnya  : tampilkan sesuai flow default (atau kosong)
                $visibleAllowed = collect($allowed)->filter(function ($to) use ($isScheduled, $isExecuted, $isSettled) {
                    $to = strtolower((string) $to);

                    if ($isScheduled) {
                        return in_array($to, [
                            LegalAction::STATUS_EXECUTED,
                            LegalAction::STATUS_CANCELLED,
                        ], true);
                    }

                    if ($isExecuted) {
                        return $to === LegalAction::STATUS_SETTLED;
                    }

                    if ($isSettled) {
                        return $to === LegalAction::STATUS_CLOSED;
                    }

                    return true; // default: biarkan flow lain tampil normal
                })->values();
            @endphp

            <!-- @if($canExportPdf)
                <div class="mt-4 flex justify-end">
                    <a href="{{ route('legal-actions.ht.audit_pdf', $action) }}"
                    target="_blank"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        📄 Download PDF Audit
                    </a>
                </div>
            @endif -->

            <div class="mt-4 flex flex-wrap gap-2">
                @forelse ($visibleAllowed as $to)
                    @php
                        $toLower = strtolower((string) $to);

                        $isCancel = $toLower === LegalAction::STATUS_CANCELLED;
                        $isSubmit = $toLower === LegalAction::STATUS_SUBMITTED;
                        $isMarkScheduled = $toLower === LegalAction::STATUS_SCHEDULED;

                        // default style
                        $style = $isCancel ? $btn('danger') : $btn('primary');

                        // disabled logic
                        $disabled = false;
                        $disabledReason = '';

                        // Submit disabled if requirements not met
                        if ($isSubmit && !$canSubmit) {
                            $disabled = true;

                            if (!$canSubmitDocs) {
                                $disabledReason .= "Dokumen wajib belum diverifikasi ($requiredDocVerified/$requiredDocTotal). ";
                            }
                            if (!$tlComplete) {
                                $disabledReason .= "Checklist verifikasi (TL/Kasi) belum lengkap ($tlCheckedTotal/$tlRequiredTotal). ";
                            }

                        }

                        // Mark Scheduled disabled if timeline invalid
                        if ($isMarkScheduled && !$canMarkScheduled) {
                            $disabled = true;
                            $disabledReason = $markScheduledReason ?: "Belum memenuhi syarat Mark Scheduled.";
                        }

                        $title = $disabled ? trim($disabledReason) : $hint($to);
                    @endphp

                    <form method="POST"
                        action="{{ route('legal-actions.ht.status', $action) }}"
                        onsubmit="
                            if ({{ $disabled ? 'true' : 'false' }}) return false;
                            const remarks = document.getElementById('ht_remarks').value.trim();
                            if ('{{ $to }}' === '{{ LegalAction::STATUS_CANCELLED }}' && !remarks) {
                                alert('Untuk Cancel, alasan (remarks) wajib diisi.');
                                return false;
                            }
                            this.querySelector('input[name=remarks]').value = remarks;
                            return true;
                        ">
                        @csrf
                        <input type="hidden" name="to_status" value="{{ $to }}">
                        <input type="hidden" name="remarks" value="">

                        <button type="submit"
                            {{ $disabled ? 'disabled' : '' }}
                            class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold
                                {{ $disabled ? 'opacity-50 cursor-not-allowed' : $style }}"
                            title="{{ $title }}">
                            {{ $label($to) }}
                        </button>

                    </form>
                @empty
                    <div class="text-sm text-slate-500">
                        Tidak ada aksi status yang tersedia untuk tahap ini.
                    </div>
                @endforelse
            </div>

            <div class="mt-3 text-xs text-slate-500">
                Tip: arahkan mouse ke tombol untuk melihat penjelasan singkat (tooltip).
            </div>
        </div>


        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-xl border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Metode</div>
                <div class="mt-1 text-sm font-semibold text-slate-900">{{ $methodLabel($exec?->method) }}</div>

                <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <div class="text-xs text-slate-500">Sertifikat</div>
                        <div class="font-semibold text-slate-800">
                            {{ $exec?->land_cert_type ?? '-' }} {{ $exec?->land_cert_no ?? '' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-500">Owner</div>
                        <div class="font-semibold text-slate-800">{{ $exec?->owner_name ?? '-' }}</div>
                    </div>
                    <div class="col-span-2">
                        <div class="text-xs text-slate-500">Alamat Objek</div>
                        <div class="font-semibold text-slate-800">{{ $exec?->object_address ?? '-' }}</div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Kesiapan Dokumen</div>
                <div class="mt-1 text-sm font-semibold text-slate-900">{{ $reqVerified }}/{{ $reqTotal }} dokumen wajib verified</div>

                <div class="mt-4">
                    <div class="text-xs text-slate-500">Nilai</div>
                    <div class="mt-1 grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="text-xs text-slate-500">Taksasi</div>
                            <div class="font-semibold text-slate-900">
                                {{ $exec?->appraisal_value ? number_format((float)$exec->appraisal_value,0,',','.') : '-' }}
                            </div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="text-xs text-slate-500">Outstanding</div>
                            <div class="font-semibold text-slate-900">
                                {{ $exec?->outstanding_at_start ? number_format((float)$exec->outstanding_at_start,0,',','.') : '-' }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3 flex items-center gap-3">
                    <span class="inline-flex items-center gap-2 rounded-xl border px-3 py-1.5 text-xs font-semibold {{ $checklistClass }}">
                        <span>{{ $checklistIcon }}</span>
                        <span>Checklist Verifikasi (TL/Kasi): {{ $checklistLabel }}</span>
                    </span>

                    @if($lastChecklist)
                        <span class="text-xs text-slate-500">
                            Terakhir dicek oleh
                            <span class="font-semibold">{{ $lastChecklist->checked_by_name ?? 'TL/Kasi' }}</span>
                            ({{ $lastChecklist->checked_at->format('d/m/Y H:i') }})
                        </span>
                    @endif
                </div>

                <div class="mt-4">
                    <div class="text-xs text-slate-500">Hasil Eksekusi (jika sudah)</div>
                    <div class="mt-1 text-sm font-semibold text-slate-900">
                        @if($exec?->method === 'bawah_tangan' && $sale && $sale->sale_value)
                            Penjualan bawah tangan: Rp {{ number_format((float)$sale->sale_value,0,',','.') }}
                        @elseif($auctionLaku && $auctionLaku->sold_value)
                            Lelang laku (Attempt #{{ $auctionLaku->attempt_no }}): Rp {{ number_format((float)$auctionLaku->sold_value,0,',','.') }}
                        @else
                            -
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @if($exec?->notes)
            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div class="text-xs text-slate-500">Catatan</div>
                <div class="mt-1 text-sm text-slate-800 whitespace-pre-line">{{ $exec->notes }}</div>
            </div>
        @endif
    </div>

    {{-- MODAL --}}
    <div x-cloak x-show="modalOpen"
         x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center px-4"
         aria-modal="true" role="dialog">
        <div class="absolute inset-0 bg-slate-900/50" @click="closeModal()"></div>

        <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-xl border border-slate-200 p-5" @click.stop>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-sm font-semibold text-slate-900" x-text="label"></div>
                    <div class="mt-1 text-xs text-slate-500">
                        Status baru: <span class="font-semibold" x-text="toStatus"></span>
                    </div>
                </div>

                <button type="button"
                        class="rounded-lg border border-slate-200 px-2 py-1 text-sm hover:bg-slate-50"
                        @click="closeModal()">
                    ✕
                </button>
            </div>

            <template x-if="hint">
                <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800" x-text="hint"></div>
            </template>

            <div class="mt-4">
                <label class="text-xs text-slate-500">Remarks (opsional tapi disarankan)</label>
                <textarea id="ht-remarks-input" x-model="remarks" rows="4"
                          class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500"
                          placeholder="Tulis alasan/penjelasan perubahan status..."></textarea>
            </div>

            <form action="{{ $statusRoute }}" method="POST" class="mt-4 flex items-center justify-end gap-2">
                @csrf

                <input type="hidden" name="return_url" value="{{ route('legal-actions.ht.show', $action) . '?tab=summary' }}">
                <input type="hidden" name="to_status" :value="toStatus">
                <input type="hidden" name="remarks" :value="remarks">

                <button type="button"
                        class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        @click="closeModal()">
                    Batal
                </button>

                <button type="submit"
                        class="rounded-xl px-4 py-2 text-sm font-semibold"
                        :class="btnClass">
                    Kirim
                </button>
            </form>
        </div>
    </div>
</div>
