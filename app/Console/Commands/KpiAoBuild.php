<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Services\Kpi\AoKpiMonthlyService;

class KpiAoBuild extends Command
{
    protected $signature = 'kpi:ao-build {--period=} {--user_id=}';
    protected $description = 'Build KPI AO monthly for a period';

    public function handle(AoKpiMonthlyService $svc): int
    {
        $period = $this->option('period')
            ? Carbon::parse($this->option('period'))->startOfMonth()->toDateString()
            : now()->startOfMonth()->toDateString();

        $userId = $this->option('user_id') ? (int) $this->option('user_id') : null;

        $res = $svc->buildForPeriod($period, $userId);

        $this->info("OK AO build period={$res['period']} rows={$res['rows']}");
        return self::SUCCESS;
    }
}
