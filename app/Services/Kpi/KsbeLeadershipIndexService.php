<?php

namespace App\Services\Kpi;

use App\Models\KpiKsbeMonthly;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KsbeLeadershipIndexService
{
    /**
     * Bobot Leadership Index (LI)
     * Total 1.00
     */
    private array $liWeights = [
        'pi_scope' => 0.55,
        'stability'=> 0.20,
        'risk'     => 0.15,
        'improve'  => 0.10,
    ];

    /**
     * Threshold stability
     */
    private float $bottomThresholdPi = 2.50; // bawahan di bawah ini dianggap "bottom"

    public function __construct(
        // kalau kamu butuh org service, inject di sini
    ) {}

    /**
     * MAIN: build + persist LI KSBE.
     *
     * @param string $periodYm "YYYY-MM"
     * @param object $authUser user login (KSBE/KASI/KBL)
     * @param array $ksbePayload output dari KsbeKpiMonthlyService::buildForPeriod()
     *        expected keys: period(Carbon), leader(array), weights(array), recap(array), items(Collection|array)
     */
    public function buildAndStore(string $periodYm, $authUser, array $ksbePayload): array
    {
        // normalize period
        $period = $ksbePayload['period'] ?? Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        if (!$period instanceof Carbon) $period = Carbon::parse($period)->startOfMonth();
        $periodDate = $period->toDateString(); // YYYY-MM-01

        $ksbeId = (int)($authUser->id ?? 0);

        // items as Collection
        $items = $ksbePayload['items'] ?? collect();
        if (is_array($items)) $items = collect($items);
        if (!$items instanceof Collection) $items = collect($items);

        // recap
        $recap = (array)($ksbePayload['recap'] ?? []);

        // weights KPI BE (scope PI)
        $beWeights = (array)($ksbePayload['weights'] ?? $this->beServiceWeights());

        // =========================================================
        // 1) PI_scope (Opsi C): total PI dari recap agregat
        //    NOTE: gunakan helper kamu supaya konsisten
        // =========================================================
        $piScope = $this->buildPiScopeFromRecap($recap, $beWeights);
        $piScopeTotal = (float)($piScope['pi_scope_total'] ?? 0);

        // =========================================================
        // 2) Stability Index (SI) + meta
        // =========================================================
        $si = $this->buildStabilityIndex($items);

        // meta optional (buat debug/penjelasan)
        $stabilityMeta = [
            'scope_be_count'  => (int)($si['scope_be_count'] ?? $items->count()),
            'active_be_count' => (int)($si['active_be_count'] ?? 0),
            'coverage_pct'    => (float)($si['coverage_pct'] ?? 0),
            'pi_stddev'       => (float)($si['pi_stddev'] ?? 0),
            'bottom_be_count' => (int)($si['bottom_be_count'] ?? 0),
            'bottom_pct'      => (float)($si['bottom_pct'] ?? 0),
            'si_components'   => [
                'coverage' => (int)($si['si_coverage_score'] ?? 0),
                'spread'   => (int)($si['si_spread_score'] ?? 0),
                'bottom'   => (int)($si['si_bottom_score'] ?? 0),
            ],
        ];

        // =========================================================
        // 3) Risk Index (RI)
        // =========================================================
        $ri = $this->buildRiskIndex($recap);

        // =========================================================
        // 4) Improvement Index (II)
        // =========================================================
        $ii = $this->buildImprovementIndex(
            $period,
            $ksbeId,
            (float)$piScope['pi_scope_total'],
            (float)$si['si_total'],
            (int)$ri['ri_score']
        );

        // =========================================================
        // 5) Final LI
        // =========================================================
        $iiForLi = ($ii['ii_score'] ?? 0) > 0 ? (int)$ii['ii_score'] : 3;
        $riForLi = ($ri['ri_score'] ?? 0) > 0 ? (int)$ri['ri_score'] : 3;

        $liTotal = $this->calcLiTotal(
            (float)$piScope['pi_scope_total'],
            (float)$si['si_total'],
            $riForLi,
            $iiForLi
        );

        // =========================================================
        // 6) Insights (optional)
        // =========================================================
        $insights = $this->buildInsights($items, $recap, $si, $ri, $ii);

        // =========================================================
        // 7) Persist ke DB
        // =========================================================
        $row = [
            'period'        => $periodDate,
            'ksbe_user_id'  => $ksbeId,

            // scope
            'scope_be_count'   => (int)($si['scope_be_count'] ?? 0),
            'active_be_count'  => (int)($si['active_be_count'] ?? 0),
            'coverage_pct'     => (float)($si['coverage_pct'] ?? 0),

            // recap (target/actual)
            'target_os_selesai'   => (float)($recap['target']['os'] ?? 0),
            'target_noa_selesai'  => (int)  ($recap['target']['noa'] ?? 0),
            'target_bunga_masuk'  => (float)($recap['target']['bunga'] ?? 0),
            'target_denda_masuk'  => (float)($recap['target']['denda'] ?? 0),

            'actual_os_selesai'   => (float)($recap['actual']['os'] ?? 0),
            'actual_noa_selesai'  => (int)  ($recap['actual']['noa'] ?? 0),
            'actual_bunga_masuk'  => (float)($recap['actual']['bunga'] ?? 0),
            'actual_denda_masuk'  => (float)($recap['actual']['denda'] ?? 0),

            // NPL
            'os_npl_prev'     => (float)($recap['actual']['os_npl_prev'] ?? 0),
            'os_npl_now'      => (float)($recap['actual']['os_npl_now'] ?? 0),
            'net_npl_drop'    => (float)($recap['actual']['net_npl_drop'] ?? 0),
            'npl_drop_pct'    => (float)($ri['npl_drop_pct'] ?? 0),

            // ach/score/pi scope
            'ach_os'      => (float)($recap['ach']['os'] ?? 0),
            'ach_noa'     => (float)($recap['ach']['noa'] ?? 0),
            'ach_bunga'   => (float)($recap['ach']['bunga'] ?? 0),
            'ach_denda'   => (float)($recap['ach']['denda'] ?? 0),

            'score_os'    => (int)($recap['score']['os'] ?? 1),
            'score_noa'   => (int)($recap['score']['noa'] ?? 1),
            'score_bunga' => (int)($recap['score']['bunga'] ?? 1),
            'score_denda' => (int)($recap['score']['denda'] ?? 1),

            'pi_os'          => (float)($recap['pi']['os'] ?? 0),
            'pi_noa'         => (float)($recap['pi']['noa'] ?? 0),
            'pi_bunga'       => (float)($recap['pi']['bunga'] ?? 0),
            'pi_denda'       => (float)($recap['pi']['denda'] ?? 0),
            'pi_scope_total' => (float)$piScopeTotal,

            // stability
            'pi_stddev'         => (float)($si['pi_stddev'] ?? 0),
            'bottom_be_count'   => (int)($si['bottom_be_count'] ?? 0),
            'bottom_pct'        => (float)($si['bottom_pct'] ?? 0),
            'si_coverage_score' => (int)($si['si_coverage_score'] ?? 0),
            'si_spread_score'   => (int)($si['si_spread_score'] ?? 0),
            'si_bottom_score'   => (int)($si['si_bottom_score'] ?? 0),
            'si_total'          => (float)($si['si_total'] ?? 0),

            // risk & improvement
            'ri_score'           => (int)($ri['ri_score'] ?? 0),
            'prev_pi_scope_total'=> (float)($ii['prev_pi_scope_total'] ?? 0),
            'delta_pi'           => (float)($ii['delta_pi'] ?? 0),
            'ii_score'           => (int)($ii['ii_score'] ?? 0),

            // final
            'li_total'       => (float)$liTotal,
            'json_insights'  => $insights,
            'calculated_at'  => now(),
        ];

        KpiKsbeMonthly::query()->updateOrCreate(
            ['period' => $periodDate, 'ksbe_user_id' => $ksbeId],
            $row
        );

        // =========================================================
        // 8) RETURN payload untuk Blade + AI Engine
        //
        // ✅ KUNCI:
        // - Set root 'pi_scope' supaya tidak 0 lagi
        // - Set 'li.*' karena AI engine baca 'li.pi_scope', 'li.stability', dst
        // - Tetap sediakan 'leadership.*' biar Blade lama tidak rusak
        // =========================================================
        $li = [
            'total'      => (float)$liTotal,
            'weights'    => $this->liWeights,

            // komponen utama (ini yang dibaca AI engine)
            'pi_scope'   => (float)$piScopeTotal,
            'stability'  => (float)($si['si_total'] ?? 0),
            'risk'       => (float)($ri['ri_score'] ?? 0),
            'improve'    => (float)($ii['ii_score'] ?? 0),

            // detail pendukung
            'pi_scope_detail'  => $piScope,
            'stability_detail' => $si,
            'stability_meta'   => $stabilityMeta,
            'risk_detail'      => $ri,
            'improvement_detail'=> $ii,
            'insights'         => $insights,
        ];

      

        return array_merge($ksbePayload, [
            // ✅ root fields (buat kartu top)
            'pi_scope'       => (float)$piScopeTotal,
            'stability'      => (float)($si['si_total'] ?? 0),
            'risk'           => (float)($ri['ri_score'] ?? 0),
            'improve'        => (float)($ii['ii_score'] ?? 0),

            // ✅ key standar untuk AI engine
            'li' => $li,

            // ✅ backward compatible (kalau Blade lama pakai leadership.*)
            'leadership' => [
                'li_total'     => (float)$liTotal,
                'li_weights'   => $this->liWeights,
                'pi_scope'     => $piScope,
                'stability'    => $si,
                'stability_meta' => $stabilityMeta,
                'risk'         => $ri,
                'improvement'  => $ii,
                'insights'     => $insights,
            ],
        ]);
    }

    // =========================================================
    // Component builders
    // =========================================================

    private function buildPiScopeFromRecap(array $recap, array $beWeights): array
    {
        $pi = (array)($recap['pi'] ?? []);
        $piTotal = (float)($pi['total'] ?? 0);

        // kalau recap belum ada pi total, fallback hitung dari score*weight
        if ($piTotal <= 0) {
            $sc = (array)($recap['score'] ?? []);
            $piOs = round(((int)($sc['os'] ?? 1)) * (float)($beWeights['os'] ?? 0.5), 2);
            $piNoa= round(((int)($sc['noa'] ?? 1)) * (float)($beWeights['noa'] ?? 0.1), 2);
            $piB  = round(((int)($sc['bunga'] ?? 1)) * (float)($beWeights['bunga'] ?? 0.2), 2);
            $piD  = round(((int)($sc['denda'] ?? 1)) * (float)($beWeights['denda'] ?? 0.2), 2);
            $piTotal = round($piOs + $piNoa + $piB + $piD, 2);
        }

        return [
            'pi_scope_total' => $piTotal,
        ];
    }

    private function buildStabilityIndex(Collection $items): array
    {
        $scope = $items->count();

        // pi list
        $piVals = $items->map(function ($x) {
            $pi = is_array($x) ? ($x['pi']['total'] ?? 0) : ($x->pi['total'] ?? 0);
            return (float)$pi;
        })->values();

        // active: punya actual OS recovery atau NOA selesai (biar coverage realistis)
        $activeCnt = $items->filter(function ($x) {
            $actual = is_array($x) ? ($x['actual'] ?? []) : ($x->actual ?? []);
            $os = (float)($actual['os'] ?? 0);
            $noa= (int)  ($actual['noa'] ?? 0);
            return ($os > 0) || ($noa > 0);
        })->count();

        $coveragePct = $scope > 0 ? round(($activeCnt / $scope) * 100, 2) : 0;

        // stddev PI
        $std = $this->stddev($piVals->all());

        // bottom
        $bottomCnt = $piVals->filter(fn($v) => $v < $this->bottomThresholdPi)->count();
        $bottomPct = $scope > 0 ? round(($bottomCnt / $scope) * 100, 2) : 0;

        // scoring
        $covScore   = $this->scoreCoverage1to6($coveragePct);
        $spreadScore= $this->scoreStddev1to6($std);
        $bottomScore= $this->scoreBottomPct1to6($bottomPct);

        // SI total (0.4/0.3/0.3)
        $siTotal = round((0.40 * $covScore) + (0.30 * $spreadScore) + (0.30 * $bottomScore), 2);

        return [
            'scope_be_count' => $scope,
            'active_be_count'=> $activeCnt,
            'coverage_pct'   => $coveragePct,
            'pi_stddev'      => round($std, 3),
            'bottom_be_count'=> $bottomCnt,
            'bottom_pct'     => $bottomPct,

            'si_coverage_score' => $covScore,
            'si_spread_score'   => $spreadScore,
            'si_bottom_score'   => $bottomScore,
            'si_total'          => $siTotal,
        ];
    }

    private function buildRiskIndex(array $recap): array
    {
        $prev    = (float) ($recap['actual']['os_npl_prev'] ?? 0);
        $netDrop = (float) ($recap['actual']['net_npl_drop'] ?? 0);

        // Kalau tidak ada baseline NPL bulan lalu, dropPct tidak meaningful
        $dropPct = ($prev > 0) ? round(($netDrop / $prev) * 100, 2) : null;

        // Score:
        // - Kalau dropPct null => N/A (0) atau netral (3). Aku sarankan 0 untuk display.
        $riScore = ($dropPct === null) ? 0 : $this->scoreNplDropPct1to6((float) $dropPct);

        return [
            'npl_drop_pct' => $dropPct,  // bisa null
            'ri_score'     => $riScore,  // bisa 0 (N/A)
            'meta' => [
                'os_npl_prev' => $prev,
                'net_drop'    => $netDrop,
            ],
        ];
    }

    private function buildImprovementIndex(
        Carbon $period,
        int $ksbeId,
        float $piNow,
        float $siNow,
        int $riNow
    ): array {
        $prevPeriod = (clone $period)->subMonthNoOverflow()->startOfMonth()->toDateString();

        $prev = KpiKsbeMonthly::query()
            ->where('period', $prevPeriod)
            ->where('ksbe_user_id', $ksbeId)
            ->first();

        // ===== baseline belum ada → NA =====
        if (!$prev) {
            return [
                'prev_period' => $prevPeriod,
                'na' => true,

                // legacy keys (biar kode lama aman)
                'prev_pi_scope_total' => null,
                'delta_pi' => null,
                'delta_si' => null,
                'delta_ri' => null,

                'prev' => [
                    'pi_scope_total' => null,
                    'si_total'       => null,
                    'ri_score'       => null,
                ],
                'now' => [
                    'pi_scope_total' => round($piNow, 2),
                    'si_total'       => round($siNow, 2),
                    'ri_score'       => (int)$riNow,
                ],
                'delta' => [
                    'pi' => null,
                    'si' => null,
                    'ri' => null,
                ],

                'cmi' => [
                    'raw'  => null,
                    'norm' => ['pi'=>null,'si'=>null,'ri'=>null],
                    'weights' => ['pi'=>0.50,'si'=>0.30,'ri'=>0.20],
                ],

                'ii_score' => 0,
                'ii_label' => 'NA',
                'reason'   => 'MoM belum ada (baseline belum tersedia)',
            ];
        }

        $piPrev = (float)($prev->pi_scope_total ?? 0);
        $siPrev = (float)($prev->si_total ?? 0);
        $riPrev = (int)  ($prev->ri_score ?? 0);

        $dPi = round($piNow - $piPrev, 2);
        $dSi = round($siNow - $siPrev, 2);
        $dRi = (int)($riNow - $riPrev);

        // ===== Normalisasi delta → -1..+1 =====
        $piNorm = $this->clamp($dPi / 0.50, -1, 1);
        $siNorm = $this->clamp($dSi / 0.50, -1, 1);
        $riNorm = $this->clamp($dRi / 2.0,  -1, 1);

        // ===== Bobot CMI =====
        $wPi = 0.50;
        $wSi = 0.30;
        $wRi = 0.20;

        $cmiRaw = round(($wPi*$piNorm) + ($wSi*$siNorm) + ($wRi*$riNorm), 3);

        $iiScore = $this->scoreCmi1to6($cmiRaw);

        $iiLabel = match (true) {
            $iiScore >= 6 => 'GOOD+',
            $iiScore >= 5 => 'GOOD',
            $iiScore >= 4 => 'UP',
            $iiScore >= 3 => 'FLAT',
            $iiScore >= 2 => 'DOWN',
            default => 'DROP',
        };

        $reason = "Momentum MoM: ΔPI {$dPi} • ΔSI {$dSi} • ΔRI {$dRi} (CMI {$cmiRaw})";

        return [
            'prev_period' => $prevPeriod,
            'na' => false,

            // legacy keys (biar kode lama aman)
            'prev_pi_scope_total' => round($piPrev, 2),
            'delta_pi' => $dPi,
            'delta_si' => $dSi,
            'delta_ri' => $dRi,

            'prev' => [
                'pi_scope_total' => round($piPrev, 2),
                'si_total'       => round($siPrev, 2),
                'ri_score'       => (int)$riPrev,
            ],
            'now' => [
                'pi_scope_total' => round($piNow, 2),
                'si_total'       => round($siNow, 2),
                'ri_score'       => (int)$riNow,
            ],
            'delta' => [
                'pi' => $dPi,
                'si' => $dSi,
                'ri' => $dRi,
            ],

            'cmi' => [
                'raw' => $cmiRaw,
                'norm' => [
                    'pi' => round($piNorm, 3),
                    'si' => round($siNorm, 3),
                    'ri' => round($riNorm, 3),
                ],
                'weights' => ['pi'=>$wPi,'si'=>$wSi,'ri'=>$wRi],
            ],

            'ii_score' => $iiScore,
            'ii_label' => $iiLabel,
            'reason'   => $reason,
        ];
    }

    private function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    private function scoreCmi1to6(?float $cmiRaw): int
    {
        if ($cmiRaw === null) return 0;

        if ($cmiRaw >= 0.60) return 6;
        if ($cmiRaw >= 0.30) return 5;
        if ($cmiRaw >= 0.10) return 4;
        if ($cmiRaw > -0.10) return 3;
        if ($cmiRaw > -0.30) return 2;
        return 1;
    }

    private function calcLiTotal(float $piScopeTotal, float $siTotal, int $riScore, int $iiScore): float
    {
        $w = $this->liWeights;

        $components = [
            'pi_scope'  => ['score' => $piScopeTotal, 'weight' => $w['pi_scope']],
            'stability' => ['score' => $siTotal,      'weight' => $w['stability']],
            'risk'      => ['score' => $riScore,      'weight' => $w['risk']],
            'improve'   => ['score' => $iiScore,      'weight' => $w['improve']],
        ];

        $weightedSum = 0;
        $effectiveWeight = 0;

        foreach ($components as $comp) {
            // score 0 = N/A → exclude dari kalkulasi
            if ($comp['score'] > 0) {
                $weightedSum += $comp['score'] * $comp['weight'];
                $effectiveWeight += $comp['weight'];
            }
        }

        // kalau semua N/A (tidak mungkin sih), return 0
        if ($effectiveWeight == 0) return 0;

        // normalisasi bobot
        $li = $weightedSum / $effectiveWeight;

        return round($li, 2);
    }

    private function buildInsights(Collection $items, array $recap, array $si, array $ri, array $ii): array
    {
        // fokus coaching: gap terbesar target-actual dari recap
        $gap = [
            'os'    => (float)($recap['target']['os'] ?? 0) - (float)($recap['actual']['os'] ?? 0),
            'noa'   => (int)  ($recap['target']['noa'] ?? 0) - (int)  ($recap['actual']['noa'] ?? 0),
            'bunga' => (float)($recap['target']['bunga'] ?? 0) - (float)($recap['actual']['bunga'] ?? 0),
            'denda' => (float)($recap['target']['denda'] ?? 0) - (float)($recap['actual']['denda'] ?? 0),
        ];

        // pilih fokus (prioritas OS, lalu bunga, lalu noa, lalu denda)
        $focus = 'os';
        $maxAbs = abs($gap['os']);
        foreach (['bunga','noa','denda'] as $k) {
            $v = is_numeric($gap[$k]) ? (float)$gap[$k] : (float)abs((int)$gap[$k]);
            if (abs($v) > $maxAbs) { $maxAbs = abs($v); $focus = $k; }
        }

        // critical BE: PI bottom
        $critical = $items->map(function($x){
            $name = is_array($x) ? ($x['name'] ?? '-') : ($x->name ?? '-');
            $code = is_array($x) ? ($x['code'] ?? '') : ($x->code ?? '');
            $pi   = is_array($x) ? (float)($x['pi']['total'] ?? 0) : (float)($x->pi['total'] ?? 0);
            return ['name'=>$name,'code'=>$code,'pi'=>$pi];
        })->sortBy('pi')->values();

        $critical = $critical->filter(fn($r)=>$r['pi'] < $this->bottomThresholdPi)->take(5)->values()->all();

        // rekomendasi singkat
        $actions = [];
        if (($si['coverage_pct'] ?? 0) < 50) $actions[] = 'Aktifkan pipeline: minimal 1 action recovery per BE per minggu (monitor coverage).';
        if (($si['bottom_pct'] ?? 0) > 30) $actions[] = 'Coaching fokus bottom performers: daily huddle 15 menit, review 3 case terbesar.';
        if (($ri['ri_score'] ?? 3) <= 2) $actions[] = 'Risk control: audit cohort NPL yang naik; kunci early warning & prioritas penanganan.';
        $deltaPi = data_get($ii, 'delta.pi', data_get($ii, 'delta_pi'));
            if ($deltaPi !== null && (float)$deltaPi < 0) {
                $actions[] = 'Recovery plan 2 minggu: targetkan quick wins ...';
            }
        
        if (empty($actions)) $actions[] = 'Pertahankan ritme: scale up OS recovery via curing kolek 3→2/1 dan eksekusi LUNAS prioritas.';

        return [
            'focus_area' => $focus,     // os|noa|bunga|denda
            'gap' => $gap,
            'critical_be' => $critical,
            'actions' => $actions,
        ];
    }

    // =========================================================
    // Scoring helpers (1..6)
    // =========================================================

    private function scoreCoverage1to6(float $coveragePct): int
    {
        if ($coveragePct >= 80) return 6;
        if ($coveragePct >= 60) return 5;
        if ($coveragePct >= 40) return 4;
        if ($coveragePct >= 20) return 3;
        if ($coveragePct >  0) return 2;
        return 1;
    }

    private function scoreStddev1to6(float $std): int
    {
        // makin kecil makin bagus
        if ($std <= 0.30) return 6;
        if ($std <= 0.60) return 5;
        if ($std <= 0.90) return 4;
        if ($std <= 1.20) return 3;
        if ($std <= 1.50) return 2;
        return 1;
    }

    private function scoreBottomPct1to6(float $bottomPct): int
    {
        // makin kecil makin bagus
        if ($bottomPct <= 10) return 6;
        if ($bottomPct <= 20) return 5;
        if ($bottomPct <= 30) return 4;
        if ($bottomPct <= 40) return 3;
        if ($bottomPct <= 50) return 2;
        return 1;
    }

    private function scoreNplDropPct1to6(float $dropPct): int
    {
        // dropPct positif = NPL turun (bagus)
        if ($dropPct >= 10) return 6;
        if ($dropPct >= 5)  return 5;
        if ($dropPct >= 1)  return 4;
        if ($dropPct >= 0)  return 3;
        if ($dropPct > -5)  return 2;
        return 1;
    }

    private function scoreDeltaPi1to6(?float $delta): int
    {
        if ($delta === null) return 0;

        if ($delta >= 0.80) return 6;   // sangat besar
        if ($delta >= 0.50) return 5;   // besar
        if ($delta >= 0.20) return 4;   // membaik
        if ($delta >= 0.00) return 3;   // stagnan / sedikit naik
        if ($delta >= -0.20) return 2;  // sedikit turun
        return 1;                       // turun signifikan
    }

    // =========================================================
    // Utils
    // =========================================================

    private function stddev(array $vals): float
    {
        $n = count($vals);
        if ($n <= 1) return 0.0;

        $mean = array_sum($vals) / $n;
        $var = 0.0;
        foreach ($vals as $v) $var += pow(((float)$v - $mean), 2);

        return sqrt($var / ($n - 1)); // sample stddev
    }

    private function beServiceWeights(): array
    {
        return [
            'os'    => 0.50,
            'noa'   => 0.10,
            'bunga' => 0.20,
            'denda' => 0.20,
        ];
    }
}