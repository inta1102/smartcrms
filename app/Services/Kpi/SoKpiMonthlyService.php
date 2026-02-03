<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SoKpiMonthlyService
{
    /**
     * RR SO: installments in period (current KPI month) but restricted to accounts
     * disbursed in the last 3 months window ending at KPI period.
     */
    public function buildForPeriod(string $periodYmd, ?int $userId = null): array
    {
        $period = Carbon::parse($periodYmd)->startOfMonth()->toDateString();
        $winStart = Carbon::parse($period)->subMonths(2)->startOfMonth()->toDateString(); // 3-month window start

        // Users (SO only)
        $usersQ = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '')
            ->whereIn('level', ['SO']);

        if ($userId) $usersQ->where('id', $userId);

        $users = $usersQ->get();

        // Disbursement agg by ao_code for period
        $disbAgg = DB::table('loan_disbursements')
            ->selectRaw("ao_code, ROUND(SUM(amount)) as os_disbursement, COUNT(DISTINCT account_no) as noa_disbursement")
            ->where('period', $period)
            ->groupBy('ao_code');

        // Targets
        $targets = DB::table('kpi_so_targets')
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        $count = 0;

        DB::transaction(function () use ($users, $targets, $disbAgg, $period, $winStart, &$count) {
            foreach ($users as $u) {
                $aoCode = (string) ($u->ao_code ?? '');
                if ($aoCode === '') continue;

                $d = DB::query()->fromSub($disbAgg, 'd')->where('d.ao_code', $aoCode)->first();
                $osDisb = (int) ($d->os_disbursement ?? 0);
                $noaDisb = (int) ($d->noa_disbursement ?? 0);

                // accounts disbursed in the last 3 months window (winStart..period)
                $accounts = DB::table('loan_disbursements')
                    ->where('ao_code', $aoCode)
                    ->whereBetween('period', [$winStart, $period])
                    ->pluck('account_no');

                $rrDue = 0; $rrOntime = 0; $rrPct = 0.0;

                if ($accounts->count() > 0) {
                    $rr = DB::table('loan_installments')
                        ->selectRaw("
                            COUNT(*) as rr_due_count,
                            SUM(CASE WHEN is_paid_ontime = 1 THEN 1 ELSE 0 END) as rr_paid_ontime_count
                        ")
                        ->where('period', $period)
                        ->whereIn('account_no', $accounts->all())
                        ->first();

                    $rrDue = (int) ($rr->rr_due_count ?? 0);
                    $rrOntime = (int) ($rr->rr_paid_ontime_count ?? 0);
                    $rrPct = $rrDue > 0 ? round(100.0 * $rrOntime / $rrDue, 2) : 0.0;
                }

                $t = $targets->get($u->id);
                $targetId = $t->id ?? null;
                $targetOs = (int) ($t->target_os_disbursement ?? 0);
                $targetNoa = (int) ($t->target_noa_disbursement ?? 0);
                $targetActivity = (int) ($t->target_activity ?? 0);

                $osAchPct = KpiScoreHelper::safePct((float)$osDisb, (float)$targetOs);
                $noaAchPct = KpiScoreHelper::safePct((float)$noaDisb, (float)$targetNoa);

                $activityActual = 0; // TODO: hook agenda/visit
                $activityPct = KpiScoreHelper::safePct((float)$activityActual, (float)$targetActivity);

                $scoreOs = KpiScoreHelper::scoreFromAchievementPct($osAchPct);
                $scoreNoa = KpiScoreHelper::scoreFromAchievementPct($noaAchPct);
                $scoreRr = KpiScoreHelper::scoreFromRepaymentRate($rrPct);
                $scoreActivity = KpiScoreHelper::scoreFromAchievementPct($activityPct);

                // weights (SO): OS 40, NOA 30, RR 20, Activity 10 (silakan adjust)
                $total = round(
                    $scoreOs * 0.40 +
                    $scoreNoa * 0.30 +
                    $scoreRr * 0.20 +
                    $scoreActivity * 0.10,
                    2
                );

                DB::table('kpi_so_monthlies')->updateOrInsert(
                    ['period' => $period, 'user_id' => $u->id],
                    [
                        'ao_code' => $aoCode,
                        'target_id' => $targetId,

                        'os_disbursement' => $osDisb,
                        'noa_disbursement' => $noaDisb,

                        'rr_due_count' => $rrDue,
                        'rr_paid_ontime_count' => $rrOntime,
                        'rr_pct' => $rrPct,

                        'activity_target' => $targetActivity,
                        'activity_actual' => $activityActual,
                        'activity_pct' => $activityPct,

                        'is_final' => true, // disbursement/import-based; if you want, tie to import completeness
                        'calculated_at' => now(),

                        'score_os' => $scoreOs,
                        'score_noa' => $scoreNoa,
                        'score_rr' => $scoreRr,
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
            'rr_window_start' => $winStart,
            'rows' => $count,
        ];
    }
}
