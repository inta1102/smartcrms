<?php

namespace App\Services\Kpi;

use App\Models\KpiTlbeMonthly;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TlBeMonthlyService
{
    // bobot TLBE mengikuti BE
    private float $wOs    = 0.50;
    private float $wNoa   = 0.10;
    private float $wBunga = 0.20;
    private float $wDenda = 0.20;

    // leadership weights (kamu bisa tweak)
    private float $wTeamPerf = 0.70;
    private float $wCoverage = 0.15;
    private float $wConsist  = 0.15;

    public function buildForPeriod(string $periodYm, $authUser): array
    {
        $period = $this->parsePeriod($periodYm);
        $periodDate = $period->toDateString(); // YYYY-MM-01

        // scope BE ids dari TLBE
        $beUserIds = $this->resolveScopeBeUserIdsForTlbe($authUser, $periodDate);

        if (empty($beUserIds)) {
            return [
                'period' => $period,
                'weights' => $this->weights(),
                'leader' => null,
                'recap' => null,
                'rankings' => collect(),
            ];
        }

        // Ambil BE rows (pakai sumber paling aman: kpi_be_monthlies kalau ada, fallback realtime builder BE)
        // ✅ paling rapi: panggil BeKpiMonthlyService buildForPeriod, lalu filter scope.
        $beSvc = app(\App\Services\Kpi\BeKpiMonthlyService::class);
        $bePack = $beSvc->buildForPeriod($period->format('Y-m'), $authUser);

        /** @var Collection $beItems */
        $beItems = collect($bePack['items'] ?? [])
            ->filter(fn($it) => in_array((int)($it['be_user_id'] ?? 0), $beUserIds, true))
            ->values();

        // kalau kosong, return kosong
        if ($beItems->isEmpty()) {
            return [
                'period' => $period,
                'weights' => $this->weights(),
                'leader' => $authUser,
                'recap' => null,
                'rankings' => collect(),
            ];
        }

        // ==============
        // Aggregation
        // ==============
        $scopeCount = (int)$beItems->count();

        $sumTargetOs    = (float)$beItems->sum(fn($x) => (float)($x['target']['os'] ?? 0));
        $sumTargetNoa   = (int)  $beItems->sum(fn($x) => (int)  ($x['target']['noa'] ?? 0));
        $sumTargetBunga = (float)$beItems->sum(fn($x) => (float)($x['target']['bunga'] ?? 0));
        $sumTargetDenda = (float)$beItems->sum(fn($x) => (float)($x['target']['denda'] ?? 0));

        $sumActualOs    = (float)$beItems->sum(fn($x) => (float)($x['actual']['os'] ?? 0));
        $sumActualNoa   = (int)  $beItems->sum(fn($x) => (int)  ($x['actual']['noa'] ?? 0));
        $sumActualBunga = (float)$beItems->sum(fn($x) => (float)($x['actual']['bunga'] ?? 0));
        $sumActualDenda = (float)$beItems->sum(fn($x) => (float)($x['actual']['denda'] ?? 0));

        $achOs    = $sumTargetOs    > 0 ? ($sumActualOs    / $sumTargetOs)    : 0.0;
        $achNoa   = $sumTargetNoa   > 0 ? ($sumActualNoa   / $sumTargetNoa)   : 0.0;
        $achBunga = $sumTargetBunga > 0 ? ($sumActualBunga / $sumTargetBunga) : 0.0;
        $achDenda = $sumTargetDenda > 0 ? ($sumActualDenda / $sumTargetDenda) : 0.0;

        // score logic sama seperti BE (band 0..1 => 1..6)
        $scorePercent = fn(?float $ratio) => $this->scorePercent($ratio);

        // NOA: lebih fair gunakan achievement ratio (bukan absolute) di TL level
        $sOs    = $scorePercent($sumTargetOs > 0 ? $achOs : null);
        $sNoa   = $scorePercent($sumTargetNoa > 0 ? $achNoa : null);
        $sBunga = $scorePercent($sumTargetBunga > 0 ? $achBunga : null);
        $sDenda = $scorePercent($sumTargetDenda > 0 ? $achDenda : null);

        $piOs    = round($sOs    * $this->wOs, 2);
        $piNoa   = round($sNoa   * $this->wNoa, 2);
        $piBunga = round($sBunga * $this->wBunga, 2);
        $piDenda = round($sDenda * $this->wDenda, 2);

        $teamPi = round($piOs + $piNoa + $piBunga + $piDenda, 2);

        // ==============
        // Leadership Index
        // ==============
        $pis = $beItems->map(fn($x) => (float)($x['pi']['total'] ?? 0))->values();
        $avgPi = $pis->avg() ?? 0.0;

        // coverage: PI >= 3.00 (kamu bisa ganti threshold)
        $coverageCount = (int)$pis->filter(fn($v) => $v >= 3.0)->count();
        $coveragePct = $scopeCount > 0 ? round(($coverageCount / $scopeCount) * 100, 2) : 0.0;

        // consistency index: 1 - normalized stdev
        $std = $this->stddev($pis->all());
        // normalisasi sederhana (clamp 0..1). Asumsi PI 0..6, dev max kira2 3
        $consistencyIdx = 1 - min(max($std / 3.0, 0.0), 1.0);
        $consistencyIdx = round($consistencyIdx, 4);

        // total final = leadership weighted
        $teamPerfNorm = $teamPi / 6.0; // 0..1
        $coverageNorm = $coveragePct / 100.0;

        $finalNorm =
            ($teamPerfNorm * $this->wTeamPerf) +
            ($coverageNorm * $this->wCoverage) +
            ($consistencyIdx * $this->wConsist);

        $totalPi = round($finalNorm * 6.0, 2);

        // rankings (BE scope) sort total PI desc + assign rank
        $rankings = $beItems
            ->sortByDesc(fn($x) => (float)($x['pi']['total'] ?? 0))
            ->values()
            ->map(function ($x, $i) {
                $x['rank'] = $i + 1;
                return $x;
            });

        $recap = [
            'tlbe_user_id' => (int)$authUser->id,
            'name' => $authUser->name ?? 'TLBE',
            'period' => $periodDate,
            'scope_count' => $scopeCount,

            'target_sum' => [
                'os' => $sumTargetOs, 'noa' => $sumTargetNoa, 'bunga' => $sumTargetBunga, 'denda' => $sumTargetDenda,
            ],
            'actual_sum' => [
                'os' => $sumActualOs, 'noa' => $sumActualNoa, 'bunga' => $sumActualBunga, 'denda' => $sumActualDenda,
            ],

            'ach_pct' => [
                'os' => round($achOs*100, 2),
                'noa' => round($achNoa*100, 2),
                'bunga' => round($achBunga*100, 2),
                'denda' => round($achDenda*100, 2),
            ],

            'score' => ['os'=>$sOs,'noa'=>$sNoa,'bunga'=>$sBunga,'denda'=>$sDenda],
            'pi' => ['os'=>$piOs,'noa'=>$piNoa,'bunga'=>$piBunga,'denda'=>$piDenda,'team'=>$teamPi,'total'=>$totalPi],

            'leadership' => [
                'avg_pi_be' => round($avgPi, 2),
                'coverage_pct' => $coveragePct,
                'consistency_idx' => $consistencyIdx,
                'stddev_pi' => round($std, 4),
            ],
        ];

        return [
            'period' => $period,
            'weights' => $this->weights(),
            'leader' => $authUser,
            'recap' => $recap,
            'rankings' => $rankings,
        ];
    }

    public function recalcAndUpsert(string $periodYm, $tlbeUser): KpiTlbeMonthly
    {
        $pack = $this->buildForPeriod($periodYm, $tlbeUser);
        $periodDate = $pack['period']->toDateString();

        $recap = $pack['recap'] ?? null;
        if (!$recap) {
            // upsert empty row biar tidak error
            return KpiTlbeMonthly::updateOrCreate(
                ['period' => $periodDate, 'tlbe_user_id' => (int)$tlbeUser->id],
                ['scope_count' => 0, 'total_pi' => 0, 'team_pi' => 0]
            );
        }

        return KpiTlbeMonthly::updateOrCreate(
            ['period' => $periodDate, 'tlbe_user_id' => (int)$tlbeUser->id],
            [
                'scope_count' => (int)$recap['scope_count'],

                'target_os_sum' => (float)$recap['target_sum']['os'],
                'target_noa_sum' => (int)$recap['target_sum']['noa'],
                'target_bunga_sum' => (float)$recap['target_sum']['bunga'],
                'target_denda_sum' => (float)$recap['target_sum']['denda'],

                'actual_os_sum' => (float)$recap['actual_sum']['os'],
                'actual_noa_sum' => (int)$recap['actual_sum']['noa'],
                'actual_bunga_sum' => (float)$recap['actual_sum']['bunga'],
                'actual_denda_sum' => (float)$recap['actual_sum']['denda'],

                'ach_os_pct' => (float)$recap['ach_pct']['os'],
                'ach_noa_pct' => (float)$recap['ach_pct']['noa'],
                'ach_bunga_pct' => (float)$recap['ach_pct']['bunga'],
                'ach_denda_pct' => (float)$recap['ach_pct']['denda'],

                'score_os' => (int)$recap['score']['os'],
                'score_noa' => (int)$recap['score']['noa'],
                'score_bunga' => (int)$recap['score']['bunga'],
                'score_denda' => (int)$recap['score']['denda'],

                'pi_os' => (float)$recap['pi']['os'],
                'pi_noa' => (float)$recap['pi']['noa'],
                'pi_bunga' => (float)$recap['pi']['bunga'],
                'pi_denda' => (float)$recap['pi']['denda'],
                'team_pi' => (float)$recap['pi']['team'],

                'avg_pi_be' => (float)$recap['leadership']['avg_pi_be'],
                'coverage_pct' => (float)$recap['leadership']['coverage_pct'],
                'consistency_idx' => (float)$recap['leadership']['consistency_idx'],

                'total_pi' => (float)$recap['pi']['total'],
                'calc_mode' => 'mixed',
            ]
        );
    }

    private function parsePeriod(string $periodYm): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        } catch (\Throwable $e) {
            return now()->startOfMonth();
        }
    }

    private function weights(): array
    {
        return [
            'os' => $this->wOs,
            'noa' => $this->wNoa,
            'bunga' => $this->wBunga,
            'denda' => $this->wDenda,
            'leadership' => [
                'team_perf' => $this->wTeamPerf,
                'coverage' => $this->wCoverage,
                'consistency' => $this->wConsist,
            ]
        ];
    }

    private function scorePercent(?float $ratio): int
    {
        if ($ratio === null) return 1;
        if ($ratio < 0.25) return 1;
        if ($ratio < 0.50) return 2;
        if ($ratio < 0.75) return 3;
        if ($ratio < 1.00) return 4;
        if ($ratio <= 1.0000001) return 5;
        return 6;
    }

    private function stddev(array $values): float
    {
        $n = count($values);
        if ($n <= 1) return 0.0;
        $mean = array_sum($values) / $n;
        $var = 0.0;
        foreach ($values as $v) {
            $var += pow(((float)$v) - $mean, 2);
        }
        $var /= ($n - 1);
        return sqrt($var);
    }

    /**
     * Scope TLBE → BE bawahan.
     * ✅ implement default pakai org_assignments (recommended).
     * Kalau belum ada, fallback: semua BE (sementara).
     */
    
    private function resolveScopeBeUserIdsForTlbe($tlbeUser, string $periodDate): array
    {
        if (!$tlbeUser) return [];

        // normalize role atasan
        $leaderRole = $tlbeUser->level instanceof \BackedEnum
            ? strtoupper(trim((string)$tlbeUser->level->value))
            : strtoupper(trim((string)$tlbeUser->level));

        // hanya TLBE
        if ($leaderRole !== 'TLBE') return [];

        // 1) ambil user_id bawahan dari org_assignments (yang aktif di tanggal period)
        $subIds = DB::table('org_assignments as oa')
            ->where('oa.leader_id', (int)$tlbeUser->id)
            ->whereRaw('UPPER(TRIM(oa.leader_role)) = ?', ['TLBE'])
            ->where('oa.is_active', 1)
            ->whereDate('oa.effective_from', '<=', $periodDate)
            ->where(function ($q) use ($periodDate) {
                $q->whereNull('oa.effective_to')
                ->orWhereDate('oa.effective_to', '>=', $periodDate);
            })
            ->pluck('oa.user_id')
            ->map(fn($x) => (int)$x)
            ->unique()
            ->values()
            ->all();

        if (empty($subIds)) return [];

        // 2) filter hanya user yg level BE
        return DB::table('users')
            ->whereIn('id', $subIds)
            ->whereRaw("UPPER(TRIM(level)) = 'BE'")
            ->pluck('id')
            ->map(fn($x) => (int)$x)
            ->values()
            ->all();
    }

    private function resolveScopeBeUserIdsForKsbe($ksbeUser, string $periodDate): array
    {
        if (!$ksbeUser) return [];

        $role = $ksbeUser->level instanceof \BackedEnum
            ? strtoupper(trim((string)$ksbeUser->level->value))
            : strtoupper(trim((string)$ksbeUser->level));

        if ($role !== 'KSBE') return [];

        $subIds = DB::table('org_assignments as oa')
            ->where('oa.leader_id', (int)$ksbeUser->id)
            ->whereRaw('UPPER(TRIM(oa.leader_role)) = ?', ['KSBE'])
            ->where('oa.is_active', 1)
            ->whereDate('oa.effective_from', '<=', $periodDate)
            ->where(function ($q) use ($periodDate) {
                $q->whereNull('oa.effective_to')
                ->orWhereDate('oa.effective_to', '>=', $periodDate);
            })
            ->pluck('oa.user_id')
            ->map(fn($x) => (int)$x)
            ->unique()
            ->values()
            ->all();

        if (empty($subIds)) return [];

        // pastikan hanya BE
        return DB::table('users')
            ->whereIn('id', $subIds)
            ->whereRaw("UPPER(TRIM(level)) = 'BE'")
            ->pluck('id')
            ->map(fn($x)=>(int)$x)
            ->values()
            ->all();
    }
}