<?php

namespace App\Services\Kpi;

use App\Models\User;
use App\Models\Kpi\KpiKslrMonthly;
use App\Services\Org\OrgScopeService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class KslrMonthlyBuilder
{
    public function build(
        int $kslrId,
        string $periodYmd,
        string $calcMode,
        OrgScopeService $scope
    ): KpiKslrMonthly {

        $periodDate = Carbon::parse($periodYmd)->startOfMonth()->toDateString();

        // ==========================================================
        // 1) SCOPE
        // ==========================================================
        $descIds = $scope->descendantUserIds($kslrId, $periodDate, 'lending', 3);

        $users = User::whereIn('id', $descIds)
            ->get(['id','ao_code','level']);

        $soIds = [];
        $roAoCodes = [];

        foreach ($users as $u) {
            $role = $this->resolveRole($u);

            if ($role === 'SO') {
                $soIds[] = $u->id;
            }

            if ($role === 'RO' && !empty($u->ao_code)) {
                $roAoCodes[] = str_pad(trim((string)$u->ao_code), 6, '0', STR_PAD_LEFT);
            }
        }

        $roAoCodes = array_unique($roAoCodes);

        // ==========================================================
        // 2) KPI RO
        // ==========================================================
        $roRows = collect();

        if (!empty($roAoCodes)) {
            $roRows = DB::table('kpi_ro_monthly')
                ->whereDate('period_month', $periodDate)
                ->whereIn('ao_code', $roAoCodes)
                ->get();
        }

        // ==========================================================
        // 3) KPI SO
        // ==========================================================
        $soRows = collect();
        $soTargets = collect();

        if (!empty($soIds)) {

            $soRows = DB::table('kpi_so_monthlies')
                ->whereDate('period', $periodDate)
                ->whereIn('user_id', $soIds)
                ->get();

            $soTargets = DB::table('kpi_so_targets')
                ->whereDate('period', $periodDate)
                ->whereIn('user_id', $soIds)
                ->get();
        }

        // ==========================================================
        // 4) AGREGASI
        // ==========================================================

        // KYD
        $sumOsAct  = (float) $soRows->sum('os_disbursement');
        $sumOsTgt  = (float) $soTargets->sum('target_os_disbursement');
        $kydAchPct = $sumOsTgt > 0 ? ($sumOsAct / $sumOsTgt) * 100 : 0;

        // DPK
        $dpkMigPct = $roRows->count()
            ? (float) $roRows->avg('dpk_pct')
            : 0;

        // RR
        $rrPct = $roRows->count()
            ? (float) $roRows->avg('repayment_pct')
            : 0;

        // Community
        $sumActAct = (float) $soRows->sum('activity_actual');
        $sumActTgt = (float) $soTargets->sum('target_activity');
        $communityPct = $sumActTgt > 0
            ? ($sumActAct / $sumActTgt) * 100
            : 0;

        // ==========================================================
        // 5) SCORING
        // ==========================================================

        $scoreKyd = $this->scoreKyd($kydAchPct);
        $scoreDpk = $this->scoreMigrasiDpk($dpkMigPct);
        $scoreRr  = $this->scoreRr($rrPct);
        $scoreCom = $this->scoreCommunity($sumActAct);

        $total = ($scoreKyd * 0.50)
               + ($scoreDpk * 0.15)
               + ($scoreRr  * 0.25)
               + ($scoreCom * 0.10);

        // ==========================================================
        // 6) UPSERT SNAPSHOT
        // ==========================================================

        return KpiKslrMonthly::updateOrCreate(
            [
                'period'    => $periodDate,
                'kslr_id'   => $kslrId,
                'calc_mode' => $calcMode,
            ],
            [
                'kyd_ach_pct' => $kydAchPct,
                'dpk_mig_pct' => $dpkMigPct,
                'rr_pct'      => $rrPct,
                'community_pct' => $communityPct,

                'score_kyd' => $scoreKyd,
                'score_dpk' => $scoreDpk,
                'score_rr'  => $scoreRr,
                'score_com' => $scoreCom,

                'total_score_weighted' => $total,

                'meta' => [
                    'so_count' => count($soIds),
                    'ro_count' => count($roAoCodes),
                    'desc_count' => count($descIds),
                ],
            ]
        );
    }

    // ==========================================================
    // SCORING RULES
    // ==========================================================

    private function scoreKyd(float $pct): int
    {
        if ($pct < 85) return 1;
        if ($pct < 90) return 2;
        if ($pct < 95) return 3;
        if ($pct < 100) return 4;
        if ($pct <= 100) return 5;
        return 6;
    }

    private function scoreMigrasiDpk(float $pct): int
    {
        if ($pct > 4) return 1;
        if ($pct >= 3) return 2;
        if ($pct >= 2) return 3;
        if ($pct >= 1) return 4;
        if ($pct > 0) return 5;
        return 6;
    }

    private function scoreRr(float $pct): int
    {
        if ($pct < 70) return 1;
        if ($pct < 80) return 2;
        if ($pct < 90) return 3;
        if ($pct < 100) return 4;
        return 5;
    }

    private function scoreCommunity(float $count): int
    {
        if ($count <= 1) return 1;
        if ($count == 2) return 2;
        if ($count == 3) return 3;
        if ($count == 4) return 4;
        if ($count == 5) return 5;
        return 6;
    }

    private function resolveRole($u): string
    {
        $raw = $u->level ?? null;

        if ($raw instanceof \BackedEnum) {
            $raw = $raw->value;
        }

        return strtoupper(trim((string)($raw ?? '')));
    }
}