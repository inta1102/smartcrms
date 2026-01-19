<?php

namespace App\Http\Controllers;

use App\Models\LegalAction;
use App\Models\LegalAdminChecklist;
use App\Models\CaseAction;
use App\Services\Legal\LegalActionStatusService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LegalActionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $q = LegalAction::query()
            ->with(['legalCase.nplCase.loanAccount']);

        // ===== Filters =====
        $status  = $request->string('status')->toString();
        $type    = $request->string('type')->toString();
        $handler = $request->string('handler')->toString();
        $sort    = $request->string('sort')->toString() ?: 'newest';
        $kw      = trim((string) $request->input('q', ''));
        $from    = $request->input('from');
        $to      = $request->input('to');

        if ($status && $status !== 'all') $q->where('status', $status);
        if ($type && $type !== 'all')     $q->where('action_type', $type);
        if ($handler && $handler !== 'all') $q->where('handler_type', $handler);
        if ($from) $q->whereDate('start_at', '>=', $from);
        if ($to)   $q->whereDate('start_at', '<=', $to);

        if ($kw !== '') {
            $q->where(function ($qq) use ($kw) {
                $qq->where('external_ref_no', 'like', "%{$kw}%")
                ->orWhere('summary', 'like', "%{$kw}%")
                ->orWhereHas('legalCase.nplCase', function ($npl) use ($kw) {
                    $npl->where('cif', 'like', "%{$kw}%")
                        ->orWhere('debtor_name', 'like', "%{$kw}%")
                        ->orWhereHas('loanAccount', function ($la) use ($kw) {
                            $la->where('no_rek', 'like', "%{$kw}%")
                                ->orWhere('account_no', 'like', "%{$kw}%");
                        });
                });
            });
        }

        // ===== KPI (pakai clone sebelum sorting) =====
        $kpiRows = (clone $q)
            ->selectRaw('LOWER(status) as s, COUNT(*) as cnt')
            ->groupBy('s')
            ->pluck('cnt', 's');

        $total = (int) $kpiRows->sum();
        $openStatuses = ['draft','prepared','submitted','scheduled','in_progress','waiting'];
        $submittedStatuses = ['submitted'];
        $scheduledStatuses = ['scheduled'];
        $closedStatuses = ['closed','completed','executed','settled'];

        $open      = (int) $kpiRows->only($openStatuses)->sum();
        $submitted = (int) $kpiRows->only($submittedStatuses)->sum();
        $scheduled = (int) $kpiRows->only($scheduledStatuses)->sum();
        $closed    = (int) $kpiRows->only($closedStatuses)->sum();

        // ===== Sorting =====
        if ($sort === 'oldest') {
            $q->orderBy('start_at')->orderBy('id');
        } else {
            $q->orderByDesc('start_at')->orderByDesc('id');
        }

        $actions = $q->paginate(15)->withQueryString();

            // dropdown options
            $statusOptions = [
                'all' => 'Semua',
                'draft' => 'Draft',
                'prepared' => 'Prepared',
                'submitted' => 'Submitted',
                'scheduled' => 'Scheduled',
                'in_progress' => 'In Progress',
                'waiting' => 'Waiting',
                'executed' => 'Executed',
                'settled' => 'Settled',
                'completed' => 'Completed',
                'closed' => 'Closed',
                'cancelled' => 'Cancelled',
                'failed' => 'Failed',
            ];

            $typeOptions = [
                'all' => 'Semua',
                LegalAction::TYPE_SOMASI => 'Somasi',
                LegalAction::TYPE_HT_EXECUTION => 'HT Execution',
                LegalAction::TYPE_FIDUSIA_EXEC => 'Fidusia',
                LegalAction::TYPE_CIVIL_LAWSUIT => 'Gugatan Perdata',
                LegalAction::TYPE_PKPU_BANKRUPTCY => 'PKPU/Pailit',
                LegalAction::TYPE_CRIMINAL_REPORT => 'Pidana',
            ];

            $handlerOptions = [
                'all' => 'Semua',
                'internal' => 'Internal',
                'external' => 'External',
            ];

            $sortOptions = [
                'newest' => 'Terbaru',
                'oldest' => 'Terlama',
            ];

        $stats = compact('total','open','submitted','scheduled','closed');

        // ===== Ringkasan per TIPE (mengikuti filter yang sama) =====
        $typeRows = (clone $q)
            ->selectRaw('action_type as t, COUNT(*) as cnt')
            ->groupBy('t')
            ->pluck('cnt', 't');

        // Mapping label tipe (samakan dengan yang di tabel)
        $typeLabels = [
            LegalAction::TYPE_SOMASI         => 'Somasi',
            LegalAction::TYPE_HT_EXECUTION   => 'HT Execution',
            LegalAction::TYPE_FIDUSIA_EXEC   => 'Fidusia',
            LegalAction::TYPE_CIVIL_LAWSUIT  => 'Gugatan Perdata',
            LegalAction::TYPE_PKPU_BANKRUPTCY=> 'PKPU/Pailit',
            LegalAction::TYPE_CRIMINAL_REPORT=> 'Pidana',
        ];

        // Susun jadi array siap render
        $typeSummary = collect($typeLabels)
            ->map(function ($label, $key) use ($typeRows) {
                return [
                    'key'   => $key,
                    'label' => $label,
                    'count' => (int) ($typeRows[$key] ?? 0),
                ];
            })
            ->values();

        // Tambahan: tipe lain yang tidak terdaftar (kalau ada)
        $knownKeys = array_keys($typeLabels);
        $unknown = collect($typeRows)
            ->reject(fn($v, $k) => in_array($k, $knownKeys, true))
            ->map(function ($cnt, $k) {
                return [
                    'key'   => (string) $k,
                    'label' => strtoupper((string) $k),
                    'count' => (int) $cnt,
                ];
            })
            ->values();

        $typeSummary = $typeSummary->concat($unknown);

        // Untuk bar chart: cari max biar bisa hitung persen lebar
        $typeMax = (int) $typeSummary->max('count') ?: 1;

        return view('legal.actions.index', compact(
            'actions',
            'stats',
            'statusOptions','typeOptions','handlerOptions','sortOptions',
            'typeSummary','typeMax'
        ));

    }

    /**
     * DETAIL LEGAL ACTION
     */
    public function show(LegalAction $action, LegalActionStatusService $svc)
    {
        $this->authorize('view', $action);

        // ✅ kalau HT Execution, jangan pakai view generik
        if (($action->action_type ?? '') === LegalAction::TYPE_HT_EXECUTION) {
            return redirect()->route('legal-actions.ht.show', [
                'action' => $action->id,
                'tab'    => request('tab', 'summary'),
            ]);
        }

        $action->load([
            'legalCase.nplCase',
            'statusLogs.changer',
            'documents.uploader',
            'costs',
            'shipment',
            'events' => fn ($q) => $q
                ->orderByRaw("FIELD(status,'scheduled','done','cancelled')")
                ->orderBy('event_at'),
        ]);

        // Allowed transitions (dropdown update status)
        $allowedFlow = $svc->allowedTransitions($action);

        $allowed = array_values(array_filter($allowedFlow, function ($to) use ($action) {
            return auth()->user()?->can('updateStatus', [$action, $to]);
        }));

        // =========================
        // Progress khusus SOMASI
        // =========================
        $progress = null;

        if (($action->action_type ?? '') === 'somasi') {

            $getEventAt = function (string $type) use ($action) {
                $ev = $action->events?->firstWhere('event_type', $type);
                return $ev?->event_at;
            };

            $meta   = (array) ($action->meta ?? []);
            $somasi = (array) ($meta['somasi'] ?? []);

            $receiptStatus = $somasi['receipt_status'] ?? null;

            $hasDraftDoc = false;
            if ($action->relationLoaded('documents')) {
                $hasDraftDoc = $action->documents
                    ->where('doc_type', 'somasi_draft')
                    ->isNotEmpty();
            }

            $sentAtFromMeta     = !empty($somasi['sent_at']) ? \Carbon\Carbon::parse($somasi['sent_at']) : null;
            $receivedAtFromMeta = !empty($somasi['received_at']) ? \Carbon\Carbon::parse($somasi['received_at']) : null;

            $progress = [
                'draft_at'      => $action->created_at,
                'has_draft_doc' => $hasDraftDoc,

                'delivery_method'  => $somasi['delivery_method'] ?? null,
                'courier_name'     => $somasi['courier_name'] ?? null,
                'tracking_no'      => $somasi['tracking_no'] ?? null,
                'delivery_address' => $somasi['delivery_address'] ?? null,
                'shipping_note'    => $somasi['shipping_note'] ?? null,

                'receipt_status'    => $receiptStatus,
                'received_note'     => $somasi['received_note'] ?? null,
                'receiver_name'     => $somasi['receiver_name'] ?? null,
                'receiver_relation' => $somasi['receiver_relation'] ?? null,
                'return_reason'     => $somasi['return_reason'] ?? null,

                'sent_at'     => $sentAtFromMeta ?: $getEventAt('somasi_sent'),
                'received_at' => $receivedAtFromMeta ?: $getEventAt('somasi_received'),
                'deadline_at' => $getEventAt('somasi_deadline'),

                'responded_at'   => $getEventAt('somasi_responded'),
                'no_response_at' => $getEventAt('somasi_no_response'),
            ];

            // somasi docs (bukti)
            $somasiDocs = collect();
            if ($action->relationLoaded('documents')) {
                $somasiDocs = $action->documents->whereIn('doc_type', [
                    \App\Models\LegalDocument::DOC_SOMASI_SHIPPING_RECEIPT,
                    \App\Models\LegalDocument::DOC_SOMASI_TRACKING_SCREENSHOT,
                    \App\Models\LegalDocument::DOC_SOMASI_POD,
                    \App\Models\LegalDocument::DOC_SOMASI_RETURN_PROOF,
                ])->values();
            }
            $progress['somasi_docs'] = $somasiDocs;

            // Fallback sent_at
            if (empty($progress['sent_at'])) {
                $progress['sent_at'] = $action->shipment?->sent_at
                    ?? $action->shipment?->created_at
                    ?? null;

                if (empty($progress['sent_at']) && $action->relationLoaded('statusLogs')) {
                    $candidate = $action->statusLogs
                        ->whereIn('to_status', ['sent','submitted','received','waiting','completed'])
                        ->sortBy(fn ($l) => $l->changed_at ?? $l->created_at)
                        ->first();

                    $progress['sent_at'] = $candidate?->changed_at ?? $candidate?->created_at ?? null;
                }

                if (empty($progress['sent_at']) && !empty($progress['received_at'])) {
                    $progress['sent_at'] = $progress['received_at'];
                }
            }

            // Fallback received_at
            if (empty($progress['received_at']) && in_array($receiptStatus, ['received', 'delivered_tracking'], true)) {
                $progress['received_at'] =
                    $getEventAt('somasi_received')
                    ?? $getEventAt('somasi_delivered_tracking')
                    ?? $action->updated_at;
            }

            // Derive state
            $state = 'draft';
            if (!empty($progress['sent_at'])) $state = 'sent';

            if (!empty($progress['received_at']) || in_array($receiptStatus, ['delivered_tracking','received','returned','unknown'], true)) {
                $state = 'received';
            }

            $hasFinal = !empty($progress['responded_at']) || !empty($progress['no_response_at']);
            if (!$hasFinal && !empty($progress['sent_at'])) {
                $state = (!empty($progress['received_at']) || in_array($receiptStatus, ['delivered_tracking','received','returned','unknown'], true))
                    ? 'waiting'
                    : 'sent';
            }

            if ($hasFinal) $state = 'completed';
            if (in_array($action->status, ['failed','cancelled'], true)) $state = $action->status;

            $progress['state'] = $state;
        }

        // Logs timeline
        $logs = $action->statusLogs()
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get();

        // derive_from / derive_to
        $prevTo = null;
        foreach ($logs as $log) {
            $log->derived_from = $log->from_status ?? $prevTo;
            $log->derived_to   = $log->to_status;
            if (!empty($log->derived_to)) $prevTo = $log->derived_to;
        }

        $nextFrom = null;
        for ($i = $logs->count() - 1; $i >= 0; $i--) {
            $log = $logs[$i];
            if (empty($log->derived_to) && !empty($nextFrom)) {
                $log->derived_to = $nextFrom;
            }
            if (!empty($log->derived_from)) $nextFrom = $log->derived_from;
        }

        if ($logs->count() > 0) {
            $last = $logs->last();
            if (empty($last->derived_to)) {
                $last->derived_to = (string) $action->status;
            }
        }

        // ✅ Checklist admin (pakai action yang benar, tidak overwrite)
        $checklist = LegalAdminChecklist::where('legal_action_id', $action->id)
            ->orderBy('sort_order')
            ->get();

        return view('legal.actions.show', [
            'action'    => $action,
            'allowed'   => $allowed,
            'progress'  => $progress,
            'logs'      => $logs,
            'checklist' => $checklist,
        ]);
    }

    /**
     * UPDATE DATA NON-STATUS
     */
    public function update(Request $request, LegalAction $action)
    {
        $this->authorize('update', $action);

        // NOTE: kalau input recovery_amount pakai format rupiah (1.000.000),
        // lebih aman validasi string lalu normalize sendiri. Di sini aku buat fleksibel:
        $validated = $request->validate([
            'external_ref_no'      => ['nullable','string','max:255'],
            'external_institution' => ['nullable','string','max:255'],

            'handler_type'  => ['nullable','in:internal,external'],
            'law_firm_name' => [
                Rule::requiredIf(fn() => $request->input('handler_type') === 'external'),
                'nullable','string','max:255',
            ],
            'handler_name'  => ['nullable','string','max:255'],
            'handler_phone' => ['nullable','string','max:30'],

            'summary' => ['nullable','string','max:255'],
            'notes'   => ['nullable','string'],

            'result_type'     => ['nullable','in:paid,partial,reject,no_response'],
            'recovery_amount' => ['nullable'],  // kita normalize manual (bisa "1.000.000")
            'recovery_date'   => ['nullable','date'],
        ], [
            'law_firm_name.required' => 'Law Firm wajib diisi jika Handler Type = External.',
        ]);

        // normalize string
        foreach ([
            'external_ref_no','external_institution',
            'handler_type','law_firm_name','handler_name','handler_phone',
            'summary','notes','result_type'
        ] as $f) {
            if (array_key_exists($f, $validated)) {
                $validated[$f] = is_string($validated[$f]) ? trim($validated[$f]) : $validated[$f];
                if ($validated[$f] === '') $validated[$f] = null;
            }
        }

        // INTERNAL => law_firm_name null
        if (($validated['handler_type'] ?? null) !== 'external') {
            $validated['law_firm_name'] = null;
        }

        // result kosong => recovery null
        if (empty($validated['result_type'])) {
            $validated['recovery_date'] = null;
            $validated['recovery_amount'] = null;
        }

        // normalize recovery_amount sekali saja
        if (array_key_exists('recovery_amount', $validated) && $validated['recovery_amount'] !== null) {
            $raw = trim((string) $validated['recovery_amount']);
            if ($raw === '') {
                $validated['recovery_amount'] = null;
            } else {
                $raw = str_replace([' ', '.'], '', $raw); // hapus ribuan
                $raw = str_replace(',', '.', $raw);       // koma desimal
                $validated['recovery_amount'] = is_numeric($raw) ? (float) $raw : null;
            }
        }

        $action->update($validated);

        return redirect()
            ->to(route('legal-actions.show', $action) . '?tab=overview')
            ->with('success', 'Data tindakan legal berhasil diperbarui.');
    }

    /**
     * UPDATE STATUS LEGAL ACTION
     */
    public function updateStatus(Request $request, LegalAction $action, LegalActionStatusService $svc)
    {
        $backToStatusTab = route('legal-actions.show', $action) . '?tab=status';

        $toStatus = strtolower(trim((string) $request->input('to_status', '')));
        $request->merge(['to_status' => $toStatus]);

        $allowedFlow = $svc->allowedTransitions($action);
        if (empty($allowedFlow)) {
            return redirect($backToStatusTab)
                ->withErrors(['to_status' => 'Tidak ada transisi status yang diizinkan dari status saat ini.'])
                ->withInput();
        }

        $this->authorize('updateStatus', [$action, $toStatus]);

        $validated = $request->validate([
            'to_status'  => ['required', 'string', 'max:30', Rule::in($allowedFlow)],
            'remarks'    => ['nullable', 'string', 'max:2000'],
            'return_url' => ['nullable', 'string', 'max:2000'],
        ], [
            'to_status.in' => 'Status tujuan tidak valid untuk status saat ini.',
        ]);

        if (($action->status ?? '') === $validated['to_status']) {
            return redirect($backToStatusTab)
                ->withErrors(['to_status' => 'Status tujuan sama dengan status saat ini.'])
                ->withInput();
        }

        $fromStatus = (string) ($action->status ?? '');
        $remarks    = $validated['remarks'] ?? null;

        $type = (string) ($action->action_type ?? '');
        $isHt = ($type === LegalAction::TYPE_HT_EXECUTION);

        // redirect target (HT punya halaman sendiri)
        $tab = $isHt
            ? match ($validated['to_status']) {
                'draft'       => 'summary',
                'prepared'    => 'execution',
                'submitted'   => 'documents',
                'in_progress' => 'timeline',
                'executed'    => 'timeline',
                'settled'     => 'summary',
                'closed'      => 'summary',
                'cancelled'   => 'summary',
                'failed'      => 'timeline',
                default       => 'summary',
            }
            : 'status';

        $redirectTo = $isHt
            ? route('legal-actions.ht.show', $action) . '?tab=' . $tab
            : route('legal-actions.show', $action) . '?tab=' . $tab;

        $returnUrl = trim((string) ($validated['return_url'] ?? ''));
        if ($returnUrl) {
            $redirectTo = $isHt
                ? (str_contains($returnUrl, '/legal-actions/'.$action->id.'/ht') ? $returnUrl : $redirectTo)
                : $returnUrl;
        }

        try {
            // 1) transition status + log
            $svc->transition(
                action: $action,
                toStatus: $validated['to_status'],
                changedBy: auth()->id(),
                remarks: $remarks
            );

            // 2) HT event hanya kalau relasi ada & memang HT
            if ($isHt && method_exists($action, 'htEvents')) {
                $action->htEvents()->create([
                    'event_type' => 'status_changed',
                    'event_date' => now()->toDateString(),
                    'ref_no'     => null,
                    'notes'      => "Status berubah ke: {$toStatus}. " . ($remarks ?? ''),
                ]);
            }

            // 3) mirror ke CaseAction (untuk timeline NPL case)
            $action->refresh();
            $action->loadMissing('legalCase');

            $nplCaseId = $action->legalCase?->npl_case_id;

            if ($nplCaseId) {
                $legalType = strtoupper((string) $action->action_type);
                $toUp      = strtoupper((string) $validated['to_status']);
                $fromUp    = strtoupper($fromStatus ?: '-');

                // anti double-click 10 detik
                $recentExists = CaseAction::where('source_system', 'legal_status')
                    ->where('source_ref_id', $action->id)
                    ->where('result', $toUp)
                    ->where('action_at', '>=', now()->subSeconds(10))
                    ->exists();

                if (!$recentExists) {
                    CaseAction::create([
                        'npl_case_id'   => $nplCaseId,
                        'user_id'       => auth()->id(),
                        'source_system' => 'legal_status',
                        'source_ref_id' => $action->id,

                        'action_at'   => now(),
                        'action_type' => 'legal',
                        'description' => "LEGAL {$legalType}: {$fromUp} → {$toUp}"
                            . ($remarks ? "\nCatatan: " . trim((string) $remarks) : ''),
                        'result'      => $toUp,

                        'next_action'     => null,
                        'next_action_due' => null,

                        'meta' => [
                            'legal_action_id' => $action->id,
                            'legal_case_id'   => $action->legal_case_id,
                            'legal_type'      => $action->action_type,
                            'from_status'     => $fromStatus,
                            'to_status'       => $validated['to_status'],
                        ],
                    ]);
                }
            }

            return redirect($redirectTo)
                ->with('success', 'Status tindakan legal berhasil diperbarui.');

        } catch (\DomainException $e) {
            return redirect($redirectTo)
                ->withErrors(['to_status' => $e->getMessage()])
                ->withInput();

        } catch (\Throwable $e) {
            \Log::error('[LEGAL][updateStatus] gagal', [
                'action_id' => $action->id,
                'from'      => $fromStatus,
                'to'        => $validated['to_status'] ?? $toStatus,
                'user_id'   => auth()->id(),
                'error'     => $e->getMessage(),
            ]);

            return redirect($redirectTo)
                ->withErrors(['to_status' => 'Gagal mengubah status. Silakan coba lagi atau hubungi TI.'])
                ->withInput();
        }
    }

    public function htAuditPdf(LegalAction $action)
    {
        $this->authorize('auditHt', $action);

        if (($action->action_type ?? '') !== LegalAction::TYPE_HT_EXECUTION) abort(404);

        $finalAuction = null;
        if (method_exists($action, 'htAuctions')) {
            $finalAuction = $action->htAuctions()
                ->whereNotNull('auction_date')
                ->whereIn('auction_result', ['laku','tidak_laku'])
                ->orderByDesc('auction_date')
                ->orderByDesc('id')
                ->first();
        }

        $logs = method_exists($action, 'statusLogs')
            ? $action->statusLogs()->orderBy('changed_at')->get()
            : collect();

        $milestones = $logs->map(fn($l) => [
            'from'    => strtoupper($l->from_status ?? '-'),
            'to'      => strtoupper($l->to_status ?? '-'),
            'at'      => $l->changed_at ?? $l->created_at,
            'remarks' => $l->remarks ?? null,
        ])->values();

        $pdf = Pdf::loadView('legal.ht.pdf.audit_summary', [
            'action'       => $action,
            'finalAuction' => $finalAuction,
            'milestones'   => $milestones,
            'generatedAt'  => now(),
            'generatedBy'  => auth()->user(),
        ])->setPaper('A4', 'portrait');

        $filename = "HT_AUDIT_SUMMARY_{$action->id}_" . now()->format('Ymd_His') . ".pdf";
        return $pdf->download($filename);
    }
}
