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
            $monthStart = $period->copy()->startOfMonth()->toDateString();
            $monthEnd   = $period->copy()->endOfMonth()->toDateString();

            /*
            |--------------------------------------------------------------------------
            | 1) Scope TLFE bawah KSFE (WITH effective range)
            |--------------------------------------------------------------------------
            */
            $tlfeIds = DB::table('org_assignments')
                ->where('leader_id', $ksfeId)
                ->where('is_active', 1)
                ->whereDate('effective_from', '<=', $monthEnd)
                ->where(function ($w) use ($monthStart) {
                    $w->whereNull('effective_to')
                      ->orWhereDate('effective_to', '>=', $monthStart);
                })
                ->pluck('user_id')
                ->map(fn ($x) => (int) $x)
                ->toArray();

            // kalau scope kosong, simpan empty (NO_DATA)
            if (empty($tlfeIds)) {
                $mode = $this->resolveMode($period, []);
                return $this->storeEmpty($ksfeId, $period, $mode, 0, [
                    'reason' => 'no_tlfe_scope',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 2) Resolve mode (berdasarkan ketersediaan KPI TLFE untuk scope ini)
            |--------------------------------------------------------------------------
            */
            $mode = $this->resolveMode($period, $tlfeIds);

            /*
            |--------------------------------------------------------------------------
            | 3) Ambil KPI TLFE monthly (sesuai mode)
            |    + fallback kalau mode pilihan tidak ada datanya
            |--------------------------------------------------------------------------
            */
            $tlRows = $this->fetchTlfeRows($period, $mode, $tlfeIds);

            if ($tlRows->isEmpty()) {
                $fallbackMode = $mode === 'eom' ? 'realtime' : 'eom';
                $fallbackRows = $this->fetchTlfeRows($period, $fallbackMode, $tlfeIds);

                if ($fallbackRows->isNotEmpty()) {
                    $mode   = $fallbackMode;
                    $tlRows = $fallbackRows;
                }
            }

            // kalau tetap kosong, jangan bikin tlfe_count = 0 (scope ada!)
            if ($tlRows->isEmpty()) {
                return $this->storeEmpty($ksfeId, $period, $mode, count($tlfeIds), [
                    'reason' => 'no_tlfe_kpi_rows',
                    'mode'   => $mode,
                ]);
            }

            $tlCount = $tlRows->count();

            /*
            |--------------------------------------------------------------------------
            | 4) PI_scope KSFE (avg LI TLFE)
            |--------------------------------------------------------------------------
            */
            $piScope = round((float) $tlRows->avg('leadership_index'), 2);

            /*
            |--------------------------------------------------------------------------
            | 5) Risk index (avg risk TLFE) - fallback ke piScope kalau null semua
            |--------------------------------------------------------------------------
            */
            $riskIndex = $tlRows->contains(fn ($r) => !is_null($r->risk_index))
                ? round((float) $tlRows->avg('risk_index'), 2)
                : round($piScope, 2);

            /*
            |--------------------------------------------------------------------------
            | 6) Stability antar TLFE
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
                $lis    = $tlRows->pluck('leadership_index')->map(fn($v) => (float)$v);
                $minLi  = (float) $lis->min();
                $maxLi  = (float) $lis->max();
                $spread = $maxLi - $minLi;
                $bottom = $minLi;

                // coverage TLFE "cukup": LI >= 3.5 (bisa kamu ubah)
                $coveragePct = $lis->filter(fn ($v) => (float)$v >= 3.5)->count() / $tlCount * 100;

                $spreadScore   = $this->scoreSpread((float)$spread);
                $bottomScore   = $this->scoreBottom((float)$bottom);
                $coverageScore = $this->scoreCoverage((float)$coveragePct);

                $stabilityIndex = round(
                    0.4 * $spreadScore +
                    0.3 * $bottomScore +
                    0.3 * $coverageScore,
                    2
                );

                $stabilityMeta = [
                    'spread'       => round((float)$spread, 4),
                    'bottom'       => round((float)$bottom, 4),
                    'coverage_pct' => round((float)$coveragePct, 2),
                    'spread_score' => $spreadScore,
                    'bottom_score' => $bottomScore,
                    'cov_score'    => $coverageScore,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | 7) Improvement (MoM delta PI_scope)
            |--------------------------------------------------------------------------
            */
            $prevPeriod = $period->copy()->subMonth()->startOfMonth();
            $prevMode   = $this->resolveMode($prevPeriod, $tlfeIds);

            // cari prev row: mode yang dipilih, kalau tidak ada coba mode lain
            $prev = DB::table('kpi_ksfe_monthlies')
                ->whereDate('period', $prevPeriod)
                ->where('ksfe_id', $ksfeId)
                ->where('calc_mode', $prevMode)
                ->first();

            if (!$prev) {
                $prevAltMode = $prevMode === 'eom' ? 'realtime' : 'eom';
                $prev = DB::table('kpi_ksfe_monthlies')
                    ->whereDate('period', $prevPeriod)
                    ->where('ksfe_id', $ksfeId)
                    ->where('calc_mode', $prevAltMode)
                    ->first();
                if ($prev) {
                    $prevMode = $prevAltMode;
                }
            }

            $improvementIndex = 3.00; // netral
            if ($prev) {
                $delta = $piScope - (float) ($prev->pi_scope ?? 0);
                $improvementIndex = $this->scoreImprovement((float)$delta);
            }

            /*
            |--------------------------------------------------------------------------
            | 8) Leadership Index KSFE (bobot bisa kamu adjust)
            |--------------------------------------------------------------------------
            */
            $leadershipIndex = round(
                0.35 * $piScope +
                0.25 * (float) $stabilityIndex +
                0.25 * (float) $riskIndex +
                0.15 * (float) $improvementIndex,
                2
            );

            $status = $this->resolveStatus((float)$leadershipIndex);

            /*
            |--------------------------------------------------------------------------
            | 9) Meta audit
            |--------------------------------------------------------------------------
            */
            $meta = array_merge([
                'pi_scope_basis'    => 'avg_tlfe_leadership_index',
                'tlfe_scope_count'  => count($tlfeIds),
                'tlfe_rows_count'   => $tlCount,
                'avg_tlfe_risk'     => $riskIndex,
                'improve_prev_mode' => $prevMode,
                'mode_used'         => $mode,
            ], $stabilityMeta);

            /*
            |--------------------------------------------------------------------------
            | 10) Upsert
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
    | Fetch TLFE rows helper
    |--------------------------------------------------------------------------
    */
    private function fetchTlfeRows(Carbon $period, string $mode, array $tlfeIds)
    {
        return DB::table('kpi_tlfe_monthlies')
            ->whereDate('period', $period)
            ->where('calc_mode', $mode)
            ->whereIn('tlfe_id', $tlfeIds)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Mode resolver (scope-aware)
    |--------------------------------------------------------------------------
    */
    private function resolveMode(Carbon $period, array $tlfeIds = []): string
    {
        $currentMonth = now()->startOfMonth();

        // bulan berjalan → realtime
        if ($period->equalTo($currentMonth)) {
            return 'realtime';
        }

        // prefer eom jika ada utk scope ini
        $hasEom = DB::table('kpi_tlfe_monthlies')
            ->whereDate('period', $period)
            ->when(!empty($tlfeIds), fn ($q) => $q->whereIn('tlfe_id', $tlfeIds))
            ->where('calc_mode', 'eom')
            ->exists();

        if ($hasEom) return 'eom';

        // fallback realtime jika ada utk scope ini
        $hasRealtime = DB::table('kpi_tlfe_monthlies')
            ->whereDate('period', $period)
            ->when(!empty($tlfeIds), fn ($q) => $q->whereIn('tlfe_id', $tlfeIds))
            ->where('calc_mode', 'realtime')
            ->exists();

        return $hasRealtime ? 'realtime' : 'eom';
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

    private function storeEmpty(
        int $ksfeId,
        Carbon $period,
        string $mode,
        int $tlfeScopeCount = 0,
        array $meta = []
    ): KpiKsfeMonthly {
        return KpiKsfeMonthly::updateOrCreate(
            [
                'period'    => $period,
                'ksfe_id'   => $ksfeId,
                'calc_mode' => $mode,
            ],
            [
                // ✅ penting: kalau scope ada tapi KPI TLFE belum kebentuk, jangan 0 biar UI tidak misleading
                'tlfe_count'        => (int) $tlfeScopeCount,
                'pi_scope'          => 0,
                'stability_index'   => null,
                'risk_index'        => null,
                'improvement_index' => null,
                'leadership_index'  => 0,
                'status_label'      => 'NO_DATA',
                'meta'              => !empty($meta) ? $meta : null,
            ]
        );
    }
}