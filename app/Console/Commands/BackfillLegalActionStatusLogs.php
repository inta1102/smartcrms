<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLegalActionStatusLogs extends Command
{
    protected $signature = 'legal:backfill-status-logs {--dry-run : Tampilkan perubahan tanpa update DB}';
    protected $description = 'Backfill from_status pada legal_action_status_logs berdasarkan log sebelumnya (per legal_action_id).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Mulai backfill from_status...'.($dryRun ? ' (DRY RUN)' : ''));

        // Ambil semua legal_action_id yang punya log
        $actionIds = DB::table('legal_action_status_logs')
            ->select('legal_action_id')
            ->groupBy('legal_action_id')
            ->pluck('legal_action_id');

        $totalUpdated = 0;

        DB::beginTransaction();
        try {
            foreach ($actionIds as $actionId) {

                // Urutkan paling aman: changed_at lalu id
                $logs = DB::table('legal_action_status_logs')
                    ->where('legal_action_id', $actionId)
                    ->orderBy('changed_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->get(['id', 'from_status', 'to_status', 'changed_at', 'created_at']);

                $prevTo = null;

                foreach ($logs as $idx => $log) {
                    // pastikan to_status ada
                    $to = $log->to_status;

                    // Kalau changed_at null (kadang data lama), set dari created_at (opsional)
                    if (empty($log->changed_at) && !empty($log->created_at)) {
                        if ($dryRun) {
                            $this->line("ACTION {$actionId} LOG {$log->id}: set changed_at = created_at ({$log->created_at})");
                        } else {
                            DB::table('legal_action_status_logs')
                                ->where('id', $log->id)
                                ->update(['changed_at' => $log->created_at]);
                        }
                    }

                    // Backfill from_status hanya kalau masih kosong
                    if (empty($log->from_status)) {
                        $newFrom = $prevTo; // untuk log pertama akan null (memang ga ada sebelumnya)

                        if ($dryRun) {
                            $this->line("ACTION {$actionId} LOG {$log->id}: from_status NULL -> ".($newFrom ?? 'NULL')." | to_status={$to}");
                        } else {
                            DB::table('legal_action_status_logs')
                                ->where('id', $log->id)
                                ->update(['from_status' => $newFrom]);
                        }

                        $totalUpdated++;
                    }

                    // simpan to_status untuk baris berikutnya
                    $prevTo = $to;
                }
            }

            if ($dryRun) {
                DB::rollBack();
                $this->warn("DRY RUN selesai. Total kandidat update: {$totalUpdated} (tidak ada perubahan DB).");
            } else {
                DB::commit();
                $this->info("Selesai. Total log yang diupdate from_status: {$totalUpdated}");
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Gagal: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
