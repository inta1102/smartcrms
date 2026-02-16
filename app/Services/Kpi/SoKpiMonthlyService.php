<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Kpi\KpiSoCommunityInput;

class SoKpiMonthlyService
{
    /**
     * SO KPI (NEW - 1..6):
     * - OS Realisasi (55%)  : score by achievement % (0-24=1, 25-49=2, 50-74=3, 75-99=4, 100=5, >100=6)
     * - NOA Realisasi (15%) : score by count (1..5, >5=6)
     * - Repayment Rate (20%): score by RR% (<70=1, 70-79.9=2, 80-89.9=3, 90-94.9=4, 95-99.9=5, 100=6)
     * - Handling Komunitas (10%): score by count (0=1, 1=4, 2=5, >2=6)
     *
     * Data:
     * - OS/NOA: loan_disbursements (period = Y-m-01)
     * - RR (COHORT): OS current (ft_pokok=0 & ft_bunga=0) / total OS
     *   ✅ hanya untuk rekening yang DISBURSE mulai 1 Jan 2026 s/d period KPI (kumulatif)
     *   ✅ khusus KPI Jan 2026: RR dipastikan 100% (business rule)
     *
     * Input manual (KBL) - sekarang fungsi ADJUSTMENT:
     * - Handling Komunitas (handling_actual) -> dianggap "adjustment" (+/-) terhadap hasil AUTO dari community_handlings
     * - OS Adjustment (os_adjustment) -> mengurangi OS disbursement (raw) sebelum scoring
     *
     * Sumber data Handling Komunitas (AUTO):
     * - community_handlings: count DISTINCT community_id yang aktif pada periode untuk role SO + user_id
     *   aktif = period_from <= period AND (period_to is null OR period_to >= period)
     *
     * Rule sumber data RR:
     * - Kalau period = bulan berjalan => ambil loan_accounts pada position_date terakhir
     * - Kalau period < bulan berjalan => ambil loan_account_snapshots_monthly pada snapshot_month = period
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

        // ✅ Input manual KBL (ADJUSTMENT handling + OS adjustment)
        // NOTE: handling_actual disini dianggap adjustment (+/-) terhadap AUTO dari community_handlings
        $adjRows = KpiSoCommunityInput::query()
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        // ✅ AUTO actual komunitas dari community_handlings (role=SO)
        // Count DISTINCT community_id yang aktif pada periode
        $userIds = $users->pluck('id')->map(fn($v) => (int)$v)->all();

        $handlingAutoMap = [];
        if (!empty($userIds)) {
            $handlingAutoMap = DB::table('community_handlings')
                ->selectRaw('user_id, COUNT(DISTINCT community_id) as cnt')
                ->where('role', 'SO')
                ->whereIn('user_id', $userIds)
                ->whereDate('period_from', '<=', $period)
                ->where(function ($q) use ($period) {
                    $q->whereNull('period_to')
                      ->orWhereDate('period_to', '>=', $period);
                })
                ->groupBy('user_id')
                ->pluck('cnt', 'user_id')
                ->map(fn($v) => (int)$v)
                ->all();
        }

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
            $handlingAutoMap,
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

                // ✅ ambil adjustment KBL
                $adj = $adjRows->get($u->id);
                $osAdj = (int) ($adj->os_adjustment ?? 0);

                // Handling:
                // - AUTO dari community_handlings
                // - + adjustment KBL (handling_actual) (bisa kamu jadikan delta)
                $handlingAuto = (int) ($handlingAutoMap[$u->id] ?? 0);
                $handlingAdj  = (int) ($adj->handling_actual ?? 0); // dianggap adjustment
                $handlingActual = $handlingAuto + $handlingAdj;
                if ($handlingActual < 0) $handlingActual = 0;

                // OS NETTO untuk scoring (tidak boleh negatif)
                $osNet = $osRaw - $osAdj;
                if ($osNet < 0) $osNet = 0;

                // ==========================================================
                // ✅ RR (COHORT): account yang disburse mulai 2026-01-01 s/d period KPI
                // ==========================================================
                $rrOsTotal   = 0;
                $rrOsCurrent = 0;
                $rrPct       = 0.0;

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
                $targetRr       = (float)($t->target_rr ?? 100); // hanya display/cek
                $targetHandling = (int) ($t->target_activity ?? 0);

                // =========================
                // Helpers (local, no impact to other KPI)
                // =========================
                $safePct = function (float $actual, float $target): float {
                    if ($target <= 0) return 0.0;
                    return round(100.0 * $actual / $target, 2);
                };

                $scoreByAchPct = function (?float $achPct): int {
                    if ($achPct === null) return 1;
                    if ($achPct < 25) return 1;
                    if ($achPct < 50) return 2;
                    if ($achPct < 75) return 3;
                    if ($achPct < 100) return 4;
                    if ($achPct <= 100.0000001) return 5;
                    return 6;
                };

                $scoreByNoa = function (int $n): int {
                    if ($n <= 1) return 1;
                    if ($n === 2) return 2;
                    if ($n === 3) return 3;
                    if ($n === 4) return 4;
                    if ($n === 5) return 5;
                    return 6;
                };

                // RR untuk SO: <70, 70-79.9, 80-89.9, 90-94.9, 95-99.9, 100
                $scoreByRrSo = function (?float $rr): int {
                    if ($rr === null) return 1;
                    if ($rr < 70) return 1;
                    if ($rr < 80) return 2;
                    if ($rr < 90) return 3;
                    if ($rr < 95) return 4;
                    if ($rr < 100) return 5;
                    return 6; // 100
                };

                // Handling komunitas: 0=1, 1=4, 2=5, >2=6
                $scoreByHandling = function (int $n): int {
                    if ($n <= 0) return 1;
                    if ($n === 1) return 4;
                    if ($n === 2) return 5;
                    return 6; // >2
                };

                // ====== pct (untuk display) ======
                $osAchPct       = $safePct((float)$osNet, (float)$targetOs);
                $noaAchPct      = $safePct((float)$noaDisb, (float)$targetNoa);
                $handlingPct    = $safePct((float)$handlingActual, (float)$targetHandling);

                // ====== scores (NEW 1..6) ======
                $scoreOs        = $scoreByAchPct($osAchPct);

                // ✅ sesuai tabel SO: NOA score berdasar jumlah NOA realisasi (bukan %)
                $scoreNoa       = $scoreByNoa($noaDisb);

                // ✅ RR score berdasar rrPct
                $scoreRr        = $scoreByRrSo($rrPct);

                // ✅ handling score berdasar jumlah handling (AUTO + adjustment)
                $scoreHandling  = $scoreByHandling($handlingActual);

                // ✅ total (SO NEW): OS 55, NOA 15, RR 20, Handling 10
                $total = round(
                    $scoreOs * 0.55 +
                    $scoreNoa * 0.15 +
                    $scoreRr * 0.20 +
                    $scoreHandling * 0.10,
                    2
                );

                // key upsert
                $key = ['period' => $period, 'user_id' => $u->id];

                $payload = [
                    'ao_code'   => $aoCode,
                    'target_id' => $targetId,

                    // ✅ simpan NET untuk scoring
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

                    // scores (NEW 1..6)
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
