<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CrmsRefreshAgenda extends Command
{
    protected $signature = 'crms:refresh-agenda
    {--case= : Hanya proses 1 case_id (opsional)}
    {--dry-run : Hanya tampilkan step, tidak eksekusi}
    {--force : Jalan tanpa konfirmasi}';

    protected $description = 'CRMS: Setelah import, jalankan SyncLegacySp lalu RebuildSpSchedules (tanpa queue)';

    public function handle(): int
    {
        $caseId = $this->option('case');
        $dryRun = (bool) $this->option('dry-run');

        $force = (bool) $this->option('force');
        if (!$force && !$dryRun) {
            if (!$this->confirm('Jalankan refresh agenda (sync legacy + refresh chain)?', true)) {
                $this->warn('Dibatalkan.');
                return self::SUCCESS;
            }
        }

        $this->info('=== CRMS Refresh Agenda START ===');
        if ($caseId) $this->info("Target case_id: {$caseId}");
        if ($dryRun) $this->warn("DRY RUN: tidak mengeksekusi command.");

        // 1) Sync legacy SP
        $this->line('Step 1/2: Sync Legacy SP');
        if (!$dryRun) {
            $args = [];
            if ($caseId) $args['--case'] = $caseId;

            $exit = $this->call('crms:sync-legacy-sp', $args);
            if ($exit !== 0) {
                $this->error('SyncLegacySp gagal. Stop.');
                return self::FAILURE;
            }
        }

        // 2) Refresh warning chain (Follow-up + SP chain)
        $this->line('Step 2/2: Refresh Warning Chain (Follow-up + SP chain)');
        if (!$dryRun) {
            $args = [
                '--status' => 'open',
                '--force'  => true, // agar command ini tidak confirm lagi
            ];

            if ($caseId) $args['--case'] = $caseId;

            $exit = $this->call('cases:refresh-warning-chain', $args);

            if ($exit !== 0) {
                $this->error('RefreshWarningChain gagal.');
                return self::FAILURE;
            }
        }

        $this->info('=== CRMS Refresh Agenda DONE ✅ ===');
        return self::SUCCESS;
    }

}
