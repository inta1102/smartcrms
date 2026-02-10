<?php

namespace App\Console\Commands;

use App\Services\Kpi\SoKpiMonthlyService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class KpiSoBuild extends Command
{
    protected $signature = 'kpi:so-build {--period=} {--user_id=}';
    protected $description = 'Build KPI SO monthly for a period (disbursement-based, RR by AO portfolio)';

    public function handle(SoKpiMonthlyService $svc): int
    {
        $period = $this->option('period')
            ? Carbon::parse($this->option('period'))->startOfMonth()->toDateString()
            : now()->startOfMonth()->toDateString();

        $userId = $this->option('user_id') ? (int) $this->option('user_id') : null;

        $res = $svc->buildForPeriod($period, $userId);

        $periodOut = $res['period'] ?? $period;
        $rowsOut   = $res['rows'] ?? 0;

        // backward compatible (dulu pakai window)
        $rrWindow  = $res['rr_window_start'] ?? null;

        // info relevan untuk RR bulan berjalan (source = loan_accounts position_date terakhir)
        $posDate   = $res['position_date'] ?? null;

        $parts = [
            "OK KPI SO",
            "period={$periodOut}",
        ];

        if (!empty($posDate)) {
            $parts[] = "position_date={$posDate}";
        }

        if (!empty($rrWindow)) {
            $parts[] = "rr_window_start={$rrWindow}";
        }

        $parts[] = "rows={$rowsOut}";

        $this->info(implode(' ', $parts));

        return self::SUCCESS;
    }
}
