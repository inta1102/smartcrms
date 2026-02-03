<?php

namespace App\Console\Commands;

use App\Services\Kpi\SoKpiMonthlyService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class KpiSoBuild extends Command
{
    protected $signature = 'kpi:so-build {--period=} {--user_id=}';
    protected $description = 'Build KPI SO monthly for a period (disbursement-based, RR 3-month window)';

    public function handle(SoKpiMonthlyService $svc): int
    {
        $period = $this->option('period')
            ? Carbon::parse($this->option('period'))->startOfMonth()->toDateString()
            : now()->startOfMonth()->toDateString();

        $userId = $this->option('user_id') ? (int)$this->option('user_id') : null;

        $res = $svc->buildForPeriod($period, $userId);

        $this->info("OK KPI SO period={$res['period']} rr_window_start={$res['rr_window_start']} rows={$res['rows']}");
        return self::SUCCESS;
    }
}
