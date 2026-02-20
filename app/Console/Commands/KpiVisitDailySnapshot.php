<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KpiVisitDailySnapshot extends Command
{
    protected $signature = 'kpi:visit-daily-snapshot
                            {--date= : Snapshot date (YYYY-MM-DD). Default: today}
                            {--yesterday : Use yesterday as snapshot date}
                            {--ao= : Specific ao_code (6 digit) optional}
                            {--dry : Dry run (no upsert)}';

    protected $description = 'Build daily visit KPI snapshot per AO from ro_visits into kpi_visit_daily_aos';

    public function handle(): int
    {
        // =========================
        // 0) Resolve date
        // =========================
        $dateOpt = trim((string)$this->option('date'));
        $useYesterday = (bool)$this->option('yesterday');

        if ($useYesterday) {
            $date = Carbon::yesterday()->toDateString();
        } elseif ($dateOpt !== '') {
            try {
                $date = Carbon::parse($dateOpt)->toDateString();
            } catch (\Throwable $e) {
                $this->error("Invalid --date format. Use YYYY-MM-DD");
                return self::FAILURE;
            }
        } else {
            $date = Carbon::today()->toDateString();
        }

        $aoFilter = trim((string)$this->option('ao'));
        if ($aoFilter !== '') {
            $aoFilter = str_pad($aoFilter, 6, '0', STR_PAD_LEFT);
        } else {
            $aoFilter = null;
        }

        $dry = (bool)$this->option('dry');

        // =========================
        // 1) Basic validations
        // =========================
        if (!Schema::hasTable('ro_visits')) {
            $this->error("Table ro_visits not found.");
            return self::FAILURE;
        }
        if (!Schema::hasTable('kpi_visit_daily_aos')) {
            $this->error("Table kpi_visit_daily_aos not found.");
            return self::FAILURE;
        }

        $hasPhotos = Schema::hasTable('ro_visit_photos');
        // ro_visit_photos expected columns: ro_visit_id, path

        // =========================
        // 2) Build aggregation query
        // =========================
        $q = DB::table('ro_visits as v')
            ->selectRaw('v.ao_code as ao_code')
            ->selectRaw('SUM(CASE WHEN v.status = "planned" THEN 1 ELSE 0 END) as planned_total')
            ->selectRaw('SUM(CASE WHEN v.status = "done" THEN 1 ELSE 0 END) as done_total')
            // overdue = planned + visit_date < snapshotDate
            ->selectRaw('SUM(CASE WHEN v.status = "planned" AND v.visit_date < ? THEN 1 ELSE 0 END) as overdue_total', [$date])
            // done_with_geo (lat & lng not null)
            ->selectRaw('SUM(CASE WHEN v.status = "done" AND v.lat IS NOT NULL AND v.lng IS NOT NULL THEN 1 ELSE 0 END) as done_with_geo')
            // done_with_note (note exists and length >= 10)
            ->selectRaw('SUM(CASE WHEN v.status = "done" AND v.lkh_note IS NOT NULL AND CHAR_LENGTH(TRIM(v.lkh_note)) >= 10 THEN 1 ELSE 0 END) as done_with_note')
            ->whereDate('v.visit_date', '<=', $date) // <= snapshot date (biar overdue kebaca)
            ->whereNotNull('v.ao_code')
            ->whereRaw('TRIM(v.ao_code) <> ""');

        if ($aoFilter) {
            $q->where('v.ao_code', $aoFilter);
        }

        // =========================
        // 2b) done_with_photo
        // =========================
        if ($hasPhotos) {
            // join subquery: count photos per ro_visit_id
            $photoSub = DB::table('ro_visit_photos')
                ->selectRaw('ro_visit_id, COUNT(*) as photo_count')
                ->groupBy('ro_visit_id');

            $q->leftJoinSub($photoSub, 'p', function ($join) {
                $join->on('p.ro_visit_id', '=', 'v.id');
            });

            $q->addSelect(DB::raw('SUM(CASE WHEN v.status = "done" AND COALESCE(p.photo_count,0) > 0 THEN 1 ELSE 0 END) as done_with_photo'));
        } else {
            $q->addSelect(DB::raw('0 as done_with_photo'));
        }

        $q->groupBy('v.ao_code');

        $rows = $q->get();

        if ($rows->isEmpty()) {
            $this->warn("No ro_visits rows found for snapshot date <= {$date}" . ($aoFilter ? " (AO {$aoFilter})" : ""));
            return self::SUCCESS;
        }

        // =========================
        // 3) Upsert snapshot rows
        // =========================
        $payload = [];
        foreach ($rows as $r) {
            $planned = (int)($r->planned_total ?? 0);
            $done    = (int)($r->done_total ?? 0);
            $denom   = max($planned + $done, 0);

            // VCR% = done / (done+planned) * 100
            $vcr = $denom > 0 ? round(($done / $denom) * 100, 1) : 0.0;

            $payload[] = [
                'date'            => $date,
                'ao_code'         => str_pad(trim((string)$r->ao_code), 6, '0', STR_PAD_LEFT),

                'planned_total'   => $planned,
                'done_total'      => $done,
                'overdue_total'   => (int)($r->overdue_total ?? 0),
                'vcr_pct'         => $vcr,

                'done_with_geo'   => (int)($r->done_with_geo ?? 0),
                'done_with_photo' => (int)($r->done_with_photo ?? 0),
                'done_with_note'  => (int)($r->done_with_note ?? 0),

                'meta' => json_encode([
                    'source' => 'ro_visits',
                    'has_photos_table' => $hasPhotos,
                    'snapshot_generated_at' => now()->toDateTimeString(),
                ]),

                'updated_at' => now(),
                'created_at' => now(),
            ];
        }

        $this->info("Snapshot date: {$date}");
        $this->info("Rows prepared: " . count($payload));
        if ($dry) {
            $this->warn("Dry run enabled: no upsert executed.");
            return self::SUCCESS;
        }

        // Upsert by unique(date, ao_code)
        DB::table('kpi_visit_daily_aos')->upsert(
            $payload,
            ['date', 'ao_code'],
            [
                'planned_total','done_total','overdue_total','vcr_pct',
                'done_with_geo','done_with_photo','done_with_note',
                'meta','updated_at',
            ]
        );

        $this->info("Upsert done ✅");
        return self::SUCCESS;
    }
}