<?php

namespace App\Jobs;

use App\Models\NplCase;
use App\Services\CaseActionLegacySpSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncLegacySpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $caseId) {}

    public function handle(CaseActionLegacySpSyncService $svc): void
    {
        $case = NplCase::find($this->caseId);
        if (!$case) return;

        // 1️⃣ Panggil sync (service hanya urus data legacy)
        $result = $svc->syncForCase($case);

        // update waktu sync (1 pintu, di job)
        $case->forceFill([
            'last_legacy_sync_at' => now(),
        ])->save();

        // 2️⃣ Ambil fingerprint hasil sync
        $newFp = $result['fingerprint'] ?? null;
        if (!$newFp) {
            // gagal sync / tidak ada data
            return;
        }

        // 3️⃣ Kalau fingerprint berubah → rebuild schedule
        if ($newFp !== $case->legacy_sp_fingerprint) {

            $case->forceFill([
                'legacy_sp_fingerprint' => $newFp,
            ])->save();

            // ⛓️ chaining ke rebuild
            RebuildSpScheduleJob::dispatch($case->id)
                ->onQueue('crms');
        }
    }
}
