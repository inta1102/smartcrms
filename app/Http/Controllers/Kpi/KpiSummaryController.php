<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Kpi\KpiSummaryService;

class KpiSummaryController extends Controller
{
    public function index(Request $request, KpiSummaryService $svc)
    {
        // period: support 2026-02 / 2026-02-01; default bulan ini
        $rawPeriod = trim((string) $request->query('period', ''));
        $level     = strtoupper(trim((string) $request->query('level', 'ALL'))); // STAFF/TL/KASI/ALL
        $role      = strtoupper(trim((string) $request->query('role', 'ALL')));  // RO/TLRO/KSLR/ALL
        $q         = trim((string) $request->query('q', ''));                    // search nama

        $rows = $svc->build($rawPeriod, [
            'level' => $level,
            'role'  => $role,
            'q'     => $q,
        ]);

        return view('kpi.summary.index', [
            'rows' => $rows,
            'filters' => [
                'period' => $svc->normalizePeriodLabel($rawPeriod),
                'level'  => $level,
                'role'   => $role,
                'q'      => $q,
            ],
        ]);
    }
}