<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DashboardDekomMovement;
use App\Models\DashboardDekomSnapshot;
use App\Services\Dashboard\DekomCreditConditionBuilderService;
use App\Services\Dashboard\DekonExecutiveNarrativeService;
use App\Services\Dashboard\DekonRiskRadarService;
use App\Services\Dashboard\DekonWaterfallBuilderService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DekonDashboardController extends Controller
{
    public function index(
        Request $request,
        DekonRiskRadarService $riskRadar,
        DekonExecutiveNarrativeService $narrativeService
    ) {
        $periodInput = (string) $request->query('period', now()->format('Y-m'));

        try {
            $selectedPeriod = Carbon::createFromFormat('Y-m', $periodInput)->startOfMonth();
        } catch (\Throwable $e) {
            $selectedPeriod = now()->startOfMonth();
            $periodInput = $selectedPeriod->format('Y-m');
        }

        $defaultMode = $this->resolveDefaultMode($selectedPeriod);

        $mode = strtolower((string) $request->query('mode', $defaultMode));
        if (! in_array($mode, ['eom', 'realtime', 'hybrid'], true)) {
            $mode = $defaultMode;
        }

        $availablePeriods = DashboardDekomSnapshot::query()
            ->select('period_month')
            ->distinct()
            ->orderByDesc('period_month')
            ->pluck('period_month')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'))
            ->values();

        // ambil row sesuai pilihan user
        $row = DashboardDekomSnapshot::query()
            ->whereDate('period_month', $selectedPeriod->toDateString())
            ->where('mode', $mode)
            ->first();

        // fallback hanya untuk isi data, mode pilihan user tetap dipertahankan
        $effectiveMode = $mode;

        if (! $row) {
            $fallbackRow = DashboardDekomSnapshot::query()
                ->whereDate('period_month', $selectedPeriod->toDateString())
                ->whereIn('mode', ['realtime', 'eom', 'hybrid'])
                ->orderByRaw(
                    "FIELD(mode, ?, ?, ?)",
                    [$mode, 'realtime', 'eom', 'hybrid']
                )
                ->first();

            if ($fallbackRow) {
                $row = $fallbackRow;
                $effectiveMode = $fallbackRow->mode;
            }
        }

        $meta = is_array($row?->meta ?? null) ? $row->meta : [];

        $prevRow = DashboardDekomSnapshot::query()
            ->whereDate('period_month', $selectedPeriod->copy()->subMonthNoOverflow()->toDateString())
            ->where('mode', $effectiveMode)
            ->first();

        $insights = $this->buildInsights($row, $prevRow);
        $riskRadarData = $riskRadar->evaluate($row, $prevRow);
        $executiveNarrative = $narrativeService->generate($row, $prevRow);

        $movementRows = DashboardDekomMovement::query()
            ->whereDate('period_month', $selectedPeriod->toDateString())
            ->where('mode', $effectiveMode)
            ->orderBy('section')
            ->orderBy('subgroup')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $movementSections = [
            'npl_improvement'        => $movementRows->where('section', 'npl_improvement')->values(),
            'quality_improvement'    => $movementRows->where('section', 'quality_improvement')->values(),
            'quality_deterioration'  => $movementRows->where('section', 'quality_deterioration')->values(),
            'credit_activity'        => $movementRows->where('section', 'credit_activity')->values(),
        ];

        $waterfall = app(DekonWaterfallBuilderService::class)
            ->build($selectedPeriod, $effectiveMode);

        $creditCondition = app(DekomCreditConditionBuilderService::class)
            ->build($selectedPeriod);

        // trend harus mengikuti mode data yang benar-benar dipakai
        $trendRows = $this->buildTrendRows($selectedPeriod, $effectiveMode);

        return view('dashboard.dekom.index', [
            'period' => $periodInput,
            'mode' => $mode, // mode pilihan user
            'effectiveMode' => $effectiveMode, // mode data yang benar-benar dipakai
            'row' => $row,
            'meta' => $meta,
            'availablePeriods' => $availablePeriods,
            'insights' => $insights,
            'riskRadar' => $riskRadarData,
            'executiveNarrative' => $executiveNarrative,
            'movementSections' => $movementSections,
            'waterfall' => $waterfall,
            'periodLabel' => $selectedPeriod->translatedFormat('F Y'),
            'creditCondition' => $creditCondition,

            'trendLabels' => $trendRows->map(fn ($r) => Carbon::parse($r->period_month)->format('M y'))->values(),
            'trendTotalOs' => $trendRows->map(fn ($r) => (float) $r->total_os)->values(),
            'trendNplPct' => $trendRows->map(fn ($r) => (float) $r->npl_pct)->values(),
            'trendNplTarget' => $trendRows->map(
                fn ($r) => (float) data_get($r->meta, 'target.target_npl_pct', 0)
            )->values(),

            'trendTargetActual' => [
                'target_ytd' => $trendRows->map(fn ($r) => (float) $r->target_ytd)->values(),
                'actual_ytd' => $trendRows->map(fn ($r) => (float) $r->total_os)->values(),
            ],

            'trendKolek' => [
                'l_os'   => $trendRows->map(fn ($r) => (float) $r->l_os)->values(),
                'dpk_os' => $trendRows->map(fn ($r) => (float) $r->dpk_os)->values(),
                'kl_os'  => $trendRows->map(fn ($r) => (float) $r->kl_os)->values(),
                'd_os'   => $trendRows->map(fn ($r) => (float) $r->d_os)->values(),
                'm_os'   => $trendRows->map(fn ($r) => (float) $r->m_os)->values(),
            ],

            'trendFt' => [
                'ft0_os' => $trendRows->map(fn ($r) => (float) $r->ft0_os)->values(),
                'ft1_os' => $trendRows->map(fn ($r) => (float) $r->ft1_os)->values(),
                'ft2_os' => $trendRows->map(fn ($r) => (float) $r->ft2_os)->values(),
                'ft3_os' => $trendRows->map(fn ($r) => (float) $r->ft3_os)->values(),
            ],

            'portfolioComposition' => [
                'labels' => ['L', 'DPK', 'KL', 'D', 'M'],
                'values' => [
                    (float) ($row->l_os ?? 0),
                    (float) ($row->dpk_os ?? 0),
                    (float) ($row->kl_os ?? 0),
                    (float) ($row->d_os ?? 0),
                    (float) ($row->m_os ?? 0),
                ],
            ],

            'targetAchievement' => [
                'labels' => $trendRows->map(fn ($r) => Carbon::parse($r->period_month)->format('M y'))->values(),

                'disbursement_target' => $trendRows->map(fn ($r) => (float) data_get($r->meta, 'target.target_disbursement', 0))->values(),
                'disbursement_actual' => $trendRows->map(fn ($r) => (float) ($r->realisasi_mtd ?? 0))->values(),

                'os_target' => $trendRows->map(fn ($r) => (float) data_get($r->meta, 'target.target_os', 0))->values(),
                'os_actual' => $trendRows->map(fn ($r) => (float) ($r->total_os ?? 0))->values(),

                'npl_target' => $trendRows->map(fn ($r) => (float) data_get($r->meta, 'target.target_npl_pct', 0))->values(),
                'npl_actual' => $trendRows->map(fn ($r) => (float) ($r->npl_pct ?? 0))->values(),
            ],
        ]);
    }

    protected function resolveDefaultMode(Carbon $period): string
    {
        return $period->isSameMonth(now()) ? 'realtime' : 'eom';
    }

    protected function buildInsights($row, $prevRow): array
    {
        if (!$row) {
            return [];
        }

        $insights = [];

        $totalOs = (float) ($row->total_os ?? 0);
        $nplPct = (float) ($row->npl_pct ?? 0);
        $restrOs = (float) ($row->restr_os ?? 0);
        $dpd12Os = (float) ($row->dpd12_os ?? 0);
        $targetYtd = (float) ($row->target_ytd ?? 0);
        $actualYtd = (float) ($row->realisasi_ytd ?? 0);

        if ($prevRow) {
            $prevTotalOs = (float) ($prevRow->total_os ?? 0);

            if ($prevTotalOs > 0) {
                $deltaOsPct = (($totalOs - $prevTotalOs) / $prevTotalOs) * 100;
                $insights[] = [
                    'type' => $deltaOsPct >= 0 ? 'positive' : 'warning',
                    'text' => 'Total OS ' . ($deltaOsPct >= 0 ? 'naik' : 'turun') . ' ' . number_format(abs($deltaOsPct), 2, ',', '.') . '% dibanding bulan lalu.',
                ];
            }

            $prevNplPct = (float) ($prevRow->npl_pct ?? 0);
            $deltaNpl = $nplPct - $prevNplPct;

            $insights[] = [
                'type' => $deltaNpl <= 0 ? 'positive' : 'danger',
                'text' => 'NPL bergerak ' . ($deltaNpl > 0 ? 'naik' : 'turun') . ' ' . number_format(abs($deltaNpl), 2, ',', '.') . ' p.p dibanding bulan lalu.',
            ];
        }

        if ($targetYtd > 0) {
            $ach = ($actualYtd / $targetYtd) * 100;
            $insights[] = [
                'type' => $ach >= 100 ? 'positive' : ($ach >= 80 ? 'warning' : 'danger'),
                'text' => 'Pencapaian realisasi YTD terhadap target berada di level ' . number_format($ach, 2, ',', '.') . '%.',
            ];
        } else {
            $insights[] = [
                'type' => 'neutral',
                'text' => 'Target YTD belum tersedia, sehingga achievement target belum dapat dievaluasi.',
            ];
        }

        if ($totalOs > 0 && $restrOs > 0) {
            $restrPct = ($restrOs / $totalOs) * 100;
            $insights[] = [
                'type' => $restrPct >= 20 ? 'warning' : 'neutral',
                'text' => 'Portofolio restrukturisasi mencapai ' . number_format($restrPct, 2, ',', '.') . '% dari total OS.',
            ];
        }

        if ($dpd12Os > 0) {
            $insights[] = [
                'type' => 'danger',
                'text' => 'Eksposur DPD > 12 bulan tercatat sebesar Rp ' . number_format($dpd12Os, 0, ',', '.') . '.',
            ];
        }

        return array_slice($insights, 0, 5);
    }

    protected function buildTrendRows(Carbon $selectedPeriod, string $preferredMode = 'eom')
    {
        $all = DashboardDekomSnapshot::query()
            ->whereDate('period_month', '<=', $selectedPeriod->toDateString())
            ->orderBy('period_month')
            ->get()
            ->groupBy(fn ($r) => Carbon::parse($r->period_month)->format('Y-m'));

        $rows = $all->map(function ($group) use ($preferredMode) {
            return $group->sortBy(function ($r) use ($preferredMode) {
                return match ($r->mode) {
                    $preferredMode => 0,
                    'realtime'     => 1,
                    'eom'          => 2,
                    'hybrid'       => 3,
                    default        => 9,
                };
            })->first();
        })->filter();

        // buang bulan-bulan yang datanya kosong total
        $rows = $rows->filter(function ($r) {
            return
                (float) ($r->total_os ?? 0) > 0 ||
                (float) ($r->npl_pct ?? 0) > 0 ||
                (float) ($r->l_os ?? 0) > 0 ||
                (float) ($r->dpk_os ?? 0) > 0 ||
                (float) ($r->kl_os ?? 0) > 0 ||
                (float) ($r->d_os ?? 0) > 0 ||
                (float) ($r->m_os ?? 0) > 0;
        });

        return $rows->values();
    }
}