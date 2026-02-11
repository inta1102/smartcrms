<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class KpiOsDailyBackfillL0Lt extends Command
{
    protected $signature = 'kpi:os-daily-backfill-l0lt {--from=} {--to=}';
    protected $description = 'Backfill OS/NOA L0 dan LT ke tabel kpi_os_daily_aos dari loan_accounts (berdasarkan ft_pokok/ft_bunga).';

    public function handle(): int
    {
        $latest = DB::table('loan_accounts')->max('position_date');
        if (!$latest) {
            $this->error('loan_accounts kosong atau position_date null.');
            return self::FAILURE;
        }

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->toDateString()
            : Carbon::parse($latest)->toDateString();

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->toDateString()
            : Carbon::parse($to)->subDays(45)->toDateString(); // default backfill 45 hari

        $this->info("Backfill L0/LT from={$from} to={$to} ...");

        // agregasi harian per ao_code
        $rows = DB::table('loan_accounts as la')
            ->selectRaw("
                DATE(la.position_date) as d,
                LPAD(TRIM(la.ao_code),6,'0') as ao_code,

                ROUND(SUM(CASE WHEN la.is_active=1 THEN la.outstanding ELSE 0 END)) as os_total,
                SUM(CASE WHEN la.is_active=1 THEN 1 ELSE 0 END) as noa_total,

                ROUND(SUM(CASE WHEN la.is_active=1 AND la.ft_pokok=0 AND la.ft_bunga=0 THEN la.outstanding ELSE 0 END)) as os_l0,
                SUM(CASE WHEN la.is_active=1 AND la.ft_pokok=0 AND la.ft_bunga=0 THEN 1 ELSE 0 END) as noa_l0,

                ROUND(SUM(CASE WHEN la.is_active=1 AND (la.ft_pokok=1 OR la.ft_bunga=1) THEN la.outstanding ELSE 0 END)) as os_lt,
                SUM(CASE WHEN la.is_active=1 AND (la.ft_pokok=1 OR la.ft_bunga=1) THEN 1 ELSE 0 END) as noa_lt
            ")
            ->whereBetween(DB::raw('DATE(la.position_date)'), [$from, $to])
            ->whereNotNull('la.ao_code')
            ->whereRaw("TRIM(la.ao_code) <> ''")
            ->groupBy('d', 'ao_code')
            ->orderBy('d')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('Tidak ada data agregasi yang ditemukan.');
            return self::SUCCESS;
        }

        DB::beginTransaction();
        try {
            foreach ($rows as $r) {
                // update existing row dulu
                $updated = DB::table('kpi_os_daily_aos')
                    ->whereDate('position_date', $r->d)
                    ->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [$r->ao_code])
                    ->update([
                        'os_total'  => (float) $r->os_total,
                        'noa_total' => (int) $r->noa_total,
                        'os_l0'     => (float) $r->os_l0,
                        'noa_l0'    => (int) $r->noa_l0,
                        'os_lt'     => (float) $r->os_lt,
                        'noa_lt'    => (int) $r->noa_lt,
                    ]);

                // kalau tidak ada row (belum pernah dibuat), insert
                if ($updated === 0) {
                    DB::table('kpi_os_daily_aos')->insert([
                        'position_date' => $r->d,
                        'ao_code'       => $r->ao_code,
                        'os_total'      => (float) $r->os_total,
                        'noa_total'     => (int) $r->noa_total,
                        'os_l0'         => (float) $r->os_l0,
                        'noa_l0'        => (int) $r->noa_l0,
                        'os_lt'         => (float) $r->os_lt,
                        'noa_lt'        => (int) $r->noa_lt,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            }

            DB::commit();
            $this->info('OK backfill selesai.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Gagal: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
