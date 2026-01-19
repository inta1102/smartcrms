<?php 

namespace App\Services\Legal;

use App\Models\LegalAction;
use App\Models\LegalActionStatusLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Models\LegalEvent;
use Illuminate\Support\Facades\Config;

// ✅ Eksekusi HT models
use App\Models\Legal\LegalActionHtExecution;
use App\Models\Legal\LegalActionHtDocument;
use Illuminate\Validation\ValidationException;
use App\Services\Legal\LegalActionReadinessService;
use Carbon\CarbonInterface;


class LegalActionStatusService
{
    /**
     * Definisi alur status (global).
     */
    private array $defaultFlow = [
        'draft'       => ['submitted', 'cancelled'],
        'submitted'   => ['in_progress', 'waiting', 'cancelled'],
        'in_progress' => ['waiting', 'completed', 'failed', 'cancelled'],
        'waiting'     => ['in_progress', 'completed', 'failed', 'cancelled'],
        'completed'   => [],
        'cancelled'   => [],
        'failed'      => ['in_progress', 'cancelled'],
    ];

    /**
     * Flow khusus per tipe
     */

    private array $flowByType = [
        'somasi' => [
            'draft'     => ['submitted', 'cancelled'],
            'waiting'   => ['completed', 'failed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
            'failed'    => ['submitted', 'cancelled'],
            'submitted' => ['waiting', 'failed', 'cancelled'],
            'waiting'   => ['completed', 'failed', 'cancelled'],
            
        ],

        // ✅ alias 1
        'eksekusi_ht' => [
            'draft'     => ['prepared','cancelled'],
            'prepared'  => ['submitted','draft','cancelled'],
            'submitted' => ['scheduled','prepared','cancelled'],
            'scheduled' => ['executed','cancelled'],
            'executed'  => ['settled'],
            'settled'   => ['closed'],
            'closed'    => [],
            'cancelled' => [],
        ],

        // ✅ alias 2 (INI YANG BIKIN BUTTON SETTLED MUNCUL)
        'ht_execution' => [
            'draft'     => ['prepared','cancelled'],
            'prepared'  => ['submitted','draft','cancelled'],
            'submitted' => ['scheduled','prepared','cancelled'],
            'scheduled' => ['executed','cancelled'],
            'executed'  => ['settled'],
            'settled'   => ['closed'],
            'closed'    => [],
            'cancelled' => [],
        ],
    ];

    public function canTransition(LegalAction $action, string $toStatus): bool
    {
        $from = (string) $action->status;
        $flow = $this->getFlowFor($action);

        return in_array($toStatus, $flow[$from] ?? [], true);
    }

    /**
     * Update status + auto log
     */
    public function transition(
        LegalAction $action,
        string $toStatus,
        ?int $changedBy = null,
        ?string $remarks = null,
        ?CarbonInterface $changedAt = null
    ): LegalAction {
        $fromStatus = (string) $action->status;

        $toStatus = strtolower(trim($toStatus));
        $fromStatus = strtolower(trim($fromStatus));

        if ($fromStatus === $toStatus) {
            return $action->fresh();
        }

        if (!$this->canTransition($action, $toStatus)) {
            throw new \DomainException(
                "Illegal status transition: {$fromStatus} -> {$toStatus} for action_type={$action->action_type}"
            );
        }

        // =========================================================
        // ✅ GUARD KHUSUS SOMASI:
        // status COMPLETED hanya boleh kalau Step 4 sudah tercatat (response/no_response)
        // =========================================================
        if (($action->action_type ?? '') === 'somasi' && $toStatus === 'completed') {

            $hasFinalEvent = $action->events()
                ->whereIn('event_type', ['somasi_responded', 'somasi_no_response'])
                ->where('status', 'done')
                ->exists();

            if (!$hasFinalEvent) {
                throw new \DomainException(
                    "Somasi belum selesai. Lengkapi Step 4 (Respon Debitur / Tidak Ada Respons) terlebih dahulu."
                );
            }
        }

        // =========================================================
        // ✅ GUARD KHUSUS EKSEKUSI HT
        // =========================================================
        if (($action->action_type ?? '') === 'eksekusi_ht') {
            $this->guardEksekusiHt($action, $fromStatus, $toStatus);
        }

        $changedAt ??= now();

        return DB::transaction(function () use (
            $action, $fromStatus, $toStatus, $changedBy, $remarks, $changedAt
        ) {

            $action->status = $toStatus;

            // start/end time (sesuaikan: di model kamu pakai start_at/end_at)
            if (in_array($toStatus, ['in_progress', 'waiting'], true) && empty($action->start_at)) {
                $action->start_at = $changedAt;
            }

            // untuk flow eksekusi_ht, end_at saat terminal states
            if (in_array($toStatus, ['completed', 'cancelled', 'failed', 'closed'], true)) {
                $action->end_at = $changedAt;
            }

            $action->save();

            LegalActionStatusLog::create([
                'legal_action_id' => $action->id,
                'from_status'     => $fromStatus,
                'to_status'       => $toStatus,
                'changed_at'      => $changedAt,
                'changed_by'      => $changedBy,
                'remarks'         => $remarks,
            ]);

            $this->autoCreateEvents($action, $fromStatus, $toStatus, $changedBy, $changedAt);

            // =========================================================
            // ✅ LOCK / UNLOCK EKSEKUSI HT
            // =========================================================
            if (($action->action_type ?? '') === 'eksekusi_ht') {
                $this->applyLockingEksekusiHt($action, $fromStatus, $toStatus);
            }

            return $action->fresh();
        });
    }

    private function getFlowFor(LegalAction $action): array
    {
        $atype = strtolower(trim((string) ($action->action_type ?? '')));

        // alias: kalau action_type pakai konstanta ht_execution, arahkan ke flow eksekusi_ht
        if ($atype === strtolower(LegalAction::TYPE_HT_EXECUTION)) {
            $atype = 'eksekusi_ht';
        }

        return $this->flowByType[$atype] ?? $this->defaultFlow;
    }


    public function allowedTransitions(LegalAction $action): array
    {
        $currentStatus = strtolower((string) $action->status);

        // Ambil flow dasar dari config / flowByType
        $flow = $this->getFlowFor($action)[$currentStatus] ?? [];

        // =========================================================
        // ✅ KHUSUS SOMASI
        // =========================================================
        if (($action->action_type ?? '') === LegalAction::TYPE_SOMASI) {

            // Somasi tidak boleh diselesaikan manual via dropdown
            // Selesai hanya lewat Step 4 (Respon / No Response)
            $flow = array_values(array_filter($flow, function ($status) {
                return strtolower((string) $status) !== LegalAction::STATUS_COMPLETED;
            }));
            

            return $flow;
        }

        // =========================================================
        // ✅ KHUSUS EKSEKUSI HT
        // =========================================================
        // ✅ KHUSUS EKSEKUSI HT
        $atype = strtolower(trim((string) ($action->action_type ?? '')));

        if ($atype === strtolower(LegalAction::TYPE_HT_EXECUTION)) {
            $atype = 'eksekusi_ht';
        }

        if ($atype === 'eksekusi_ht') {
            return $this->getFlowFor($action)[$currentStatus] ?? [];
        }

           

        // =========================================================
        // DEFAULT (non-somasi & non-HT)
        // =========================================================
        return $flow;
        

    }

    /**
     * =========================================================
     * GUARD: EKSEKUSI HT (Hak Tanggungan)
     * =========================================================
     */
    private function guardEksekusiHt(LegalAction $action, string $fromStatus, string $toStatus): void
    {
        // ambil detail HT (relasi harus ada di model LegalAction)
        $ht = $action->htExecution;

        // 1) draft -> prepared: detail minimal wajib
        if ($fromStatus === 'draft' && $toStatus === 'prepared') {
            if (!$ht) {
                throw new \DomainException("Data Eksekusi HT belum dibuat. Lengkapi data objek & metode terlebih dahulu.");
            }

            $missing = [];
            if (empty($ht->method)) $missing[] = 'method';
            if (empty($ht->land_cert_type)) $missing[] = 'land_cert_type';
            if (empty($ht->land_cert_no)) $missing[] = 'land_cert_no';
            if (empty($ht->owner_name)) $missing[] = 'owner_name';
            if (empty($ht->object_address)) $missing[] = 'object_address';

            if (is_null($ht->appraisal_value) && is_null($ht->outstanding_at_start)) {
                $missing[] = 'appraisal_value/outstanding_at_start';
            }

            if (!empty($missing)) {
                throw new \DomainException("Field wajib belum lengkap: " . implode(', ', $missing));
            }
        }

        // 2) prepared -> submitted: dokumen required harus VERIFIED
        if ($fromStatus === 'prepared' && $toStatus === 'submitted') {
            $unverifiedRequired = $action->htDocuments()
                ->where('is_required', true)
                ->where('status', '!=', LegalActionHtDocument::STATUS_VERIFIED)
                ->count();

            if ($unverifiedRequired > 0) {
                throw new \DomainException("Masih ada {$unverifiedRequired} dokumen wajib yang belum VERIFIED.");
            }
        }

        // 3) in_progress -> executed: harus ada bukti eksekusi sesuai metode
        if ($fromStatus === 'in_progress' && $toStatus === 'executed') {
            if (!$ht) {
                throw new \DomainException("Data Eksekusi HT tidak ditemukan.");
            }

            if ($ht->method === LegalActionHtExecution::METHOD_BAWAH_TANGAN) {
                $sale = $action->htUnderhandSale;
                if (!$sale || is_null($sale->sale_value)) {
                    throw new \DomainException("Penjualan bawah tangan belum lengkap (buyer/sale_value).");
                }
            } else {
                // parate / pn
                $hasSold = $action->htAuctions()
                    ->where('auction_result', 'laku')
                    ->whereNotNull('sold_value')
                    ->exists();

                if (!$hasSold) {
                    throw new \DomainException("Belum ada lelang yang berhasil (LAKU) dengan nilai laku.");
                }
            }
        }

        // 4) executed -> settled: nilai realisasi wajib ada
        if ($fromStatus === 'executed' && $toStatus === 'settled') {
            if (!$ht) {
                throw new \DomainException("Data Eksekusi HT tidak ditemukan.");
            }

            if ($ht->method === LegalActionHtExecution::METHOD_BAWAH_TANGAN) {
                $sale = $action->htUnderhandSale;
                if (!$sale || is_null($sale->sale_value)) {
                    throw new \DomainException("Nilai penjualan bawah tangan belum ada.");
                }
            } else {
                $hasSoldValue = $action->htAuctions()
                    ->where('auction_result', 'laku')
                    ->whereNotNull('sold_value')
                    ->exists();

                if (!$hasSoldValue) {
                    throw new \DomainException("Nilai laku lelang belum terisi.");
                }
            }
        }
    }

    /**
     * =========================================================
     * LOCKING: EKSEKUSI HT
     * - saat masuk submitted+ => lock htExecution
     * - saat rollback ke prepared/draft => unlock
     * =========================================================
     */
    private function applyLockingEksekusiHt(LegalAction $action, string $fromStatus, string $toStatus): void
    {
        $ht = $action->htExecution;
        if (!$ht) return;

        $lockStatuses = ['submitted', 'in_progress', 'executed', 'settled', 'closed'];

        // rollback: unlock
        if (in_array($fromStatus, $lockStatuses, true) && in_array($toStatus, ['prepared', 'draft'], true)) {
            $ht->locked_at = null;
            $ht->lock_reason = null;
            $ht->save();
            return;
        }

        // lock
        if (in_array($toStatus, $lockStatuses, true)) {
            if (is_null($ht->locked_at)) {
                $ht->locked_at = now();
            }
            $ht->lock_reason = "locked_by_status:{$toStatus}";
            $ht->save();
        }
    }

    /**
     * ===============================
     * AUTO CREATE EVENTS (AMAN)
     * ===============================
     */
    private function autoCreateEvents(
        LegalAction $action,
        string $fromStatus,
        string $toStatus,
        ?int $changedBy,
        CarbonInterface $changedAt
    ): void {

        // helper upsert
        $upsertEvent = function (array $where, array $payload) {
            $event = LegalEvent::where($where)->first();

            if ($event) {
                if ($event->status === 'scheduled') {
                    $event->fill($payload)->save();
                }
                return $event;
            }

            return LegalEvent::create(array_merge($where, $payload));
        };

        // helper close
        $closeEvent = function (array $where, string $status) {
            LegalEvent::where($where)
                ->where('status', 'scheduled')
                ->update([
                    'status'    => $status,
                    'remind_at' => null,
                ]);
        };

        /**
         * =====================================
         * SOMASI – STATUS → SUBMITTED
         * =====================================
         */
        if ($action->action_type === 'somasi' && $toStatus === 'submitted') {

            /**
             * 1️⃣ EVENT: SOMASI SENT (DONE)
             */
            LegalEvent::firstOrCreate(
                [
                    'legal_action_id' => $action->id,
                    'event_type'      => 'somasi_sent',
                ],
                [
                    'legal_case_id' => $action->legal_case_id,
                    'title'         => 'Somasi dikirim',
                    'event_at'      => $changedAt,
                    'status'        => 'done',
                    'notes'         => 'Somasi dikirim ke debitur.',
                    'created_by'    => $changedBy,
                ]
            );

            /**
             * 2️⃣ EVENT: DEADLINE RESPON SOMASI
             */
            $deadlineDays = (int) config('legal.somasi.deadline_days', 7);
            $remindBefore = (int) config('legal.somasi.remind_days_before', 1);

            $deadlineAt = $changedAt->copy()
                ->addDays($deadlineDays)
                ->setTime(23, 59, 0);

            $remindAt = $deadlineAt->copy()
                ->subDays($remindBefore)
                ->setTime(
                    (int) config('legal.somasi.remind_hour', 9),
                    (int) config('legal.somasi.remind_minute', 0),
                    0
                );

            if ($remindAt->lessThan($changedAt)) {
                $remindAt = $changedAt->copy()->addMinutes(10);
            }

            $upsertEvent(
                [
                    'legal_action_id' => $action->id,
                    'event_type'      => 'somasi_deadline',
                ],
                [
                    'legal_case_id'   => $action->legal_case_id,
                    'title'           => 'Batas akhir respon SOMASI',
                    'event_at'        => $deadlineAt,
                    'status'          => 'scheduled',
                    'notes'           => 'Auto deadline somasi.',
                    'remind_at'       => $remindAt,
                    'remind_channels' => ['whatsapp'],
                    'created_by'      => $changedBy,
                ]
            );
        }

        /**
         * =====================================
         * SOMASI – SELESAI
         * =====================================
         */
        if ($action->action_type === 'somasi'
            && in_array($toStatus, ['completed', 'failed', 'cancelled'], true)
        ) {
            $closeEvent(
                [
                    'legal_action_id' => $action->id,
                    'event_type'      => 'somasi_deadline',
                ],
                $toStatus === 'cancelled' ? 'cancelled' : 'done'
            );
        }
    }

    public function markPrepared(LegalAction $action): void
    {
        $this->readiness->ensureChecklistComplete($action);

        // logic status change
    }

}
