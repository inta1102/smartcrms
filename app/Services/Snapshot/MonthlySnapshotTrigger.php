<?php

namespace App\Services\Snapshot;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

class MonthlySnapshotTrigger
{
    public function handleIfEom(string $positionDate): void
    {
        $d = Carbon::parse($positionDate)->startOfDay();
        $isEom = $d->isSameDay($d->copy()->endOfMonth());

        if (!$isEom) return;

        $month = $d->format('Y-m'); // untuk --month=YYYY-MM

        // jalankan snapshot dengan source position_date tsb
        Artisan::call('crms:snapshot-loan-monthly', [
            '--month'         => $month,
            '--position_date' => $d->toDateString(),
        ]);
    }
}
