<?php

namespace App\Jobs;

use App\Models\ImportLog;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class RunMonthlyLoanSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $positionDate) {}

    public function handle(): void
    {
        $d = Carbon::parse($this->positionDate)->startOfDay();
        $monthStart = $d->copy()->startOfMonth()->toDateString();
        $monthEnd   = $d->copy()->endOfMonth()->toDateString();

        // ✅ Rule closing realistis: import sukses terakhir di bulan tsb
        $maxSuccessPos = ImportLog::query()
            ->where('module', 'loans')
            ->where('status', 'success')
            ->whereDate('position_date', '>=', $monthStart)
            ->whereDate('position_date', '<=', $monthEnd)
            ->selectRaw('MAX(DATE(position_date)) as maxd')
            ->value('maxd');

        if ($maxSuccessPos !== $d->toDateString()) {
            ImportLog::create([
                'module'        => 'loan_snapshot_monthly',
                'position_date' => $d->toDateString(),
                'run_type'      => 'import',   // enum hanya import|reimport
                'file_name'     => null,
                'status'        => 'success',  // ⬅️ tetap success
                'message'       => "Snapshot SKIP (bukan closing bulan). max_success={$maxSuccessPos}, this={$d->toDateString()}",
                'imported_by'   => null,
            ]);
            return;
        }

        try {
            $exitCode = Artisan::call('crms:snapshot-loan-monthly', [
                '--month'         => $d->format('Y-m'),
                '--position_date' => $d->toDateString(),
            ]);

            $output = trim(Artisan::output());

            ImportLog::create([
                'module'        => 'loan_snapshot_monthly',
                'position_date' => $d->toDateString(),
                'run_type'      => 'auto',
                'file_name'     => null,
                'status'        => $exitCode === 0 ? 'success' : 'failed',
                'message'       => $exitCode === 0
                    ? "Snapshot sukses: month={$d->format('Y-m')} source={$d->toDateString()}"
                    : "Snapshot gagal: exitCode={$exitCode} | {$output}",
                'imported_by'   => null,
            ]);

        } catch (\Throwable $e) {
            Log::error('[SNAPSHOT] monthly snapshot failed', [
                'posDate' => $d->toDateString(),
                'msg'     => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            ImportLog::create([
                'module'        => 'loan_snapshot_monthly',
                'position_date' => $d->toDateString(),
                'run_type'      => 'import',
                'file_name'     => null,
                'status'        => 'failed',
                'message'       => 'Snapshot gagal: ' . $e->getMessage(),
                'imported_by'   => null,
            ]);

            throw $e; // biar masuk failed_jobs juga (opsional)
        }
    }
}
