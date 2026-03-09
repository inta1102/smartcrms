<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class KpiOsDailySnapshot extends Command
{
    protected $signature = 'kpi:os-daily-snapshot {--date=}';

    protected $description = 'Build OS snapshot per AO per day (os_total, os_l0, os_lt, os_dpk, os_potensi, os_kl, os_d, os_m, noa_*) from loan_accounts';

    public function handle(): int
    {
        $opt = (string) ($this->option('date') ?? '');

        if ($opt !== '') {
            $date = Carbon::parse($opt)->toDateString();
        } else {
            $max = DB::table('loan_accounts')
                ->whereDate('position_date', '<=', now()->toDateString())
                ->max('position_date');

            if (!$max) {
                $this->error('No loan_accounts.position_date found.');
                return self::FAILURE;
            }

            $date = Carbon::parse($max)->toDateString();
        }

        $this->info("Building daily OS snapshot for date={$date} ...");

        /**
         * =========================================================
         * DEFINISI HARIAN TERBARU
         * =========================================================
         * - L0      : kolek=1 AND ft_pokok=0 AND ft_bunga=0
         * - LT      : kolek=1 AND (ft_pokok>0 OR ft_bunga>0)
         * - DPK     : kolek=2 AND (ft_pokok=2 OR ft_bunga=2)
         * - Potensi : kolek=2 AND (ft_pokok=3 OR ft_bunga=3)
         * - KL      : kolek=3
         * - D       : kolek=4
         * - M       : kolek=5
         *
         * Catatan:
         * - LT spesifik kolek=1 dengan flag FT > 0
         * - DPK spesifik kolek=2 dengan FT flag = 2
         * - Potensi spesifik kolek=2 dengan FT flag = 3
         * =========================================================
         */

        // grouping AO konsisten
        $aoExpr = "LPAD(TRIM(COALESCE(loan_accounts.ao_code,'')),6,'0')";

        // alias singkat untuk ekspresi SQL bucket
        $l0Expr = "
            COALESCE(kolek,0)=1
            AND COALESCE(ft_pokok,0)=0
            AND COALESCE(ft_bunga,0)=0
        ";

        $ltExpr = "
            COALESCE(kolek,0)=1
            AND (
                COALESCE(ft_pokok,0)>0
                OR COALESCE(ft_bunga,0)>0
            )
        ";

        $dpkExpr = "
            COALESCE(kolek,0)=2
            AND (
                COALESCE(ft_pokok,0)=2
                OR COALESCE(ft_bunga,0)=2
            )
        ";

        $potensiExpr = "
            COALESCE(kolek,0)=2
            AND (
                COALESCE(ft_pokok,0)=3
                OR COALESCE(ft_bunga,0)=3
            )
        ";

        $klExpr = "COALESCE(kolek,0)=3";
        $dExpr  = "COALESCE(kolek,0)=4";
        $mExpr  = "COALESCE(kolek,0)=5";

        $rows = DB::table('loan_accounts')
            ->selectRaw("
                {$aoExpr} as ao_code,

                ROUND(SUM(COALESCE(outstanding,0)), 2) as os_total,
                COUNT(account_no) as noa_total,

                ROUND(SUM(CASE
                    WHEN {$l0Expr}
                    THEN COALESCE(outstanding,0) ELSE 0 END
                ), 2) as os_l0,
                SUM(CASE
                    WHEN {$l0Expr}
                    THEN 1 ELSE 0 END
                ) as noa_l0,

                ROUND(SUM(CASE
                    WHEN {$ltExpr}
                    THEN COALESCE(outstanding,0) ELSE 0 END
                ), 2) as os_lt,
                SUM(CASE
                    WHEN {$ltExpr}
                    THEN 1 ELSE 0 END
                ) as noa_lt,

                ROUND(SUM(CASE
                    WHEN {$dpkExpr}
                    THEN COALESCE(outstanding,0) ELSE 0 END
                ), 2) as os_dpk,
                SUM(CASE
                    WHEN {$dpkExpr}
                    THEN 1 ELSE 0 END
                ) as noa_dpk,

                ROUND(SUM(CASE
                    WHEN {$potensiExpr}
                    THEN COALESCE(outstanding,0) ELSE 0 END
                ), 2) as os_potensi,
                SUM(CASE
                    WHEN {$potensiExpr}
                    THEN 1 ELSE 0 END
                ) as noa_potensi,

                ROUND(SUM(CASE
                    WHEN {$klExpr}
                    THEN COALESCE(outstanding,0) ELSE 0 END
                ), 2) as os_kl,
                SUM(CASE
                    WHEN {$klExpr}
                    THEN 1 ELSE 0 END
                ) as noa_kl,

                ROUND(SUM(CASE
                    WHEN {$dExpr}
                    THEN COALESCE(outstanding,0) ELSE 0 END
                ), 2) as os_d,
                SUM(CASE
                    WHEN {$dExpr}
                    THEN 1 ELSE 0 END
                ) as noa_d,

                ROUND(SUM(CASE
                    WHEN {$mExpr}
                    THEN COALESCE(outstanding,0) ELSE 0 END
                ), 2) as os_m,
                SUM(CASE
                    WHEN {$mExpr}
                    THEN 1 ELSE 0 END
                ) as noa_m
            ")
            ->whereDate('position_date', $date)
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code) <> ''")
            ->whereRaw("COALESCE(outstanding,0) > 0")
            ->groupByRaw($aoExpr)
            ->get();

        if ($rows->isEmpty()) {
            $this->warn("No data for date={$date}. Nothing upserted.");
            return self::SUCCESS;
        }

        $now = now();

        $payload = $rows->map(function ($r) use ($date, $now) {
            $ao = (string) ($r->ao_code ?? '000000');
            $ao = $ao !== '' ? $ao : '000000';

            return [
                'position_date' => $date,
                'ao_code'       => $ao,

                'os_total'      => (float) ($r->os_total ?? 0),
                'os_l0'         => (float) ($r->os_l0 ?? 0),
                'os_lt'         => (float) ($r->os_lt ?? 0),
                'os_dpk'        => (float) ($r->os_dpk ?? 0),
                'os_potensi'    => (float) ($r->os_potensi ?? 0),
                'os_kl'         => (float) ($r->os_kl ?? 0),
                'os_d'          => (float) ($r->os_d ?? 0),
                'os_m'          => (float) ($r->os_m ?? 0),

                'noa_total'     => (int) ($r->noa_total ?? 0),
                'noa_l0'        => (int) ($r->noa_l0 ?? 0),
                'noa_lt'        => (int) ($r->noa_lt ?? 0),
                'noa_dpk'       => (int) ($r->noa_dpk ?? 0),
                'noa_potensi'   => (int) ($r->noa_potensi ?? 0),
                'noa_kl'        => (int) ($r->noa_kl ?? 0),
                'noa_d'         => (int) ($r->noa_d ?? 0),
                'noa_m'         => (int) ($r->noa_m ?? 0),

                'source'        => 'loan_accounts',
                'computed_at'   => $now,
                'updated_at'    => $now,
                'created_at'    => $now,
            ];
        })
        ->filter(fn ($x) => ($x['ao_code'] ?? '000000') !== '000000')
        ->values()
        ->all();

        DB::table('kpi_os_daily_aos')->upsert(
            $payload,
            ['position_date', 'ao_code'],
            [
                'os_total',
                'os_l0',
                'os_lt',
                'os_dpk',
                'os_potensi',
                'os_kl',
                'os_d',
                'os_m',

                'noa_total',
                'noa_l0',
                'noa_lt',
                'noa_dpk',
                'noa_potensi',
                'noa_kl',
                'noa_d',
                'noa_m',

                'source',
                'computed_at',
                'updated_at',
            ]
        );

        $this->info("OK upserted rows=" . count($payload));

        // log ringkas untuk validasi cepat
        $grand = [
            'os_total'    => array_sum(array_map(fn ($x) => (float) $x['os_total'], $payload)),
            'os_l0'       => array_sum(array_map(fn ($x) => (float) $x['os_l0'], $payload)),
            'os_lt'       => array_sum(array_map(fn ($x) => (float) $x['os_lt'], $payload)),
            'os_dpk'      => array_sum(array_map(fn ($x) => (float) $x['os_dpk'], $payload)),
            'os_potensi'  => array_sum(array_map(fn ($x) => (float) $x['os_potensi'], $payload)),
            'os_kl'       => array_sum(array_map(fn ($x) => (float) $x['os_kl'], $payload)),
            'os_d'        => array_sum(array_map(fn ($x) => (float) $x['os_d'], $payload)),
            'os_m'        => array_sum(array_map(fn ($x) => (float) $x['os_m'], $payload)),
            'noa_total'   => array_sum(array_map(fn ($x) => (int) $x['noa_total'], $payload)),
            'noa_l0'      => array_sum(array_map(fn ($x) => (int) $x['noa_l0'], $payload)),
            'noa_lt'      => array_sum(array_map(fn ($x) => (int) $x['noa_lt'], $payload)),
            'noa_dpk'     => array_sum(array_map(fn ($x) => (int) $x['noa_dpk'], $payload)),
            'noa_potensi' => array_sum(array_map(fn ($x) => (int) $x['noa_potensi'], $payload)),
            'noa_kl'      => array_sum(array_map(fn ($x) => (int) $x['noa_kl'], $payload)),
            'noa_d'       => array_sum(array_map(fn ($x) => (int) $x['noa_d'], $payload)),
            'noa_m'       => array_sum(array_map(fn ($x) => (int) $x['noa_m'], $payload)),
        ];

        $this->line('Grand summary: ' . json_encode($grand));

        return self::SUCCESS;
    }
}