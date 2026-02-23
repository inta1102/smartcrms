<?php

namespace App\Services\Kpi;

use App\Models\KpiTlfeMonthly;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TlfeLeadershipBuilder
{
    public function build(int $tlfeId, string $periodYmd): KpiTlfeMonthly
    {
        return DB::transaction(function () use ($tlfeId, $periodYmd) {

            $period = Carbon::parse($periodYmd)->startOfMonth();
            $mode   = $this->resolveMode($period);

            /*
            |--------------------------------------------------------------------------
            | 1. Ambil FE Scope (org_assignments)
            |--------------------------------------------------------------------------
            */
            $feIds = DB::table('org_assignments')
                ->where('leader_id', $tlfeId)
                ->pluck('user_id')
                ->toArray();

            if (empty($feIds)) {
                return $this->storeEmpty($tlfeId, $period, $mode);
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Ambil KPI FE
            |--------------------------------------------------------------------------
            */
            $feRows = DB::table('kpi_fe_monthlies')
                ->whereDate('period', $period)
                ->where('calc_mode', $mode)
                ->whereIn('fe_user_id', $feIds)   // ✅ FIX
                ->get();

            if ($feRows->isEmpty()) {
                return $this->storeEmpty($tlfeId, $period, $mode);
            }

            $feCount = $feRows->count();

            /*
            |--------------------------------------------------------------------------
            | 3. PI Scope
            |--------------------------------------------------------------------------
            */
            $piScope = round($feRows->avg('total_score_weighted'), 2);

            /*
            |--------------------------------------------------------------------------
            | 4. Stability
            |--------------------------------------------------------------------------
            */
            $pis      = $feRows->pluck('total_score_weighted');
            $minPi    = $pis->min();
            $maxPi    = $pis->max();
            $spread   = $maxPi - $minPi;
            $bottom   = $minPi;

            $coverage = $pis->filter(fn($v) => $v >= 3)->count() / $feCount * 100;

            $spreadScore   = $this->scoreSpread($spread);
            $bottomScore   = $this->scoreBottom($bottom);
            $coverageScore = $this->scoreCoverage($coverage);

            $stabilityIndex = round(
                0.4 * $spreadScore +
                0.3 * $bottomScore +
                0.3 * $coverageScore,
                2
            );

            /*
            |--------------------------------------------------------------------------
            | 5. Risk Index (pakai actual migrasi jika ada)
            |--------------------------------------------------------------------------
            */
            $riskIndex = null;

            if ($feRows->first()->migrasi_npl_actual_pct ?? false) {
                $avgMigrasi = $feRows->avg('migrasi_npl_actual_pct');
                $riskIndex  = $this->scoreRisk($avgMigrasi);
            } else {
                // fallback proxy pakai PI scope
                $riskIndex = round($piScope, 2);
                $avgMigrasi = null;
            }

            /*
            |--------------------------------------------------------------------------
            | 6. Improvement (MoM PI_scope)
            |--------------------------------------------------------------------------
            */
            $prevPeriod = $period->copy()->subMonth();
            $prevMode   = $this->resolveMode($prevPeriod);

            $prev = DB::table('kpi_tlfe_monthlies')
                ->whereDate('period', $prevPeriod)
                ->where('tlfe_id', $tlfeId)
                ->where('calc_mode', $prevMode)
                ->first();

            $improvementIndex = 3.00; // netral

            if ($prev) {
                $delta = $piScope - $prev->pi_scope;
                $improvementIndex = $this->scoreImprovement($delta);
            }

            /*
            |--------------------------------------------------------------------------
            | 7. Leadership Index
            |--------------------------------------------------------------------------
            */
            $leadershipIndex = round(
                0.40 * $piScope +
                0.25 * $stabilityIndex +
                0.20 * $riskIndex +
                0.15 * $improvementIndex,
                2
            );

            $status = $this->resolveStatus($leadershipIndex);

            /*
            |--------------------------------------------------------------------------
            | 8. Upsert
            |--------------------------------------------------------------------------
            */
            return KpiTlfeMonthly::updateOrCreate(
                [
                    'period'    => $period,
                    'tlfe_id'   => $tlfeId,
                    'calc_mode' => $mode,
                ],
                [
                    'fe_count'          => $feCount,
                    'pi_scope'          => $piScope,
                    'stability_index'   => $stabilityIndex,
                    'risk_index'        => $riskIndex,
                    'improvement_index' => $improvementIndex,
                    'leadership_index'  => $leadershipIndex,
                    'status_label'      => $status,
                    'meta' => [
                        'spread'        => $spread,
                        'bottom'        => $bottom,
                        'coverage_pct'  => round($coverage,2),
                        'avg_migrasi'   => $avgMigrasi ?? null,
                    ],
                ]
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function resolveMode(Carbon $period): string
    {
        $now = now()->startOfMonth();
        return $period->equalTo($now) ? 'realtime' : 'eom';
    }

    private function scoreSpread(float $spread): int
    {
        return match(true) {
            $spread <= 0.75 => 6,
            $spread <= 1.25 => 5,
            $spread <= 2.00 => 4,
            $spread <= 2.75 => 3,
            $spread <= 3.50 => 2,
            default => 1,
        };
    }

    private function scoreBottom(float $bottom): int
    {
        return match(true) {
            $bottom >= 4.5 => 6,
            $bottom >= 4.0 => 5,
            $bottom >= 3.5 => 4,
            $bottom >= 3.0 => 3,
            $bottom >= 2.0 => 2,
            default => 1,
        };
    }

    private function scoreCoverage(float $coverage): int
    {
        return match(true) {
            $coverage >= 90 => 6,
            $coverage >= 75 => 5,
            $coverage >= 60 => 4,
            $coverage >= 45 => 3,
            $coverage >= 30 => 2,
            default => 1,
        };
    }

    private function scoreRisk(float $avgMigrasi): int
    {
        return match(true) {
            $avgMigrasi <= 0.30 => 6,
            $avgMigrasi <= 0.60 => 5,
            $avgMigrasi <= 1.00 => 4,
            $avgMigrasi <= 2.00 => 3,
            $avgMigrasi <= 3.00 => 2,
            default => 1,
        };
    }

    private function scoreImprovement(float $delta): int
    {
        return match(true) {
            $delta >= 0.50 => 6,
            $delta >= 0.25 => 5,
            $delta >= 0.10 => 4,
            $delta >= 0.00 => 3,
            $delta >= -0.20 => 2,
            default => 1,
        };
    }

    private function resolveStatus(float $li): string
    {
        return match(true) {
            $li >= 4.5 => 'AMAN',
            $li >= 3.5 => 'CUKUP',
            $li >= 2.5 => 'WASPADA',
            default => 'KRITIS',
        };
    }

    private function storeEmpty(int $tlfeId, Carbon $period, string $mode)
    {
        return KpiTlfeMonthly::updateOrCreate(
            [
                'period'    => $period,
                'tlfe_id'   => $tlfeId,
                'calc_mode' => $mode,
            ],
            [
                'fe_count'          => 0,
                'pi_scope'          => 0,
                'stability_index'   => 0,
                'risk_index'        => 0,
                'improvement_index' => 0,
                'leadership_index'  => 0,
                'status_label'      => 'NO_DATA',
                'meta'              => null,
            ]
        );
    }
}