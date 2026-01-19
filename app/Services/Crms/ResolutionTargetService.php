<?php

namespace App\Services\Crms;

use App\Models\AoAgenda;
use App\Models\CaseResolutionTarget;
use App\Models\NplCase;
use App\Models\OrgAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ResolutionTargetService
{
    // Status (konsisten, jangan campur string lain)
    public const ST_PENDING_TL   = 'pending_tl';
    public const ST_PENDING_KASI = 'pending_kasi';
    public const ST_ACTIVE       = 'active';
    public const ST_REJECTED     = 'rejected';
    public const ST_SUPERSEDED   = 'superseded';

    /**
     * Propose target baru.
     * ✅ AUTO ROUTE:
     * - Jika AO punya TL => status pending_tl & needs_tl_approval=1
     * - Jika AO TIDAK punya TL (leader_role bukan TL*) => status pending_kasi & needs_tl_approval=0
     *
     * Note:
     * - $initialStatus optional: kalau kamu set manual, service akan hormati.
     */
    public function propose(
        NplCase $case,
        Carbon|string $targetDate,
        ?string $strategy,
        int $proposedBy,
        ?string $reason = null,
        string $targetOutcome = 'lunas',
        ?string $initialStatus = null
    ): CaseResolutionTarget {

        $targetDate = $this->normalizeDate($targetDate);
        $this->validateBusinessRules($targetDate, $strategy, $targetOutcome);

        return DB::transaction(function () use ($case, $targetDate, $strategy, $proposedBy, $reason, $targetOutcome, $initialStatus) {

            // =========================
            // 1) Tentukan routing awal
            // =========================
            $route = $this->resolveInitialRoute($proposedBy);

            // kalau caller kasih initialStatus, pakai itu (override)
            $status = $initialStatus ?: $route['status'];

            // needs_tl_approval ikut status bila override
            $needsTlApproval = $route['needs_tl_approval'];
            if ($initialStatus) {
                $needsTlApproval = (strtolower($initialStatus) === self::ST_PENDING_TL);
            }

            // =========================
            // 2) Create target
            // =========================
            $payload = [
                'npl_case_id'     => $case->id,
                'target_date'     => $targetDate->toDateString(),
                'strategy'        => $strategy,
                'target_outcome'  => strtolower($targetOutcome),
                'status'          => $status,
                'is_active'       => false,
                'proposed_by'     => $proposedBy,
                'reason'          => $reason,
            ];

            // kolom baru kamu: needs_tl_approval
            if (Schema::hasColumn('case_resolution_targets', 'needs_tl_approval')) {
                $payload['needs_tl_approval'] = $needsTlApproval ? 1 : 0;
            }

            return CaseResolutionTarget::create($payload);
        });
    }

    public function approveTl(CaseResolutionTarget $target, int $byUserId, ?string $notes = null): CaseResolutionTarget
    {
        return DB::transaction(function () use ($target, $byUserId, $notes) {

            $target = CaseResolutionTarget::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            // kalau target ini memang tidak butuh TL, harusnya tidak masuk inbox TL
            if (Schema::hasColumn('case_resolution_targets', 'needs_tl_approval') && (int)$target->needs_tl_approval === 0) {
                throw ValidationException::withMessages([
                    'status' => 'Target ini tidak membutuhkan approval TL (langsung ke Kasi).',
                ]);
            }

            if (strtolower((string)$target->status) !== self::ST_PENDING_TL) {
                throw ValidationException::withMessages([
                    'status' => 'Target tidak dalam status PENDING_TL.',
                ]);
            }

            $target->status         = self::ST_PENDING_KASI;
            $target->tl_approved_by = $byUserId;
            $target->tl_approved_at = now();
            $target->tl_notes       = $notes ? trim($notes) : null;
            $target->save();

            return $target;
        });
    }

    public function approveKasi(CaseResolutionTarget $target, int $userId, ?string $notes = null): CaseResolutionTarget
    {
        return DB::transaction(function () use ($target, $userId, $notes) {

            $target = CaseResolutionTarget::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            $st = strtolower((string)$target->status);

            // ✅ KASI boleh approve kalau:
            // - status pending_kasi (normal)
            // - ATAU status pending_tl tapi needs_tl_approval=0 (skip TL case)
            $skipTl = false;
            if (Schema::hasColumn('case_resolution_targets', 'needs_tl_approval')) {
                $skipTl = ((int)$target->needs_tl_approval === 0);
            }

            if (!($st === self::ST_PENDING_KASI || ($st === self::ST_PENDING_TL && $skipTl))) {
                throw ValidationException::withMessages([
                    'status' => 'Target tidak dalam status PENDING_KASI (atau belum memenuhi syarat skip TL).',
                ]);
            }

            // Supersede target lain (jaga 1 target aktif per case)
            CaseResolutionTarget::query()
                ->where('npl_case_id', $target->npl_case_id)
                ->where('id', '!=', $target->id)
                ->where(function ($q) {
                    $q->where('is_active', 1)
                      ->orWhere('status', self::ST_ACTIVE);
                })
                ->update([
                    'is_active'          => 0,
                    'status'             => self::ST_SUPERSEDED,
                    'deactivated_by'     => $userId,
                    'deactivated_at'     => now(),
                    'deactivated_reason' => 'Digantikan oleh target baru yang telah disetujui Kasi.',
                ]);

            $now = now();

            $target->fill([
                'kasi_approved_by' => $userId,
                'kasi_approved_at' => $now,
                'approved_by'      => $userId,
                'approved_at'      => $now,

                'kasi_notes'       => $notes ? trim($notes) : null,

                'status'           => self::ST_ACTIVE,
                'is_active'        => 1,
                'activated_by'     => $userId,
                'activated_at'     => $now,
            ]);

            $target->save();

            // Auto-create agenda follow-up
            $this->syncAgendasForActiveTarget($target, $userId);

            return $target;
        });
    }

    public function reject(CaseResolutionTarget $target, int $rejectorUserId, string $rejectReason): CaseResolutionTarget
    {
        return DB::transaction(function () use ($target, $rejectorUserId, $rejectReason) {

            $target->refresh();

            if (!in_array($target->status, [self::ST_PENDING_TL, self::ST_PENDING_KASI], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Target hanya bisa ditolak saat pending.',
                ]);
            }

            $target->update([
                'status'        => self::ST_REJECTED,
                'reject_reason' => $rejectReason,
                'rejected_by'   => $rejectorUserId,
                'rejected_at'   => now(),
                'is_active'     => 0,
            ]);

            return $target;
        });
    }

    public function getActiveTarget(int $nplCaseId): ?CaseResolutionTarget
    {
        return CaseResolutionTarget::query()
            ->where('npl_case_id', $nplCaseId)
            ->where('is_active', 1)
            ->latest('activated_at')
            ->first();
    }

    private function normalizeDate(Carbon|string $targetDate): Carbon
    {
        return $targetDate instanceof Carbon
            ? $targetDate->copy()->startOfDay()
            : Carbon::parse($targetDate)->startOfDay();
    }

    private function validateBusinessRules(Carbon $targetDate, ?string $strategy, string $targetOutcome): void
    {
        if ($targetDate->lt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'target_date' => 'Target penyelesaian tidak boleh di tanggal yang sudah lewat.',
            ]);
        }

        $allowedOutcome = ['lunas', 'lancar'];
        if (!in_array(strtolower($targetOutcome), $allowedOutcome, true)) {
            throw ValidationException::withMessages([
                'target_outcome' => 'Target kondisi tidak valid (lunas/lancar).',
            ]);
        }

        if ($strategy !== null) {
            $allowed = ['lelang', 'rs', 'ayda', 'intensif', 'jual_jaminan'];
            if (!in_array(strtolower($strategy), $allowed, true)) {
                throw ValidationException::withMessages([
                    'strategy' => 'Strategi tidak valid.',
                ]);
            }
        }
    }

    /**
     * Deteksi apakah user punya TL.
     * Rule praktis (sesuai kasus Helmi):
     * - Kalau leader_role TL* => perlu TL approval
     * - Kalau leader_role KSL/KSR/others => langsung pending_kasi
     * - Kalau tidak ada org assignment => default aman: pending_tl
     */
    private function resolveInitialRoute(int $userId): array
    {
        $oa = OrgAssignment::query()
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->first();

        // default aman: butuh TL (kalau mapping org belum lengkap)
        if (!$oa) {
            return [
                'needs_tl_approval' => true,
                'status'            => self::ST_PENDING_TL,
                'leader_role'       => null,
            ];
        }

        $leaderRole = strtoupper((string) $oa->leader_role);

        $needsTl = in_array($leaderRole, ['TL', 'TLL', 'TLR', 'TLF'], true);

        return [
            'needs_tl_approval' => $needsTl,
            'status'            => $needsTl ? self::ST_PENDING_TL : self::ST_PENDING_KASI,
            'leader_role'       => $leaderRole,
        ];
    }

    public function syncAgendasForActiveTarget(CaseResolutionTarget $target, ?int $actorId = null): void
    {
        if (strtolower((string)$target->status) !== self::ST_ACTIVE || (int)$target->is_active !== 1) {
            return;
        }

        DB::transaction(function () use ($target, $actorId) {

            // =========================
            // 1) Tentukan "last step" dari CaseAction terakhir (relevan)
            // =========================
            $lastStep = -1;

            try {
                $lastAction = \App\Models\CaseAction::query()
                    ->where('npl_case_id', $target->npl_case_id)
                    ->whereNotNull('action_type')
                    ->orderByDesc('action_at')
                    ->first();


                if ($lastAction) {
                    $lastStep = $this->mapActionTypeToStep((string)$lastAction->action_type);
                }
            } catch (\Throwable $e) {
                $lastStep = -1;
            }

            $startFrom = $lastStep + 1; // kalau last = visit (1) => startFrom = 2 (evaluation)
            if ($startFrom > 2) {
                // sudah sampai evaluation, tidak perlu buat agenda lagi
                return;
            }

            // =========================
            // 2) Template milestone
            //    (due_at relatif dari "now")
            // =========================
            $items = [
                [
                    'agenda_type' => 'wa',
                    'title'       => 'Follow-up WA debitur (sesuai target)',
                    'due_at'      => now()->addDay()->setTime(9, 0),
                    'notes'       => $target->reason ? "Target notes: {$target->reason}" : null,
                    'evidence_required' => 1,
                    'step'        => 0,
                ],
                [
                    'agenda_type' => 'visit',
                    'title'       => 'Kunjungan/Visit lapangan untuk validasi kondisi',
                    'due_at'      => now()->addDays(3)->setTime(10, 0),
                    'notes'       => $target->strategy ? "Strategi: {$target->strategy}" : null,
                    'evidence_required' => 1,
                    'step'        => 1,
                ],
                [
                    'agenda_type' => 'evaluation',
                    'title'       => 'Evaluasi Non-Lit/Lit bila target belum tercapai',
                    'due_at'      => now()->addDays(7)->setTime(15, 0),
                    'notes'       => $target->kasi_notes ? "Catatan Kasi: {$target->kasi_notes}" : null,
                    'evidence_required' => 0,
                    'step'        => 2,
                ],
            ];

            // =========================
            // 3) Create agenda mulai dari step berikutnya
            //    + idempotent: cek per target+agenda_type
            // =========================
            foreach ($items as $it) {

                if ((int)$it['step'] < (int)$startFrom) {
                    continue; // ✅ skip milestone yang sudah terlewati
                }

                $exists = \App\Models\AoAgenda::query()
                    ->where('npl_case_id', $target->npl_case_id)
                    ->where('resolution_target_id', $target->id)
                    ->where('agenda_type', $it['agenda_type'])
                    ->exists();

                if ($exists) continue;

                \App\Models\AoAgenda::create([
                    'npl_case_id'          => $target->npl_case_id,
                    'resolution_target_id' => $target->id,

                    'ao_id'                => null,

                    'agenda_type'          => $it['agenda_type'],
                    'title'                => $it['title'],
                    'notes'                => $it['notes'],

                    'planned_at'           => now(),
                    'due_at'               => $it['due_at'],
                    'status'               => \App\Models\AoAgenda::STATUS_PLANNED,

                    'evidence_required'    => (int) $it['evidence_required'],

                    'created_by'           => $actorId,
                    'updated_by'           => $actorId,
                ]);
            }
        });
    }

    /**
     * Mapping action_type -> milestone step (0=wa,1=visit,2=evaluation)
     * Sesuaikan kalau ada variasi value di DB.
     */
    protected function mapActionTypeToStep(string $actionType): int
    {
        $t = strtolower(trim($actionType));

        // milestone progress yang valid
        if ($t === 'whatsapp') return 0;
        if ($t === 'visit')    return 1;

        // ✅ legal/escalation chain dianggap sudah "lebih jauh" dari WA/Visit
        // jadi kita set ke step 1 supaya agenda yang tersisa hanya EVALUATION
        if (in_array($t, ['sp1','sp2','sp3','spak','spjad','spt','legal'], true)) {
            return 1;
        }

        // optional: non_litigasi kamu mau dianggap apa?
        // kalau non_litigasi dianggap sudah pernah follow-up, bisa set 0 atau 1.
        if ($t === 'non_litigasi') {
            return 0; // atau 1 kalau kamu anggap sudah "eskalasi"
        }

        return -1;
    }

    public function forceActivateByKti(
        NplCase $case,
        string $targetDate,
        ?string $strategy,
        int $inputBy,
        ?string $reason,
        string $targetOutcome
    ): CaseResolutionTarget {

        $targetDateC = $this->normalizeDate($targetDate);
        $this->validateBusinessRules($targetDateC, $strategy, $targetOutcome);

        return DB::transaction(function () use ($case, $targetDateC, $strategy, $inputBy, $reason, $targetOutcome) {

            $now = now();

            // 1) Matikan target aktif lama (kalau ada) + set superseded
            CaseResolutionTarget::query()
                ->where('npl_case_id', $case->id)
                ->where(function ($q) {
                    $q->where('is_active', 1)
                      ->orWhere('status', self::ST_ACTIVE);
                })
                ->update([
                    'is_active'          => 0,
                    'status'             => self::ST_SUPERSEDED,
                    'deactivated_by'     => $inputBy,
                    'deactivated_at'     => $now,
                    'deactivated_reason' => 'Digantikan oleh input KTI' . ($reason ? (': '.$reason) : ''),
                ]);

            // 2) Insert target baru langsung ACTIVE (tanpa TL/Kasi)
            $t = new CaseResolutionTarget();
            $t->npl_case_id    = $case->id;
            $t->target_date    = $targetDateC->toDateString();
            $t->strategy       = $strategy ? strtolower(trim($strategy)) : null;
            $t->target_outcome = strtolower(trim($targetOutcome));

            $t->status     = self::ST_ACTIVE;
            $t->is_active  = 1;

            // audit trail
            $t->proposed_by   = $inputBy;
            $t->activated_by  = $inputBy;
            $t->activated_at  = $now;
            $t->tl_notes      = '[INPUT OLEH KTI]' . ($reason ? ' '.$reason : '');

            if (Schema::hasColumn('case_resolution_targets', 'needs_tl_approval')) {
                $t->needs_tl_approval = 0;
            }

            if (Schema::hasColumn('case_resolution_targets', 'approved_by')) {
                $t->approved_by = $inputBy;
            }
            if (Schema::hasColumn('case_resolution_targets', 'approved_at')) {
                $t->approved_at = $now;
            }

            if (Schema::hasColumn('case_resolution_targets', 'reason')) {
                $t->reason = $reason ? trim($reason) : null;
            } else {
                $t->tl_notes = $reason ? ('[INPUT KTI] ' . trim($reason)) : null;
            }

            $t->save();

            // 3) Auto-create agenda follow-up
            $this->syncAgendasForActiveTarget($t, $inputBy);

            return $t;
        });
    }
}
