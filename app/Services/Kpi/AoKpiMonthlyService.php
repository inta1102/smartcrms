<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AoKpiMonthlyService
{
    public function buildForPeriod(string $periodYmd, ?int $userId = null): array
    {
        $period     = Carbon::parse($periodYmd)->startOfMonth()->toDateString();
        $periodEnd  = Carbon::parse($period)->endOfMonth()->toDateString();
        $prevPeriod = Carbon::parse($period)->subMonth()->startOfMonth()->toDateString();

        // detect final: snapshot month period exists?
        $hasSnapshotPeriod = DB::table('loan_account_snapshots_monthly')
            ->where('snapshot_month', $period)
            ->limit(1)
            ->exists();

        $closingSource = $hasSnapshotPeriod ? 'snapshot' : 'live';

        /**
         * ✅ FIX UTAMA:
         * Untuk mode LIVE, jangan pakai $period (awal bulan).
         * Ambil position_date terakhir di bulan period (closing date).
         */
        $positionDate = null;
        if (!$hasSnapshotPeriod) {
            $positionDate = DB::table('loan_accounts')
                ->whereBetween('position_date', [$period, $periodEnd])
                ->max('position_date');

            // fallback: kalau bulan tsb belum ada data sama sekali, pakai max <= periodEnd
            if (!$positionDate) {
                $positionDate = DB::table('loan_accounts')
                    ->whereDate('position_date', '<=', $periodEnd)
                    ->max('position_date');
            }
        }

        // AO list (from users with ao_code)
        $usersQ = DB::table('users')
            ->select(['id', 'name', 'ao_code', 'level'])
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '');

        if (!is_null($userId)) {
            $usersQ->where('id', $userId);
        }

        // adjust levels if needed
        $usersQ->whereIn('level', ['AO']);

        $users = $usersQ->get();

        // Opening aggregate by ao_code
        $openingAgg = DB::table('loan_account_snapshots_monthly')
            ->selectRaw("ao_code, ROUND(SUM(outstanding)) as os_opening, COUNT(*) as noa_opening")
            ->where('snapshot_month', $prevPeriod)
            ->groupBy('ao_code');

        // Closing aggregate by ao_code (live vs snapshot)
        $closingAgg = $hasSnapshotPeriod
            ? DB::table('loan_account_snapshots_monthly')
                ->selectRaw("ao_code, ROUND(SUM(outstanding)) as os_closing, COUNT(*) as noa_closing")
                ->where('snapshot_month', $period)
                ->groupBy('ao_code')
            : DB::table('loan_accounts')
                ->selectRaw("ao_code, ROUND(SUM(outstanding)) as os_closing, COUNT(*) as noa_closing")
                ->whereDate('position_date', $positionDate) // ✅ pakai closing date
                ->groupBy('ao_code');

        // NPL migration by ao_code (prev snapshot join loan_accounts live)
        $nplMigAgg = DB::table('loan_account_snapshots_monthly as prev')
            ->join('loan_accounts as now', 'now.account_no', '=', 'prev.account_no')
            ->selectRaw("
                prev.ao_code,
                SUM(CASE WHEN prev.kolek < 3 AND now.kolek >= 3 THEN now.outstanding ELSE 0 END) as os_npl_migrated
            ")
            ->where('prev.snapshot_month', $prevPeriod)
            ->when(!$hasSnapshotPeriod, function ($q) use ($positionDate) {
                $q->whereDate('now.position_date', $positionDate); // ✅ selaraskan posisi (live)
            })
            ->when($hasSnapshotPeriod, function ($q) use ($period) {
                /**
                 * Kalau snapshot period ada:
                 * sebenarnya idealnya join ke snapshot "now" (bulan period) bukan loan_accounts.
                 * Tapi karena struktur kamu sekarang join ke loan_accounts, maka kita biarkan.
                 * Kalau kamu mau bener-bener snapshot-only, nanti aku bikinin versi join snapshot vs snapshot.
                 */
                $q->whereDate('now.position_date', $period); // best-effort (kalau memang posisi = period)
            })
            ->groupBy('prev.ao_code');

        /**
         * ✅ RR (Repayment Rate) versi baru:
         * RR = OS lancar tanpa tunggakan / Total OS
         * Lancar tanpa tunggakan: ft_pokok = 0 AND ft_bunga = 0
         */
        $rrAgg = $hasSnapshotPeriod
            ? DB::table('loan_account_snapshots_monthly')
                ->selectRaw("
                    ao_code,
                    ROUND(SUM(outstanding)) as rr_os_total,
                    ROUND(SUM(CASE WHEN COALESCE(ft_pokok,0)=0 AND COALESCE(ft_bunga,0)=0 THEN outstanding ELSE 0 END)) as rr_os_current
                ")
                ->where('snapshot_month', $period)
                ->groupBy('ao_code')
            : DB::table('loan_accounts')
                ->selectRaw("
                    ao_code,
                    ROUND(SUM(outstanding)) as rr_os_total,
                    ROUND(SUM(CASE WHEN COALESCE(ft_pokok,0)=0 AND COALESCE(ft_bunga,0)=0 THEN outstanding ELSE 0 END)) as rr_os_current
                ")
                ->whereDate('position_date', $positionDate) // ✅ pakai closing date
                ->groupBy('ao_code');

        // Targets (approved preferred; if not exists still ok)
        $targets = DB::table('kpi_ao_targets')
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        $count = 0;

        DB::transaction(function () use (
            $users,
            $openingAgg,
            $closingAgg,
            $nplMigAgg,
            $rrAgg,
            $targets,
            $period,
            $prevPeriod,
            $closingSource,
            $hasSnapshotPeriod,
            &$count
        ) {
            foreach ($users as $u) {
                $aoCode = (string) ($u->ao_code ?? '');
                if ($aoCode === '') {
                    continue;
                }

                // fetch agg rows using subqueries
                $open  = DB::query()->fromSub($openingAgg, 'o')->where('o.ao_code', $aoCode)->first();
                $close = DB::query()->fromSub($closingAgg, 'c')->where('c.ao_code', $aoCode)->first();
                $mig   = DB::query()->fromSub($nplMigAgg, 'm')->where('m.ao_code', $aoCode)->first();
                $rr    = DB::query()->fromSub($rrAgg, 'r')->where('r.ao_code', $aoCode)->first();

                $osOpening  = (int) ($open->os_opening ?? 0);
                $noaOpening = (int) ($open->noa_opening ?? 0);

                $osClosing  = (int) ($close->os_closing ?? 0);
                $noaClosing = (int) ($close->noa_closing ?? 0);

                $osGrowth  = $osClosing - $osOpening;
                $noaGrowth = $noaClosing - $noaOpening;

                $osNplMigrated   = (int) ($mig->os_npl_migrated ?? 0);
                $nplMigrationPct = KpiScoreHelper::safePct((float) $osNplMigrated, (float) max($osOpening, 0));

                // ✅ RR baru (OS basis)
                $rrOsTotal   = (int) ($rr->rr_os_total ?? 0);
                $rrOsCurrent = (int) ($rr->rr_os_current ?? 0);
                $rrPct       = $rrOsTotal > 0 ? round(100.0 * $rrOsCurrent / $rrOsTotal, 2) : 0.0;

                // kolom lama tetap diisi 0 supaya schema lama tidak pecah
                $rrDue    = 0;
                $rrOntime = 0;

                $t = $targets->get($u->id);

                $targetId       = $t->id ?? null;
                $targetOs       = (int) ($t->target_os_growth ?? 0);
                $targetNoa      = (int) ($t->target_noa_growth ?? 0);
                $targetActivity = (int) ($t->target_activity ?? 0);

                // achievements
                $osAchPct       = KpiScoreHelper::safePct((float) $osGrowth, (float) $targetOs);
                $noaAchPct      = KpiScoreHelper::safePct((float) $noaGrowth, (float) $targetNoa);
                $activityActual = 0; // TODO
                $activityPct    = KpiScoreHelper::safePct((float) $activityActual, (float) $targetActivity);

                // scores
                $scoreOs       = KpiScoreHelper::scoreFromAchievementPct($osAchPct);
                $scoreNoa      = KpiScoreHelper::scoreFromAchievementPct($noaAchPct);
                $scoreRr       = KpiScoreHelper::scoreFromRepaymentRate($rrPct);
                $scoreKolek    = KpiScoreHelper::scoreFromNplMigration($nplMigrationPct);
                $scoreActivity = KpiScoreHelper::scoreFromAchievementPct($activityPct);

                // weights (AO): OS 35, NOA 15, RR 25, Kolek 15, Activity 10
                $total = round(
                    $scoreOs * 0.35 +
                    $scoreNoa * 0.15 +
                    $scoreRr * 0.25 +
                    $scoreKolek * 0.15 +
                    $scoreActivity * 0.10,
                    2
                );

                $key = ['period' => $period, 'user_id' => $u->id];

                $payload = [
                    'ao_code'   => $aoCode,
                    'target_id' => $targetId,

                    'os_opening' => $osOpening,
                    'os_closing' => $osClosing,
                    'os_growth'  => $osGrowth,

                    'noa_opening' => $noaOpening,
                    'noa_closing' => $noaClosing,
                    'noa_growth'  => $noaGrowth,

                    'os_npl_migrated'   => $osNplMigrated,
                    'npl_migration_pct' => $nplMigrationPct,

                    // legacy cols (keep)
                    'rr_due_count'         => $rrDue,
                    'rr_paid_ontime_count' => $rrOntime,

                    // ✅ rr_pct tetap dipakai tapi maknanya sekarang OS-based
                    'rr_pct' => $rrPct,

                    'activity_target' => $targetActivity,
                    'activity_actual' => $activityActual,
                    'activity_pct'    => $activityPct,

                    'is_final'      => $hasSnapshotPeriod,
                    'data_source'   => $closingSource,
                    'calculated_at' => now(),

                    'score_os'       => $scoreOs,
                    'score_noa'      => $scoreNoa,
                    'score_rr'       => $scoreRr,
                    'score_kolek'    => $scoreKolek,
                    'score_activity' => $scoreActivity,
                    'score_total'    => $total,

                    'updated_at' => now(),
                ];

                // ✅ FIX: jangan overwrite created_at saat update
                $exists = DB::table('kpi_ao_monthlies')->where($key)->exists();
                if ($exists) {
                    DB::table('kpi_ao_monthlies')->where($key)->update($payload);
                } else {
                    $payload['created_at'] = now();
                    DB::table('kpi_ao_monthlies')->insert(array_merge($key, $payload));
                }

                $count++;
            }
        });

        return [
            'period'        => $period,
            'prevPeriod'    => $prevPeriod,
            'source'        => $closingSource,
            'is_final'      => $hasSnapshotPeriod,
            'position_date' => $positionDate, // ✅ biar kebaca saat debug (null kalau snapshot)
            'rows'          => $count,
        ];
    }
}
