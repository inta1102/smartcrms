<?php

namespace App\Services\Legal;

use App\Models\LegalAction;
use App\Models\Legal\LegalActionHtDocument;
use App\Models\LegalAdminChecklist;
use App\Models\Legal\LegalActionHtAuction;



class HtExecutionStatusService
{
    public function allowedTransitions(LegalAction $action): array
    {
        $s = strtolower(trim((string) ($action->status ?? 'draft')));

        return match ($s) {
            'draft' => ['prepared', 'cancelled'],
            'prepared' => ['submitted', 'cancelled'],
            'submitted' => ['scheduled', 'cancelled'],
            'scheduled' => ['executed', 'cancelled'],
            'executed' => ['settled'],
            'settled' => ['closed'],
            'closed', 'cancelled' => [],
            default => [],
        };
    }


    public function canTransition(LegalAction $action, string $to): bool
    {
        return in_array($to, $this->allowedTransitions($action), true);
    }

    /**
     * Validasi syarat per transisi (yang bikin Mark Prepared beneran bermakna)
     */
    public function validateTransition(LegalAction $action, string $to): array
    {
        $errors = [];

        // =========================
        // 0) Guard umum (opsional)
        // =========================
        $current = (string) ($action->status ?? '');

        // =========================
        // 1) Mark Prepared
        // =========================
        if ($to === LegalAction::STATUS_PREPARED) {
            $ht = $action->htExecution;

            if (!$ht) {
                return ["Data Eksekusi HT belum dibuat/diisi."];
            }

            if (empty($ht->method))         $errors[] = "Metode eksekusi belum diisi.";
            if (empty($ht->land_cert_type)) $errors[] = "Jenis sertifikat (land_cert_type) belum diisi.";
            if (empty($ht->land_cert_no))   $errors[] = "Nomor sertifikat (land_cert_no) belum diisi.";
            if (empty($ht->owner_name))     $errors[] = "Owner belum diisi.";
            if (empty($ht->object_address)) $errors[] = "Alamat objek belum diisi.";
            if (empty($ht->appraisal_value) || (float) $ht->appraisal_value <= 0) {
                $errors[] = "Nilai taksasi belum valid.";
            }

            // opsional: pastikan status asal DRAFT
            if ($current !== LegalAction::STATUS_DRAFT) {
                $errors[] = "Mark Prepared hanya bisa dari status DRAFT.";
            }
        }

        // =========================
        // 2) Submit to KPKNL (SUBMITTED)
        // =========================
        if ($to === LegalAction::STATUS_SUBMITTED) {

            // opsional tapi bagus: status asal harus PREPARED
            if ($current !== LegalAction::STATUS_PREPARED) {
                $errors[] = "Submit ke KPKNL hanya bisa dari status PREPARED.";
            }

            // Dokumen wajib harus VERIFIED
            $docRequired = $action->htDocuments()
                ->where('is_required', true)
                ->count();

            $docVerified = $action->htDocuments()
                ->where('is_required', true)
                ->where('status', LegalActionHtDocument::STATUS_VERIFIED)
                ->count();

            if ($docRequired > 0 && $docVerified < $docRequired) {
                $errors[] = "Dokumen wajib belum diverifikasi ($docVerified/$docRequired).";
            }

            // Checklist TL harus lengkap
            $tlRequired = LegalAdminChecklist::where('legal_action_id', $action->id)
                ->where('is_required', 1)
                ->count();

            $tlChecked = LegalAdminChecklist::where('legal_action_id', $action->id)
                ->where('is_required', 1)
                ->where('is_checked', 1)
                ->count();

            if ($tlRequired > 0 && $tlChecked < $tlRequired) {
                $errors[] = "Checklist TL belum lengkap ($tlChecked/$tlRequired).";
            }
        }

        // =========================
        // 3) Mark Scheduled (SCHEDULED)
        // =========================
        if ($to === LegalAction::STATUS_SCHEDULED) {

            if ($current !== LegalAction::STATUS_SUBMITTED) {
                $errors[] = "Mark Scheduled hanya bisa dari status SUBMITTED.";
            }

            // event penetapan jadwal wajib ada
            $scheduleEvent = $action->htEvents()
                ->where('event_type', 'penetapan_jadwal')
                ->whereNotNull('event_at')
                ->orderByDesc('event_at')
                ->first();

            if (!$scheduleEvent) {
                $errors[] = "Belum ada Timeline 'Penetapan jadwal lelang'. Tambahkan dulu di tab Proses & Timeline.";
                return $errors;
            }

            // optional: pastikan penetapan jadwal tidak sebelum submit_kpknl
            $submitEvent = $action->htEvents()
                ->where('event_type', 'submit_kpknl')
                ->whereNotNull('event_at')
                ->orderByDesc('event_at')
                ->first();

            if ($submitEvent && $scheduleEvent->event_at->lt($submitEvent->event_at)) {
                $errors[] = "Tanggal Penetapan Jadwal tidak boleh lebih awal dari tanggal Submit ke KPKNL.";
            }
        }

        // =========================
        // 4) Mark Executed (EXECUTED)
        // =========================
        if ($to === LegalAction::STATUS_EXECUTED) {

            if (($action->status ?? '') !== LegalAction::STATUS_SCHEDULED) {
                $errors[] = "Status harus SCHEDULED sebelum bisa EXECUTED.";
                return $errors;
            }

            // wajib ada attempt yang sudah final (laku / tidak_laku)
            $hasFinalAuction = $action->htAuctions()
                ->whereNotNull('auction_date')
                ->whereIn('auction_result', [
                    LegalActionHtAuction::RESULT_LAKU,
                    LegalActionHtAuction::RESULT_TIDAK_LAKU,
                ])
                ->exists();

            if (!$hasFinalAuction) {
                $errors[] = "Belum ada Attempt lelang yang final. Isi tanggal lelang + hasil (LAKU / TIDAK LAKU) dulu.";
            }

            // jika LAKU, biasanya wajib settlement_date & sold_value (opsional sesuai kebijakan)
            $hasSold = $action->htAuctions()
                ->whereIn('auction_result', [LegalActionHtAuction::RESULT_LAKU])
                ->exists();

            if ($hasSold) {
                $soldOk = $action->htAuctions()
                    ->where('auction_result', LegalActionHtAuction::RESULT_LAKU)
                    ->whereNotNull('sold_value')
                    ->exists();

                if (!$soldOk) {
                    $errors[] = "Hasil LAKU: sold_value belum diisi.";
                }
                // kalau kamu mau wajib settlement_date:
                // $settleOk = ... whereNotNull('settlement_date')
            }
        }

        // =========================
        // 5) Mark Settled (SETTLED)
        // =========================
        if ($to === LegalAction::STATUS_SETTLED) {

            // sesuai desain: settled hanya setelah executed
            if ($current !== LegalAction::STATUS_EXECUTED) {
                $errors[] = "Mark Settled hanya bisa dari status EXECUTED.";
                return $errors;
            }

            // cari attempt final terbaru (lebih aman daripada exists doang)
            $finalAuction = $action->htAuctions()
                ->whereNotNull('auction_date')
                ->whereIn('auction_result', [
                    LegalActionHtAuction::RESULT_LAKU,
                    LegalActionHtAuction::RESULT_TIDAK_LAKU,
                ])
                ->orderByDesc('auction_date')
                ->orderByDesc('id')
                ->first();

            if (!$finalAuction) {
                $errors[] = "Belum ada Attempt lelang yang final (LAKU / TIDAK LAKU).";
                return $errors;
            }

            // SETTLED hanya relevan jika hasil LAKU
            if ($finalAuction->auction_result !== LegalActionHtAuction::RESULT_LAKU) {
                $errors[] = "Mark Settled hanya bisa jika hasil lelang LAKU.";
                return $errors;
            }

            // wajib settlement_date
            if (empty($finalAuction->settlement_date)) {
                $errors[] = "Hasil LAKU: tanggal pelunasan (settlement_date) belum diisi.";
            }

            // wajib sold_value (biar jelas nilai laku)
            if (empty($finalAuction->sold_value) || (float) $finalAuction->sold_value <= 0) {
                $errors[] = "Hasil LAKU: sold_value belum valid.";
            }

            // opsional tapi sangat disarankan: risalah wajib ada
            if (empty($finalAuction->risalah_file_path)) {
                $errors[] = "Risalah lelang belum diupload.";
            }

            // opsional: settlement_date tidak boleh sebelum auction_date
            if (!empty($finalAuction->settlement_date) && !empty($finalAuction->auction_date)) {
                if ($finalAuction->settlement_date->lt($finalAuction->auction_date)) {
                    $errors[] = "Tanggal pelunasan tidak boleh lebih awal dari tanggal lelang.";
                }
            }
        }

        // =========================
        // 6) Mark Closed (CLOSED)
        // =========================
        if ($to === LegalAction::STATUS_CLOSED) {

            if (!in_array($current, [LegalAction::STATUS_SETTLED, LegalAction::STATUS_EXECUTED], true)) {
                $errors[] = "Mark Closed hanya bisa dari status SETTLED atau EXECUTED.";
                return $errors;
            }

            $finalAuction = $action->htAuctions()
                ->whereNotNull('auction_date')
                ->whereIn('auction_result', [LegalActionHtAuction::RESULT_LAKU, LegalActionHtAuction::RESULT_TIDAK_LAKU])
                ->orderByDesc('auction_date')->orderByDesc('id')->first();

            if (!$finalAuction) {
                $errors[] = "Belum ada Attempt lelang yang final (LAKU / TIDAK LAKU).";
                return $errors;
            }

            if ($finalAuction->auction_result === LegalActionHtAuction::RESULT_LAKU) {
                if ($current !== LegalAction::STATUS_SETTLED) {
                    $errors[] = "Hasil LAKU: harus SETTLED dulu sebelum CLOSE.";
                }
            } else {
                // hasil tidak laku: boleh close dari executed, tapi remarks wajib (cek di request/service)
                // di sini hanya validasi data pendukung
                if (empty($finalAuction->risalah_file_path)) {
                    $errors[] = "Risalah lelang belum diupload.";
                }
            }
        }

        return $errors;
    }
}
