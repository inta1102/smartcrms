<?php

namespace App\Http\Controllers;

use App\Models\CaseAction;
use App\Models\LegalAction;
use App\Models\LegalDocument;
use App\Models\LegalEvent;
use App\Services\Legal\SomasiTimelineService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LegalActionSomasiController extends Controller
{
    private const RECEIPT_STATUSES  = ['received','delivered_tracking','returned','unknown'];
    private const DELIVERY_METHODS  = ['ao_direct','internal_courier','expedition','email','whatsapp'];

    public function __construct()
    {
        $this->middleware('auth');
    }

    private function authorizeSomasi(LegalAction $action): void
    {
        abort_unless(($action->action_type ?? '') === 'somasi', 404);

        // ✅ 1 pintu role sementara: yang boleh update action, boleh update somasi
        // Nanti kalau mau rapih: ganti ke $this->authorize('updateSomasi', $action);
        $this->authorize('update', $action);
    }

    public function saveShipping(Request $request, LegalAction $action)
    {
        $this->authorizeSomasi($action);

        $data = $request->validate([
            'delivery_method'   => ['required', 'string', Rule::in(self::DELIVERY_METHODS)],
            'courier_name'      => ['nullable', 'string', 'max:100'],
            'tracking_no'       => ['nullable', 'string', 'max:100'],
            'sent_at'           => ['nullable', 'date'],
            'delivery_address'  => ['nullable', 'string', 'max:255'],
            'shipping_note'     => ['nullable', 'string', 'max:2000'],
        ]);

        // aturan: expedition wajib courier + resi
        if (($data['delivery_method'] ?? null) === 'expedition') {
            if (empty($data['courier_name']) || empty($data['tracking_no'])) {
                return back()->withErrors([
                    'courier_name' => 'Nama ekspedisi wajib diisi untuk metode ekspedisi.',
                    'tracking_no'  => 'Nomor resi wajib diisi untuk metode ekspedisi.',
                ])->withInput();
            }
        }

        DB::transaction(function () use ($action, $data) {

            $meta   = (array) ($action->meta ?? []);
            $somasi = (array) ($meta['somasi'] ?? []);

            $somasi['delivery_method']  = $data['delivery_method'];
            $somasi['courier_name']     = $data['courier_name'] ?? null;
            $somasi['tracking_no']      = $data['tracking_no'] ?? null;

            $sentAt = !empty($data['sent_at']) ? Carbon::parse($data['sent_at']) : now();
            $somasi['sent_at']          = $sentAt->toDateTimeString();
            $somasi['sent_by']          = auth()->id();

            $somasi['delivery_address'] = $data['delivery_address'] ?? null;
            $somasi['shipping_note']    = $data['shipping_note'] ?? null;

            $meta['somasi'] = $somasi;
            $action->meta   = $meta;
            $action->save();

            // Event: somasi_sent (idempotent)
            $this->upsertSomasiEvent(
                action: $action,
                eventType: 'somasi_sent',
                title: 'Somasi dikirim',
                at: $sentAt,
                status: 'done',
                notes: $data['shipping_note'] ?? null
            );
        });

        return back()->with('success', 'Data pengiriman SOMASI tersimpan.');
    }

    public function saveReceipt(Request $request, LegalAction $action, SomasiTimelineService $timeline)
    {
        $this->authorizeSomasi($action);

        $data = $request->validate([
            'receipt_status'    => ['required', 'string', Rule::in(self::RECEIPT_STATUSES)],
            'received_at'       => ['nullable', 'date'],
            'received_note'     => ['nullable', 'string', 'max:2000'],
            'receiver_name'     => ['nullable', 'string', 'max:100'],
            'receiver_relation' => ['nullable', 'string', 'max:50'],
            'return_reason'     => ['nullable', 'string', 'max:255'],
        ]);

        // conditional rule: returned => return_reason wajib
        if (($data['receipt_status'] ?? '') === 'returned' && empty($data['return_reason'])) {
            return back()->withErrors([
                'return_reason' => 'Alasan return wajib diisi jika status = returned.',
            ])->withInput();
        }

        DB::transaction(function () use ($action, $data, $timeline) {

            $meta   = (array) ($action->meta ?? []);
            $somasi = (array) ($meta['somasi'] ?? []);

            $somasi['receipt_status'] = $data['receipt_status'];

            $receivedAt = !empty($data['received_at'])
                ? Carbon::parse($data['received_at'])
                : now();

            $somasi['received_at'] = $receivedAt->toDateTimeString();

            $somasi['received_note']     = $data['received_note'] ?? null;
            $somasi['receiver_name']     = $data['receiver_name'] ?? null;
            $somasi['receiver_relation'] = $data['receiver_relation'] ?? null;
            $somasi['return_reason']     = $data['return_reason'] ?? null;

            $somasi['receipt_marked_by'] = auth()->id();

            $meta['somasi'] = $somasi;
            $action->meta   = $meta;
            $action->save();

            // ===== build description =====
            $descLines = [
                "LEGAL SOMASI: PENERIMAAN",
                "Status: " . strtoupper($data['receipt_status']),
                "Waktu: " . $receivedAt->format('Y-m-d H:i'),
            ];

            if (!empty($data['receiver_name']))     $descLines[] = "Penerima: " . $data['receiver_name'];
            if (!empty($data['receiver_relation'])) $descLines[] = "Relasi: " . $data['receiver_relation'];
            if (!empty($data['received_note']))     $descLines[] = "Catatan: " . $data['received_note'];

            if (($data['receipt_status'] ?? '') === 'returned') {
                $descLines[] = "Return: " . ($data['return_reason'] ?? '-');
            }

            $description = implode("\n", $descLines);

            $result = match ($data['receipt_status']) {
                'received'           => 'RECEIVED',
                'delivered_tracking' => 'RECEIVED',   // boleh kamu ganti jadi DELIVERED kalau mau beda
                'returned'           => 'RETURNED',
                default              => 'UNKNOWN',
            };

            // ✅ 1 pintu timeline: lewat service
            $timeline->upsert(
                action: $action,
                milestone: 'received',
                result: $result,
                description: $description,
                proofUrl: null
            );

            // ===== optional events =====
            $note = $data['received_note'] ?? null;

            match ($data['receipt_status']) {
                'received' => $this->upsertSomasiEvent($action, 'somasi_received', 'Somasi diterima', $receivedAt, 'done', $note),
                'delivered_tracking' => $this->upsertSomasiEvent($action, 'somasi_delivered_tracking', 'Somasi delivered (tracking)', $receivedAt, 'done', $note),
                'returned' => $this->upsertSomasiEvent(
                    $action,
                    'somasi_returned',
                    'Somasi gagal/return',
                    $receivedAt,
                    'done',
                    trim(($note ?? '') . "\nReturn: " . ($data['return_reason'] ?? '-'))
                ),
                default => $this->upsertSomasiEvent($action, 'somasi_unknown', 'Somasi tidak terkonfirmasi', $receivedAt, 'done', $note),
            };
        });

        return back()->with('success', 'Status penerimaan SOMASI tersimpan.');
    }

    /**
     * Upsert event, audit-friendly:
     * - created_by hanya saat create
     * - update pakai updated_by jika kolom ada
     */
    private function upsertSomasiEvent(
        LegalAction $action,
        string $eventType,
        string $title,
        Carbon $at,
        string $status = 'done',
        ?string $notes = null
    ): void {
        $event = LegalEvent::query()
            ->where('legal_action_id', $action->id)
            ->where('event_type', $eventType)
            ->first();

        $payload = [
            'legal_case_id'      => $action->legal_case_id,
            'legal_action_id'    => $action->id,
            'event_type'         => $eventType,
            'title'              => $title,
            'event_at'           => $at,
            'status'             => $status,
            'notes'              => $notes,
            'remind_at'          => null,
            'remind_channels'    => null,
        ];

        if ($event) {
            if (($event->status ?? '') !== 'cancelled') {
                $event->fill($payload);

                if ($event->isFillable('updated_by')) {
                    $event->updated_by = auth()->id();
                }

                $event->save();
            }
            return;
        }

        $payload['created_by'] = auth()->id();
        LegalEvent::create($payload);
    }

    public function uploadShippingDoc(Request $request, LegalAction $action)
    {
        $this->authorizeSomasi($action);

        $validated = $request->validate([
            'doc_type' => ['required', 'string', Rule::in([
                LegalDocument::DOC_SOMASI_SHIPPING_RECEIPT,
                LegalDocument::DOC_SOMASI_TRACKING_SCREENSHOT,
                LegalDocument::DOC_SOMASI_POD,
                LegalDocument::DOC_SOMASI_RETURN_PROOF,
            ])],
            'file'  => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');

        $dir      = "legal/{$action->legal_case_id}/actions/{$action->id}/somasi";
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $ext      = strtolower($file->getClientOriginalExtension());

        // fallback aman
        if ($safeName === '') $safeName = 'file';

        $filename = $validated['doc_type'] . '-' . now()->format('YmdHis') . '-' . $safeName . '.' . $ext;
        $path     = $file->storeAs($dir, $filename, 'public');

        LegalDocument::create([
            'legal_case_id'   => $action->legal_case_id,
            'legal_action_id' => $action->id,
            'doc_type'        => $validated['doc_type'],
            'title'           => $validated['title'] ?: $this->defaultTitle($validated['doc_type']),
            'file_path'       => $path,
            'file_name'       => $filename,
            'mime_type'       => $file->getClientMimeType(),
            'file_size'       => $file->getSize(),
            'hash_sha256'     => hash_file('sha256', $file->getRealPath()),
            'uploaded_by'     => auth()->id(),
            'uploaded_at'     => now(),
        ]);

        return back()->with('success', 'Bukti berhasil diupload.');
    }

    public function downloadShippingDoc(LegalAction $action, LegalDocument $doc)
    {
        $this->authorize('view', $action);

        abort_unless(($action->action_type ?? '') === 'somasi', 404);
        abort_unless((int) $doc->legal_action_id === (int) $action->id, 404);

        return Storage::disk('public')->download($doc->file_path, $doc->file_name);
    }

    public function deleteShippingDoc(LegalAction $action, LegalDocument $doc)
    {
        $this->authorizeSomasi($action);

        abort_unless((int) $doc->legal_action_id === (int) $action->id, 404);

        if ($doc->file_path && Storage::disk('public')->exists($doc->file_path)) {
            Storage::disk('public')->delete($doc->file_path);
        }

        $doc->delete();

        return back()->with('success', 'Bukti dihapus.');
    }

    private function defaultTitle(string $docType): string
    {
        return match ($docType) {
            LegalDocument::DOC_SOMASI_SHIPPING_RECEIPT     => 'Bukti Kirim Somasi (Resi)',
            LegalDocument::DOC_SOMASI_TRACKING_SCREENSHOT => 'Screenshot Tracking Somasi',
            LegalDocument::DOC_SOMASI_POD                 => 'Proof of Delivery Somasi (POD)',
            LegalDocument::DOC_SOMASI_RETURN_PROOF        => 'Bukti Return / Gagal Kirim Somasi',
            default                                       => 'Dokumen Somasi',
        };
    }

    /**
     * Catatan:
     * - method upsertSomasiTimeline() kamu sebelumnya sebaiknya DIHAPUS
     *   supaya "1 pintu timeline" hanya via SomasiTimelineService.
     * - Kalau masih dibutuhkan untuk legacy fallback, mending pindah ke service, bukan controller.
     */
}
