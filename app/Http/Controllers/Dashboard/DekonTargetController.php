<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DashboardDekomTarget;
use App\Services\Dashboard\DekonDashboardBuilder;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DekonTargetController extends Controller
{
    public function index(Request $request)
    {
        $periodInput = trim((string) $request->get('period', now()->format('Y-m')));

        try {
            $period = Carbon::createFromFormat('Y-m', $periodInput)->startOfMonth();
        } catch (\Throwable $e) {
            $period = now()->startOfMonth();
            $periodInput = $period->format('Y-m');
        }

        $row = DashboardDekomTarget::query()
            ->whereDate('period_month', $period->toDateString())
            ->first();

        $availablePeriods = DashboardDekomTarget::query()
            ->orderByDesc('period_month')
            ->pluck('period_month')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'))
            ->values();

        return view('dashboard.dekom.targets.index', [
            'period' => $periodInput,
            'periodLabel' => $period->translatedFormat('F Y'),
            'row' => $row,
            'availablePeriods' => $availablePeriods,
        ]);
    }

    public function store(Request $request, DekonDashboardBuilder $builder)
    {
        $data = $request->validate([
            'period_month' => ['required', 'date_format:Y-m'],
            'target_disbursement' => ['required', 'numeric', 'min:0'],
            'target_os' => ['required', 'numeric', 'min:0'],
            'target_npl_pct' => ['required', 'numeric', 'min:0'],
        ], [
            'period_month.required' => 'Periode wajib diisi.',
            'period_month.date_format' => 'Format periode harus YYYY-MM.',
        ]);

        $period = Carbon::createFromFormat('Y-m', $data['period_month'])->startOfMonth();

        DashboardDekomTarget::query()->updateOrCreate(
            ['period_month' => $period->toDateString()],
            [
                'target_disbursement' => (float) $data['target_disbursement'],
                'target_os' => (float) $data['target_os'],
                'target_npl_pct' => (float) $data['target_npl_pct'],
                'updated_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]
        );

        // rebuild snapshot dashboard agar target langsung terbaca
        $builder->buildForPeriod($period, 'eom');

        if ($period->equalTo(now()->copy()->startOfMonth())) {
            $builder->buildForPeriod($period, 'realtime');
        }

        return redirect()
            ->route('dashboard.dekom.targets.index', ['period' => $period->format('Y-m')])
            ->with('success', 'Target Dashboard Dekom berhasil disimpan.');
    }
}