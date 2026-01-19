<?php

namespace App\Services\Crms;

use App\Models\ActionSchedule;
use App\Models\CaseAction;
use App\Models\NonLitigationAction;

class NonLitApprovalService
{
    /**
     * TL approve: PENDING_TL -> PENDING_KASI (bukan final approve)
     */
    public function approveTl(
        NonLitigationAction $nonLit,
        int $userId,
        ?string $notes
    ): void {
        abort_unless(
            $nonLit->status === NonLitigationAction::STATUS_PENDING_TL,
            422,
            'Status non-litigasi tidak valid untuk approval TL.'
        );

        $notes = is_null($notes) ? null : trim($notes);
        if ($notes === '') $notes = null;

        // status gate: TL hanya meneruskan ke KASI
        $nonLit->status         = NonLitigationAction::STATUS_PENDING_KASI;
        $nonLit->approval_notes = $notes;
        $nonLit->approved_by    = $userId;
        $nonLit->approved_at    = now();
        $nonLit->save();

        // Timeline event (idempotent + updateable)
        $ca = CaseAction::query()->firstOrNew([
            'npl_case_id'   => $nonLit->npl_case_id,
            'source_system' => 'non_litigation_tl_approve',
            'source_ref_id' => (string) $nonLit->id,
        ]);

        $ca->fill([
            'user_id'         => $userId,
            'action_at'       => now(),
            'action_type'     => 'non_litigasi',
            'description'     => $this->buildEvent("NON-LITIGASI TL APPROVED", $nonLit, $notes),
            'result'          => 'TL_APPROVED',
            'next_action'     => 'Menunggu approval KASI (Non-Litigasi)',
            'next_action_due' => null,
            'meta' => [
                'event'                    => 'tl_approved',
                'non_litigation_action_id' => $nonLit->id,
                'non_litigation_type'      => $nonLit->action_type,
                'approval_notes'           => $notes,
            ],
        ]);

        $ca->save();
    }

    /**
     * TL reject: PENDING_TL -> REJECTED
     */
    public function rejectTl(NonLitigationAction $nonLit, int $tlUserId, string $reason): void
    {
        abort_unless(
            $nonLit->status === NonLitigationAction::STATUS_PENDING_TL,
            422,
            'Status tidak valid untuk reject TL.'
        );

        $reason = trim($reason);
        abort_unless($reason !== '', 422, 'Alasan penolakan wajib diisi.');

        $nonLit->status           = NonLitigationAction::STATUS_REJECTED;
        $nonLit->rejected_by      = $tlUserId;
        $nonLit->rejected_by_name = auth()->user()?->name ?? auth()->user()?->username ?? null;
        $nonLit->rejected_at      = now();
        $nonLit->rejection_notes  = $reason;
        $nonLit->save();

        $ca = CaseAction::query()->firstOrNew([
            'npl_case_id'   => $nonLit->npl_case_id,
            'source_system' => 'non_litigation_tl_reject',
            'source_ref_id' => (string) $nonLit->id,
        ]);

        $ca->fill([
            'user_id'         => $tlUserId,
            'action_at'       => now(),
            'action_type'     => 'non_litigasi',
            'description'     => $this->buildEvent("NON-LITIGASI TL REJECTED", $nonLit, $reason),
            'result'          => 'TL_REJECTED',
            'next_action'     => 'Revisi usulan non-litigasi / tindak lanjut penanganan',
            'next_action_due' => null,
            'meta' => [
                'event'                    => 'tl_rejected',
                'non_litigation_action_id' => $nonLit->id,
                'non_litigation_type'      => $nonLit->action_type,
                'rejection_notes'          => $reason,
            ],
        ]);

        $ca->save();

        // cancel schedule monitoring pending (jaga-jaga)
        ActionSchedule::query()
            ->where('npl_case_id', $nonLit->npl_case_id)
            ->where('schedulable_type', NonLitigationAction::class)
            ->where('schedulable_id', $nonLit->id)
            ->where('type', 'monitoring')
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);
    }

    /**
     * KASI approve: PENDING_KASI (atau skip TL) -> APPROVED (final)
     */
    public function approveKasi(NonLitigationAction $nonLit, int $kasiUserId, array $validated): void
    {
        $isInbox = $nonLit->status === NonLitigationAction::STATUS_PENDING_KASI
            || ($nonLit->status === NonLitigationAction::STATUS_PENDING_TL && (int) $nonLit->needs_tl_approval === 0);

        abort_unless($isInbox, 422, 'Status tidak valid untuk approval KASI.');

        $notes = isset($validated['approval_notes']) ? trim((string) $validated['approval_notes']) : null;
        if ($notes === '') $notes = null;

        // final approve (idempotent: kalau sudah approved, tetap update notes/dates bila dikirim)
        $nonLit->status           = NonLitigationAction::STATUS_APPROVED;
        $nonLit->approved_by      = $kasiUserId;
        $nonLit->approved_by_name = auth()->user()?->name ?? auth()->user()?->username ?? null;
        $nonLit->approved_at      = $nonLit->approved_at ?? now();
        $nonLit->approval_notes   = $notes;

        if (!empty($validated['effective_date'])) {
            $nonLit->effective_date = $validated['effective_date'];
        }
        if (!empty($validated['monitoring_next_due'])) {
            $nonLit->monitoring_next_due = $validated['monitoring_next_due'];
        }

        $nonLit->save();

        // CaseAction final (idempotent + updateable)
        $payload = [
            'npl_case_id'   => $nonLit->npl_case_id,
            'user_id'       => $kasiUserId,
            'source_system' => 'non_litigation',
            'source_ref_id' => (string) $nonLit->id,

            'action_at'   => now(),
            'action_type' => 'non_litigasi',
            'description' => $this->buildApprovedDescription($nonLit),
            'result'      => 'APPROVED',

            'next_action'     => $this->buildNextAction($nonLit),
            'next_action_due' => $nonLit->monitoring_next_due,

            'meta' => [
                'non_litigation_action_id' => $nonLit->id,
                'non_litigation_type'      => $nonLit->action_type,
                'effective_date'           => $nonLit->effective_date ? (string) $nonLit->effective_date->format('Y-m-d') : null,
                'commitment_amount'        => $nonLit->commitment_amount,
                'approval_notes'           => $nonLit->approval_notes,
            ],
        ];

        $existing = CaseAction::query()
            ->where('npl_case_id', $nonLit->npl_case_id)
            ->where('source_system', 'non_litigation')
            ->where('source_ref_id', (string) $nonLit->id)
            ->first();

        if (!$existing) {
            $caseAction = CaseAction::create($payload);
            if (!$nonLit->case_action_id) {
                $nonLit->case_action_id = $caseAction->id;
                $nonLit->save();
            }
        } else {
            $existing->fill($payload)->save();
            if (!$nonLit->case_action_id) {
                $nonLit->case_action_id = $existing->id;
                $nonLit->save();
            }
        }

        // Schedule monitoring (idempotent)
        $existingSchedule = ActionSchedule::query()
            ->where('npl_case_id', $nonLit->npl_case_id)
            ->where('schedulable_type', NonLitigationAction::class)
            ->where('schedulable_id', $nonLit->id)
            ->where('type', 'monitoring')
            ->where('status', 'pending')
            ->first();

        if ($nonLit->monitoring_next_due) {
            $payloadSched = [
                'npl_case_id'      => $nonLit->npl_case_id,
                'schedulable_type' => NonLitigationAction::class,
                'schedulable_id'   => $nonLit->id,
                'type'             => 'monitoring',
                'title'            => 'Monitoring Non-Litigasi',
                'notes'            => 'Monitoring hasil non-litigasi yang telah disetujui.',
                'scheduled_at'     => $nonLit->monitoring_next_due,
                'status'           => 'pending',
                'created_by'       => $kasiUserId,
            ];

            if (!$existingSchedule) {
                ActionSchedule::create($payloadSched);
            } else {
                $existingSchedule->fill([
                    'scheduled_at' => $payloadSched['scheduled_at'],
                    'title'        => $payloadSched['title'],
                    'notes'        => $payloadSched['notes'],
                ])->save();
            }
        } else {
            if ($existingSchedule) {
                $existingSchedule->status = 'cancelled';
                $existingSchedule->save();
            }
        }
    }

    /**
     * KASI reject: PENDING_KASI (atau skip TL) -> REJECTED
     */
    public function rejectKasi(NonLitigationAction $nonLit, int $kasiUserId, string $reason): void
    {
        $isInbox = $nonLit->status === NonLitigationAction::STATUS_PENDING_KASI
            || ($nonLit->status === NonLitigationAction::STATUS_PENDING_TL && (int) $nonLit->needs_tl_approval === 0);

        abort_unless($isInbox, 422, 'Status tidak valid untuk reject KASI.');

        $reason = trim($reason);
        abort_unless($reason !== '', 422, 'Alasan penolakan wajib diisi.');

        $nonLit->status           = NonLitigationAction::STATUS_REJECTED;
        $nonLit->rejected_by      = $kasiUserId;
        $nonLit->rejected_by_name = auth()->user()?->name ?? auth()->user()?->username ?? null;
        $nonLit->rejected_at      = now();
        $nonLit->rejection_notes  = $reason;
        $nonLit->save();

        $ca = CaseAction::query()->firstOrNew([
            'npl_case_id'   => $nonLit->npl_case_id,
            'source_system' => 'non_litigation_kasi_reject',
            'source_ref_id' => (string) $nonLit->id,
        ]);

        $ca->fill([
            'user_id'         => $kasiUserId,
            'action_at'       => now(),
            'action_type'     => 'non_litigasi',
            'description'     => $this->buildEvent("NON-LITIGASI KASI REJECTED", $nonLit, $reason),
            'result'          => 'KASI_REJECTED',
            'next_action'     => 'Revisi usulan non-litigasi / tindak lanjut penanganan',
            'next_action_due' => null,
            'meta' => [
                'event'                    => 'kasi_rejected',
                'non_litigation_action_id' => $nonLit->id,
                'non_litigation_type'      => $nonLit->action_type,
                'rejection_notes'          => $reason,
            ],
        ]);

        $ca->save();

        ActionSchedule::query()
            ->where('npl_case_id', $nonLit->npl_case_id)
            ->where('schedulable_type', NonLitigationAction::class)
            ->where('schedulable_id', $nonLit->id)
            ->where('type', 'monitoring')
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);
    }

    // ================= helpers text =================

    private function buildEvent(string $title, NonLitigationAction $nonLit, ?string $notes): string
    {
        $type    = strtoupper((string) $nonLit->action_type);
        $summary = trim((string) $nonLit->proposal_summary);

        $parts = [];
        $parts[] = "{$title} ({$type})";
        if ($summary !== '') $parts[] = "Ringkasan: {$summary}";
        if ($notes !== null && trim((string)$notes) !== '') $parts[] = "Catatan: " . trim((string)$notes);

        return implode("\n", $parts);
    }

    private function buildApprovedDescription(NonLitigationAction $nonLit): string
    {
        $type    = strtoupper((string) $nonLit->action_type);
        $summary = trim((string) $nonLit->proposal_summary);

        $parts = [];
        $parts[] = "NON-LITIGASI APPROVED ({$type})";
        if ($summary !== '') $parts[] = "Ringkasan: {$summary}";

        if (!empty($nonLit->proposal_detail)) {
            $detail = is_array($nonLit->proposal_detail)
                ? json_encode($nonLit->proposal_detail, JSON_UNESCAPED_UNICODE)
                : (string) $nonLit->proposal_detail;
            $detail = trim($detail);
            if ($detail !== '') $parts[] = "Detail: {$detail}";
        }

        if ($nonLit->effective_date) {
            $parts[] = "Effective: " . $nonLit->effective_date->format('d-m-Y');
        }

        if (!is_null($nonLit->commitment_amount)) {
            $parts[] = "Komitmen: Rp " . number_format((float) $nonLit->commitment_amount, 0, ',', '.');
        }

        return implode("\n", $parts);
    }

    private function buildNextAction(NonLitigationAction $nonLit): ?string
    {
        $t = (string) ($nonLit->action_type ?? 'non-litigasi');

        return $nonLit->monitoring_next_due
            ? "Monitoring tindak lanjut non-litigasi ({$t})"
            : "Monitoring tindak lanjut non-litigasi";
    }
}
