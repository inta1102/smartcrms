<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class KpiOsDailySnapshot extends Command
{
    protected $signature = 'kpi:os-daily-snapshot {--date=}';
    protected $description = 'Build OS snapshot per AO per day (os_total, os_l0, os_lt, noa_*) from loan_accounts';

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

        // Definisi:
        // - L0: ft_pokok=0 AND ft_bunga=0
        // - LT: ft_pokok>0 OR ft_bunga>0  (kalau mau khusus FT=1, ganti >0 menjadi =1)
        $rows = DB::table('loan_accounts')
            ->selectRaw("
                LPAD(TRIM(COALESCE(ao_code,'')),6,'0') as ao_code,

                SUM(COALESCE(outstanding,0)) as os_total,
                COUNT(*) as noa_total,

                SUM(CASE WHEN COALESCE(ft_pokok,0)=0 AND COALESCE(ft_bunga,0)=0
                         THEN COALESCE(outstanding,0) ELSE 0 END) as os_l0,
                SUM(CASE WHEN COALESCE(ft_pokok,0)=0 AND COALESCE(ft_bunga,0)=0
                         THEN 1 ELSE 0 END) as noa_l0,

                SUM(CASE WHEN COALESCE(ft_pokok,0)>0 OR COALESCE(ft_bunga,0)>0
                         THEN COALESCE(outstanding,0) ELSE 0 END) as os_lt,
                SUM(CASE WHEN COALESCE(ft_pokok,0)>0 OR COALESCE(ft_bunga,0)>0
                         THEN 1 ELSE 0 END) as noa_lt
            ")
            ->whereDate('position_date', $date)
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code) <> ''")
            ->groupBy('ao_code')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn("No data for date={$date}. Nothing upserted.");
            return self::SUCCESS;
        }

        $now = now();

        $payload = $rows->map(function ($r) use ($date, $now) {
            $ao = (string) ($r->ao_code ?? '000000');
            $ao = $ao !== '' ? $ao : '000000';

            // os_l0 & os_lt adalah decimal di tabel snapshot,
            // jadi simpan sebagai numeric string/float aman (jangan dipaksa int)
            $osTotal = (string) ($r->os_total ?? 0);
            $osL0    = (string) ($r->os_l0 ?? 0);
            $osLT    = (string) ($r->os_lt ?? 0);

            return [
                'position_date' => $date,
                'ao_code'       => $ao,

                'os_total'      => (int) round((float) $osTotal),  // kolom bigint
                'os_l0'         => (float) $osL0,                  // kolom decimal(18,2)
                'os_lt'         => (float) $osLT,                  // kolom decimal(18,2)

                'noa_total'     => (int) ($r->noa_total ?? 0),
                'noa_l0'        => (int) ($r->noa_l0 ?? 0),
                'noa_lt'        => (int) ($r->noa_lt ?? 0),

                'source'        => 'loan_accounts',
                'computed_at'   => $now,
                'updated_at'    => $now,
                'created_at'    => $now,
            ];
        })->filter(fn ($x) => ($x['ao_code'] ?? '000000') !== '000000')
          ->values()
          ->all();

        DB::table('kpi_os_daily_aos')->upsert(
            $payload,
            ['position_date', 'ao_code'],
            [
                'os_total','os_l0','os_lt',
                'noa_total','noa_l0','noa_lt',
                'source','computed_at','updated_at'
            ]
        );

        $this->info("OK upserted rows=" . count($payload));
        return self::SUCCESS;
    }
}
