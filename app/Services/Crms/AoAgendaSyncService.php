<?php

namespace App\Services\Crms;

use App\Models\AoAgenda;
use App\Models\CaseResolutionTarget;
use App\Models\NplCase;
use Illuminate\Support\Facades\DB;

class AoAgendaSyncService
{
    public function syncAgendasForActiveTarget(CaseResolutionTarget $target, int $actorUserId): void
    {
        // hanya untuk target ACTIVE
        if (strtoupper((string)$target->status) !== 'ACTIVE' || (int)$target->is_active !== 1) {
            return;
        }

        DB::transaction(function () use ($target, $actorUserId) {
            // cari AO assignee (minimal: simpan ao_name kalau belum bisa mapping user)
            $assignee = $this->resolveAssigneeFromCase($target);

            // default agenda (minimal 3 item)
            $defaults = [
                [
                    'agenda_type' => 'wa_followup',
                    'title'       => 'WA follow-up debitur + dokumentasi bukti WA',
                    'notes'       => 'Lakukan follow-up via WA sesuai strategi target. Lampirkan bukti WA.',
                    'due_days'    => 2,
                    'evidence_required' => 1,
                ],
                [
                    'agenda_type' => 'visit',
                    'title'       => 'Kunjungan / Visit debitur (bila diperlukan)',
                    'notes'       => 'Jika tidak ada respon WA / perlu klarifikasi, lakukan visit dan catat hasil.',
                    'due_days'    => 7,
                    'evidence_required' => 0,
                ],
                [
                    'agenda_type' => 'evaluation',
                    'title'       => 'Evaluasi eskalasi Non-Lit / Lit bila target tidak tercapai',
                    'notes'       => 'Jika tidak ada progres, siapkan usulan Non-Lit/Lit sesuai SOP.',
                    'due_days'    => 10,
                    'evidence_required' => 0,
                ],
            ];

            foreach ($defaults as $d) {
                // idempotent: jangan dobel (1 agenda_type per target)
                $exists = AoAgenda::where('resolution_target_id', $target->id)
                    ->where('agenda_type', $d['agenda_type'])
                    ->exists();

                if ($exists) continue;

                AoAgenda::create([
                    'title'                => $d['title'],
                    'notes'                => $d['notes'] ?? null,
                    'npl_case_id'           => $target->npl_case_id,
                    'resolution_target_id'  => $target->id,
                    'ao_id'                => $assignee['ao_id'],          // kalau belum ada, isi 0? lebih baik nullable, tapi kolom kamu NOT NULL
                    'agenda_type'           => $d['agenda_type'],
                    'planned_at'            => now(),
                    'due_at'                => now()->addDays((int)$d['due_days']),
                    'status'                => 'planned',
                    'evidence_required'     => (int)($d['evidence_required'] ?? 0),
                    'created_by'            => $actorUserId,
                    'updated_by'            => $actorUserId,
                ]);
            }
        });
    }

    /**
     * Karena DB kamu pakai ao_code & ao_name di loan_accounts,
     * untuk step awal kita buat "assignee" berbasis ao_name/ao_code.
     * Nanti step lanjutan: mapping ao_code -> user_id (tabel users) atau tabel master AO.
     */
    private function resolveAssigneeFromCase(CaseResolutionTarget $target): array
    {
        $case = NplCase::with('loanAccount')->find($target->npl_case_id);

        $aoCode = $case?->loanAccount?->ao_code;
        $aoName = $case?->loanAccount?->ao_name;

        /**
         * IMPORTANT:
         * Kolom ao_id di ao_agendas kamu NOT NULL.
         * Kalau kamu belum punya tabel master AO / mapping user, ada 2 opsi:
         * 1) ubah ao_id jadi nullable (paling aman)
         * 2) sementara isi ao_id = 0 (kurang ideal untuk FK)
         *
         * Aku sarankan ubah ao_id jadi nullable biar clean.
         */
        return [
            'ao_id'   => 0, // sementara (lihat catatan di atas)
            'ao_code' => $aoCode,
            'ao_name' => $aoName,
        ];
    }
}
