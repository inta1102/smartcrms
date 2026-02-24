<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TlroLeadershipBuilder
{
    public function build(int $tlroId, string $periodDate)
    {
        $period = Carbon::parse($periodDate)->startOfMonth();
        $isCurrentMonth = $period->equalTo(now()->startOfMonth());

        $calcMode = $isCurrentMonth ? 'realtime' : 'eom';

        // =====================================================
        // 1️⃣ Ambil RO scope dari org_assignments
        // =====================================================
        $roIds = DB::table('org_assignments')
            ->where('leader_id', $tlroId)
            ->pluck('user_id')
            ->toArray();

        if (empty($roIds)) {
            return $this->storeEmpty($tlroId, $period, $calcMode);
        }

        // =====================================================
        // 2️⃣ Ambil KPI RO monthly
        // =====================================================
        // 1) scope RO ids (dari org_assignments)
        $roIds = DB::table('org_assignments')
            ->where('leader_id', $tlroId)
            ->pluck('user_id')
            ->toArray();

        if (empty($roIds)) {
            return $this->storeEmpty($tlroId, $period, $calcMode);
        }

        // 2) ambil ao_code RO scope (users)
        $aoCodes = DB::table('users')
            ->whereIn('id', $roIds)
            ->whereNotNull('ao_code')
            ->pluck('ao_code')
            ->map(fn($v) => str_pad(trim((string)$v), 6, '0', STR_PAD_LEFT))
            ->filter(fn($v) => $v !== '' && $v !== '000000')
            ->unique()
            ->values()
            ->toArray();

        if (empty($aoCodes)) {
            return $this->storeEmpty($tlroId, $period, $calcMode);
        }

        // 3) query KPI RO monthly pakai ao_code
        $roRows = DB::table('kpi_ro_monthly')
            ->whereDate('period_month', $period->toDateString())
            ->where('calc_mode', $calcMode)
            ->whereIn('ao_code', $aoCodes)
            ->get();

        if ($roRows->isEmpty()) {
            return $this->storeEmpty($tlroId, $period, $calcMode);
        }

        $roCount = $roRows->count();

        // =====================================================
        // 3️⃣ PI_scope = avg total_score_weighted RO
        // =====================================================
        $piScope = round($roRows->avg('total_score_weighted'), 2);

        // =====================================================
        // 4️⃣ Stability (spread antar RO)
        // =====================================================
        $maxScore = $roRows->max('total_score_weighted');
        $minScore = $roRows->min('total_score_weighted');
        $spread   = $maxScore - $minScore;

        // konversi spread ke skor 1-6
        $stability = max(1, min(6, round(6 - $spread, 2)));

        // =====================================================
        // 5️⃣ Governance Risk (weighted by OS total)
        // =====================================================
        $totalOs = $roRows->sum('os_total');

        if ($totalOs > 0) {
            $riskWeighted = $roRows->sum(function ($r) {
                return $r->risk_index * $r->os_total;
            });

            $riskIndex = round($riskWeighted / $totalOs, 2);
        } else {
            $riskIndex = round($roRows->avg('risk_index'), 2);
        }

        // =====================================================
        // 6️⃣ Improvement (MoM PI_scope)
        // =====================================================
        $prevPeriod = $period->copy()->subMonth();

        $prevRow = DB::table('kpi_tlro_monthlies')
            ->where('tlro_id', $tlroId)
            ->whereDate('period', $prevPeriod->toDateString())
            ->where('calc_mode', 'eom')
            ->first();

        $improvementIndex = 3; // default neutral

        if ($prevRow) {
            $delta = $piScope - (float)$prevRow->pi_scope;

            if ($delta > 0.5)      $improvementIndex = 6;
            elseif ($delta > 0.2)  $improvementIndex = 5;
            elseif ($delta > 0)    $improvementIndex = 4;
            elseif ($delta == 0)   $improvementIndex = 3;
            elseif ($delta > -0.2) $improvementIndex = 2;
            else                   $improvementIndex = 1;
        }

        // =====================================================
        // 7️⃣ Leadership Index (weighted model)
        // =====================================================
        $leadershipIndex = round(
              (0.4 * $piScope)
            + (0.2 * $stability)
            + (0.2 * $riskIndex)
            + (0.2 * $improvementIndex),
            2
        );

        $status = $this->resolveStatus($leadershipIndex);

        // =====================================================
        // 8️⃣ Meta (AI engine data)
        // =====================================================
        $meta = [
            'max_ro' => $roRows->sortByDesc('total_score_weighted')->first()->ro_id ?? null,
            'min_ro' => $roRows->sortBy('total_score_weighted')->first()->ro_id ?? null,
            'spread' => round($spread,2),
            'weighted_risk' => true,
        ];

        // =====================================================
        // 9️⃣ Upsert
        // =====================================================
        DB::table('kpi_tlro_monthlies')->updateOrInsert(
            [
                'tlro_id'  => $tlroId,
                'period'   => $period->toDateString(),
                'calc_mode'=> $calcMode,
            ],
            [
                'ro_count'         => $roCount,
                'pi_scope'         => $piScope,
                'stability_index'  => $stability,
                'risk_index'       => $riskIndex,
                'improvement_index'=> $improvementIndex,
                'leadership_index' => $leadershipIndex,
                'status_label'     => $status,
                'meta'             => json_encode($meta),
                'updated_at'       => now(),
                'created_at'       => now(),
            ]
        );

        return DB::table('kpi_tlro_monthlies')
            ->where('tlro_id', $tlroId)
            ->whereDate('period', $period->toDateString())
            ->where('calc_mode', $calcMode)
            ->first();
    }

    private function resolveStatus(float $li): string
    {
        if ($li >= 4.5) return 'AMAN';
        if ($li >= 3.0) return 'WASPADA';
        return 'KRITIS';
    }

    private function storeEmpty($tlroId, $period, $mode)
    {
        DB::table('kpi_tlro_monthlies')->updateOrInsert(
            [
                'tlro_id' => $tlroId,
                'period'  => $period->toDateString(),
                'calc_mode' => $mode,
            ],
            [
                'ro_count' => 0,
                'pi_scope' => 0,
                'stability_index' => 0,
                'risk_index' => 0,
                'improvement_index' => 0,
                'leadership_index' => 0,
                'status_label' => 'NO DATA',
                'meta' => json_encode(['empty' => true]),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return DB::table('kpi_tlro_monthlies')
            ->where('tlro_id', $tlroId)
            ->whereDate('period', $period->toDateString())
            ->where('calc_mode', $mode)
            ->first();
    }
}