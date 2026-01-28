<?php

namespace App\Console\Commands;

use App\Services\Kpi\MarketingKpiMonthlyService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class KpiMarketingCalculate extends Command
{
    protected $signature = 'kpi:marketing-calculate {--period=} {--user_id=}';
    protected $description = 'Recalc KPI marketing monthly score per period (based on target & snapshot/live)';

    public function handle(MarketingKpiMonthlyService $svc): int
    {
        $period = $this->option('period')
            ? Carbon::parse($this->option('period'))->startOfMonth()
            : now()->startOfMonth();

        $userId = $this->option('user_id') ? (int) $this->option('user_id') : null;

        if ($userId) {
            $svc->recalcForUserAndPeriod($userId, $period);
            $this->info("OK calculate period={$period->toDateString()} user_id={$userId}");
            return self::SUCCESS;
        }

        // kalau mau all AO, kamu bisa loop user ao_code di sini (mirip recalcAll controller)
        $this->warn("No user_id provided. Implement all-AO loop here if needed.");
        return self::SUCCESS;
    }
}
