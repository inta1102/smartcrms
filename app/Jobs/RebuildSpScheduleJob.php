<?php

namespace App\Jobs;

use App\Models\NplCase;
use App\Services\CaseScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RebuildSpScheduleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $caseId) {}

    public function handle(CaseScheduler $scheduler): void
    {
        $case = NplCase::find($this->caseId);
        if (!$case) return;

        $scheduler->rebuildSpSchedulesForCase($case);
    }
}
