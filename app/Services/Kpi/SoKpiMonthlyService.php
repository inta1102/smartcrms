<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SoKpiMonthlyService
{
    /**
     * SO KPI:
     * - OS/NOA = disbursement bulan period
     * - RR = OS current (ft_pokok=0 & ft_bunga=0) / total OS
     *   hanya untuk account yang disburse dalam window 3 bulan (winStart..period)
     *
     * Rule sumber data RR:
     * - Kalau period = bulan berjalan => ambil dari loan_accounts pada position_date terakhir
     * - Kalau period < bulan berjalan => ambil dari loan_account_snapshots_monthly pada snapshot_month = period
     */
    public function buildForPeriod(string $periodYmd, ?int $userId = null): array
    {
        $period     = Carbon::parse($periodYmd)->startOfMonth()->toDateString(); // Y-m-01
        $periodEnd  = Carbon::parse($period)->endOfMonth()->toDateString();
        $winStart   = Carbon::parse($period)->subMonths(2)->startOfMonth()->toDateString();

        $isCurrentMonth = Carbon::parse($period)->equalTo(now()->startOfMonth());

        // positionDate hanya dipakai untuk bulan berjalan
        $positionDate = null;
        if ($isCurrentMonth) {
            $positionDate = DB::table('loan_accounts')
                ->whereBetween('position_date', [$period, $periodEnd])
                ->max('position_date');

            if (!$positionDate) {
                $positionDate = DB::table('loan_accounts')
                    ->whereDate('position_date', '<=', $periodEnd)
                    ->max('position_date');
            }
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

        // Disbursement agg by ao_code (normalized)
        $disbAgg = DB::table('loan_disbursements')
            ->selectRaw("
                LPAD(TRIM(ao_code),6,'0') as ao_code,
                ROUND(SUM(amount)) as os_disbursement,
                COUNT(DISTINCT account_no) as noa_disbursement
            ")
            ->where('period', $period)
            ->groupBy('ao_code');

        // Targets (period = Y-m-01)
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
            $isCurrentMonth,
            $positionDate,
            &$count
        ) {
            foreach ($users as $u) {
                $aoCode = str_pad(trim((string)($u->ao_code ?? '')), 6, '0', STR_PAD_LEFT);
                if ($aoCode === '' || $aoCode === '000000') {
                    continue;
                }

                // disbursement (bulan period)
                $d = DB::query()->fromSub($disbAgg, 'd')->where('d.ao_code', $aoCode)->first();
                $osDisb  = (int) ($d->os_disbursement ?? 0);
                $noaDisb = (int) ($d->noa_disbursement ?? 0);

                // account_no yang disburse di window 3 bulan
                $accountNos = DB::table('loan_disbursements')
                    ->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [$aoCode])
                    ->whereBetween('period', [$winStart, $period])
                    ->pluck('account_no')
                    ->map(fn($v) => trim((string)$v))
                    ->filter(fn($v) => $v !== '')
                    ->unique()
                    ->values()
                    ->all();

                // ====== RR (OS-based) ======
                $rrOsTotal   = 0;
                $rrOsCurrent = 0;
                $rrPct       = 0.0;

                if (!empty($accountNos)) {
                    if ($isCurrentMonth) {
                        if (!empty($positionDate)) {
                            $rr = DB::table('loan_accounts')
                                ->selectRaw("
                                    ROUND(SUM(outstanding)) as rr_os_total,
                                    ROUND(SUM(
                                        CASE WHEN COALESCE(ft_pokok,0)=0
                                              AND COALESCE(ft_bunga,0)=0
                                             THEN outstanding ELSE 0 END
                                    )) as rr_os_current
                                ")
                                ->whereDate('position_date', $positionDate)
                                ->whereIn('account_no', $accountNos)
                                ->first();

                            $rrOsTotal   = (int) ($rr->rr_os_total ?? 0);
                            $rrOsCurrent = (int) ($rr->rr_os_current ?? 0);
                        }
                    } else {
                        $rr = DB::table('loan_account_snapshots_monthly')
                            ->selectRaw("
                                ROUND(SUM(outstanding)) as rr_os_total,
                                ROUND(SUM(
                                    CASE WHEN COALESCE(ft_pokok,0)=0
                                          AND COALESCE(ft_bunga,0)=0
                                         THEN outstanding ELSE 0 END
                                )) as rr_os_current
                            ")
                            ->whereDate('snapshot_month', $period)
                            ->whereIn('account_no', $accountNos)
                            ->first();

                        $rrOsTotal   = (int) ($rr->rr_os_total ?? 0);
                        $rrOsCurrent = (int) ($rr->rr_os_current ?? 0);
                    }

                    $rrPct = $rrOsTotal > 0 ? round(100.0 * $rrOsCurrent / $rrOsTotal, 2) : 0.0;
                }

                // legacy cols (schema lama) tetap 0
                $rrDue    = 0;
                $rrOntime = 0;

                // ====== Targets by user_id ======
                $t = $targets->get($u->id);

                $targetId       = $t->id ?? null;
                $targetOs       = (int) ($t->target_os_disbursement ?? 0);
                $targetNoa      = (int) ($t->target_noa_disbursement ?? 0);
                $targetRr       = (float)($t->target_rr ?? 100);
                $targetActivity = (int) ($t->target_activity ?? 0);

                // ====== existing monthly row (untuk ambil activity_actual yg sudah diinput TL/Kasi) ======
                $existing = DB::table('kpi_so_monthlies')
                    ->where('period', $period)
                    ->where('user_id', $u->id)
                    ->first();

                $activityActual = (int)($existing->activity_actual ?? 0);

                // ====== pct & scores ======
                $osAchPct       = KpiScoreHelper::safePct((float)$osDisb, (float)$targetOs);
                $noaAchPct      = KpiScoreHelper::safePct((float)$noaDisb, (float)$targetNoa);
                $activityPct    = KpiScoreHelper::safePct((float)$activityActual, (float)$targetActivity);

                $scoreOs        = KpiScoreHelper::scoreFromAchievementPct($osAchPct);
                $scoreNoa       = KpiScoreHelper::scoreFromAchievementPct($noaAchPct);

                // RR scoring: pakai rrPct actual (0..100)
                $scoreRr        = KpiScoreHelper::scoreFromRepaymentRate($rrPct);
                $scoreActivity  = KpiScoreHelper::scoreFromAchievementPct($activityPct);

                // total (SO): OS 40, NOA 30, RR 20, Activity 10
                $total = round(
                    $scoreOs * 0.40 +
                    $scoreNoa * 0.30 +
                    $scoreRr * 0.20 +
                    $scoreActivity * 0.10,
                    2
                );

                // key upsert
                $key = ['period' => $period, 'user_id' => $u->id];

                $payload = [
                    'ao_code'   => $aoCode,
                    'target_id' => $targetId,

                    'os_disbursement'  => $osDisb,
                    'noa_disbursement' => $noaDisb,

                    // legacy cols keep 0
                    'rr_due_count'         => $rrDue,
                    'rr_paid_ontime_count' => $rrOntime,

                    // rr actual (OS-based)
                    'rr_pct' => $rrPct,

                    // activity
                    'activity_target' => $targetActivity,
                    'activity_actual' => $activityActual,
                    'activity_pct'    => $activityPct,

                    'is_final'      => true,
                    'calculated_at' => now(),

                    // scores
                    'score_os'       => $scoreOs,
                    'score_noa'      => $scoreNoa,
                    'score_rr'       => $scoreRr,
                    'score_activity' => $scoreActivity,
                    'score_total'    => $total,

                    'updated_at' => now(),
                ];

                // upsert (tanpa overwrite created_at)
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
