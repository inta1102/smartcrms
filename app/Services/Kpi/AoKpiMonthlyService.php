<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AoKpiMonthlyService
{
    /**
     * KPI AO UMKM (single mode):
     * - Pertumbuhan NOA (count disbursement month)           bobot 30%
     * - Realisasi Bulanan (OS disbursement month, % target)  bobot 20%
     * - Kualitas Kredit (RR)                                bobot 25%
     * - Grab to Community (AUTO dari community_handlings)   bobot 20%
     * - Daily Report (Kunjungan - input/manual)             bobot 5%
     *
     * NOTE:
     * - Actual Community sekarang dihitung AUTO dari tabel community_handlings (role=AO, aktif di periode).
     * - Tabel kpi_ao_activity_inputs.community_actual dipakai sebagai ADJUSTMENT (delta) saja.
     * - Daily report tetap dari kpi_ao_activity_inputs.daily_report_actual.
     */
    public function buildForPeriod(string $periodYmd, ?int $userId = null): array
    {
        $period     = Carbon::parse($periodYmd)->startOfMonth()->toDateString(); // Y-m-01
        $periodEnd  = Carbon::parse($period)->endOfMonth()->toDateString();
        $prevPeriod = Carbon::parse($period)->subMonth()->startOfMonth()->toDateString();

        // detect final: snapshot month period exists?
        $hasSnapshotPeriod = DB::table('loan_account_snapshots_monthly')
            ->where('snapshot_month', $period)
            ->limit(1)
            ->exists();

        $closingSource = $hasSnapshotPeriod ? 'snapshot' : 'live';

        // LIVE closing date (position_date terakhir dalam bulan tsb)
        $positionDate = null;
        if (!$hasSnapshotPeriod) {
            $positionDate = DB::table('loan_accounts')
                ->whereBetween('position_date', [$period, $periodEnd])
                ->max('position_date');

            if (!$positionDate) {
                $positionDate = DB::table('loan_accounts')
                    ->whereDate('position_date', '<=', $periodEnd)
                    ->max('position_date');
            }
        }

        // Users (AO only)
        $usersQ = DB::table('users')
            ->select(['id', 'name', 'ao_code', 'level'])
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '')
            ->whereIn('level', ['AO']);

        if (!is_null($userId)) $usersQ->where('id', $userId);
        $users = $usersQ->get();

        // ✅ AUTO Community actual dari community_handlings (role=AO)
        // Count DISTINCT community_id yang aktif di periode
        $communityAutoMap = [];
        $userIds = $users->pluck('id')->map(fn($v) => (int)$v)->all();
        if (!empty($userIds)) {
            $communityAutoMap = DB::table('community_handlings')
                ->selectRaw('user_id, COUNT(DISTINCT community_id) as cnt')
                ->where('role', 'AO')
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

        // Opening (prev snapshot) - tetap ada buat info portfolio
        $openingAgg = DB::table('loan_account_snapshots_monthly')
            ->selectRaw("LPAD(TRIM(ao_code),6,'0') as ao_code, ROUND(SUM(outstanding)) as os_opening, COUNT(*) as noa_opening")
            ->where('snapshot_month', $prevPeriod)
            ->groupBy('ao_code');

        // Closing (snapshot vs live)
        $closingAgg = $hasSnapshotPeriod
            ? DB::table('loan_account_snapshots_monthly')
                ->selectRaw("LPAD(TRIM(ao_code),6,'0') as ao_code, ROUND(SUM(outstanding)) as os_closing, COUNT(*) as noa_closing")
                ->where('snapshot_month', $period)
                ->groupBy('ao_code')
            : DB::table('loan_accounts')
                ->selectRaw("LPAD(TRIM(ao_code),6,'0') as ao_code, ROUND(SUM(outstanding)) as os_closing, COUNT(*) as noa_closing")
                ->whereDate('position_date', $positionDate)
                ->groupBy('ao_code');

        // ✅ Disbursement bulan period (OS + NOA disbursement)
        $disbAgg = DB::table('loan_disbursements')
            ->selectRaw("
                LPAD(TRIM(ao_code),6,'0') as ao_code,
                ROUND(SUM(amount)) as os_disbursement,
                COUNT(DISTINCT account_no) as noa_disbursement
            ")
            ->where(function($q) use ($period, $periodEnd) {
                $q->whereDate('period', $period)
                    ->orWhereBetween('disb_date', [$period, $periodEnd]);
            })
            ->groupBy('ao_code');

        // ✅ RR (OS lancar tanpa tunggakan / total OS) => simpan TOTAL & CURRENT untuk weighted TLUM
        $rrAgg = $hasSnapshotPeriod
            ? DB::table('loan_account_snapshots_monthly')
                ->selectRaw("
                    LPAD(TRIM(ao_code),6,'0') as ao_code,
                    ROUND(SUM(outstanding)) as rr_os_total,
                    ROUND(SUM(CASE WHEN COALESCE(ft_pokok,0)=0 AND COALESCE(ft_bunga,0)=0 THEN outstanding ELSE 0 END)) as rr_os_current
                ")
                ->where('snapshot_month', $period)
                ->groupBy('ao_code')
            : DB::table('loan_accounts')
                ->selectRaw("
                    LPAD(TRIM(ao_code),6,'0') as ao_code,
                    ROUND(SUM(outstanding)) as rr_os_total,
                    ROUND(SUM(CASE WHEN COALESCE(ft_pokok,0)=0 AND COALESCE(ft_bunga,0)=0 THEN outstanding ELSE 0 END)) as rr_os_current
                ")
                ->whereDate('position_date', $positionDate)
                ->groupBy('ao_code');

        // Targets
        $targets = DB::table('kpi_ao_targets')
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        // ✅ input manual AO: (sekarang) community_adjustment + daily report
        // - community_actual => diperlakukan sebagai ADJUSTMENT (delta) terhadap AUTO dari community_handlings
        $inputs = DB::table('kpi_ao_activity_inputs')
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        $count = 0;

        DB::transaction(function () use (
            $users,
            $communityAutoMap,
            $openingAgg,
            $closingAgg,
            $disbAgg,
            $rrAgg,
            $targets,
            $inputs,
            $period,
            $closingSource,
            $hasSnapshotPeriod,
            &$count
        ) {
            foreach ($users as $u) {
                $aoCode = str_pad(trim((string)($u->ao_code ?? '')), 6, '0', STR_PAD_LEFT);
                if ($aoCode === '' || $aoCode === '000000') continue;

                $open  = DB::query()->fromSub($openingAgg, 'o')->where('o.ao_code', $aoCode)->first();
                $close = DB::query()->fromSub($closingAgg, 'c')->where('c.ao_code', $aoCode)->first();
                $disb  = DB::query()->fromSub($disbAgg, 'd')->where('d.ao_code', $aoCode)->first();
                $rr    = DB::query()->fromSub($rrAgg, 'r')->where('r.ao_code', $aoCode)->first();

                // info portfolio
                $osOpening  = (int) ($open->os_opening ?? 0);
                $noaOpening = (int) ($open->noa_opening ?? 0);
                $osClosing  = (int) ($close->os_closing ?? 0);
                $noaClosing = (int) ($close->noa_closing ?? 0);

                // KPI utama UMKM
                $osDisb   = (int) ($disb->os_disbursement ?? 0);
                $noaDisb  = (int) ($disb->noa_disbursement ?? 0);

                // RR totals (penting untuk weighted TLUM)
                $rrOsTotal   = (int) ($rr->rr_os_total ?? 0);
                $rrOsCurrent = (int) ($rr->rr_os_current ?? 0);
                $rrPct       = $rrOsTotal > 0 ? round(100.0 * $rrOsCurrent / $rrOsTotal, 2) : 0.0;

                // targets
                $t = $targets->get($u->id);

                $targetId        = $t->id ?? null;
                $targetOsDisb    = (int) ($t->target_os_disbursement ?? 0);
                $targetNoaDisb   = (int) ($t->target_noa_disbursement ?? 0);
                $targetRr        = (float)($t->target_rr ?? 100);
                $targetCommunity = (int) ($t->target_community ?? 0);
                $targetDaily     = (int) ($t->target_daily_report ?? 0);

                // manual inputs (adjustment + daily)
                $inp = $inputs->get($u->id);

                // ✅ AUTO community count (distinct komunitas aktif di periode)
                $communityAuto = (int) ($communityAutoMap[$u->id] ?? 0);

                // ✅ community_actual dari inputs dipakai sebagai ADJUSTMENT (delta)
                $communityAdj  = (int) ($inp->community_actual ?? 0);

                // final actual (tidak boleh negatif)
                $communityActual = $communityAuto + $communityAdj;
                if ($communityActual < 0) $communityActual = 0;

                // daily report tetap manual
                $dailyActual = (int) ($inp->daily_report_actual ?? 0);

                // pct utk display
                $osPct  = KpiScoreHelper::safePct((float)$osDisb, (float)$targetOsDisb);
                $noaPct = KpiScoreHelper::safePct((float)$noaDisb, (float)$targetNoaDisb);
                $comPct = KpiScoreHelper::safePct((float)$communityActual, (float)$targetCommunity);
                $dayPct = KpiScoreHelper::safePct((float)$dailyActual, (float)$targetDaily);

                // scoring rubrik AO UMKM (1..6)
                $scoreNoa = KpiScoreHelper::scoreFromAoNoaGrowth6($noaDisb);
                $scoreOs  = KpiScoreHelper::scoreFromAoOsRealisasiPct6($osPct);
                $scoreRr  = KpiScoreHelper::scoreFromRepaymentRateAo6($rrPct);
                $scoreCom = KpiScoreHelper::scoreFromAoCommunity6($communityActual);
                $scoreDay = KpiScoreHelper::scoreFromAoDailyReport6($dailyActual);

                // bobot AO UMKM: NOA30, OS20, RR25, Community20, Daily5
                $total = round(
                    $scoreNoa * 0.30 +
                    $scoreOs  * 0.20 +
                    $scoreRr  * 0.25 +
                    $scoreCom * 0.20 +
                    $scoreDay * 0.05,
                    2
                );

                $key = ['period' => $period, 'user_id' => $u->id];

                $payload = [
                    'scheme'    => 'AO_UMKM',
                    'ao_code'   => $aoCode,
                    'target_id' => $targetId,

                    // info portfolio (biar legacy UI lain gak pecah)
                    'os_opening' => $osOpening,
                    'os_closing' => $osClosing,
                    'os_growth'  => ($osClosing - $osOpening),
                    'noa_opening'=> $noaOpening,
                    'noa_closing'=> $noaClosing,
                    'noa_growth' => ($noaClosing - $noaOpening),

                    // KPI UMKM
                    'os_disbursement'      => $osDisb,
                    'noa_disbursement'     => $noaDisb,
                    'os_disbursement_pct'  => round($osPct, 2),
                    'noa_disbursement_pct' => round($noaPct, 2),

                    // RR
                    'rr_os_total'          => $rrOsTotal,
                    'rr_os_current'        => $rrOsCurrent,
                    'rr_due_count'         => 0,
                    'rr_paid_ontime_count' => 0,
                    'rr_pct'               => $rrPct,

                    // community (AUTO + adjustment)
                    'community_target' => $targetCommunity,
                    'community_actual' => $communityActual,
                    'community_pct'    => round($comPct, 2),

                    // daily report
                    'daily_report_target' => $targetDaily,
                    'daily_report_actual' => $dailyActual,
                    'daily_report_pct'    => round($dayPct, 2),

                    // legacy activity_* diset 0
                    'activity_target' => 0,
                    'activity_actual' => 0,
                    'activity_pct'    => 0,

                    'is_final'      => $hasSnapshotPeriod,
                    'data_source'   => $closingSource,
                    'calculated_at' => now(),

                    // scores (UMKM)
                    'score_os'           => $scoreOs,
                    'score_noa'          => $scoreNoa,
                    'score_rr'           => $scoreRr,
                    'score_community'    => $scoreCom,
                    'score_daily_report' => $scoreDay,
                    'score_total'        => $total,

                    // legacy score yg gak dipakai
                    'score_kolek'    => 0,
                    'score_activity' => 0,

                    'updated_at' => now(),
                ];

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
            'position_date' => $positionDate,
            'rows'          => $count,
        ];
    }
}
