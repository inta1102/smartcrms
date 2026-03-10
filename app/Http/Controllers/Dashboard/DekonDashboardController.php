<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DashboardDekomSnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\Dashboard\DekonRiskRadarService;
use App\Services\Dashboard\DekonExecutiveNarrativeService;

class DekonDashboardController extends Controller
{
    public function index(
        Request $request,
        DekonRiskRadarService $riskRadar,
        DekonExecutiveNarrativeService $narrativeService
    )
    {
        $periodInput = trim((string) $request->get('period', now()->format('Y-m')));
        $mode = strtolower(trim((string) $request->get('mode', 'eom')));

        if (!in_array($mode, ['eom', 'realtime', 'hybrid'], true)) {
            $mode = 'eom';
        }

        try {
            $period = Carbon::createFromFormat('Y-m', $periodInput)->startOfMonth();
        } catch (\Throwable $e) {
            $period = now()->startOfMonth();
            $periodInput = $period->format('Y-m');
        }

        $row = DashboardDekomSnapshot::query()
            ->whereDate('period_month', $period->toDateString())
            ->where('mode', $mode)
            ->first();

        if (!$row) {
            $latest = DashboardDekomSnapshot::query()
                ->where('mode', $mode)
                ->orderByDesc('period_month')
                ->first();

            if ($latest) {
                $row = $latest;
                $period = Carbon::parse($latest->period_month)->startOfMonth();
                $periodInput = $period->format('Y-m');
            }
        }

        $trendRows = DashboardDekomSnapshot::query()
            ->where('mode', $mode)
            ->orderBy('period_month')
            ->get();

        $availablePeriods = DashboardDekomSnapshot::query()
            ->where('mode', $mode)
            ->orderByDesc('period_month')
            ->pluck('period_month')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'))
            ->values();

        $meta = is_array($row?->meta ?? null) ? $row->meta : [];

        $selectedPeriod = $row?->period_month
            ? Carbon::parse($row->period_month)->startOfMonth()
            : $period->copy()->startOfMonth();

        $prevRow = DashboardDekomSnapshot::query()
            ->where('mode', $mode)
            ->whereDate('period_month', $selectedPeriod->copy()->subMonthNoOverflow()->toDateString())
            ->first();

        $insights = $this->buildInsights($row, $prevRow);
        $riskRadarData = $riskRadar->evaluate($row, $prevRow);
        $executiveNarrative = $narrativeService->generate($row, $prevRow);

        $viewData = [
            'period' => $periodInput,
            'mode' => $mode,
            'row' => $row,
            'meta' => $meta,
            'availablePeriods' => $availablePeriods,
            'insights' => $insights,
            'riskRadar' => $riskRadarData,
            'executiveNarrative' => $executiveNarrative,

            'trendLabels' => $trendRows->map(fn ($r) => Carbon::parse($r->period_month)->format('M y'))->values(),

            'trendTotalOs' => $trendRows->map(fn ($r) => (float) $r->total_os)->values(),
            'trendNplPct'  => $trendRows->map(fn ($r) => (float) $r->npl_pct)->values(),

            'trendTargetActual' => [
                'target_ytd' => $trendRows->map(fn ($r) => (float) $r->target_ytd)->values(),
                'actual_ytd' => $trendRows->map(fn ($r) => (float) $r->realisasi_ytd)->values(),
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
        ];

        return view('dashboard.dekom.index', $viewData);
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
}