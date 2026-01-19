<?php

namespace App\Http\Controllers;

use App\Services\Executive\ExecutiveDashboardService;
use App\Services\Kpi\PicHandlingKpiService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class ExecutiveDashboardController extends Controller
{
    public function __construct(
        protected ExecutiveDashboardService $service
    ) {}

    public function index(Request $request, PicHandlingKpiService $kpiService)
    {
        $user = $request->user();

        $filters = [
            'range'   => $request->get('range', 'mtd'),
            'start'   => $request->get('start'),     // YYYY-MM-DD
            'end'     => $request->get('end'),       // YYYY-MM-DD
            'ao_code' => $request->get('ao_code'),
            'unit'    => $request->get('unit'),
            'bucket'  => $request->get('bucket'),
            'status'  => $request->get('status'),
        ];

        // Data utama dashboard executive (snapshot, attention, dsb)
        $data = $this->service->build($user, $filters);

        // ✅ Ambil range KPI dari filter yang sama (konsisten)
        $start = !empty($filters['start'])
            ? Carbon::parse($filters['start'])->startOfDay()
            : now()->startOfMonth();

        $end = !empty($filters['end'])
            ? Carbon::parse($filters['end'])->endOfDay()
            : now()->endOfMonth();

        // ✅ KPI PIC (Completion)
        $picKpi = $kpiService->leaderboardCompletion(
            start: $start,
            end: $end,
            limit: 5
        );

        // dd($start->toDateTimeString(), $end->toDateTimeString(), $picKpi);

        // ✅ Return view (gabung data biar rapi)
        return view('dashboard.executive.index', array_merge($data, [
            'picKpi' => $picKpi,
        ]));
    }
}
