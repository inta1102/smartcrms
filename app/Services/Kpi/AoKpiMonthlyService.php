<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AoKpiMonthlyService
{
    public function buildForPeriod(string $periodYmd, ?int $userId = null): array
    {
        $period = Carbon::parse($periodYmd)->startOfMonth()->toDateString();
        $prevPeriod = Carbon::parse($period)->subMonth()->startOfMonth()->toDateString();

        // detect final: snapshot month period exists?
        $hasSnapshotPeriod = DB::table('loan_account_snapshots_monthly')
            ->where('snapshot_month', $period)
            ->limit(1)
            ->exists();

        $closingSource = $hasSnapshotPeriod ? 'snapshot' : 'live';

        // AO list (from users with ao_code)
        $usersQ = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '');

        if ($userId) $usersQ->where('id', $userId);

        // adjust levels if needed
        $usersQ->whereIn('level', ['AO','RO','SO','FE','BE']);

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
                ->groupBy('ao_code');

        // NPL migration by ao_code (prev snapshot join loan_accounts live)
        $nplMigAgg = DB::table('loan_account_snapshots_monthly as prev')
            ->join('loan_accounts as now', 'now.account_no', '=', 'prev.account_no')
            ->selectRaw("
                prev.ao_code,
                SUM(CASE WHEN prev.kolek < 3 AND now.kolek >= 3 THEN now.outstanding ELSE 0 END) as os_npl_migrated
            ")
            ->where('prev.snapshot_month', $prevPeriod)
            ->groupBy('prev.ao_code');

        // Repayment rate agg by ao_code for this period
        $rrAgg = DB::table('loan_installments')
            ->selectRaw("
                ao_code,
                COUNT(*) as rr_due_count,
                SUM(CASE WHEN is_paid_ontime = 1 THEN 1 ELSE 0 END) as rr_paid_ontime_count
            ")
            ->where('period', $period)
            ->groupBy('ao_code');

        // Targets (approved preferred; if not exists still ok)
        $targets = DB::table('kpi_ao_targets')
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        $count = 0;

        DB::transaction(function () use (
            $users, $openingAgg, $closingAgg, $nplMigAgg, $rrAgg, $targets,
            $period, $prevPeriod, $closingSource, $hasSnapshotPeriod, &$count
        ) {
            foreach ($users as $u) {
                $aoCode = (string) ($u->ao_code ?? '');
                if ($aoCode === '') continue;

                // fetch agg rows using subqueries (efficient enough for small AO count)
                $open = DB::query()->fromSub($openingAgg, 'o')->where('o.ao_code', $aoCode)->first();
                $close = DB::query()->fromSub($closingAgg, 'c')->where('c.ao_code', $aoCode)->first();
                $mig = DB::query()->fromSub($nplMigAgg, 'm')->where('m.ao_code', $aoCode)->first();
                $rr = DB::query()->fromSub($rrAgg, 'r')->where('r.ao_code', $aoCode)->first();

                $osOpening = (int) ($open->os_opening ?? 0);
                $noaOpening = (int) ($open->noa_opening ?? 0);

                $osClosing = (int) ($close->os_closing ?? 0);
                $noaClosing = (int) ($close->noa_closing ?? 0);

                $osGrowth = $osClosing - $osOpening;
                $noaGrowth = $noaClosing - $noaOpening;

                $osNplMigrated = (int) ($mig->os_npl_migrated ?? 0);
                $nplMigrationPct = KpiScoreHelper::safePct((float)$osNplMigrated, (float)max($osOpening, 0));

                $rrDue = (int) ($rr->rr_due_count ?? 0);
                $rrOntime = (int) ($rr->rr_paid_ontime_count ?? 0);
                $rrPct = $rrDue > 0 ? round(100.0 * $rrOntime / $rrDue, 2) : 0.0;

                $t = $targets->get($u->id);

                $targetId = $t->id ?? null;
                $targetOs = (int) ($t->target_os_growth ?? 0);
                $targetNoa = (int) ($t->target_noa_growth ?? 0);
                $targetActivity = (int) ($t->target_activity ?? 0);

                // achievements
                $osAchPct = KpiScoreHelper::safePct((float)$osGrowth, (float)$targetOs);
                $noaAchPct = KpiScoreHelper::safePct((float)$noaGrowth, (float)$targetNoa);
                $activityActual = 0; // TODO: hook to agenda table when ready
                $activityPct = KpiScoreHelper::safePct((float)$activityActual, (float)$targetActivity);

                // scores
                $scoreOs = KpiScoreHelper::scoreFromAchievementPct($osAchPct);
                $scoreNoa = KpiScoreHelper::scoreFromAchievementPct($noaAchPct);
                $scoreRr = KpiScoreHelper::scoreFromRepaymentRate($rrPct);
                $scoreKolek = KpiScoreHelper::scoreFromNplMigration($nplMigrationPct);
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

                DB::table('kpi_ao_monthlies')->updateOrInsert(
                    ['period' => $period, 'user_id' => $u->id],
                    [
                        'ao_code' => $aoCode,
                        'target_id' => $targetId,

                        'os_opening' => $osOpening,
                        'os_closing' => $osClosing,
                        'os_growth' => $osGrowth,

                        'noa_opening' => $noaOpening,
                        'noa_closing' => $noaClosing,
                        'noa_growth' => $noaGrowth,

                        'os_npl_migrated' => $osNplMigrated,
                        'npl_migration_pct' => $nplMigrationPct,

                        'rr_due_count' => $rrDue,
                        'rr_paid_ontime_count' => $rrOntime,
                        'rr_pct' => $rrPct,

                        'activity_target' => $targetActivity,
                        'activity_actual' => $activityActual,
                        'activity_pct' => $activityPct,

                        'is_final' => $hasSnapshotPeriod,
                        'data_source' => $closingSource,
                        'calculated_at' => now(),

                        'score_os' => $scoreOs,
                        'score_noa' => $scoreNoa,
                        'score_rr' => $scoreRr,
                        'score_kolek' => $scoreKolek,
                        'score_activity' => $scoreActivity,
                        'score_total' => $total,

                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $count++;
            }
        });

        return [
            'period' => $period,
            'prevPeriod' => $prevPeriod,
            'source' => $closingSource,
            'is_final' => $hasSnapshotPeriod,
            'rows' => $count,
        ];
    }
}
