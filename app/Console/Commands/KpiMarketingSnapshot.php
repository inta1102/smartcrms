<?php

namespace App\Console\Commands;

use App\Services\Kpi\MarketingKpiSnapshotService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class KpiMarketingSnapshot extends Command
{
    protected $signature = 'kpi:marketing-snapshot {--period=} {--user_id=}';
    protected $description = 'Build KPI marketing snapshot (OS opening/closing, OS growth, NOA new) per period';

    public function handle(MarketingKpiSnapshotService $svc): int
    {
        $period = $this->option('period')
            ? Carbon::parse($this->option('period'))->startOfMonth()->toDateString()
            : now()->startOfMonth()->toDateString();

        $userId = $this->option('user_id') ? (int) $this->option('user_id') : null;

        $res = $svc->buildForPeriod($period, $userId);

        $this->info("OK snapshot period={$res['period']} users={$res['users']} upsert={$res['upsert']}");

        return self::SUCCESS;
    }
}
