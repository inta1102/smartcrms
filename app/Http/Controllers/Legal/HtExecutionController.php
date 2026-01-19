<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Models\LegalAction;
use App\Models\Legal\LegalActionHtAuction;
use App\Models\Legal\LegalActionHtDocument;
use App\Models\Legal\LegalActionHtEvent;
use App\Models\Legal\LegalActionHtExecution;
use App\Models\Legal\LegalActionHtUnderhandSale;
use App\Models\LegalAdminChecklist;
use App\Models\CaseAction;
use App\Services\Legal\LegalActionReadinessService;
use App\Services\Legal\HtExecutionStatusService;
use App\Services\Legal\LegalActionStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class HtExecutionController extends Controller
{
    /**
     * Show halaman Eksekusi HT (tab ringkasan/data/dokumen/timeline).
     */
    public function show(Request $request, LegalAction $action)
    {
        $this->authorize('view', $action);
        $this->assertHtExecution($action);

        $tab = (string) $request->query('tab', '');
        if ($tab === '') {
            return redirect()->to(route('legal-actions.ht.show', $action) . '?tab=summary');
        }

        $allowedTabs = ['summary', 'execution', 'documents', 'timeline'];
        if (!in_array($tab, $allowedTabs, true)) $tab = 'summary';

        $action->loadMissing([
            'legalCase',
            'legalCase.nplCase.loanAccount',
            'htExecution',
            'htDocuments',
            'htAuctions',
            'statusLogs',
            'htEvents',
            'htUnderhandSale',
        ]);

        // ✅ ensure htExecution row exists (biar summary gak '-' terus)
        if (!$action->htExecution) {
            LegalActionHtExecution::create([
                'legal_action_id' => $action->id,
                'method' => null, // sekarang boleh null (opsi B)
            ]);

            $action->unsetRelation('htExecution');
            $action->load('htExecution');
        }
        $readOnly   = (bool) optional($action->htExecution)->locked_at;
        $eventTypes = config('ht_events.types', []);

        $this->ensureChecklistSeeded($action);

        $documents = $action->htDocuments()->latest()->get();

        $checklist = LegalAdminChecklist::where('legal_action_id', $action->id)
            ->orderBy('sort_order')
            ->get();

        // Allowed transitions dari service
        $statusSvc = app(LegalActionStatusService::class);
        $allowed   = $statusSvc->allowedTransitions($action);

        return view('legal.ht.show', compact(
            'action','readOnly','tab','eventTypes','checklist','documents','allowed'
        ));
    }

    /**
     * Create/Update detail eksekusi HT.
     * - Jika htExecution belum ada, create.
     * - Jika sudah lock, tidak boleh ubah field inti.
     */
    public function upsertExecution(Request $request, LegalAction $action)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        $action->loadMissing('htExecution');

        $exec   = $action->htExecution;
        $locked = (bool) optional($exec)->locked_at;

        if ($locked) {
            $rules = [
                'notes' => ['nullable', 'string', 'max:4000'],
            ];
        } else {
            $rules = [
                'method'                => ['nullable', 'string', Rule::in([
                    LegalActionHtExecution::METHOD_PARATE,
                    LegalActionHtExecution::METHOD_PN,
                    LegalActionHtExecution::METHOD_BAWAH_TANGAN,
                ])],
                'basis_default_at'      => ['nullable', 'date'],
                'collateral_summary'    => ['nullable', 'string'],
                'ht_deed_no'            => ['nullable', 'string', 'max:100'],
                'ht_cert_no'            => ['nullable', 'string', 'max:100'],
                'land_cert_type'        => ['nullable', 'string', 'max:30'],
                'land_cert_no'          => ['nullable', 'string', 'max:100'],
                'owner_name'            => ['nullable', 'string', 'max:255'],
                'object_address'        => ['nullable', 'string'],
                'appraisal_value'       => ['nullable', 'numeric', 'min:0'],
                'outstanding_at_start'  => ['nullable', 'numeric', 'min:0'],
                'notes'                 => ['nullable', 'string', 'max:4000'],
            ];
        }

        $data = $request->validate($rules);

        // upsert
        if (!$exec) {
            $exec = new LegalActionHtExecution();
            $exec->legal_action_id = $action->id;
        }

        $exec->fill($data);
        $exec->save();

        // refresh relation biar UI langsung update
        $action->unsetRelation('htExecution');

        return back()->with('success', 'Data Eksekusi HT berhasil disimpan.');
    }

    public function upsert(Request $request, LegalAction $action)
    {
        return $this->upsertExecution($request, $action);
    }

    /**
     * Upload dokumen (create doc).
     */
    public function storeDocument(Request $request, LegalAction $action)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        $this->ensureNotLockedForMutation($action, 'Dokumen tidak bisa ditambah karena data sudah terkunci.');

        $data = $request->validate([
            'doc_type'    => ['required', 'string', 'max:50'],
            'doc_no'      => ['nullable', 'string', 'max:100'],
            'doc_date'    => ['nullable', 'date'],
            'issued_by'   => ['nullable', 'string', 'max:255'],
            'remarks'     => ['nullable', 'string', 'max:4000'],
            'is_required' => ['nullable', 'boolean'],
            'file'        => ['nullable', 'file', 'max:10240'], // 10MB
        ]);

        $doc = new LegalActionHtDocument();
        $doc->legal_action_id = $action->id;
        $doc->doc_type        = $data['doc_type'];
        $doc->doc_no          = $data['doc_no'] ?? null;
        $doc->doc_date        = $data['doc_date'] ?? null;
        $doc->issued_by       = $data['issued_by'] ?? null;
        $doc->remarks         = $data['remarks'] ?? null;
        $doc->is_required     = (bool) ($data['is_required'] ?? false);

        if ($request->hasFile('file')) {
            // simpan ke disk local (tanpa symlink)
            $path = $this->storeUploadedFile(
                file: $request->file('file'),
                action: $action,
                folder: 'documents',
                disk: 'local',
                prefix: 'doc'
            );

            $doc->file_path = $path;
            $doc->file_disk = 'local';
            $doc->status    = LegalActionHtDocument::STATUS_UPLOADED;
        } else {
            $doc->status = LegalActionHtDocument::STATUS_DRAFT;
        }

        $doc->save();

        return back()->with('success', 'Dokumen berhasil ditambahkan.');
    }

    /**
     * Update meta dokumen + optional upload ulang file.
     */
    public function updateDocumentMeta(Request $request, LegalAction $action, LegalActionHtDocument $doc)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        if ((int)$doc->legal_action_id !== (int)$action->id) abort(404);

        if (in_array(strtolower((string)$action->status), [
            LegalAction::STATUS_CLOSED,
            LegalAction::STATUS_CANCELLED,
        ], true)) {
            abort(403, 'Data sudah final. Tidak bisa diubah.');
        }

        $this->ensureNotLockedForMutation($action, 'Dokumen tidak bisa diubah karena data sudah terkunci.');

        $data = $request->validate([
            'doc_no'      => ['nullable', 'string', 'max:100'],
            'doc_date'    => ['nullable', 'date'],
            'issued_by'   => ['nullable', 'string', 'max:255'],
            'remarks'     => ['nullable', 'string', 'max:4000'],
            'is_required' => ['nullable', 'boolean'],
            'file'        => ['nullable', 'file', 'max:10240'],
        ]);

        $doc->doc_no    = $data['doc_no'] ?? $doc->doc_no;
        $doc->doc_date  = $data['doc_date'] ?? $doc->doc_date;
        $doc->issued_by = $data['issued_by'] ?? $doc->issued_by;
        $doc->remarks   = $data['remarks'] ?? $doc->remarks;

        if (array_key_exists('is_required', $data)) {
            $doc->is_required = (bool) $data['is_required'];
        }

        if ($request->hasFile('file')) {
            $disk = $doc->file_disk ?: 'local';

            // hapus file lama (di disk yang benar)
            if (!empty($doc->file_path) && Storage::disk($disk)->exists($doc->file_path)) {
                Storage::disk($disk)->delete($doc->file_path);
            }

            $path = $this->storeUploadedFile(
                file: $request->file('file'),
                action: $action,
                folder: 'documents',
                disk: 'local',
                prefix: 'doc'
            );

            $doc->file_path = $path;
            $doc->file_disk = 'local';
            $doc->status    = LegalActionHtDocument::STATUS_UPLOADED;

            // kalau sebelumnya verified, reset
            $doc->verified_by  = null;
            $doc->verified_at  = null;
            $doc->verify_notes = null;
        }

        $doc->save();

        return back()->with('success', 'Dokumen berhasil diperbarui.');
    }

    /**
     * Verify / Reject dokumen (Supervisor).
     */
    public function verifyDocument(Request $request, LegalAction $action, LegalActionHtDocument $doc)
    {
        $this->authorize('verifyDocument', $action);
        $this->assertHtExecution($action);

        if ((int)$doc->legal_action_id !== (int)$action->id) abort(404);

        $data = $request->validate([
            'status'       => ['required', Rule::in([
                LegalActionHtDocument::STATUS_VERIFIED,
                LegalActionHtDocument::STATUS_REJECTED
            ])],
            'verify_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // verify boleh walau locked
        $doc->status      = $data['status'];
        $doc->verified_by = auth()->id();
        $doc->verified_at = now();
        $doc->verify_notes = $data['verify_notes'] ?? null;
        $doc->save();

        return back()->with('success', 'Verifikasi dokumen tersimpan.');
    }

    /**
     * Delete dokumen.
     */
    public function deleteDocument(LegalAction $action, LegalActionHtDocument $doc)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        if ((int)$doc->legal_action_id !== (int)$action->id) abort(404);

        $this->ensureNotLockedForMutation($action, 'Dokumen tidak bisa dihapus karena data sudah terkunci.');

        $disk = $doc->file_disk ?: 'local';
        if ($doc->file_path && Storage::disk($disk)->exists($doc->file_path)) {
            Storage::disk($disk)->delete($doc->file_path);
        }

        $doc->delete();

        return back()->with('success', 'Dokumen dihapus.');
    }

    /**
     * View dokumen inline (PDF/image).
     */
    public function viewDocument(LegalAction $action, LegalActionHtDocument $doc)
    {
        $this->authorize('view', $action);
        $this->assertHtExecution($action);

        if ((int) $doc->legal_action_id !== (int) $action->id) abort(404);
        if (empty($doc->file_path)) abort(404);

        $disk = $doc->file_disk ?: 'local';
        if (!Storage::disk($disk)->exists($doc->file_path)) abort(404);

        return Storage::disk($disk)->response($doc->file_path, null, [
            'Content-Disposition'    => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Add timeline event (manual).
     */
    public function storeEvent(Request $request, LegalAction $action)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        $allowedTypes = [
            'submit_kpknl',
            'pengumuman_lelang',
            'penetapan_jadwal',
            'klarifikasi',
            'kunjungan_lapangan',
            'pemberitahuan_debitur',
            'status_changed',
            'custom',
        ];

        $data = $request->validate([
            'event_type'  => ['required', 'string', 'max:50', Rule::in($allowedTypes)],
            'event_label' => ['nullable', 'string', 'max:255'],
            'event_date'  => ['required', 'date_format:Y-m-d H:i'],
            'ref_no'      => ['nullable', 'string', 'max:100'],
            'notes'       => ['nullable', 'string', 'max:4000'],
        ]);

        if ($data['event_type'] === 'custom' && empty($data['event_label'])) {
            return back()->withErrors(['event_label' => 'Label custom wajib diisi jika event_type = custom.'])->withInput();
        }

        if ($data['event_type'] === 'penetapan_jadwal' && empty($data['ref_no'])) {
            return back()->withErrors(['ref_no' => 'Nomor surat/penetapan KPKNL sebaiknya diisi untuk Penetapan jadwal lelang.'])->withInput();
        }

        $labelMap = [
            'submit_kpknl'          => 'Submit berkas ke KPKNL',
            'pengumuman_lelang'     => 'Pengumuman lelang',
            'penetapan_jadwal'      => 'Penetapan jadwal lelang',
            'klarifikasi'           => 'Klarifikasi / komunikasi',
            'kunjungan_lapangan'    => 'Kunjungan lapangan',
            'pemberitahuan_debitur' => 'Pemberitahuan ke debitur',
            'status_changed'        => 'Perubahan status',
            'custom'                => 'Lainnya',
        ];

        $eventType  = $data['event_type'];
        $eventLabel = $eventType === 'custom'
            ? ($data['event_label'] ?? 'Custom')
            : ($labelMap[$eventType] ?? strtoupper($eventType));

        $eventAt = \Carbon\Carbon::parse($data['event_date']);

        DB::transaction(function () use ($action, $data, $eventType, $eventLabel, $eventAt) {

            // A) Simpan event
            $ev = new LegalActionHtEvent();
            $ev->legal_action_id = $action->id;
            $ev->event_type      = $eventType;
            $ev->event_at        = $eventAt;
            $ev->ref_no          = $data['ref_no'] ?? null;
            $ev->notes           = $data['notes'] ?? null;
            $ev->created_by      = auth()->id();
            $ev->payload         = [
                'event_label' => $eventType === 'custom' ? ($data['event_label'] ?? null) : null,
                'display'     => $eventLabel,
            ];
            $ev->save();

            // B) Pantulkan ke case_actions
            $nplCaseId = $action->legalCase?->npl_case_id;
            if ($nplCaseId) {
                CaseAction::updateOrCreate(
                    [
                        'source_system' => 'legal_ht_event',
                        'source_ref_id' => $ev->id, // ✅ event id
                    ],
                    [
                        'npl_case_id' => $nplCaseId,
                        'user_id'     => auth()->id(),
                        'action_at'   => $ev->event_at ?? now(),
                        'action_type' => 'legal',
                        'description' => "LEGAL HT_EXECUTION: {$eventLabel}"
                                        ."\nRef: ".($ev->ref_no ?? '-')
                                        .($ev->notes ? ("\nCatatan: ".$ev->notes) : ''),
                        'result'      => strtoupper($ev->event_type),
                        'meta'        => [
                            'legal_action_id' => $action->id,
                            'ht_event_id'     => $ev->id,
                            'event_type'      => $ev->event_type,
                        ],
                    ]
                );
            }
        });

        return redirect()->to(route('legal-actions.ht.show', $action) . '?tab=timeline')
            ->with('success', 'Event timeline ditambahkan dan dicatat di Timeline Penanganan.');
    }

    public function deleteEvent(LegalAction $action, LegalActionHtEvent $event)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        if ((int)$event->legal_action_id !== (int)$action->id) abort(404);

        $eventId = (int) $event->id;

        DB::transaction(function () use ($action, $event, $eventId) {

            $nplCaseId = $action->legalCase?->npl_case_id;
            if ($nplCaseId) {
                // ✅ sesuai storeEvent: source_system=legal_ht_event, source_ref_id=event_id
                CaseAction::where('npl_case_id', $nplCaseId)
                    ->where('source_system', 'legal_ht_event')
                    ->where('source_ref_id', $eventId)
                    ->delete();
            }

            $event->delete();
        });

        return back()->with('success', 'Event dihapus dan dicatat di Timeline Penanganan.');
    }

    /**
     * Create auction attempt.
     */
    public function storeAuction(Request $request, LegalAction $action)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        $status = strtolower((string) $action->status);

        if ($status !== LegalAction::STATUS_SCHEDULED) {
            return back()->withErrors([
                'auction' => 'Attempt lelang hanya bisa ditambahkan saat status SCHEDULED (jadwal sudah keluar).'
            ]);
        }

        $exec = $action->htExecution;
        if ($exec && $exec->method === LegalActionHtExecution::METHOD_BAWAH_TANGAN) {
            abort(422, 'Metode bawah tangan tidak menggunakan lelang.');
        }

        $svc = app(LegalActionStatusService::class);

        $data = $request->validateWithBag('auction', [
            'kpknl_office'    => ['nullable','string','max:255'],
            'registration_no' => ['nullable','string','max:255'],
            'limit_value'     => ['nullable'],
            'auction_date'    => ['required','date'],
            'auction_result'  => ['required', Rule::in(['laku','tidak_laku','batal','tunda'])],
            'sold_value'      => ['nullable'],
            'winner_name'     => ['nullable','string','max:255'],
            'settlement_date' => ['nullable','date'],
            'risalah_file'    => ['nullable','file','mimes:pdf,jpg,jpeg,png','max:5120'],
            'notes'           => ['nullable','string','max:4000'],
        ]);

        $toNumber = fn($v) => ($v === null || $v === '') ? null : (float) (preg_replace('/[^\d]/', '', (string)$v) ?: 0);

        $data['limit_value'] = ($data['limit_value'] ?? null) ? $toNumber($data['limit_value']) : null;
        $data['sold_value']  = ($data['sold_value'] ?? null)  ? $toNumber($data['sold_value'])  : null;

        if (($data['auction_result'] ?? null) === 'laku') {
            $errs = [];

            if (empty($data['sold_value']) || (float)$data['sold_value'] < 1) $errs[] = "Hasil LAKU: sold_value wajib diisi dan > 0.";
            if (empty($data['settlement_date'])) $errs[] = "Hasil LAKU: tanggal pelunasan (settlement_date) wajib diisi.";
            if (!$request->hasFile('risalah_file')) $errs[] = "Hasil LAKU: risalah lelang wajib diupload.";

            if ($errs) return back()->withErrors(['auction' => implode(' ', $errs)])->withInput();
        }

        $auction = DB::transaction(function () use ($request, $action, $data, $svc) {

            $nextAttempt = (int) ($action->htAuctions()->lockForUpdate()->max('attempt_no') ?? 0) + 1;

            $auction = new LegalActionHtAuction();
            $auction->legal_action_id  = $action->id;
            $auction->attempt_no       = $nextAttempt;

            $auction->kpknl_office     = $data['kpknl_office'] ?? null;
            $auction->registration_no  = $data['registration_no'] ?? null;
            $auction->limit_value      = $data['limit_value'] ?? null;

            $auction->auction_date     = $data['auction_date'];
            $auction->auction_result   = $data['auction_result'];

            $auction->sold_value       = $data['sold_value'] ?? null;
            $auction->winner_name      = $data['winner_name'] ?? null;
            $auction->settlement_date  = $data['settlement_date'] ?? null;

            $auction->notes            = $data['notes'] ?? null;

            // simpan risalah ke local
            if ($request->hasFile('risalah_file')) {
                $path = $this->storeUploadedFile(
                    file: $request->file('risalah_file'),
                    action: $action,
                    folder: 'auctions',
                    disk: 'local',
                    prefix: 'risalah',
                    attemptNo: $nextAttempt
                );
                $auction->risalah_file_path = $path;
                $auction->risalah_file_disk = 'local'; // kalau kolomnya ada
            }

            $auction->save();

            // Auto transition
            if ($auction->auction_result === 'laku') {
                if (strtolower((string)$action->status) === 'scheduled') {
                    $svc->transition($action, 'executed', auth()->id(), 'Auto: Auction LAKU');
                }

                $settlementReady = (
                    !empty($auction->sold_value)
                    && !empty($auction->settlement_date)
                    && !empty($auction->risalah_file_path)
                );

                if ($settlementReady) {
                    $freshStatus = strtolower((string) $action->fresh()->status);
                    if (in_array($freshStatus, ['scheduled','executed'], true)) {
                        $svc->transition($action, 'settled', auth()->id(), 'Auto: Settlement lengkap (tgl + risalah)');
                    }
                }
            }

            // Timeline Penanganan
            $nplCaseId = $action->legalCase?->npl_case_id;
            if ($nplCaseId) {
                CaseAction::updateOrCreate(
                    [
                        'source_system' => 'legal_auction',
                        'source_ref_id' => $auction->id,
                    ],
                    [
                        'npl_case_id' => $nplCaseId,
                        'user_id'     => auth()->id(),
                        'action_at'   => $auction->auction_date ? \Carbon\Carbon::parse($auction->auction_date) : now(),
                        'action_type' => 'legal',
                        'description' => "LEGAL HT_EXECUTION: ATTEMPT LELANG #{$auction->attempt_no}"
                                        ."\nKPKNL: ".($auction->kpknl_office ?? '-')
                                        ."\nReg: ".($auction->registration_no ?? '-')
                                        ."\nHasil: ".strtoupper($auction->auction_result ?? '-')
                                        .($auction->sold_value ? ("\nNilai Laku: ".$auction->sold_value) : '')
                                        .($auction->winner_name ? ("\nPemenang: ".$auction->winner_name) : ''),
                        'result'      => strtoupper($auction->auction_result ?? 'ATTEMPT'),
                        'meta'        => [
                            'legal_action_id' => $action->id,
                            'auction_id'      => $auction->id,
                            'attempt_no'      => $auction->attempt_no,
                            'settlement_date' => $auction->settlement_date,
                            'risalah_file'    => $auction->risalah_file_path,
                        ],
                    ]
                );
            }

            // Auto HT Event
            $action->htEvents()->create([
                'event_type' => 'auction_attempt',
                'event_at'   => $auction->auction_date,
                'ref_no'     => $auction->registration_no,
                'notes'      => $auction->notes,
                'created_by' => auth()->id(),
                'payload'    => [
                    'attempt_no'      => $auction->attempt_no,
                    'result'          => $auction->auction_result,
                    'kpknl_office'    => $auction->kpknl_office,
                    'limit_value'     => $auction->limit_value,
                    'sold_value'      => $auction->sold_value,
                    'winner_name'     => $auction->winner_name,
                    'settlement_date' => $auction->settlement_date,
                    'risalah_file'    => $auction->risalah_file_path,
                ],
            ]);

            return $auction;
        });

        return back()->with('success', "Attempt lelang #{$auction->attempt_no} berhasil ditambahkan.");
    }

    public function updateAuction(Request $request, LegalAction $action, LegalActionHtAuction $auction)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        if ((int)$auction->legal_action_id !== (int)$action->id) abort(404);

        $status = strtolower((string)$action->status);
        if (!in_array($status, [LegalAction::STATUS_SCHEDULED, LegalAction::STATUS_EXECUTED], true)) {
            return back()->withErrors(['auction' => 'Attempt lelang hanya bisa diubah saat status SCHEDULED atau EXECUTED.']);
        }
        if (in_array($status, [LegalAction::STATUS_CLOSED, LegalAction::STATUS_CANCELLED], true)) {
            abort(403, 'Attempt lelang terkunci karena status sudah final.');
        }

        $data = $request->validateWithBag('auction', [
            'kpknl_office'     => ['nullable','string','max:255'],
            'registration_no'  => ['nullable','string','max:150'],
            'limit_value'      => ['nullable'],
            'auction_date'     => ['required','date'],
            'auction_result'   => ['required', Rule::in(['laku','tidak_laku','batal','tunda'])],
            'sold_value'       => ['nullable'],
            'winner_name'      => ['nullable','string','max:255'],
            'settlement_date'  => ['nullable','date'],
            'risalah_file'     => ['nullable','file','mimes:pdf,jpg,jpeg,png','max:5120'],
            'notes'            => ['nullable','string','max:4000'],
        ]);

        $toNumber = fn($v) => ($v === null || $v === '') ? null : (float) (preg_replace('/[^\d]/', '', (string)$v) ?: 0);

        $data['limit_value'] = ($data['limit_value'] ?? null) ? $toNumber($data['limit_value']) : null;
        $data['sold_value']  = ($data['sold_value'] ?? null)  ? $toNumber($data['sold_value'])  : null;

        if (($data['auction_result'] ?? null) === 'laku') {
            $errs = [];
            if (empty($data['sold_value']) || (float)$data['sold_value'] <= 0) $errs[] = "Hasil LAKU: sold_value wajib diisi dan > 0.";
            if (empty($data['settlement_date'])) $errs[] = "Hasil LAKU: settlement_date wajib diisi.";

            // wajib risalah (boleh sudah ada sebelumnya)
            if (!$auction->risalah_file_path && !$request->hasFile('risalah_file')) {
                $errs[] = "Hasil LAKU: risalah lelang wajib diupload.";
            }
            if ($errs) return back()->withErrors(['auction' => implode(' ', $errs)]);
        }

        DB::transaction(function () use ($request, $action, $auction, $data) {

            $auction->fill([
                'kpknl_office'    => $data['kpknl_office'] ?? null,
                'registration_no' => $data['registration_no'] ?? null,
                'limit_value'     => $data['limit_value'] ?? null,
                'auction_date'    => $data['auction_date'],
                'auction_result'  => $data['auction_result'],
                'sold_value'      => $data['sold_value'] ?? null,
                'winner_name'     => $data['winner_name'] ?? null,
                'settlement_date' => $data['settlement_date'] ?? null,
                'notes'           => $data['notes'] ?? null,
            ]);

            if ($request->hasFile('risalah_file')) {
                // delete lama (disk local)
                $disk = $auction->risalah_file_disk ?: 'local';
                if ($auction->risalah_file_path && Storage::disk($disk)->exists($auction->risalah_file_path)) {
                    Storage::disk($disk)->delete($auction->risalah_file_path);
                }

                $path = $this->storeUploadedFile(
                    file: $request->file('risalah_file'),
                    action: $action,
                    folder: 'auctions',
                    disk: 'local',
                    prefix: 'risalah',
                    attemptNo: $auction->attempt_no
                );

                $auction->risalah_file_path = $path;
                $auction->risalah_file_disk = 'local'; // kalau kolomnya ada
            }

            $auction->save();
        });

        return back()->with('success', "Attempt lelang #{$auction->attempt_no} berhasil diperbarui.");
    }

    public function deleteAuction(LegalAction $action, LegalActionHtAuction $auction)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        if ((int) $auction->legal_action_id !== (int) $action->id) abort(404);

        if (in_array(strtolower((string)$action->status), ['settled','closed'], true)) {
            abort(403, 'Attempt lelang terkunci karena status sudah SETTLED/CLOSED.');
        }

        DB::transaction(function () use ($action, $auction) {

            // A) hapus case_actions (sesuai create: source_ref_id = auction_id)
            $nplCaseId = $action->legalCase?->npl_case_id;
            if ($nplCaseId) {
                CaseAction::where('npl_case_id', $nplCaseId)
                    ->where('source_system', 'legal_auction')
                    ->where('source_ref_id', $auction->id)
                    ->delete();
            }

            // B) hapus file risalah (local)
            if ($auction->risalah_file_path) {
                $disk = $auction->risalah_file_disk ?: 'local';
                if (Storage::disk($disk)->exists($auction->risalah_file_path)) {
                    Storage::disk($disk)->delete($auction->risalah_file_path);
                }
            }

            // C) hapus data
            $auction->delete();
        });

        return back()->with('success', 'Attempt lelang berhasil dihapus.');
    }

    /**
     * Create/Update underhand sale (bawah tangan).
     */
    public function upsertUnderhandSale(Request $request, LegalAction $action)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        $exec = $action->htExecution;
        if ($exec && $exec->method !== LegalActionHtExecution::METHOD_BAWAH_TANGAN) {
            abort(422, 'Metode eksekusi bukan bawah tangan.');
        }

        $data = $request->validate([
            'agreement_date'     => ['nullable', 'date'],
            'buyer_name'         => ['nullable', 'string', 'max:255'],
            'sale_value'         => ['nullable', 'numeric', 'min:0'],
            'payment_method'     => ['nullable', 'string', 'max:100'],
            'handover_date'      => ['nullable', 'date'],
            'agreement_file'     => ['nullable', 'file', 'max:10240'],
            'proof_payment_file' => ['nullable', 'file', 'max:10240'],
            'notes'              => ['nullable', 'string', 'max:4000'],
        ]);

        $sale = $action->htUnderhandSale ?: new LegalActionHtUnderhandSale();
        $sale->legal_action_id = $action->id;

        $sale->agreement_date = $data['agreement_date'] ?? $sale->agreement_date;
        $sale->buyer_name     = $data['buyer_name'] ?? $sale->buyer_name;
        $sale->sale_value     = $data['sale_value'] ?? $sale->sale_value;
        $sale->payment_method = $data['payment_method'] ?? $sale->payment_method;
        $sale->handover_date  = $data['handover_date'] ?? $sale->handover_date;
        $sale->notes          = $data['notes'] ?? $sale->notes;

        if ($request->hasFile('agreement_file')) {
            $disk = $sale->agreement_file_disk ?: 'local';
            if ($sale->agreement_file_path && Storage::disk($disk)->exists($sale->agreement_file_path)) {
                Storage::disk($disk)->delete($sale->agreement_file_path);
            }

            $sale->agreement_file_path = $this->storeUploadedFile(
                file: $request->file('agreement_file'),
                action: $action,
                folder: 'underhand',
                disk: 'local',
                prefix: 'agreement'
            );
            $sale->agreement_file_disk = 'local'; // kalau kolomnya ada
        }

        if ($request->hasFile('proof_payment_file')) {
            $disk = $sale->proof_payment_file_disk ?: 'local';
            if ($sale->proof_payment_file_path && Storage::disk($disk)->exists($sale->proof_payment_file_path)) {
                Storage::disk($disk)->delete($sale->proof_payment_file_path);
            }

            $sale->proof_payment_file_path = $this->storeUploadedFile(
                file: $request->file('proof_payment_file'),
                action: $action,
                folder: 'underhand',
                disk: 'local',
                prefix: 'payment'
            );
            $sale->proof_payment_file_disk = 'local'; // kalau kolomnya ada
        }

        $sale->save();

        return back()->with('success', 'Data penjualan bawah tangan tersimpan.');
    }

    /**
     * Update status HT via service.
     */
    public function updateHtStatus(Request $request, LegalAction $action, HtExecutionStatusService $svc)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        $data = $request->validate([
            'to_status' => ['required', 'string', 'max:30'],
            'remarks'   => ['nullable', 'string', 'max:2000'],
        ]);

        $to      = strtolower(trim($data['to_status']));
        $remarks = trim((string)($data['remarks'] ?? '')) ?: null;

        // ✅ Cancel wajib remarks
        if ($to === LegalAction::STATUS_CANCELLED && empty($remarks)) {
            return back()->withErrors(['to_status' => 'Untuk Cancel, alasan (remarks) wajib diisi.']);
        }

        // ✅ Opsi B: method boleh kosong saat draft,
        // tapi WAJIB saat naik ke prepared/submitted (dan seterusnya sesuai rule kamu)
        if (in_array($to, [LegalAction::STATUS_PREPARED, LegalAction::STATUS_SUBMITTED], true)) {
            $action->loadMissing('htExecution');

            $method = $action->htExecution?->method;
            if (!$method) {
                return back()->withErrors([
                    'to_status' => 'Metode Eksekusi wajib diisi sebelum mengubah status ke PREPARED / SUBMITTED.',
                ]);
            }
        }

        // ✅ validasi transisi dari service
        if (!$svc->canTransition($action, $to)) {
            return back()->withErrors(['to_status' => 'Aksi status tidak valid untuk kondisi saat ini.']);
        }

        $errs = $svc->validateTransition($action, $to);
        if (!empty($errs)) {
            return back()->withErrors(['to_status' => implode(' ', $errs)]);
        }

        try {
            DB::transaction(function () use ($action, $to, $remarks) {

                // ✅ lock untuk hindari race condition
                $a = LegalAction::query()
                    ->with(['legalCase', 'htExecution'])
                    ->whereKey($action->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $from = strtolower((string)($a->status ?? LegalAction::STATUS_DRAFT));
                if ($from === $to) return;

                // ✅ double-check Opsi B di dalam transaction (anti bypass / race)
                if (in_array($to, [LegalAction::STATUS_PREPARED, LegalAction::STATUS_SUBMITTED], true)) {
                    $method = $a->htExecution?->method;
                    if (!$method) {
                        abort(422, 'Metode Eksekusi wajib diisi sebelum mengubah status ke PREPARED / SUBMITTED.');
                    }
                }

                $a->status = $to;

                if (in_array($to, [LegalAction::STATUS_CLOSED, LegalAction::STATUS_CANCELLED], true)) {
                    $a->end_at = now();
                }

                $a->save();

                // status logs
                $a->statusLogs()->create([
                    'from_status' => $from,
                    'to_status'   => $to,
                    'remarks'     => $remarks,
                    'changed_by'  => auth()->id(),
                    'changed_at'  => now(),
                ]);

                // auto submit_kpknl (sekali saja)
                if ($to === LegalAction::STATUS_SUBMITTED) {
                    $exists = $a->htEvents()
                        ->where('event_type', 'submit_kpknl')
                        ->exists();

                    if (!$exists) {
                        LegalActionHtEvent::create([
                            'legal_action_id' => $a->id,
                            'event_type'      => 'submit_kpknl',
                            'event_at'        => now(),
                            'notes'           => $remarks,
                            'created_by'      => auth()->id(),
                            'payload'         => ['auto' => true, 'from' => $from, 'to' => $to],
                        ]);
                    }
                }

                // status_changed event (selalu)
                LegalActionHtEvent::create([
                    'legal_action_id' => $a->id,
                    'event_type'      => 'status_changed',
                    'event_at'        => now(),
                    'notes'           => $remarks,
                    'created_by'      => auth()->id(),
                    'payload'         => ['from' => $from, 'to' => $to],
                ]);

                // Timeline Penanganan
                $nplCaseId = $a->legalCase?->npl_case_id;
                if ($nplCaseId) {
                    CaseAction::updateOrCreate(
                        [
                            'source_system' => 'legal_ht',
                            'source_ref_id' => $a->id,
                        ],
                        [
                            'npl_case_id' => $nplCaseId,
                            'user_id'     => auth()->id(),
                            'action_at'   => now(),
                            'action_type' => 'legal',
                            'description' => "LEGAL HT_EXECUTION: " . strtoupper($from) . " → " . strtoupper($to)
                                            . ($remarks ? ("\nCatatan: " . $remarks) : ''),
                            'result'      => strtoupper($to),
                            'meta'        => [
                                'legal_action_id'   => $a->id,
                                'legal_action_type' => $a->action_type,
                                'from'              => $from,
                                'to'                => $to,
                            ],
                        ]
                    );
                }
            });
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['to_status' => 'Gagal mengubah status HT. ' . $e->getMessage()]);
        }

        return back()->with('success', 'Status HT berhasil diubah.');
    }

    /**
     * Close HT (opsional kalau kamu butuh route tombol Close khusus).
     */
    public function closeHt(Request $request, LegalAction $action, HtExecutionStatusService $svc)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        $data = $request->validate([
            'remarks' => ['nullable','string','max:2000'],
        ]);

        $remarks = trim((string)($data['remarks'] ?? '')) ?: null;

        $to = LegalAction::STATUS_CLOSED;
        if (!$svc->canTransition($action, $to)) {
            return back()->withErrors(['close' => 'Tidak bisa CLOSE dari status saat ini.']);
        }

        $errs = $svc->validateTransition($action, $to);
        if (!empty($errs)) {
            return back()->withErrors(['close' => implode(' ', $errs)]);
        }

        $from = strtolower((string)($action->status ?? LegalAction::STATUS_DRAFT));

        DB::transaction(function () use ($action, $to, $from, $remarks) {

            $action->status       = $to;
            $action->closed_at    = now();
            $action->closed_by    = auth()->id();
            $action->closed_notes = $remarks;
            $action->save();

            if (method_exists($action, 'statusLogs')) {
                $action->statusLogs()->create([
                    'from_status' => $from,
                    'to_status'   => $to,
                    'remarks'     => $remarks,
                    'changed_by'  => auth()->id(),
                    'changed_at'  => now(),
                ]);
            }

            if (method_exists($action, 'htEvents')) {
                $action->htEvents()->create([
                    'event_type' => 'status_changed',
                    'event_at'   => now(),
                    'notes'      => $remarks,
                    'created_by' => auth()->id(),
                    'payload'    => ['from' => $from, 'to' => $to],
                ]);

                $action->htEvents()->create([
                    'event_type' => 'closed',
                    'event_at'   => now(),
                    'notes'      => $remarks,
                    'created_by' => auth()->id(),
                    'payload'    => ['by' => auth()->id()],
                ]);
            }

            $nplCaseId = $action->legalCase?->npl_case_id;
            if ($nplCaseId) {
                CaseAction::updateOrCreate(
                    [
                        'source_system' => 'legal_ht_close',
                        'source_ref_id' => $action->id,
                    ],
                    [
                        'npl_case_id' => $nplCaseId,
                        'user_id'     => auth()->id(),
                        'action_at'   => now(),
                        'action_type' => 'legal',
                        'description' => "LEGAL HT_EXECUTION: ".strtoupper($from)." → ".strtoupper($to)
                                        .($remarks ? ("\nCatatan: ".$remarks) : ''),
                        'result'      => strtoupper($to),
                        'meta'        => [
                            'legal_action_id'   => $action->id,
                            'legal_action_type' => $action->action_type,
                            'from' => $from,
                            'to'   => $to,
                        ],
                    ]
                );
            }
        });

        return back()->with('success', 'HT berhasil di-CLOSE. Data terkunci & siap audit.');
    }

    /**
     * Download risalah dari disk local (karena tanpa symlink).
     */
    public function risalah(LegalAction $action, LegalActionHtAuction $auction)
    {
        $this->authorize('view', $action);
        $this->assertHtExecution($action);

        abort_unless((int)$auction->legal_action_id === (int)$action->id, 404);

        $path = $auction->risalah_file_path;
        abort_unless($path, 404);

        $disk = $auction->risalah_file_disk ?: 'local';
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->download($path);
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function assertHtExecution(LegalAction $action): void
    {
        if (($action->action_type ?? '') !== LegalAction::TYPE_HT_EXECUTION) {
            abort(404);
        }
    }

    private function ensureNotLockedForMutation(LegalAction $action, string $message = 'Data terkunci.'): void
    {
        $exec = $action->htExecution;
        if ($exec && $exec->locked_at) {
            abort(403, $message);
        }
    }

    private function storeUploadedFile(
        \Illuminate\Http\UploadedFile $file,
        LegalAction $action,
        string $folder,
        string $disk = 'local',
        string $prefix = 'file',
        ?int $attemptNo = null
    ): string {
        $ext = $file->getClientOriginalExtension();

        $nameParts = array_filter([
            $attemptNo ? "attempt-{$attemptNo}" : null,
            $prefix,
            now()->format('Ymd-His'),
        ]);

        $filename = implode('-', $nameParts) . '.' . $ext;

        $dir = "legal/ht/{$action->id}/{$folder}";

        return $file->storeAs($dir, $filename, $disk);
    }

    private function ensureChecklistSeeded(LegalAction $action): void
    {
        $items = config('legal_checklists.ht_execution', []);

        foreach ($items as $it) {
            LegalAdminChecklist::firstOrCreate(
                [
                    'legal_action_id' => $action->id,
                    'check_code'      => $it['code'],
                ],
                [
                    'check_label' => $it['label'],
                    'is_required' => (bool)($it['required'] ?? true),
                    'sort_order'  => (int)($it['order'] ?? 0),
                    'is_checked'  => false,
                    'checked_by'  => null,
                    'checked_at'  => null,
                    'notes'       => null,
                ]
            );
        }
    }

    // Optional contoh kalau kamu pakai readiness
    public function markPrepared(Request $request, LegalAction $action, LegalActionReadinessService $readiness)
    {
        $this->authorize('update', $action);
        $this->assertHtExecution($action);

        $readiness->ensureChecklistComplete($action);

        // ...lanjut mark prepared via service status (kalau kamu punya flow)
        return back()->with('success', 'Checklist lengkap. Siap lanjut ke status berikutnya.');
    }
}
