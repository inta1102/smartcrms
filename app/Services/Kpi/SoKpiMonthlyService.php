<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SoKpiMonthlyService
{
    /**
     * SO KPI:
     * - OS/NOA = disbursement bulan period
     * - RR = OS lancar tanpa tunggakan / total OS
     *   tetapi hanya untuk account yang disburse dalam window 3 bulan (winStart..period)
     */
    public function buildForPeriod(string $periodYmd, ?int $userId = null): array
    {
        $period     = Carbon::parse($periodYmd)->startOfMonth()->toDateString();
        $periodEnd  = Carbon::parse($period)->endOfMonth()->toDateString();
        $winStart   = Carbon::parse($period)->subMonths(2)->startOfMonth()->toDateString();

        // ✅ cari position_date terakhir di bulan period (fallback kalau snapshot belum dipakai)
        $positionDate = DB::table('loan_accounts')
            ->whereBetween('position_date', [$period, $periodEnd])
            ->max('position_date');

        // ✅ fallback: kalau bulan tsb belum ada data sama sekali, pakai max <= periodEnd
        if (!$positionDate) {
            $positionDate = DB::table('loan_accounts')
                ->whereDate('position_date', '<=', $periodEnd)
                ->max('position_date');
        }

        // Users (SO only)
        $usersQ = DB::table('users')
            ->select(['id', 'name', 'ao_code', 'level'])
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '')
            ->whereIn('level', ['SO']);

        if (!is_null($userId)) {
            $usersQ->where('id', $userId);
        }

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

        DB::transaction(function () use (
            $users,
            $targets,
            $disbAgg,
            $period,
            $winStart,
            $positionDate, // ✅ FIX: wajib di-pass ke closure
            &$count
        ) {
            foreach ($users as $u) {
                $aoCode = (string) ($u->ao_code ?? '');
                if ($aoCode === '') {
                    continue;
                }

                $d = DB::query()->fromSub($disbAgg, 'd')->where('d.ao_code', $aoCode)->first();
                $osDisb  = (int) ($d->os_disbursement ?? 0);
                $noaDisb = (int) ($d->noa_disbursement ?? 0);

                // accounts disbursed in the last 3 months window (winStart..period)
                $accountNos = DB::table('loan_disbursements')
                    ->where('ao_code', $aoCode)
                    ->whereBetween('period', [$winStart, $period])
                    ->pluck('account_no')
                    ->all();

                // ✅ RR baru (OS basis) pada posisi closing (positionDate)
                $rrOsTotal   = 0;
                $rrOsCurrent = 0;
                $rrPct       = 0.0;

                if (!empty($accountNos) && !empty($positionDate)) {
                    $rr = DB::table('loan_accounts')
                        ->selectRaw("
                            ROUND(SUM(outstanding)) as rr_os_total,
                            ROUND(SUM(CASE WHEN COALESCE(ft_pokok,0)=0 AND COALESCE(ft_bunga,0)=0 THEN outstanding ELSE 0 END)) as rr_os_current
                        ")
                        ->whereDate('position_date', $positionDate)
                        ->whereIn('account_no', $accountNos)
                        ->first();

                    $rrOsTotal   = (int) ($rr->rr_os_total ?? 0);
                    $rrOsCurrent = (int) ($rr->rr_os_current ?? 0);
                    $rrPct       = $rrOsTotal > 0 ? round(100.0 * $rrOsCurrent / $rrOsTotal, 2) : 0.0;
                }

                // kolom lama tetap 0 (schema lama)
                $rrDue    = 0;
                $rrOntime = 0;

                $t = $targets->get($u->id);
                $targetId       = $t->id ?? null;
                $targetOs       = (int) ($t->target_os_disbursement ?? 0);
                $targetNoa      = (int) ($t->target_noa_disbursement ?? 0);
                $targetActivity = (int) ($t->target_activity ?? 0);

                $osAchPct  = KpiScoreHelper::safePct((float) $osDisb, (float) $targetOs);
                $noaAchPct = KpiScoreHelper::safePct((float) $noaDisb, (float) $targetNoa);

                // $activityActual = 0; // TODO
                // ambil existing monthly row (kalau sudah pernah ada)
                $existing = DB::table('kpi_so_monthlies')
                    ->where('period', $period)
                    ->where('user_id', $u->id)
                    ->first();

                // ambil actual handling dari input TL/Kasi (kalau belum ada = 0)
                $activityActual = (int)($existing->activity_actual ?? 0);

                // pct & score handling
                $activityPct = KpiScoreHelper::safePct((float)$activityActual, (float)$targetActivity);
                $scoreActivity = KpiScoreHelper::scoreFromAchievementPct($activityPct);
               
                $scoreOs       = KpiScoreHelper::scoreFromAchievementPct($osAchPct);
                $scoreNoa      = KpiScoreHelper::scoreFromAchievementPct($noaAchPct);
                $scoreRr       = KpiScoreHelper::scoreFromRepaymentRate($rrPct);
                

                // weights (SO): OS 40, NOA 30, RR 20, Activity 10
                $total = round(
                    $scoreOs * 0.40 +
                    $scoreNoa * 0.30 +
                    $scoreRr * 0.20 +
                    $scoreActivity * 0.10,
                    2
                );

                $key = ['period' => $period, 'user_id' => $u->id];

                $payload = [
                    'ao_code'   => $aoCode,
                    'target_id' => $targetId,

                    'os_disbursement'  => $osDisb,
                    'noa_disbursement' => $noaDisb,

                    // legacy cols keep 0
                    'rr_due_count'         => $rrDue,
                    'rr_paid_ontime_count' => $rrOntime,

                    // ✅ rr_pct sekarang OS-based
                    'rr_pct' => $rrPct,

                    // ✅ kalau ada kolom ini nanti kita isi
                    // 'rr_os_total'   => $rrOsTotal,
                    // 'rr_os_current' => $rrOsCurrent,

                    'activity_target' => $targetActivity,
                    'activity_actual' => $activityActual,
                    'activity_pct'    => $activityPct,

                    'is_final'      => true,
                    'calculated_at' => now(),

                    'score_os'       => $scoreOs,
                    'score_noa'      => $scoreNoa,
                    'score_rr'       => $scoreRr,
                    'score_activity' => $scoreActivity,
                    'score_total'    => $total,

                    'updated_at' => now(),
                ];

                // ✅ FIX: jangan overwrite created_at saat update
                $exists = DB::table('kpi_so_monthlies')->where($key)->exists();
                if ($exists) {
                    DB::table('kpi_so_monthlies')->where($key)->update($payload);
                } else {
                    $payload['created_at'] = now();
                    DB::table('kpi_so_monthlies')->insert(array_merge($key, $payload));
                }

                $count++;
            }
        });

        return [
            'period'          => $period,
            'rr_window_start' => $winStart,
            'position_date'   => $positionDate,
            'rows'            => $count,
        ];
    }
}
