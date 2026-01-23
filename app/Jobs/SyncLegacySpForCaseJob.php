<?php

namespace App\Jobs;

use App\Models\LegacySyncRun;
use App\Models\NplCase;
use App\Services\CaseActionLegacySpSyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncLegacySpForCaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public int $caseId;
    public int $runId;

    public function __construct(int $caseId, int $runId)
    {
        $this->caseId = $caseId;
        $this->runId  = $runId;
    }

    public function handle(CaseActionLegacySpSyncService $service): void
    {
        if ($this->batch()?->cancelled()) return;

        $case = NplCase::find($this->caseId);
        if (!$case) {
            LegacySyncRun::whereKey($this->runId)->increment('failed', 1);
            LegacySyncRun::whereKey($this->runId)->increment('processed', 1);
            return;
        }

        try {
            $service->syncForCase($case);
            LegacySyncRun::whereKey($this->runId)->increment('processed', 1);
        } catch (\Throwable $e) {
            Log::error('[LEGACY SYNC JOB FAILED]', [
                'case_id' => $this->caseId,
                'run_id'  => $this->runId,
                'error'   => $e->getMessage(),
            ]);

            LegacySyncRun::whereKey($this->runId)->increment('failed', 1);
            LegacySyncRun::whereKey($this->runId)->increment('processed', 1);
            throw $e; // biar batch tahu ada yang gagal
        }
    }
}
