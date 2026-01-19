<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\LegalEvent;
use App\Models\LegalAction;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LegalSomasiNoResponse extends Command
{
    protected $signature = 'legal:somasi:no-response';
    protected $description = 'Auto mark Somasi as FAILED if no response after deadline';

    public function handle()
    {
        $now = now();

        $events = LegalEvent::where('event_type', 'somasi_deadline')
            ->where('status', 'scheduled')
            ->where('event_at', '<', $now)
            ->get();

        if ($events->isEmpty()) {
            $this->info('No overdue somasi found.');
            return;
        }

        foreach ($events as $event) {
            DB::transaction(function () use ($event, $now) {

                /** @var LegalAction $action */
                $action = LegalAction::find($event->legal_action_id);

                if (! $action || $action->status !== 'waiting') {
                    // safety guard
                    $event->status = 'done';
                    $event->save();
                    return;
                }

                // 1️⃣ Update LegalAction
                $action->status  = 'failed';
                $action->end_at  = $now;
                $action->notes   = trim(($action->notes ?? '') . "\n[Auto] Tidak ada respon sampai batas waktu.");
                $action->save();

                // 2️⃣ Tutup deadline event
                $event->status    = 'done';
                $event->remind_at = null;
                $event->save();

                // 3️⃣ Log event NO RESPONSE
                LegalEvent::create([
                    'legal_case_id'   => $action->legal_case_id,
                    'legal_action_id' => $action->id,
                    'event_type'      => 'somasi_no_response',
                    'title'           => 'Somasi tidak mendapat respon',
                    'event_at'        => $now,
                    'notes'           => 'Otomatis ditandai tidak ada respon setelah deadline.',
                    'status'          => 'done',
                    'created_by'      => null, // system
                ]);

                // 4️⃣ Eskalasi marker
                LegalEvent::create([
                    'legal_case_id'   => $action->legal_case_id,
                    'legal_action_id' => $action->id,
                    'event_type'      => 'legal_escalation_ready',
                    'title'           => 'Siap eskalasi tindakan hukum lanjutan',
                    'event_at'        => $now,
                    'notes'           => 'Rekomendasi: HT Execution / Gugatan Perdata.',
                    'status'          => 'scheduled',
                    'created_by'      => null,
                ]);
            });

            $this->info("Somasi action {$event->legal_action_id} auto FAILED.");
        }
    }
}
