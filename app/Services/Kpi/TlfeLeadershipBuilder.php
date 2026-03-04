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

            /*
            |--------------------------------------------------------------------------
            | 1. Ambil FE Scope (org_assignments) - pakai overlap effective date
            |--------------------------------------------------------------------------
            */
            $periodStart = $period->copy()->startOfMonth()->toDateString();
            $periodEnd   = $period->copy()->endOfMonth()->toDateString();

            $feIds = DB::table('org_assignments as oa')
                ->join('users as u', 'u.id', '=', 'oa.user_id')
                ->where('oa.leader_id', $tlfeId)
                ->whereRaw("UPPER(TRIM(u.level)) = 'FE'")
                // overlap rule: assignment berlaku pada bulan tsb
                ->whereDate('oa.effective_from', '<=', $periodEnd)
                ->where(function ($q) use ($periodStart) {
                    $q->whereNull('oa.effective_to')
                      ->orWhereDate('oa.effective_to', '>=', $periodStart);
                })
                ->pluck('oa.user_id')
                ->map(fn($x) => (int)$x)
                ->unique()
                ->values()
                ->toArray();

            // resolve mode harus setelah punya scope (biar bisa cek data per FE)
            $mode = $this->resolveMode($period, $feIds);

            if (empty($feIds)) {
                return $this->storeEmpty($tlfeId, $period, $mode);
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Ambil KPI FE (sesuai mode) + fallback mode kalau kosong
            |--------------------------------------------------------------------------
            */
            $feRows = DB::table('kpi_fe_monthlies')
                ->whereDate('period', $period->toDateString())
                ->where('calc_mode', $mode)
                ->whereIn('fe_user_id', $feIds) // ✅ kolom kamu: fe_user_id
                ->get();

            if ($feRows->isEmpty()) {
                $fallbackMode = $mode === 'eom' ? 'realtime' : 'eom';

                $feRows2 = DB::table('kpi_fe_monthlies')
                    ->whereDate('period', $period->toDateString())
                    ->where('calc_mode', $fallbackMode)
                    ->whereIn('fe_user_id', $feIds)
                    ->get();

                if ($feRows2->isNotEmpty()) {
                    $mode = $fallbackMode;
                    $feRows = $feRows2;
                }
            }

            if ($feRows->isEmpty()) {
                return $this->storeEmpty($tlfeId, $period, $mode);
            }

            $feCount = $feRows->count();

            /*
            |--------------------------------------------------------------------------
            | 3. PI Scope
            |--------------------------------------------------------------------------
            */
            $piScope = round((float)$feRows->avg('total_score_weighted'), 2);

            /*
            |--------------------------------------------------------------------------
            | 4. Stability
            |--------------------------------------------------------------------------
            */
            $pis      = $feRows->pluck('total_score_weighted')->map(fn($v) => (float)$v);
            $minPi    = (float)$pis->min();
            $maxPi    = (float)$pis->max();
            $spread   = $maxPi - $minPi;
            $bottom   = $minPi;

            $coverage = $feCount > 0
                ? ($pis->filter(fn($v) => (float)$v >= 3)->count() / $feCount * 100)
                : 0;

            $spreadScore   = $this->scoreSpread((float)$spread);
            $bottomScore   = $this->scoreBottom((float)$bottom);
            $coverageScore = $this->scoreCoverage((float)$coverage);

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
            $avgMigrasi = null;
            $riskIndex  = null;

            // pakai avg migrasi kalau field ada dan minimal ada 1 value non-null
            $hasMigrasi = $feRows->contains(function ($r) {
                return isset($r->migrasi_npl_actual_pct) && $r->migrasi_npl_actual_pct !== null;
            });

            if ($hasMigrasi) {
                $avgMigrasi = (float)$feRows
                    ->filter(fn($r) => isset($r->migrasi_npl_actual_pct) && $r->migrasi_npl_actual_pct !== null)
                    ->avg('migrasi_npl_actual_pct');

                $riskIndex = $this->scoreRisk((float)$avgMigrasi);
            } else {
                // fallback proxy pakai PI scope
                $riskIndex = round((float)$piScope, 2);
            }

            /*
            |--------------------------------------------------------------------------
            | 6. Improvement (MoM PI_scope)
            |--------------------------------------------------------------------------
            */
            $prevPeriod = $period->copy()->subMonth();

            // prevMode: pakai smart resolve berbasis scope juga (biar konsisten)
            $prevMode = $this->resolveMode($prevPeriod, $feIds);

            $prev = DB::table('kpi_tlfe_monthlies')
                ->whereDate('period', $prevPeriod->toDateString())
                ->where('tlfe_id', $tlfeId)
                ->where('calc_mode', $prevMode)
                ->first();

            $improvementIndex = 3.00; // netral
            if ($prev) {
                $delta = (float)$piScope - (float)($prev->pi_scope ?? 0);
                $improvementIndex = $this->scoreImprovement((float)$delta);
            }

            /*
            |--------------------------------------------------------------------------
            | 7. Leadership Index
            |--------------------------------------------------------------------------
            */
            $leadershipIndex = round(
                0.40 * (float)$piScope +
                0.25 * (float)$stabilityIndex +
                0.20 * (float)$riskIndex +
                0.15 * (float)$improvementIndex,
                2
            );

            $status = $this->resolveStatus((float)$leadershipIndex);

            /*
            |--------------------------------------------------------------------------
            | 8. Upsert
            |--------------------------------------------------------------------------
            */
            return KpiTlfeMonthly::updateOrCreate(
                [
                    'period'    => $period->toDateString(),
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
                        'spread'        => round((float)$spread, 4),
                        'bottom'        => round((float)$bottom, 4),
                        'coverage_pct'  => round((float)$coverage, 2),
                        'avg_migrasi'   => $avgMigrasi,
                        'scope_fe_ids'  => $feIds, // opsional: bantu audit
                        'period_start'  => $periodStart,
                        'period_end'    => $periodEnd,
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

    private function resolveMode(Carbon $period, array $feIds = []): string
    {
        $currentMonth = now()->startOfMonth();

        // bulan berjalan => realtime
        if ($period->equalTo($currentMonth)) return 'realtime';

        // cek EOM dulu (kalau ada, pakai EOM)
        $hasEom = DB::table('kpi_fe_monthlies')
            ->whereDate('period', $period->toDateString())
            ->when(!empty($feIds), fn($q) => $q->whereIn('fe_user_id', $feIds)) // ✅ kolom kamu: fe_user_id
            ->where('calc_mode', 'eom')
            ->exists();

        if ($hasEom) return 'eom';

        // kalau EOM belum ada tapi realtime ada => pakai realtime
        $hasRealtime = DB::table('kpi_fe_monthlies')
            ->whereDate('period', $period->toDateString())
            ->when(!empty($feIds), fn($q) => $q->whereIn('fe_user_id', $feIds))
            ->where('calc_mode', 'realtime')
            ->exists();

        if ($hasRealtime) return 'realtime';

        // fallback default
        return 'eom';
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
                'period'    => $period->toDateString(),
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