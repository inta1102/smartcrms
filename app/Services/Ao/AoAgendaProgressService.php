<?php

namespace App\Services\Ao;

use App\Models\AoAgenda;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AoAgendaProgressService
{
    public function start(AoAgenda $agenda, int $userId): void
    {
        DB::transaction(function () use ($agenda, $userId) {

            if (in_array($agenda->status, [AoAgenda::STATUS_DONE, AoAgenda::STATUS_CANCELLED], true)) {
                abort(422, 'Agenda sudah selesai / dibatalkan.');
            }

            // kalau agenda overdue, start tetap boleh dan status jadi in_progress
            $agenda->status = AoAgenda::STATUS_IN_PROGRESS;

            if ($agenda->started_at === null) $agenda->started_at = now();
            if ($agenda->started_by === null) $agenda->started_by = $userId;

            $agenda->updated_by = $userId;
            $agenda->save();
        });
    }

    public function complete(
        AoAgenda $agenda,
        int $userId,
        ?string $resultSummary = null,
        ?string $resultDetail = null
    ): void {
        DB::transaction(function () use ($agenda, $userId, $resultSummary, $resultDetail) {

            if ($agenda->status === AoAgenda::STATUS_CANCELLED) {
                abort(422, 'Agenda dibatalkan.');
            }

            // RULE evidence (pilih salah satu gaya audit)
            if ($agenda->evidence_required) {
                $hasFile  = !empty($agenda->evidence_path);
                $hasNotes = !empty($agenda->evidence_notes);

                // ✅ versi ketat: harus file
                // if (!$hasFile) abort(422, 'Agenda ini membutuhkan bukti berupa file.');

                // ✅ versi fleksibel: file ATAU notes (misal link WA/resi)
                if (!$hasFile && !$hasNotes) {
                    abort(422, 'Agenda ini membutuhkan bukti (upload file atau isi evidence notes).');
                }
            }

            $agenda->status = AoAgenda::STATUS_DONE;

            if ($agenda->completed_at === null) $agenda->completed_at = now();
            if ($agenda->completed_by === null) $agenda->completed_by = $userId;

            if ($resultSummary !== null) $agenda->result_summary = $resultSummary;
            if ($resultDetail !== null)  $agenda->result_detail  = $resultDetail;

            $agenda->updated_by = $userId;
            $agenda->save();
        });
    }

    public function reschedule(AoAgenda $agenda, int $userId, string $dueAt, string $reason): void
    {
        DB::transaction(function () use ($agenda, $userId, $dueAt, $reason) {

            if ($agenda->status === AoAgenda::STATUS_DONE) {
                abort(422, 'Agenda sudah selesai, tidak bisa reschedule.');
            }

            $newDue = Carbon::parse($dueAt);

            // Optional: larang reschedule ke masa lalu
            // if ($newDue->lt(now())) abort(422, 'Due At tidak boleh di masa lalu.');

            $agenda->due_at = $newDue;
            $agenda->status = AoAgenda::STATUS_PLANNED;

            $agenda->rescheduled_at = now();
            $agenda->rescheduled_by = $userId;
            $agenda->reschedule_reason = trim($reason);

            $agenda->updated_by = $userId;
            $agenda->save();
        });
    }

    public function cancel(AoAgenda $agenda, int $userId, ?string $reason = null): void
    {
        DB::transaction(function () use ($agenda, $userId, $reason) {

            if ($agenda->status === AoAgenda::STATUS_DONE) {
                abort(422, 'Agenda sudah selesai.');
            }

            $agenda->status = AoAgenda::STATUS_CANCELLED;
            $agenda->updated_by = $userId;

            if ($reason !== null && trim($reason) !== '') {
                $append = "CANCEL: " . trim($reason);
                $agenda->result_summary = trim(($agenda->result_summary ? $agenda->result_summary . "\n\n" : '') . $append);
            }

            $agenda->save();
        });
    }

    public function markOverdue(?int $actorId = null): int
    {
        // overdue jika due_at lewat dan masih planned/in_progress
        // NOTE: kalau mau audit "SYSTEM", kirim actorId = 0 atau null.
        $q = AoAgenda::whereNotNull('due_at')
            ->whereIn('status', [AoAgenda::STATUS_PLANNED, AoAgenda::STATUS_IN_PROGRESS])
            ->where('due_at', '<', now());

        // optional:
        // if (!is_null($actorId)) $q->update(['status' => AoAgenda::STATUS_OVERDUE, 'updated_by' => $actorId]);

        return $q->update(['status' => AoAgenda::STATUS_OVERDUE]);
    }
}
