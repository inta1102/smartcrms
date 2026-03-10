<?php

namespace App\Console\Commands;

use App\Services\Dashboard\DekonDashboardBuilder;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BuildDekonDashboard extends Command
{
    /**
     * Contoh:
     * php artisan dashboard:build-dekom
     * php artisan dashboard:build-dekom 2026-03
     * php artisan dashboard:build-dekom --from=2025-01 --to=2026-03
     * php artisan dashboard:build-dekom 2026-03 --mode=realtime
     */
    protected $signature = 'dashboard:build-dekom
                            {period? : Format YYYY-MM}
                            {--from= : Periode awal format YYYY-MM}
                            {--to= : Periode akhir format YYYY-MM}
                            {--mode=eom : eom|realtime|hybrid}';

    protected $description = 'Build monthly summary for Dashboard Dewan Komisaris';

    public function handle(DekonDashboardBuilder $builder): int
    {
        $mode = strtolower(trim((string) $this->option('mode')));
        if (!in_array($mode, ['eom', 'realtime', 'hybrid'], true)) {
            $this->error('Mode tidak valid. Gunakan: eom, realtime, atau hybrid.');
            return self::FAILURE;
        }

        $period = $this->argument('period');
        $from   = $this->option('from');
        $to     = $this->option('to');

        try {
            // =========================================================
            // CASE 1: BUILD RANGE
            // =========================================================
            if ($from || $to) {
                if (!$from || !$to) {
                    $this->error('Jika menggunakan range, --from dan --to harus diisi semua.');
                    return self::FAILURE;
                }

                $fromC = $this->parseYm($from);
                $toC   = $this->parseYm($to);

                $this->info("Build Dashboard Dekom range: {$fromC->format('Y-m')} s/d {$toC->format('Y-m')} [mode={$mode}]");

                $rows = $builder->rebuildRange($fromC, $toC, $mode);

                $this->newLine();
                $this->info('Selesai build range.');

                $this->table(
                    ['Periode', 'As Of', 'Mode', 'Source', 'Total OS', 'NPL %', 'Target YTD', 'Realisasi MTD', 'Realisasi YTD'],
                    collect($rows)->map(function ($r) {
                        $meta = is_array($r->meta ?? null) ? $r->meta : [];
                        $portfolioSource = (string) data_get($meta, 'portfolio_source', '-');

                        return [
                            optional($r->period_month)->format('Y-m'),
                            optional($r->as_of_date)->format('Y-m-d'),
                            $r->mode,
                            $portfolioSource,
                            number_format((float) $r->total_os, 2),
                            number_format((float) $r->npl_pct, 4),
                            number_format((float) $r->target_ytd, 2),
                            number_format((float) $r->realisasi_mtd, 2),
                            number_format((float) $r->realisasi_ytd, 2),
                        ];
                    })->all()
                );

                return self::SUCCESS;
            }

            // =========================================================
            // CASE 2: BUILD SINGLE PERIOD
            // =========================================================
            if ($period) {
                $periodC = $this->parseYm($period);

                $this->info("Build Dashboard Dekom periode {$periodC->format('Y-m')} [mode={$mode}]");

                $row = $builder->buildForPeriod($periodC, $mode);

                $this->renderSingleResult($row);
                return self::SUCCESS;
            }

            // =========================================================
            // CASE 3: BUILD LATEST
            // =========================================================
            $this->info("Build Dashboard Dekom latest available period [mode={$mode}]");

            $row = $builder->buildLatest($mode);

            if (!$row) {
                $this->warn('Tidak ada data snapshot / portfolio yang tersedia untuk dibuild.');
                return self::SUCCESS;
            }

            $this->renderSingleResult($row);
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('Gagal build Dashboard Dekom.');
            $this->error($e->getMessage());

            report($e);
            return self::FAILURE;
        }
    }

    protected function parseYm(string $ym): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', trim($ym))->startOfMonth();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("Format periode tidak valid: {$ym}. Gunakan format YYYY-MM.");
        }
    }

    protected function renderSingleResult($row): void
    {
        $meta = is_array($row->meta ?? null) ? $row->meta : [];

        $targetOs        = (float) data_get($meta, 'target.target_os', 0);
        $targetNplPct    = (float) data_get($meta, 'target.target_npl_pct', 0);
        $achOsPct        = (float) data_get($meta, 'target.ach_os_pct', 0);
        $portfolioSource = (string) data_get($meta, 'portfolio_source', '-');
        $momGrowthPct    = (float) data_get($meta, 'growth.mom_os_growth_pct', 0);
        $yoyGrowthPct    = (float) data_get($meta, 'growth.yoy_os_growth_pct', 0);

        $this->newLine();
        $this->info('Build selesai.');

        $this->table(
            ['Field', 'Value'],
            [
                ['Periode', optional($row->period_month)->format('Y-m')],
                ['As Of', optional($row->as_of_date)->format('Y-m-d')],
                ['Mode', $row->mode],
                ['Portfolio Source', $portfolioSource],

                ['Total OS', number_format((float) $row->total_os, 2)],
                ['Total NOA', number_format((int) $row->total_noa)],
                ['NPL OS', number_format((float) $row->npl_os, 2)],
                ['NPL %', number_format((float) $row->npl_pct, 4)],

                ['L OS', number_format((float) $row->l_os, 2)],
                ['DPK OS', number_format((float) $row->dpk_os, 2)],
                ['KL OS', number_format((float) $row->kl_os, 2)],
                ['D OS', number_format((float) $row->d_os, 2)],
                ['M OS', number_format((float) $row->m_os, 2)],

                ['FT0 OS', number_format((float) $row->ft0_os, 2)],
                ['FT1 OS', number_format((float) $row->ft1_os, 2)],
                ['FT2 OS', number_format((float) $row->ft2_os, 2)],
                ['FT3 OS', number_format((float) $row->ft3_os, 2)],

                ['Restr OS', number_format((float) $row->restr_os, 2)],
                ['Restr NOA', number_format((int) $row->restr_noa)],
                ['DPD 6 OS', number_format((float) $row->dpd6_os, 2)],
                ['DPD 12 OS', number_format((float) $row->dpd12_os, 2)],

                ['Target YTD', number_format((float) $row->target_ytd, 2)],
                ['Target OS (meta)', number_format($targetOs, 2)],
                ['Target NPL %', number_format($targetNplPct, 4)],
                ['Ach OS %', number_format($achOsPct, 4)],

                ['Realisasi MTD', number_format((float) $row->realisasi_mtd, 2)],
                ['Realisasi YTD', number_format((float) $row->realisasi_ytd, 2)],

                ['MoM Growth %', number_format($momGrowthPct, 4)],
                ['YoY Growth %', number_format($yoyGrowthPct, 4)],
            ]
        );
    }
}