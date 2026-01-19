<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\LegalEvent;
use App\Models\LegalAction;
use App\Models\LegalCase;
use App\Services\Legal\LegalActionStatusService;

class ProcessSomasiDeadlines extends Command
{
    protected $signature = 'legal:process-somasi-deadlines {--dry-run}';
    protected $description = 'Auto mark SOMASI no-response when deadline passed + escalate to next legal action (draft).';

    public function handle()
    {
        $dry = (bool) $this->option('dry-run');

        // ambil semua deadline somasi yang sudah lewat & masih scheduled
        $deadlines = LegalEvent::query()
            ->where('event_type', 'somasi_deadline')
            ->where('status', 'scheduled')
            ->whereNotNull('event_at')
            ->where('event_at', '<=', now())
            ->orderBy('event_at')
            ->get();

        if ($deadlines->isEmpty()) {
            $this->info('No expired somasi deadlines.');
            return Command::SUCCESS;
        }

        $this->info("Found {$deadlines->count()} expired somasi deadlines.");

        $svc = app(LegalActionStatusService::class);

        $processed = 0;
        foreach ($deadlines as $deadline) {

            /** @var LegalAction|null $action */
            $action = LegalAction::find($deadline->legal_action_id);
            if (!$action) continue;

            // hanya somasi
            if (($action->action_type ?? '') !== 'somasi') continue;

            // skip kalau action sudah final
            if (in_array($action->status, ['completed', 'cancelled'], true)) continue;

            // cek event responded / no_response sudah ada belum
            $hasResponded = LegalEvent::where('legal_action_id', $action->id)
                ->where('event_type', 'somasi_responded')
                ->exists();

            $hasNoResponse = LegalEvent::where('legal_action_id', $action->id)
                ->where('event_type', 'somasi_no_response')
                ->exists();

            if ($hasResponded || $hasNoResponse) {
                // kalau sudah ada final marker, tutup deadline saja biar tidak kepick lagi
                if (!$dry) {
                    $deadline->status = 'done';
                    $deadline->remind_at = null;
                    $deadline->save();
                }
                continue;
            }

            $this->line("→ Action #{$action->id} (case={$action->legal_case_id}) deadline={$deadline->event_at}");

            if ($dry) {
                $processed++;
                continue;
            }

            DB::transaction(function () use ($svc, $deadline, $action) {

                // 1) create somasi_no_response (DONE) idempotent
                LegalEvent::firstOrCreate(
                    [
                        'legal_action_id' => $action->id,
                        'event_type'      => 'somasi_no_response',
                    ],
                    [
                        'legal_case_id' => $action->legal_case_id,
                        'title'         => 'Somasi: tidak ada respon (otomatis)',
                        'event_at'      => now(),
                        'status'        => 'done',
                        'notes'         => 'Auto-generated: deadline somasi terlewati tanpa respon.',
                        'created_by'    => null, // system
                    ]
                );

                // 2) close deadline
                $deadline->status = 'done';
                $deadline->remind_at = null;
                $deadline->save();

                // 3) update status action → failed (agar flow somasi jelas)
                // Kalau status belum "waiting", tetap boleh ke failed? sesuai flowByType kamu: waiting -> failed, submitted -> ??? (di somasi flow kamu submitted -> waiting/cancelled)
                // Jadi kalau masih submitted, kita pindah ke waiting dulu (safe), lalu failed.
                if ($action->status === 'submitted') {
                    $svc->transition($action, 'waiting', null, 'SYSTEM: auto move to waiting before marking no-response.', now());
                    $action = $action->fresh();
                }

                if ($action->status === 'waiting') {
                    $svc->transition($action, 'failed', null, 'SYSTEM: Somasi no-response (deadline passed).', now());
                    $action = $action->fresh();
                }

                // 4) eskalasi: buat legal action berikutnya (draft)
                $nextType = config('legal.escalation_map.somasi', 'ht_execution');

                $legalCase = LegalCase::find($action->legal_case_id);
                if (!$legalCase) return;

                $nextSeq = (int) LegalAction::where('legal_case_id', $legalCase->id)->max('sequence_no');
                $nextSeq = $nextSeq + 1;

                // idempotent: jangan bikin dobel action eskalasi untuk somasi ini
                $already = LegalAction::where('legal_case_id', $legalCase->id)
                    ->where('action_type', $nextType)
                    ->where('sequence_no', $nextSeq) // optional, tapi aman
                    ->exists();

                if (!$already) {
                    LegalAction::create([
                        'legal_case_id' => $legalCase->id,
                        'action_type'   => $nextType,
                        'sequence_no'   => $nextSeq,
                        'status'        => 'draft',
                        'notes'         => "Auto-escalation from SOMASI no-response (action_id={$action->id}).",
                        'start_at'      => now(),
                    ]);
                }
            });

            $processed++;
        }

        $this->info("Processed: {$processed}");
        return Command::SUCCESS;
    }
}
