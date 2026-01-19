<?php

namespace App\Jobs;

use App\Models\NplCase;
use App\Services\CaseActionLegacySpSyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncLegacySpForCaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public int $caseId;

    public function __construct(int $caseId)
    {
        $this->caseId = $caseId;
    }

    public function handle(CaseActionLegacySpSyncService $service): void
    {
        // ✅ penting: kalau batch dibatalkan, jangan lanjut
        if ($this->batch()?->cancelled()) {
            return;
        }

        $case = NplCase::find($this->caseId);
        if (!$case) return;

        // sync legacy per case
        $service->syncForCase($case);
    }
}
