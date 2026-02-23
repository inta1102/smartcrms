<?php

namespace App\Services\Kpi;

use App\Models\KpiKsfeMonthly;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class KsfeLeadershipBuilder
{
    public function build(int $ksfeId, string $periodYmd): KpiKsfeMonthly
    {
        return DB::transaction(function () use ($ksfeId, $periodYmd) {

            $period = Carbon::parse($periodYmd)->startOfMonth();
            $mode   = $this->resolveMode($period);

            /*
            |--------------------------------------------------------------------------
            | 1) Scope TLFE bawah KSFE
            |--------------------------------------------------------------------------
            */
            $tlfeIds = DB::table('org_assignments')
                ->where('leader_id', $ksfeId)
                ->where('active', 1)
                ->pluck('user_id')
                ->toArray();

            if (empty($tlfeIds)) {
                return $this->storeEmpty($ksfeId, $period, $mode);
            }

            /*
            |--------------------------------------------------------------------------
            | 2) Ambil KPI TLFE monthly (sesuai mode)
            |--------------------------------------------------------------------------
            */
            $tlRows = DB::table('kpi_tlfe_monthlies')
                ->whereDate('period', $period)
                ->where('calc_mode', $mode)
                ->whereIn('tlfe_id', $tlfeIds)
                ->get();

            if ($tlRows->isEmpty()) {
                return $this->storeEmpty($ksfeId, $period, $mode);
            }

            $tlCount = $tlRows->count();

            /*
            |--------------------------------------------------------------------------
            | 3) PI_scope KSFE (avg LI TLFE)
            |--------------------------------------------------------------------------
            */
            $piScope = round($tlRows->avg('leadership_index'), 2);

            /*
            |--------------------------------------------------------------------------
            | 4) Risk index (avg risk TLFE)
            |--------------------------------------------------------------------------
            */
            $riskIndex = $tlRows->contains(fn($r) => !is_null($r->risk_index))
                ? round($tlRows->avg('risk_index'), 2)
                : round($piScope, 2);

            /*
            |--------------------------------------------------------------------------
            | 5) Stability antar TLFE
            |    - jika < 2 TLFE: NETRAL agar tidak bias "sempurna"
            |--------------------------------------------------------------------------
            */
            $stabilityIndex = null;
            $stabilityMeta  = [];

            if ($tlCount < 2) {
                $stabilityIndex = 3.50; // netral
                $stabilityMeta  = [
                    'stability_note' => 'insufficient_sample',
                    'reason' => 'TLFE count < 2, stability dibuat netral agar tidak bias',
                ];
            } else {
                $lis    = $tlRows->pluck('leadership_index');
                $minLi  = (float) $lis->min();
                $maxLi  = (float) $lis->max();
                $spread = $maxLi - $minLi;
                $bottom = $minLi;

                // coverage TLFE "cukup": LI >= 3.5 (bisa kamu ubah)
                $coveragePct = $lis->filter(fn($v) => (float)$v >= 3.5)->count() / $tlCount * 100;

                $spreadScore   = $this->scoreSpread($spread);
                $bottomScore   = $this->scoreBottom($bottom);
                $coverageScore = $this->scoreCoverage($coveragePct);

                $stabilityIndex = round(
                    0.4 * $spreadScore +
                    0.3 * $bottomScore +
                    0.3 * $coverageScore,
                    2
                );

                $stabilityMeta = [
                    'spread'       => round($spread, 4),
                    'bottom'       => round($bottom, 4),
                    'coverage_pct' => round($coveragePct, 2),
                    'spread_score' => $spreadScore,
                    'bottom_score' => $bottomScore,
                    'cov_score'    => $coverageScore,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | 6) Improvement (MoM delta PI_scope)
            |--------------------------------------------------------------------------
            */
            $prevPeriod = $period->copy()->subMonth();
            $prevMode   = $this->resolveMode($prevPeriod);

            $prev = DB::table('kpi_ksfe_monthlies')
                ->whereDate('period', $prevPeriod)
                ->where('ksfe_id', $ksfeId)
                ->where('calc_mode', $prevMode)
                ->first();

            $improvementIndex = 3.00; // netral

            if ($prev) {
                $delta = $piScope - (float)$prev->pi_scope;
                $improvementIndex = $this->scoreImprovement($delta);
            }

            /*
            |--------------------------------------------------------------------------
            | 7) Leadership Index KSFE (bobot bisa kamu adjust)
            |--------------------------------------------------------------------------
            | Default saya:
            | - PI_scope: 0.35
            | - Stability: 0.25
            | - Risk: 0.25
            | - Improvement: 0.15
            |--------------------------------------------------------------------------
            */
            $leadershipIndex = round(
                0.35 * $piScope +
                0.25 * (float)$stabilityIndex +
                0.25 * (float)$riskIndex +
                0.15 * (float)$improvementIndex,
                2
            );

            $status = $this->resolveStatus($leadershipIndex);

            /*
            |--------------------------------------------------------------------------
            | 8) Meta audit (penting utk AI engine)
            |--------------------------------------------------------------------------
            */
            $meta = array_merge([
                'pi_scope_basis'    => 'avg_tlfe_leadership_index',
                'tlfe_count'        => $tlCount,
                'avg_tlfe_risk'     => $riskIndex,
                'improve_prev_mode' => $prevMode,
            ], $stabilityMeta);

            /*
            |--------------------------------------------------------------------------
            | 9) Upsert
            |--------------------------------------------------------------------------
            */
            return KpiKsfeMonthly::updateOrCreate(
                [
                    'period'    => $period,
                    'ksfe_id'   => $ksfeId,
                    'calc_mode' => $mode,
                ],
                [
                    'tlfe_count'        => $tlCount,
                    'pi_scope'          => $piScope,
                    'stability_index'   => $stabilityIndex,
                    'risk_index'        => $riskIndex,
                    'improvement_index' => $improvementIndex,
                    'leadership_index'  => $leadershipIndex,
                    'status_label'      => $status,
                    'meta'              => $meta,
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

    // Spread scoring (pakai gap antar TLFE LI)
    private function scoreSpread(float $spread): int
    {
        return match (true) {
            $spread <= 0.50 => 6,
            $spread <= 1.00 => 5,
            $spread <= 1.75 => 4,
            $spread <= 2.50 => 3,
            $spread <= 3.25 => 2,
            default => 1,
        };
    }

    // Bottom TLFE LI scoring
    private function scoreBottom(float $bottom): int
    {
        return match (true) {
            $bottom >= 4.5 => 6,
            $bottom >= 4.0 => 5,
            $bottom >= 3.5 => 4,
            $bottom >= 3.0 => 3,
            $bottom >= 2.0 => 2,
            default => 1,
        };
    }

    // Coverage TLFE "cukup" (LI >= 3.5)
    private function scoreCoverage(float $coverage): int
    {
        return match (true) {
            $coverage >= 90 => 6,
            $coverage >= 75 => 5,
            $coverage >= 60 => 4,
            $coverage >= 45 => 3,
            $coverage >= 30 => 2,
            default => 1,
        };
    }

    private function scoreImprovement(float $delta): int
    {
        return match (true) {
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
        return match (true) {
            $li >= 4.5 => 'AMAN',
            $li >= 3.5 => 'CUKUP',
            $li >= 2.5 => 'WASPADA',
            default => 'KRITIS',
        };
    }

    private function storeEmpty(int $ksfeId, Carbon $period, string $mode): KpiKsfeMonthly
    {
        return KpiKsfeMonthly::updateOrCreate(
            [
                'period'    => $period,
                'ksfe_id'   => $ksfeId,
                'calc_mode' => $mode,
            ],
            [
                'tlfe_count'        => 0,
                'pi_scope'          => 0,
                'stability_index'   => null,
                'risk_index'        => null,
                'improvement_index' => null,
                'leadership_index'  => 0,
                'status_label'      => 'NO_DATA',
                'meta'              => null,
            ]
        );
    }
}