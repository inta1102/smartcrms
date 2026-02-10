<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Kpi\KpiSoCommunityInput;

class SoKpiMonthlyService
{
    /**
     * SO KPI:
     * - OS/NOA = disbursement bulan period
     * - RR (COHORT) = OS current (ft_pokok=0 & ft_bunga=0) / total OS
     *   ✅ hanya untuk rekening yang DISBURSE mulai 1 Jan 2026 s/d period KPI (kumulatif)
     *   ✅ khusus KPI Jan 2026: RR dipastikan 100% (business rule)
     *
     * Input manual (KBL):
     * - Handling Komunitas (handling_actual) -> dipetakan ke activity_actual
     * - OS Adjustment (os_adjustment) -> mengurangi OS disbursement (raw) sebelum scoring
     *
     * Rule sumber data RR:
     * - Kalau period = bulan berjalan => ambil dari loan_accounts pada position_date terakhir
     * - Kalau period < bulan berjalan => ambil dari loan_account_snapshots_monthly pada snapshot_month = period
     */
    public function buildForPeriod(string $periodYmd, ?int $userId = null): array
    {
        $period     = Carbon::parse($periodYmd)->startOfMonth()->toDateString(); // Y-m-01
        $periodEnd  = Carbon::parse($period)->endOfMonth()->toDateString();

        // ✅ RR cohort start (sesuai request user)
        $rrCohortStart = '2026-01-01';

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

        // ✅ Input manual KBL (handling + OS adjustment)
        $adjRows = KpiSoCommunityInput::query()
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

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

        // cache kolom optional supaya tidak berulang cek schema tiap loop
        $hasOsAdjCol  = Schema::hasColumn('kpi_so_monthlies', 'os_adjustment');
        $hasOsRawCol  = Schema::hasColumn('kpi_so_monthlies', 'os_disbursement_raw');

        DB::transaction(function () use (
            $users,
            $targets,
            $disbAgg,
            $adjRows,
            $period,
            $periodEnd,
            $rrCohortStart,
            $isCurrentMonth,
            $positionDate,
            $hasOsAdjCol,
            $hasOsRawCol,
            &$count
        ) {
            foreach ($users as $u) {
                $aoCode = str_pad(trim((string)($u->ao_code ?? '')), 6, '0', STR_PAD_LEFT);
                if ($aoCode === '' || $aoCode === '000000') {
                    continue;
                }

                // disbursement (bulan period) - RAW
                $d = DB::query()->fromSub($disbAgg, 'd')->where('d.ao_code', $aoCode)->first();
                $osRaw   = (int) ($d->os_disbursement ?? 0);
                $noaDisb = (int) ($d->noa_disbursement ?? 0);

                // ✅ ambil adjustment & handling manual (KBL)
                $adj = $adjRows->get($u->id);
                $osAdj = (int) ($adj->os_adjustment ?? 0);

                $handlingManual = $adj ? (int)($adj->handling_actual ?? 0) : null; // null jika tidak ada row

                // OS NETTO untuk scoring (tidak boleh negatif)
                $osNet = $osRaw - $osAdj;
                if ($osNet < 0) $osNet = 0;

                // ==========================================================
                // ✅ RR (COHORT): account yang disburse mulai 2026-01-01 s/d period KPI
                // ==========================================================
                $rrOsTotal   = 0;
                $rrOsCurrent = 0;
                $rrPct       = 0.0;

                // kalau period sebelum cohort start, RR = 0 (tidak ada cohort)
                if ($period >= $rrCohortStart) {
                    $accountNos = DB::table('loan_disbursements')
                        ->whereBetween('period', [$rrCohortStart, $period]) // ✅ kumulatif sejak Jan 2026
                        ->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [$aoCode])
                        ->pluck('account_no')
                        ->map(fn($v) => trim((string)$v))
                        ->filter(fn($v) => $v !== '')
                        ->unique()
                        ->values()
                        ->all();

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

                        // ✅ business rule: KPI Jan 2026 dipastikan RR = 100% (kalau ada OS cohort)
                        if ($period === $rrCohortStart) {
                            $rrPct = ($rrOsTotal > 0) ? 100.0 : 0.0;
                        }
                    }
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
                $targetHandling = (int) ($t->target_activity ?? 0);

                // ====== existing monthly row (fallback utk handling kalau belum ada input KBL) ======
                $existing = DB::table('kpi_so_monthlies')
                    ->where('period', $period)
                    ->where('user_id', $u->id)
                    ->first();

                $handlingActual = is_null($handlingManual)
                    ? (int)($existing->activity_actual ?? 0) // fallback data lama
                    : (int)$handlingManual;                  // ✅ dari input KBL

                // ====== pct & scores ======
                // ✅ OS achievement harus pakai NETTO
                $osAchPct       = KpiScoreHelper::safePct((float)$osNet, (float)$targetOs);
                $noaAchPct      = KpiScoreHelper::safePct((float)$noaDisb, (float)$targetNoa);
                $handlingPct    = KpiScoreHelper::safePct((float)$handlingActual, (float)$targetHandling);

                $scoreOs        = KpiScoreHelper::scoreFromAchievementPct($osAchPct);
                $scoreNoa       = KpiScoreHelper::scoreFromAchievementPct($noaAchPct);

                // RR scoring: pakai rrPct actual (0..100)
                $scoreRr        = KpiScoreHelper::scoreFromRepaymentRate($rrPct);
                $scoreHandling  = KpiScoreHelper::scoreFromAchievementPct($handlingPct);

                // total (SO): OS 40, NOA 30, RR 20, Handling 10
                $total = round(
                    $scoreOs * 0.40 +
                    $scoreNoa * 0.30 +
                    $scoreRr * 0.20 +
                    $scoreHandling * 0.10,
                    2
                );

                // key upsert
                $key = ['period' => $period, 'user_id' => $u->id];

                $payload = [
                    'ao_code'   => $aoCode,
                    'target_id' => $targetId,

                    // ✅ simpan NET untuk konsistensi dashboard/ranking
                    'os_disbursement'  => $osNet,
                    'noa_disbursement' => $noaDisb,

                    // legacy cols keep 0
                    'rr_due_count'         => $rrDue,
                    'rr_paid_ontime_count' => $rrOntime,

                    // ✅ RR actual (COHORT)
                    'rr_pct' => $rrPct,

                    // handling komunitas
                    'activity_target' => $targetHandling,
                    'activity_actual' => $handlingActual,
                    'activity_pct'    => $handlingPct,

                    'is_final'      => true,
                    'calculated_at' => now(),

                    // scores
                    'score_os'       => $scoreOs,
                    'score_noa'      => $scoreNoa,
                    'score_rr'       => $scoreRr,
                    'score_activity' => $scoreHandling,
                    'score_total'    => $total,

                    'updated_at' => now(),
                ];

                // ✅ optional columns kalau ada (biar bisa tampil Raw/Adj di UI)
                if ($hasOsAdjCol) $payload['os_adjustment'] = $osAdj;
                if ($hasOsRawCol) $payload['os_disbursement_raw'] = $osRaw;

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
            'period'        => $period,
            'position_date' => $positionDate,
            'rows'          => $count,
        ];
    }
}
