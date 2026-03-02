<?php

namespace App\Jobs;

use App\Models\ImportLog;
use App\Models\LoanAccountSnapshotMonthly;
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

        // ✅ 1) Guard: snapshot bulanan hanya saat EOM
        if ($d->toDateString() !== $monthEnd) {
            ImportLog::create([
                'module'        => 'loan_snapshot_monthly',
                'position_date' => $d->toDateString(),
                'run_type'      => 'import',
                'file_name'     => null,
                'status'        => 'success',
                'message'       => "Snapshot SKIP (bukan EOM). this={$d->toDateString()} eom={$monthEnd}",
                'imported_by'   => null,
            ]);
            return;
        }

        // ✅ 2) Optional: pastikan memang import closing terakhir (kalau kamu tetap mau rule ini)
        $maxSuccessPos = ImportLog::query()
            ->where('module', 'loans') // ⚠️ pastikan sama dengan module import kamu
            ->where('status', 'success')
            ->whereDate('position_date', '>=', $monthStart)
            ->whereDate('position_date', '<=', $monthEnd)
            ->selectRaw('MAX(DATE(position_date)) as maxd')
            ->value('maxd');

        if ($maxSuccessPos !== $d->toDateString()) {
            ImportLog::create([
                'module'        => 'loan_snapshot_monthly',
                'position_date' => $d->toDateString(),
                'run_type'      => 'import',
                'file_name'     => null,
                'status'        => 'success',
                'message'       => "Snapshot SKIP (bukan closing terakhir). max_success={$maxSuccessPos}, this={$d->toDateString()}",
                'imported_by'   => null,
            ]);
            return;
        }

        try {
            // ✅ 3) Clean refresh agar snapshot_month cuma 1 source_position_date
            // Kalau kamu setuju snapshot EOM harus “1 versi resmi”
            LoanAccountSnapshotMonthly::query()
                ->where('snapshot_month', $monthStart)
                ->delete();

            $exitCode = Artisan::call('crms:snapshot-loan-monthly', [
                '--month'         => $d->format('Y-m'),
                '--position_date' => $d->toDateString(),
            ]);

            $output = trim(Artisan::output());

            ImportLog::create([
                'module'        => 'loan_snapshot_monthly',
                'position_date' => $d->toDateString(),
                'run_type'      => 'import', // ✅ jangan 'auto' kalau enum tidak mendukung
                'file_name'     => null,
                'status'        => $exitCode === 0 ? 'success' : 'failed',
                'message'       => $exitCode === 0
                    ? "Snapshot sukses: month={$d->format('Y-m')} source={$d->toDateString()}"
                    : "Snapshot gagal: exitCode={$exitCode} | {$output}",
                'imported_by'   => null,
            ]);

            if ($exitCode !== 0) {
                throw new \RuntimeException("Snapshot command exitCode={$exitCode}. {$output}");
            }

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

            throw $e;
        }
    }
}