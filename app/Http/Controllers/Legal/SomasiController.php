<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Models\LegalAction;
use App\Models\LegalDocument;
use App\Models\LegalEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SomasiController extends Controller
{
    private const DEFAULT_DEADLINE_DAYS = 14;

    // ✅ Standarisasi event_type
    private const EV_SENT        = 'somasi_sent';
    private const EV_DEADLINE    = 'somasi_deadline';
    private const EV_RECEIPT     = 'somasi_receipt';
    private const EV_RESPONDED   = 'somasi_responded';
    private const EV_NO_RESPONSE = 'somasi_no_response';
    private const EV_DRAFT       = 'somasi_draft';

    public function show(LegalAction $action)
    {
        $this->authorize('view', $action);

        if (($action->action_type ?? '') !== LegalAction::TYPE_SOMASI && ($action->action_type ?? '') !== 'somasi') {
            abort(404, 'Bukan tindakan somasi.');
        }

        $action->load(['legalCase', 'events', 'documents']);

        $progress = $this->computeProgress($action);
        $allowed = []; // dropdown status tidak perlu untuk somasi (karena otomatis)
        $headerStatus = $progress['state'] ?? $action->status ?? 'draft';

        return view('legal.somasi.show', compact('action','progress','allowed','headerStatus'));

    }

    public function markSent(Request $request, LegalAction $action, \App\Services\Legal\LegalActionStatusService $svc)

    {
        $this->authorize('update', $action);
        if (($action->action_type ?? '') !== 'somasi' && ($action->action_type ?? '') !== LegalAction::TYPE_SOMASI) abort(404);

        $data = $request->validate([
            'sent_at'        => ['required', 'date'],
            'deadline_days'  => ['nullable', 'integer', 'min:1', 'max:90'],
            'notes'          => ['nullable', 'string', 'max:2000'],
            'location'       => ['nullable', 'string', 'max:255'],
        ]);

        $sentAt       = Carbon::parse($data['sent_at']);
        $deadlineDays = (int) ($data['deadline_days'] ?? self::DEFAULT_DEADLINE_DAYS);

        // deadline jam 23:59
        $deadlineAt = $sentAt->copy()->addDays($deadlineDays)->setTime(23, 59, 0);

       DB::transaction(function () use ($action, $data, $sentAt, $deadlineAt, $deadlineDays, $svc) {

            // 1) EVENT: SOMASI SENT (done, idempotent)
            LegalEvent::updateOrCreate(
                [
                    'legal_action_id' => $action->id,
                    'event_type'      => self::EV_SENT,
                ],
                [
                    'legal_case_id' => $action->legal_case_id,
                    'title'         => 'Somasi Dikirim ke Debitur',
                    'event_at'      => $sentAt,
                    'location'      => $data['location'] ?? null,
                    'notes'         => $data['notes'] ?? null,
                    'status'        => 'done',
                    'created_by'    => auth()->id(),
                ]
            );

            // 2) EVENT: DEADLINE (scheduled)
            $remindAt = $this->safeRemindAt($deadlineAt);

            LegalEvent::updateOrCreate(
                [
                    'legal_action_id' => $action->id,
                    'event_type'      => self::EV_DEADLINE,
                ],
                [
                    'legal_case_id' => $action->legal_case_id,
                    'title'         => 'Batas akhir respon SOMASI',
                    'event_at'      => $deadlineAt,
                    'remind_at'     => $remindAt,
                    'status'        => 'scheduled',
                    'created_by'    => auth()->id(),
                ]
            );

            // 3) UPDATE STATUS ACTION (PAKAI SERVICE)
            $action->refresh();

            // kalau masih draft, naikkan ke submitted (sesuai flow somasi)
            if (strtolower((string)$action->status) === 'draft') {
                $svc->transition(
                    action: $action,
                    toStatus: 'submitted',
                    changedBy: auth()->id(),
                    remarks: 'Somasi dikirim (Step 2)',
                    changedAt: $sentAt
                );
            } else {
                // kalau user markSent ulang, minimal pastikan start_at terisi
                if (empty($action->start_at)) {
                    $action->start_at = $sentAt;
                    $action->save();
                }
            }


            // 4) TIMELINE PENANGANAN (case_actions) - milestone sent
            $notesParts = [];
            $notesParts[] = "LEGAL SOMASI: DIKIRIM";
            $notesParts[] = "Dikirim pada: ".$sentAt->format('d-m-Y H:i');
            $notesParts[] = "Deadline: ".$deadlineAt->format('d-m-Y H:i')." ({$deadlineDays} hari)";
            if (!empty($data['location'])) $notesParts[] = "Lokasi: ".$data['location'];
            if (!empty($data['notes']))    $notesParts[] = "Catatan: ".trim((string) $data['notes']);

            $this->upsertSomasiTimeline(
                legalAction: $action,
                milestone: 'sent',
                result: 'SENT',
                description: implode("\n", $notesParts),
                when: $sentAt,
                nextAction: 'Cek penerimaan (Step 3) / Menunggu respon (Step 4)',
                nextDue: now()->addDays(2),
                metaExtra: [
                    'deadline_at'   => $deadlineAt->toDateTimeString(),
                    'deadline_days' => $deadlineDays,
                ],
            );
        });

        return back()->with('success', 'Somasi ditandai sudah dikirim, deadline dibuat, dan timeline diupdate.');
    }

    public function markResponse(Request $request, LegalAction $action, \App\Services\Legal\LegalActionStatusService $svc)
    {
        $this->authorize('update', $action);
        if (($action->action_type ?? '') !== 'somasi' && ($action->action_type ?? '') !== LegalAction::TYPE_SOMASI) abort(404);

        $data = $request->validate([
            'response_at'      => ['required', 'date'],
            'response_channel' => ['nullable', Rule::in(['datang', 'surat', 'wa', 'telepon', 'kuasa_hukum', 'lainnya'])],
            'notes'            => ['nullable', 'string', 'max:4000'],
        ]);

        $responseAt = Carbon::parse($data['response_at']);

        DB::transaction(function () use ($action, $data, $responseAt, $svc) {

            $notesParts = [];
            $notesParts[] = 'Channel: ' . ($data['response_channel'] ?? '-');
            if (!empty($data['notes'])) $notesParts[] = trim((string) $data['notes']);
            $notes = trim(implode("\n", $notesParts)) ?: null;

            // 1) Event: responded (done)
            LegalEvent::updateOrCreate(
                ['legal_action_id' => $action->id, 'event_type' => self::EV_RESPONDED],
                [
                    'legal_case_id' => $action->legal_case_id,
                    'title'         => 'Debitur Merespons Somasi',
                    'event_at'      => $responseAt,
                    'notes'         => $notes,
                    'status'        => 'done',
                    'created_by'    => auth()->id(),
                ]
            );

            // 2) Deadline: done (stop reminder)
            $deadline = LegalEvent::where('legal_action_id', $action->id)
                ->where('event_type', self::EV_DEADLINE)
                ->first();

            if ($deadline) {
                $deadline->status    = 'done';
                $deadline->remind_at = null;
                $deadline->save();
            }

            // 3) AUTO CLOSE via service
            $svc->transition(
                action: $action,
                toStatus: 'completed',
                changedBy: auth()->id(),
                remarks: 'Somasi selesai otomatis setelah debitur merespons',
                changedAt: now()
            );

            // 4) Timeline response
            $this->upsertSomasiTimeline(
                legalAction: $action,
                milestone: 'response',
                result: 'RESPON',
                description:
                    "LEGAL SOMASI: DEBITUR MERESPONS\n" .
                    "Channel: " . ($data['response_channel'] ?? '-') . "\n" .
                    "Catatan: " . (($data['notes'] ?? '') !== '' ? $data['notes'] : '-'),
                when: $responseAt,
                nextAction: null,
                nextDue: null,
                metaExtra: ['event_type' => self::EV_RESPONDED],
            );

            // 5) Timeline done
            $this->upsertSomasiTimeline(
                legalAction: $action,
                milestone: 'done',
                result: 'SELESAI',
                description:
                    "LEGAL SOMASI: SELESAI\n" .
                    "Dasar: Debitur merespons\n" .
                    "Ditutup pada: " . now()->format('d-m-Y H:i'),
                when: now(),
                nextAction: null,
                nextDue: null,
                metaExtra: [
                    'closed_reason' => 'response',
                    'closed_from'   => self::EV_RESPONDED,
                ],
            );
        });

        return back()->with('success', 'Respons debitur dicatat. Somasi otomatis ditutup (selesai).');
    }

    public function markNoResponse(Request $request, LegalAction $action, \App\Services\Legal\LegalActionStatusService $svc)
    {
        $this->authorize('update', $action);
        if (($action->action_type ?? '') !== 'somasi' && ($action->action_type ?? '') !== LegalAction::TYPE_SOMASI) abort(404);

        $data = $request->validate([
            'checked_at' => ['required', 'date'],
            'notes'      => ['nullable', 'string', 'max:4000'],
        ]);

        $checkedAt = Carbon::parse($data['checked_at']);

        DB::transaction(function () use ($action, $data, $checkedAt, $svc) {

            // 1) Event: No response
            LegalEvent::updateOrCreate(
                ['legal_action_id' => $action->id, 'event_type' => self::EV_NO_RESPONSE],
                [
                    'legal_case_id' => $action->legal_case_id,
                    'title'         => 'Debitur Tidak Merespons Somasi',
                    'event_at'      => $checkedAt,
                    'notes'         => !empty($data['notes']) ? trim((string) $data['notes']) : null,
                    'status'        => 'done',
                    'created_by'    => auth()->id(),
                ]
            );

            // 2) Deadline: done (stop reminder)
            $deadline = LegalEvent::where('legal_action_id', $action->id)
                ->where('event_type', self::EV_DEADLINE)
                ->first();

            if ($deadline) {
                $deadline->status    = 'done';
                $deadline->remind_at = null;
                $deadline->save();
            }

            // 3) AUTO CLOSE via service
            // kalau masih submitted, naikkan dulu ke waiting (biar flow somasi valid)
            $action->refresh(); // pastikan status terbaru di DB

            if (strtolower((string)$action->status) === 'submitted') {
                $svc->transition(
                    action: $action,
                    toStatus: 'waiting',
                    changedBy: auth()->id(),
                    remarks: 'Somasi masuk fase menunggu respon (auto sebelum close no response)',
                    changedAt: $checkedAt // bagusnya pakai waktu dicek
                );

                $action->refresh();
            }

            $svc->transition(
                action: $action,
                toStatus: 'completed',
                changedBy: auth()->id(),
                remarks: 'Somasi selesai otomatis karena tidak ada respons',
                changedAt: now()
            );


            // 4) Timeline no response
            $this->upsertSomasiTimeline(
                legalAction: $action,
                milestone: 'no_response',
                result: 'NO_RESPONSE',
                description:
                    "LEGAL SOMASI: TIDAK ADA RESPON\n" .
                    "Dicek pada: " . $checkedAt->format('d-m-Y H:i') . "\n" .
                    "Catatan: " . (($data['notes'] ?? '') !== '' ? $data['notes'] : '-'),
                when: $checkedAt,
                nextAction: null,
                nextDue: null,
                metaExtra: ['event_type' => self::EV_NO_RESPONSE],
            );

            // 5) Timeline done
            $this->upsertSomasiTimeline(
                legalAction: $action,
                milestone: 'done',
                result: 'SELESAI',
                description:
                    "LEGAL SOMASI: SELESAI\n" .
                    "Dasar: Tidak ada respons\n" .
                    "Ditutup pada: " . now()->format('d-m-Y H:i'),
                when: now(),
                nextAction: null,
                nextDue: null,
                metaExtra: [
                    'closed_reason' => 'no_response',
                    'closed_from'   => self::EV_NO_RESPONSE,
                ],
            );
        });

        return back()->with('success', 'No Response dicatat. Somasi otomatis ditutup (selesai).');
    }

    public function uploadDocument(Request $request, LegalAction $action)
    {
        $this->authorize('update', $action);

        if (($action->action_type ?? '') !== 'somasi' && ($action->action_type ?? '') !== LegalAction::TYPE_SOMASI) {
            abort(404);
        }

        /**
         * Normalisasi input:
         * - doc_type bisa datang sebagai "doc_type" atau "type"
         * - file bisa datang sebagai "file" atau "document"
         */
        $docType = $request->input('doc_type', $request->input('type'));
        $file    = $request->file('file') ?? $request->file('document');

        // ✅ doc type yang diizinkan (gabungan lama + UI sekarang)
        $allowedTypes = [
            // legacy / internal
            'somasi_draft',
            'somasi_signed',
            'somasi_sent_proof',
            'somasi_received_proof',
            'somasi_return_proof',

            // UI baru (modal)
            'somasi_tracking_screenshot',
            'somasi_pod',
        ];

        // ✅ Validasi sekali saja (tidak dobel)
        $validated = validator(
            [
                'doc_type' => $docType,
                'title'    => $request->input('title'),
                'file'     => $file,
            ],
            [
                'doc_type' => ['required', Rule::in($allowedTypes)],
                'title'    => ['nullable', 'string', 'max:255'],     // ✅ sesuai UI (opsional)
                'file'     => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'], // 10MB
            ],
            [
                'doc_type.required' => 'Jenis bukti wajib dipilih.',
                'doc_type.in'       => 'Jenis bukti tidak dikenali.',
                'file.required'     => 'File wajib diupload.',
                'file.mimes'        => 'File harus PDF/JPG/JPEG/PNG.',
                'file.max'          => 'Ukuran file maksimal 10MB.',
            ]
        )->validate();

        // pakai file hasil normalisasi (bukan dari $request->validate lama)
        $file = $validated['file'];

        // ✅ Aman tanpa symlink: simpan local
        $disk = 'local';
        $dir  = "legal/somasi/{$action->id}";
        $path = $file->store($dir, $disk);

        $hash = null;
        try {
            $tmp  = $file->getRealPath();
            $hash = ($tmp && is_file($tmp)) ? hash_file('sha256', $tmp) : null;
        } catch (\Throwable $e) {
            $hash = null;
        }

        // title boleh null → aman
        LegalDocument::create([
            'legal_case_id'   => $action->legal_case_id,
            'legal_action_id' => $action->id,
            'doc_type'        => $validated['doc_type'],
            'title'           => $validated['title'] ?? null,
            'file_path'       => $path,
            'file_name'       => $file->getClientOriginalName(),
            'mime_type'       => $file->getMimeType(),
            'file_size'       => $file->getSize(),
            'hash_sha256'     => $hash,
            'uploaded_by'     => auth()->id(),
            'uploaded_at'     => now(),
        ]);

        // optional: auto event saat draft upload
        if (($validated['doc_type'] ?? null) === 'somasi_draft') {
            LegalEvent::firstOrCreate(
                [
                    'legal_action_id' => $action->id,
                    'event_type'      => self::EV_DRAFT,
                ],
                [
                    'legal_case_id' => $action->legal_case_id,
                    'title'         => 'Draft Somasi Dibuat',
                    'event_at'      => now(),
                    'status'        => 'done',
                    'created_by'    => auth()->id(),
                ]
            );
        }

        // optional: saat received proof / pod upload → pastikan ada EV_RECEIPT
        if (in_array(($validated['doc_type'] ?? null), ['somasi_received_proof', 'somasi_pod'], true)) {
            LegalEvent::firstOrCreate(
                [
                    'legal_action_id' => $action->id,
                    'event_type'      => self::EV_RECEIPT,
                ],
                [
                    'legal_case_id' => $action->legal_case_id,
                    'title'         => 'Somasi: Status Penerimaan',
                    'event_at'      => now(),
                    'status'        => 'done',
                    'created_by'    => auth()->id(),
                ]
            );
        }

        return back()->with('success', 'Dokumen somasi berhasil diupload.');
    }

    private function computeProgress(LegalAction $action): array
    {
        $events = $action->events->keyBy('event_type');
        $docs   = $action->documents->groupBy('doc_type');

        $sent     = $events->get(self::EV_SENT);
        $deadline = $events->get(self::EV_DEADLINE);
        $receipt  = $events->get(self::EV_RECEIPT);
        $response = $events->get(self::EV_RESPONDED);
        $noResp   = $events->get(self::EV_NO_RESPONSE);

        // ===== meta somasi (sumber utama untuk receipt/shipping fields) =====
        $meta = is_array($action->meta)
            ? $action->meta
            : (is_string($action->meta) ? json_decode($action->meta, true) : []);
        if (!is_array($meta)) $meta = [];

        $som = (array)($meta['somasi'] ?? []);

        $receiptStatus = $som['receipt_status'] ?? null;
        $receivedAt    = $som['received_at'] ?? null;

        // ===== State standar yang dipakai Blade =====
        // urutan prioritas: completed > waiting > received > sent > draft
        $state = 'draft';

        // completed jika ada responded / no_response
        if ($response || $noResp) {
            $state = 'completed';
        } else {
            // sent minimal jika event sent ada
            if ($sent) $state = 'sent';

            // receipt: kalau ada receipt_status yang meaningful, naik ke received/waiting
            // NOTE: di UI kamu, setelah diterima/tracking -> MENUNGGU RESPON
            if (in_array($receiptStatus, ['received', 'delivered_tracking'], true)) {
                $state = 'waiting';
            } elseif ($receiptStatus === 'returned') {
                // kamu boleh pilih: tetap 'sent' atau state khusus
                // supaya UI tidak menganggap waiting, kita set ke 'sent' saja
                $state = 'sent';
            } elseif ($receipt) {
                // kalau event receipt ada tapi status meta belum kebaca, minimal anggap 'received'
                $state = 'received';
            }
        }

        return [
            // ===== dipakai header/progress =====
            'state'           => $state,
            'sent_at'         => $sent?->event_at,
            'deadline_at'     => $deadline?->event_at,
            'deadline_status' => $deadline?->status,

            // ===== dipakai UI (ringkasan/penerimaan) =====
            'delivery_method' => $som['delivery_method'] ?? null,
            'courier_name'    => $som['courier_name'] ?? null,
            'tracking_no'     => $som['tracking_no'] ?? null,

            'receipt_status'  => $receiptStatus,
            'received_at'     => $receivedAt,
            'received_note'   => $som['received_note'] ?? null,

            // ===== final step =====
            'responded_at'    => $response?->event_at,
            'no_response_at'  => $noResp?->event_at,

            // ===== flags dokumen (samakan nama dg Blade: has_draft_doc) =====
            'has_draft_doc'   => $docs->has('somasi_draft') || $docs->has('somasi_signed'),

            // optional flags tambahan kalau kamu butuh
            'has_return_proof' => $docs->has('somasi_return_proof'),
            'has_pod'          => $docs->has('somasi_pod'),
            'has_tracking_ss'  => $docs->has('somasi_tracking_screenshot'),
        ];
    }

    public function markReceived(Request $request, LegalAction $action, \App\Services\Legal\LegalActionStatusService $svc)
    {
        $this->authorize('update', $action);
        if (($action->action_type ?? '') !== 'somasi' && ($action->action_type ?? '') !== LegalAction::TYPE_SOMASI) abort(404);

        $data = $request->validate([
            'receipt_status' => ['required', Rule::in(['received','delivered_tracking','returned','unknown'])],
            'received_at'    => ['nullable', 'date'],

            'received_by_name'  => ['nullable', 'string', 'max:255'],
            'receiver_name'     => ['nullable', 'string', 'max:255'],
            'receiver_relation' => ['nullable', 'string', 'max:255'],

            'delivery_channel' => ['nullable', Rule::in(['pos', 'kurir', 'petugas_bank', 'kuasa_hukum', 'lainnya'])],
            'receipt_no'       => ['nullable', 'string', 'max:100'],
            'return_reason'    => ['nullable', 'string', 'max:255'],
            'notes'            => ['nullable', 'string', 'max:4000'],
        ]);

        $receiverName = $data['receiver_name'] ?? $data['received_by_name'] ?? null;

        // RULE: kalau returned wajib ada bukti return
        if (($data['receipt_status'] ?? null) === 'returned') {
            $hasReturnProof = $action->documents()
                ->where('doc_type', 'somasi_return_proof')
                ->exists();

            if (!$hasReturnProof) {
                return back()->withErrors([
                    'receipt_status' => 'Status RETURN wajib melampirkan bukti (upload Bukti Return/Gagal Kirim) minimal 1 file.',
                ])->withInput();
            }
        }

        $receivedAt = !empty($data['received_at']) ? Carbon::parse($data['received_at']) : now();

        DB::transaction(function () use ($action, $data, $receivedAt, $receiverName) {

            // simpan meta somasi
            $meta = is_array($action->meta)
                ? $action->meta
                : (is_string($action->meta) ? json_decode($action->meta, true) : []);

            if (!is_array($meta)) $meta = [];
            $meta['somasi'] = (array) ($meta['somasi'] ?? []);

            $meta['somasi']['receipt_status']    = $data['receipt_status'];
            $meta['somasi']['received_at']       = $receivedAt->toDateTimeString();
            $meta['somasi']['received_by_name']  = $receiverName;
            $meta['somasi']['receiver_relation'] = $data['receiver_relation'] ?? null;
            $meta['somasi']['delivery_channel']  = $data['delivery_channel'] ?? null;
            $meta['somasi']['receipt_no']        = $data['receipt_no'] ?? null;
            $meta['somasi']['return_reason']     = $data['return_reason'] ?? null;
            $meta['somasi']['received_note']     = $data['notes'] ?? null;

            $action->meta = $meta;
            $action->save();

            // notes event
            $notesParts = [];
            $notesParts[] = "Receipt status: " . $data['receipt_status'];
            if (!empty($data['delivery_channel']))  $notesParts[] = "Channel: ".$data['delivery_channel'];
            if (!empty($data['receipt_no']))        $notesParts[] = "No Resi/Tanda Terima: ".$data['receipt_no'];
            if (!empty($receiverName))              $notesParts[] = "Diterima oleh: ".$receiverName;
            if (!empty($data['receiver_relation'])) $notesParts[] = "Relasi: ".$data['receiver_relation'];
            if (!empty($data['return_reason']))     $notesParts[] = "Alasan return: ".$data['return_reason'];
            if (!empty($data['notes']))             $notesParts[] = trim((string) $data['notes']);
            $notes = trim(implode("\n", $notesParts)) ?: null;

            $eventTitle = match ($data['receipt_status']) {
                'received'           => 'Somasi Diterima',
                'delivered_tracking' => 'Somasi Delivered (Tracking)',
                'returned'           => 'Somasi Return / Gagal Kirim',
                default              => 'Somasi: Status Penerimaan Tidak Terkonfirmasi',
            };

            // upsert event penerimaan (1 event saja)
            LegalEvent::updateOrCreate(
                [
                    'legal_action_id' => $action->id,
                    'event_type'      => self::EV_RECEIPT,
                ],
                [
                    'legal_case_id' => $action->legal_case_id,
                    'title'         => $eventTitle,
                    'event_at'      => $receivedAt,
                    'notes'         => $notes,
                    'status'        => 'done',
                    'created_by'    => auth()->id(),
                ]
            );

            $action->refresh();
            $curr = strtolower((string) $action->status);

            // 1) Jika diterima / delivered_tracking / unknown => masuk fase waiting
            if (in_array($data['receipt_status'], ['received','delivered_tracking','unknown'], true)) {
                // kalau dari draft, naikkan dulu ke submitted biar valid
                if ($curr === 'draft') {
                    $svc->transition($action, 'submitted', auth()->id(), 'Auto submit sebelum receipt', $receivedAt);
                    $action->refresh();
                    $curr = strtolower((string) $action->status);
                }

                // dari submitted -> waiting (ini sesuai flow somasi kamu)
                if ($curr === 'submitted') {
                    $svc->transition($action, 'waiting', auth()->id(), 'Somasi diterima/delivered: menunggu respon', $receivedAt);
                }
            }

            // 2) Jika RETURNED => tandai gagal kirim (paling rapi pakai status failed)
            if ($data['receipt_status'] === 'returned') {
                // opsi paling clean: jadikan failed
                // tapi flow somasi kamu saat ini belum izinkan submitted -> failed
                // jadi kita butuh sedikit penyesuaian flow (lihat bagian C di bawah)
                if (in_array($curr, ['submitted','waiting'], true)) {
                    $svc->transition($action, 'failed', auth()->id(), 'Somasi return/gagal kirim', $receivedAt);
                }
            }

            // update status action
            if (in_array($data['receipt_status'], ['received','delivered_tracking','unknown'], true)) {
                $action->status = 'menunggu_respon';
            } elseif ($data['receipt_status'] === 'returned') {
                $action->status = 'perlu_kirim_ulang';
            }
            $action->save();

            // timeline milestone receipt
            $descParts = [];
            $descParts[] = "LEGAL SOMASI: PENERIMAAN";
            $descParts[] = "Status: ".$data['receipt_status'];
            $descParts[] = "Waktu: ".$receivedAt->format('d-m-Y H:i');
            if (!empty($receiverName))              $descParts[] = "Penerima: ".$receiverName;
            if (!empty($data['receiver_relation'])) $descParts[] = "Relasi: ".$data['receiver_relation'];
            if (!empty($data['delivery_channel']))  $descParts[] = "Channel: ".$data['delivery_channel'];
            if (!empty($data['receipt_no']))        $descParts[] = "Resi: ".$data['receipt_no'];
            if (!empty($data['return_reason']))     $descParts[] = "Alasan return: ".$data['return_reason'];
            if (!empty($data['notes']))             $descParts[] = "Catatan: ".trim((string)$data['notes']);

            $resultTimeline = match ($data['receipt_status']) {
                'received'           => 'RECEIVED',
                'delivered_tracking' => 'DELIVERED_TRACKING',
                'returned'           => 'RETURNED',
                default              => 'UNKNOWN',
            };

            $nextAction = match ($data['receipt_status']) {
                'received', 'delivered_tracking' => 'Menunggu respon debitur (Step 4)',
                'returned'                       => 'Kirim ulang somasi / perbaiki alamat',
                default                          => 'Cek penerimaan (tracking/POD) & update Step 3',
            };

            $nextDue = match ($data['receipt_status']) {
                'returned' => now()->addDays(1),
                'unknown'  => now()->addDays(1),
                default    => now()->addDays(7),
            };

            $this->upsertSomasiTimeline(
                legalAction: $action,
                milestone: 'receipt',
                result: $resultTimeline,
                description: implode("\n", $descParts),
                when: $receivedAt,
                nextAction: $nextAction,
                nextDue: $nextDue,
                metaExtra: [
                    'receipt_status' => $data['receipt_status'],
                    'event_type'     => self::EV_RECEIPT,
                ],
            );
        });

        return back()->with('success', 'Status penerimaan Somasi berhasil disimpan & timeline diupdate.');
    }

    protected function upsertSomasiTimeline(
        LegalAction $legalAction,
        string $milestone,
        string $result,
        string $description,
        \Carbon\CarbonInterface $when,
        ?string $nextAction = null,
        ?\Carbon\CarbonInterface $nextDue = null,
        array $metaExtra = []
    ): void {
        $nplCaseId = \App\Models\LegalCase::whereKey($legalAction->legal_case_id)->value('npl_case_id');
        if (!$nplCaseId) {
            \Log::warning('[SOMASI][TIMELINE] skip (npl_case_id null)', [
                'legal_action_id' => $legalAction->id,
                'legal_case_id'   => $legalAction->legal_case_id,
                'milestone'       => $milestone,
            ]);
            return;
        }

        $srcRef = $legalAction->id . ':' . $milestone;

        $meta = array_merge([
            'legal_action_id' => $legalAction->id,
            'legal_case_id'   => $legalAction->legal_case_id,
            'legal_type'      => $legalAction->action_type,
            'milestone'       => $milestone,
        ], $metaExtra);

        \App\Models\CaseAction::updateOrCreate(
            [
                'source_system' => 'legal_somasi',
                'source_ref_id' => (string) $srcRef,
            ],
            [
                'npl_case_id'      => $nplCaseId,
                'user_id'          => auth()->id(),
                'action_at'        => $when,
                'action_type'      => 'legal', // ✅ konsisten
                'result'           => strtoupper($result),
                'description'      => $description,
                'next_action'      => $nextAction,
                'next_action_due'  => $nextDue?->toDateString(),
                'meta'             => $meta,
            ]
        );
    }

    /**
     * Remind aman: default H-1 jam 09:00,
     * tapi kalau itu >= deadlineAt, fallback 2 jam sebelum deadline.
     */
    private function safeRemindAt(Carbon $deadlineAt): Carbon
    {
        $h1 = $deadlineAt->copy()->subDay()->setTime(9, 0, 0);
        if ($h1->greaterThanOrEqualTo($deadlineAt)) {
            return $deadlineAt->copy()->subHours(2);
        }
        return $h1;
    }
}
