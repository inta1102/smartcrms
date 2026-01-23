<?php

namespace App\Console\Commands;

use App\Jobs\SyncLegacySpForCaseJob;
use App\Models\LegacySyncRun;
use App\Models\NplCase;
use Illuminate\Console\Command;

class LegacySyncSpCommand extends Command
{
    protected $signature = 'legacy:sync-sp {--limit=0}';
    protected $description = 'Dispatch Legacy SP sync jobs for open cases';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $q = NplCase::query()
            ->whereNull('closed_at')
            ->orderBy('id');

        if ($limit > 0) $q->limit($limit);

        $caseIds = $q->pluck('id')->all();
        $total = count($caseIds);

        if ($total === 0) {
            $this->info('No cases to sync.');
            return self::SUCCESS;
        }

        $run = LegacySyncRun::create([
            'posisi_date' => now()->toDateString(),
            'total'       => $total,
            'processed'   => 0,
            'failed'      => 0,
            'status'      => LegacySyncRun::STATUS_RUNNING,
            'started_at'  => now(),
            'created_by'  => 1,
        ]);

        foreach ($caseIds as $caseId) {
            SyncLegacySpForCaseJob::dispatch((int)$caseId, (int)$run->id)
                ->onQueue('sync'); // atau 'crms'
        }

        $this->info("Dispatched {$total} jobs. run_id={$run->id}");
        return self::SUCCESS;
    }
}
