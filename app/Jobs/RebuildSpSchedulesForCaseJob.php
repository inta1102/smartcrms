<?php

namespace App\Jobs;

use App\Models\NplCase;
use App\Models\ScheduleUpdateLog;
use App\Services\CaseScheduler;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RebuildSpSchedulesForCaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public int $caseId;

    public function __construct(int $caseId)
    {
        $this->caseId = $caseId;
    }

    public function handle(CaseScheduler $scheduler): void
    {
        if ($this->batch()?->cancelled()) return;

        $case = NplCase::find($this->caseId);
        if (!$case) return;

        // ✅ PANGGIL METHOD YANG ADA
       $scheduler->rebuildSpSchedulesForCase($case);

        // ✅ update progress log per batch
        $batchId = $this->batch()?->id;
        if ($batchId) {
            ScheduleUpdateLog::where('batch_id', $batchId)->increment('scheduled_cases');
        }
    }

    public function failed(Throwable $e): void
    {
        $batchId = $this->batch()?->id;
        if ($batchId) {
            ScheduleUpdateLog::where('batch_id', $batchId)->increment('failed_cases');
            ScheduleUpdateLog::where('batch_id', $batchId)->update([
                'status'  => 'running', // biar final status tetap ditentukan oleh batch/endpoint
                'message' => 'Ada job gagal: ' . $e->getMessage(),
            ]);
        }
    }
}
