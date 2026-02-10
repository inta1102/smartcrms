<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class KpiOsDailySnapshot extends Command
{
    protected $signature = 'kpi:os-daily-snapshot {--date=}';
    protected $description = 'Build OS total per AO per day from loan_accounts for a given position_date (upsert to kpi_os_daily_aos)';

    public function handle(): int
    {
        // date param: YYYY-MM-DD (optional)
        $opt = (string) ($this->option('date') ?? '');

        if ($opt !== '') {
            $date = Carbon::parse($opt)->toDateString();
        } else {
            // default: pakai position_date terbaru di loan_accounts (<= hari ini)
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

        // Aggregate OS & NOA per AO
        $rows = DB::table('loan_accounts')
            ->selectRaw("
                LPAD(TRIM(COALESCE(ao_code,'')),6,'0') as ao_code,
                ROUND(SUM(COALESCE(outstanding,0))) as os_total,
                COUNT(*) as noa_total
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

        $payload = $rows->map(function ($r) use ($date) {
            $ao = (string) ($r->ao_code ?? '000000');
            if ($ao === '' || $ao === '000000') $ao = '000000';

            return [
                'position_date' => $date,
                'ao_code'       => $ao,
                'os_total'      => (int) ($r->os_total ?? 0),
                'noa_total'     => (int) ($r->noa_total ?? 0),
                'source'        => 'loan_accounts',
                'computed_at'   => now(),
                'updated_at'    => now(),
                'created_at'    => now(),
            ];
        })->filter(fn ($x) => $x['ao_code'] !== '000000')->values()->all();

        DB::table('kpi_os_daily_aos')->upsert(
            $payload,
            ['position_date', 'ao_code'],
            ['os_total', 'noa_total', 'source', 'computed_at', 'updated_at']
        );

        $this->info("OK upserted rows=" . count($payload));
        return self::SUCCESS;
    }
}
