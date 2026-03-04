<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\KpiAllExport;

class KpiExportController extends Controller
{
    public function exportAll(Request $request)
    {
        $rawPeriod = trim((string)$request->query('period', '')); // boleh '2026-02' / '2026-02-01' / kosong
        $level     = strtoupper(trim((string)$request->query('level', 'ALL')));
        $role      = strtoupper(trim((string)$request->query('role', 'ALL')));
        $q         = trim((string)$request->query('q', ''));

        // filename aman
        $periodLabel = $rawPeriod !== '' ? str_replace(['/', '\\', ':'], '-', $rawPeriod) : 'current';
        $fileName = "KPI_ALL_{$periodLabel}.xlsx";

        return Excel::download(
            new KpiAllExport($rawPeriod, [
                'level' => $level,
                'role'  => $role,
                'q'     => $q,
            ]),
            $fileName
        );
    }
}