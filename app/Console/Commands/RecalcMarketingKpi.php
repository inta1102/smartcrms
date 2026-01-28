<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\User;
use App\Services\Kpi\MarketingKpiMonthlyService;

class RecalcMarketingKpi extends Command
{
    protected $signature = 'kpi:recalc-marketing {period? : Format YYYY-MM}';
    protected $description = 'Recalculate KPI Marketing Monthly untuk semua AO';

    public function handle(MarketingKpiMonthlyService $svc): int
    {
        $periodArg = $this->argument('period');
        $period = $periodArg
            ? Carbon::createFromFormat('Y-m', $periodArg)->startOfMonth()
            : now()->startOfMonth();

        $this->info('Recalc KPI Marketing periode: '.$period->format('Y-m'));

        // Ambil semua AO aktif
        $aos = User::query()
            ->whereNotNull('ao_code')
            ->where('is_active', 1)
            ->get();

        if ($aos->isEmpty()) {
            $this->warn('Tidak ada AO aktif.');
            return self::SUCCESS;
        }

        foreach ($aos as $ao) {
            try {
                $svc->recalcForUserAndPeriod($ao->id, $period);
                $this->line("✔ {$ao->name} ({$ao->ao_code})");
            } catch (\Throwable $e) {
                $this->error("✖ {$ao->name} ({$ao->ao_code}) : ".$e->getMessage());
            }
        }

        $this->info('Selesai.');
        return self::SUCCESS;
    }
}
