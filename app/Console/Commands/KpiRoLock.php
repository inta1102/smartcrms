<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Kpi\RoKpiMonthlyBuilder;

class KpiRoLock extends Command
{
    protected $signature = 'kpi:ro:lock
        {period : Period month to lock (YYYY-MM-01)}
        {--branch= : branch_code filter}
        {--ao= : ao_code filter}
        {--force : overwrite even if already locked}';

    protected $description = 'Build KPI RO monthly in EOM mode and lock it (set locked_at)';

    public function handle(RoKpiMonthlyBuilder $builder): int
    {
        $period = (string) $this->argument('period');
        $branch = $this->option('branch') ?: null;
        $ao     = $this->option('ao') ?: null;
        $force  = (bool) $this->option('force');

        $res = $builder->buildAndStore($period, 'eom', $branch, $ao, $force);

        $this->info("KPI RO LOCK OK | period={$res['period_month']} saved={$res['saved']} skipped_locked={$res['skipped_locked']} total_ao={$res['total_ao']}");
        return Command::SUCCESS;
    }
}
