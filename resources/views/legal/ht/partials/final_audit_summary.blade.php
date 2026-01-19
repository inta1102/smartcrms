@php
    use App\Models\Legal\LegalActionHtDocument;
    use App\Models\LegalAdminChecklist;
    use App\Models\LegalAction;

    /**
     * ============================
     * STATUS & FINAL FLAG (anti undefined $isFinal)
     * ============================
     */
    $status = strtolower((string) ($action->status ?? LegalAction::STATUS_DRAFT));

    // final = settled / closed / cancelled (kamu bisa tambah executed kalau mau dianggap final juga)
    $isFinal = in_array($status, [
        LegalAction::STATUS_SETTLED,
        LegalAction::STATUS_CLOSED,
        LegalAction::STATUS_CANCELLED,
    ], true);

    /**
     * Badge (biar gak undefined juga)
     */
    $badgeText  = strtoupper($status ?: '-');
    $badgeNote  = '';
    $badgeClass = 'bg-slate-50 text-slate-700 border-slate-200';

    if ($status === LegalAction::STATUS_EXECUTED) {
        $badgeText  = 'EXECUTED';
        $badgeNote  = 'Belum Settlement';
        $badgeClass = 'bg-amber-50 text-amber-700 border-amber-200';
    } elseif ($status === LegalAction::STATUS_SETTLED) {
        $badgeText  = 'SETTLED';
        $badgeNote  = 'Siap Close';
        $badgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
    } elseif ($status === LegalAction::STATUS_CLOSED) {
        $badgeText  = 'CLOSED';
        $badgeNote  = 'Final';
        $badgeClass = 'bg-slate-900 text-white border-slate-900';
    } elseif ($status === LegalAction::STATUS_CANCELLED) {
        $badgeText  = 'CANCELLED';
        $badgeNote  = 'Final';
        $badgeClass = 'bg-rose-50 text-rose-700 border-rose-200';
    }

    /**
     * ============================
     * LAST UPDATE (anti undefined $lastLog)
     * ============================
     */
    $lastLog = null;
    $lastUpdate = null;

    try {
        if (isset($action) && method_exists($action, 'statusLogs')) {
            $lastLog = $action->statusLogs()->latest('changed_at')->first()
                ?? $action->statusLogs()->latest('created_at')->first();
        }
        $lastUpdate = $lastLog?->changed_at
            ?? $lastLog?->created_at
            ?? $action->updated_at
            ?? null;
    } catch (\Throwable $e) {
        $lastLog = null;
        $lastUpdate = $action->updated_at ?? null;
    }

    /**
     * ============================
     * DEFAULT SAFE VALUE
     * ============================
     */
    $docRequiredTotal  = 0;
    $docVerifiedTotal  = 0;
    $tlRequiredTotal   = 0;
    $tlCheckedTotal    = 0;

    $docsOk = false;
    $tlOk   = false;

    /**
     * ============================
     * DOKUMEN WAJIB (Required vs Verified)
     * ============================
     */
    try {
        if (
            isset($action)
            && method_exists($action, 'htDocuments')
            && class_exists(LegalActionHtDocument::class)
        ) {
            $docRequiredTotal = (int) $action->htDocuments()
                ->where('is_required', true)
                ->count();

            $verifiedStatus = defined(LegalActionHtDocument::class.'::STATUS_VERIFIED')
                ? LegalActionHtDocument::STATUS_VERIFIED
                : 'verified';

            $docVerifiedTotal = (int) $action->htDocuments()
                ->where('is_required', true)
                ->where('status', $verifiedStatus)
                ->count();

            if ($docRequiredTotal > 0) {
                $docsOk = $docVerifiedTotal >= $docRequiredTotal;
            }
        }
    } catch (\Throwable $e) {
        $docRequiredTotal = 0;
        $docVerifiedTotal = 0;
        $docsOk = false;
    }

        /**
     * ============================
     * FINAL AUCTION (anti undefined $finalAuction)
     * ============================
     */
    $finalAuction = null;

    try {
        if (isset($action) && method_exists($action, 'htAuctions')) {
            // ambil attempt final terbaru: LAKU / TIDAK_LAKU (fallback ke string jika constant beda)
            $finalResults = [];

            if (class_exists(\App\Models\Legal\LegalActionHtAuction::class)) {
                $finalResults = array_filter([
                    defined(\App\Models\Legal\LegalActionHtAuction::class.'::RESULT_LAKU')
                        ? \App\Models\Legal\LegalActionHtAuction::RESULT_LAKU
                        : 'laku',
                    defined(\App\Models\Legal\LegalActionHtAuction::class.'::RESULT_TIDAK_LAKU')
                        ? \App\Models\Legal\LegalActionHtAuction::RESULT_TIDAK_LAKU
                        : 'tidak_laku',
                ]);
            } else {
                $finalResults = ['laku','tidak_laku'];
            }

            $finalAuction = $action->htAuctions()
                ->whereNotNull('auction_date')
                ->whereIn('auction_result', $finalResults)
                ->orderByDesc('auction_date')
                ->orderByDesc('id')
                ->first();
        }
    } catch (\Throwable $e) {
        $finalAuction = null;
    }

    /**
     * ============================
     * MILESTONES (anti undefined $milestones)
     * ============================
     */
    $milestones = [];

    try {
        if (isset($action) && method_exists($action, 'statusLogs')) {
            $logs = $action->statusLogs()
                ->orderBy('changed_at')
                ->orderBy('id')
                ->get();

            foreach ($logs as $lg) {
                $milestones[] = [
                    'from'    => strtoupper((string)($lg->from_status ?? '-')),
                    'to'      => strtoupper((string)($lg->to_status ?? '-')),
                    'at'      => $lg->changed_at ?? $lg->created_at ?? null,
                    'remarks' => $lg->remarks ?? null,
                ];
            }
        }
    } catch (\Throwable $e) {
        $milestones = [];
    }

    /**
     * ============================
     * CHECKLIST TL (Required vs Checked)
     * ============================
     */
    try {
        if (isset($action) && class_exists(LegalAdminChecklist::class)) {
            $tlRequiredTotal = (int) LegalAdminChecklist::where('legal_action_id', $action->id ?? 0)
                ->where('is_required', 1)
                ->count();

            $tlCheckedTotal = (int) LegalAdminChecklist::where('legal_action_id', $action->id ?? 0)
                ->where('is_required', 1)
                ->where('is_checked', 1)
                ->count();

            if ($tlRequiredTotal > 0) {
                $tlOk = $tlCheckedTotal >= $tlRequiredTotal;
            }
        }
    } catch (\Throwable $e) {
        $tlRequiredTotal = 0;
        $tlCheckedTotal  = 0;
        $tlOk = false;
    }
@endphp


@if($isFinal)

<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="text-base font-bold text-slate-900">🧾 Final Audit Summary (OJK/Audit)</div>
            <div class="mt-1 text-sm text-slate-500">
                Ringkasan final eksekusi HT untuk kebutuhan audit trail & pembuktian dokumen.
            </div>
        </div>

        <div class="text-right">
            <div class="text-sm text-slate-600">Status Akhir</div>
            <div class="mt-1 inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold {{ $badgeClass }}">
                <span>{{ $badgeText }}</span>
                <span class="opacity-80">• {{ $badgeNote }}</span>
            </div>

            <div class="mt-2 text-xs text-slate-500">
                Last update:
                <span class="font-medium text-slate-700">
                    {{ $lastUpdate ? $lastUpdate->format('d/m/Y H:i') : '-' }}
                </span>
            </div>
        </div>
    </div>

    {{-- Identitas --}}
    <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Legal Case</div>
            <div class="mt-1 font-semibold text-slate-900">{{ $action->legalCase?->legal_case_no ?? '-' }}</div>
            <div class="mt-2 text-xs text-slate-500">Debitur</div>
            <div class="mt-1 font-semibold text-slate-900">
                {{ $action->legalCase?->debtor_name ?? $action->legalCase?->nplCase?->loanAccount?->customer_name ?? '-' }}
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Metode</div>
            <div class="mt-1 font-semibold text-slate-900">{{ $action->htExecution?->method_label ?? ($action->htExecution?->method ?? '-') }}</div>

            <div class="mt-2 text-xs text-slate-500">Dokumen Wajib</div>
            <div class="mt-1 font-semibold text-slate-900">{{ $docVerifiedTotal }}/{{ $docRequiredTotal }} Verified</div>
        </div>

        <div class="rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Objek / Sertifikat</div>
            <div class="mt-1 font-semibold text-slate-900">
                {{ $action->htExecution?->land_cert_type ?? '-' }} {{ $action->htExecution?->land_cert_no ?? '' }}
            </div>
            <div class="mt-2 text-xs text-slate-500">Owner</div>
            <div class="mt-1 font-semibold text-slate-900">{{ $action->htExecution?->owner_name ?? '-' }}</div>
        </div>
    </div>

    {{-- Alamat & Nilai --}}
    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
        <div class="rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Alamat Objek</div>
            <div class="mt-1 text-sm font-semibold text-slate-900">
                {{ $action->htExecution?->object_address ?? '-' }}
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 p-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <div class="text-xs text-slate-500">Nilai Taksasi</div>
                    <div class="mt-1 font-semibold text-slate-900">
                        {{ $action->htExecution?->appraisal_value ? number_format((float)$action->htExecution->appraisal_value,0,',','.') : '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Outstanding Awal</div>
                    <div class="mt-1 font-semibold text-slate-900">
                        {{ $action->htExecution?->outstanding_at_start ? number_format((float)$action->htExecution->outstanding_at_start,0,',','.') : '-' }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Hasil Final --}}
    <div class="mt-4 rounded-xl border border-slate-200 p-4">
        <div class="flex items-center justify-between">
            <div class="font-semibold text-slate-900">🏁 Hasil Final Eksekusi</div>
            @if($finalAuction)
                <div class="text-xs text-slate-500">Attempt #{{ $finalAuction->attempt_no ?? '-' }}</div>
            @endif
        </div>

        @if(!$finalAuction)
            <div class="mt-2 text-sm text-slate-600">Belum ada data attempt lelang.</div>
        @else
            <div class="mt-3 grid grid-cols-1 md:grid-cols-4 gap-3 text-sm">
                <div>
                    <div class="text-xs text-slate-500">Tgl Lelang</div>
                    <div class="mt-1 font-semibold text-slate-900">{{ $finalAuction->auction_date?->format('d/m/Y') ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Hasil</div>
                    <div class="mt-1 font-semibold text-slate-900">{{ strtoupper((string)$finalAuction->auction_result) }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Limit</div>
                    <div class="mt-1 font-semibold text-slate-900">
                        {{ $finalAuction->limit_value ? number_format((float)$finalAuction->limit_value,0,',','.') : '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Nilai Laku</div>
                    <div class="mt-1 font-semibold text-slate-900">
                        {{ $finalAuction->sold_value ? number_format((float)$finalAuction->sold_value,0,',','.') : '-' }}
                    </div>
                </div>

                <div class="md:col-span-2">
                    <div class="text-xs text-slate-500">KPKNL / Registrasi</div>
                    <div class="mt-1 font-semibold text-slate-900">
                        {{ $finalAuction->kpknl_office ?? '-' }} • Reg: {{ $finalAuction->registration_no ?? '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Settlement Date</div>
                    <div class="mt-1 font-semibold text-slate-900">{{ $finalAuction->settlement_date?->format('d/m/Y') ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Risalah</div>
                    <div class="mt-1">
                        @if($finalAuction->risalah_file_path)
                            <a class="text-indigo-600 hover:underline" target="_blank"
                               href="{{ route('legal-actions.ht.auctions.risalah', [$action, $finalAuction]) }}">
                                📄 Unduh
                            </a>
                        @else
                            <span class="text-slate-500">-</span>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Kronologi singkat --}}
    <div class="mt-4 rounded-xl border border-slate-200 p-4">
        <div class="font-semibold text-slate-900">🧭 Kronologi Status (Audit Trail)</div>
        <div class="mt-3 space-y-2">
            @forelse($milestones as $m)
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-1 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                    <div class="text-sm font-semibold text-slate-900">{{ $m['from'] }} → {{ $m['to'] }}</div>
                    <div class="text-xs text-slate-600">
                        {{ $m['at']?->format('d/m/Y H:i') ?? '-' }}
                        @if(!empty($m['remarks']))
                            • <span class="text-slate-500">{{ \Illuminate\Support\Str::limit($m['remarks'], 90) }}</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-sm text-slate-600">Belum ada status log.</div>
            @endforelse
        </div>
    </div>

    {{-- Catatan terakhir --}}
    <div class="mt-4 rounded-xl border border-slate-200 p-4">
        <div class="font-semibold text-slate-900">📝 Catatan Penutupan / Remarks</div>
        <div class="mt-2 text-sm text-slate-700 whitespace-pre-line">
            {{ $lastLog?->remarks ?: 'Tidak ada catatan.' }}
        </div>
    </div>

</div>
@endif
