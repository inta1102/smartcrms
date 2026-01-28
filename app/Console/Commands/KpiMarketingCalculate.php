<?php

namespace App\Console\Commands;

use App\Services\Kpi\MarketingKpiCalculatorService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class KpiMarketingCalculate extends Command
{
    protected $signature = 'kpi:marketing-calc {--period=} {--user_id=}';
    protected $description = 'Calculate KPI marketing score (OS & NOA) per period from approved targets + snapshots';

    public function handle(MarketingKpiCalculatorService $svc): int
    {
        $period = $this->option('period')
            ? Carbon::parse($this->option('period'))->startOfMonth()->toDateString()
            : now()->startOfMonth()->toDateString();

        $userId = $this->option('user_id') ? (int) $this->option('user_id') : null;

        $user = auth()->user();
        $calculatedBy = $user ? (int) $user->id : null;

        $res = $svc->calculateForPeriod($period, $userId, $calculatedBy);

        $this->info("OK calc period={$res['period']} targets={$res['targets']} upsert={$res['upsert']}");

        return self::SUCCESS;
    }
}
