<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\NplCase;
use App\Services\CaseScheduler;

class RebuildSpSchedules extends Command
{
    /**
     * Nama & signature command
     */
    protected $signature = 'crms:rebuild-sp-schedules
                        {--status=open : open|all}
                        {--case= : Proses hanya 1 case_id (opsional)}';

    /**
     * Deskripsi di php artisan list
     */
    protected $description = 'Rebuild jadwal SP1–SP2–SP3–SPT–SPJAD untuk kasus kredit bermasalah';

    /**
     * Jalankan command
     */
    public function handle(CaseScheduler $scheduler): int
    {
        $this->info('Mulai rebuild jadwal SP untuk kasus kredit bermasalah...');

        $caseId = $this->option('case');

        $query = NplCase::query()->with(['loanAccount', 'actions', 'schedules']);

        // ✅ kalau spesifik case
        if (!empty($caseId)) {
            $query->where('id', (int)$caseId);
        } else {
            // ✅ default: open
            if (($this->option('status') ?? 'open') === 'open') {
                $query->whereNull('closed_at');  // ✅ konsisten
            }
            // kalau status=all -> tidak difilter
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn('Tidak ada case yang memenuhi kriteria.');
            return Command::SUCCESS;
        }

        $this->info("Total case yang akan diproses: {$total}");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $created = 0;

        $query->orderBy('id')
            ->chunkById(100, function ($cases) use ($scheduler, $bar, &$created) {
                foreach ($cases as $case) {
                    $before = $case->schedules()
                        ->whereIn('type', ['sp1', 'sp2', 'sp3', 'spt', 'spjad'])
                        ->count();

                    // ✅ rebuild chain SP untuk case ini
                    $scheduler->refreshWarningChain($case);

                    $after = $case->schedules()
                        ->whereIn('type', ['sp1', 'sp2', 'sp3', 'spt', 'spjad'])
                        ->count();

                    if ($after > $before) {
                        $created += ($after - $before);
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->info("Selesai. Jumlah jadwal SP yang dibuat: {$created}");

        return Command::SUCCESS;
    }

}
