<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\CaseResolutionTarget;
use App\Models\AoAgenda;
use App\Models\CaseAction;

class ResyncTargetMilestones extends Command
{
    protected $signature = 'targets:resync-milestones
        {--active : hanya target ACTIVE}
        {--case= : filter npl_case_id}
        {--dry-run : hanya tampilkan perubahan, tidak menulis DB}';

    protected $description = 'Resync milestone AoAgenda untuk target yang sudah terlanjur dibuat, mengikuti progress terakhir';

    public function handle()
    {
        $dry = (bool)$this->option('dry-run');

        $q = CaseResolutionTarget::query();

        if ($this->option('active')) {
            $q->where('status', 'ACTIVE')->where('is_active', 1);
        }

        if ($this->option('case')) {
            $q->where('npl_case_id', (int)$this->option('case'));
        }

        $targets = $q->orderByDesc('id')->get();
        $this->info("Targets: ".$targets->count()." | dry-run=".($dry?'YES':'NO'));

        foreach ($targets as $t) {
            DB::transaction(function () use ($t, $dry) {

                // 1) last action_type (ambil yang terakhir apapun, mapping yang tentukan)
                $last = CaseAction::query()
                    ->where('npl_case_id', $t->npl_case_id)
                    ->whereNotNull('action_type')
                    ->orderByDesc('action_at')
                    ->first();

                $lastStep = $this->mapActionTypeToStep($last?->action_type);
                $startFrom = $lastStep + 1;
                if ($startFrom < 0) $startFrom = 0;
                if ($startFrom > 2) $startFrom = 3; // artinya tidak perlu agenda baru

                // 2) allowed types berdasarkan startFrom
                $typesByStep = [
                    0 => ['wa','visit','evaluation'],
                    1 => ['visit','evaluation'],
                    2 => ['evaluation'],
                    3 => [], // none
                ];
                $allowed = $typesByStep[$startFrom] ?? ['wa','visit','evaluation'];

                // 3) Ambil agenda existing untuk target ini
                $agendas = AoAgenda::query()
                    ->where('resolution_target_id', $t->id)
                    ->whereIn('agenda_type', ['wa','visit','evaluation'])
                    ->get()
                    ->keyBy('id');

                // 4) Tandai agenda yang harus di-skip
                foreach ($agendas as $a) {
                    if (in_array($a->agenda_type, $allowed, true)) {
                        continue; // tetap relevan
                    }

                    // Kalau agenda sudah punya action (ao_agenda_id), jangan diubah
                    $hasAction = CaseAction::query()
                        ->where('ao_agenda_id', $a->id)
                        ->exists();

                    if ($hasAction) {
                        continue;
                    }

                    // Hanya cancel jika status masih PLANNED (atau pending)
                    if (in_array($a->status, ['planned','pending', AoAgenda::STATUS_PLANNED], true)) {
                        $msg = "Target#{$t->id} Case#{$t->npl_case_id}: cancel agenda {$a->agenda_type} (agenda_id={$a->id}) karena startFrom={$startFrom}";
                        $this->line($msg);

                        if (!$dry) {
                            $a->status = 'canceled'; // sesuaikan status enum kamu
                            $a->notes = trim(($a->notes ? $a->notes."\n\n" : '') . "[AUTO] Skipped by resync milestones. Progress action_type=" . ($last?->action_type ?? '-'));
                            $a->save();
                        }
                    }
                }

                // 5) Pastikan agenda yang “allowed” ada (kalau belum ada, create)
                // Paling simpel: panggil service syncAgendasForActiveTarget kamu (yang sudah smart).
                // Tapi hati-hati: service kamu harus sudah idempotent by target+agenda_type (sudah kita rapikan).
                if (!$dry) {
                    app(\App\Services\Crms\ResolutionTargetService::class)
                        ->syncAgendasForActiveTarget($t, null);
                }
            });
        }

        $this->info("Done.");
        return 0;
    }

    protected function mapActionTypeToStep(?string $actionType): int
    {
        $t = strtolower(trim((string)$actionType));

        if ($t === 'whatsapp') return 0;
        if ($t === 'visit') return 1;

        // SP/legal chain => skip WA+VISIT, sisakan evaluation (anggap step 1)
        if (in_array($t, ['sp1','sp2','sp3','spak','spjad','spt','legal'], true)) {
            return 1;
        }

        // non_litigasi optional
        if ($t === 'non_litigasi') return 0;

        return -1;
    }
}
