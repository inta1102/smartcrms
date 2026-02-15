<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Kpi\RoKpiMonthlyBuilder;

class KpiRoRealtime extends Command
{
    protected $signature = 'kpi:ro:realtime
        {--period= : Period month (YYYY-MM-01). Default: current month}
        {--branch= : branch_code filter}
        {--ao= : ao_code filter}
        {--force : overwrite even if locked}';

    protected $description = 'Build KPI RO monthly in realtime mode (running) and store to kpi_ro_monthly';

    public function handle(RoKpiMonthlyBuilder $builder): int
    {
        $period = $this->option('period') ?: now()->startOfMonth()->toDateString();
        $branch = $this->option('branch') ?: null;
        $ao     = $this->option('ao') ?: null;
        $force  = (bool) $this->option('force');

        $res = $builder->buildAndStore($period, 'realtime', $branch, $ao, $force);

        $this->info("KPI RO REALTIME OK | period={$res['period_month']} saved={$res['saved']} skipped_locked={$res['skipped_locked']} total_ao={$res['total_ao']}");
        return Command::SUCCESS;
    }
}
