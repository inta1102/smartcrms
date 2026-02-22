<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Services\Kpi\KsbeKpiMonthlyService;
use Illuminate\Http\Request;

class KsbeSheetController extends Controller
{
    public function __construct(private readonly KsbeKpiMonthlyService $svc) {}

    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $periodYm = (string)$request->query('period', now()->format('Y-m'));
        $data = $this->svc->buildForPeriod($periodYm, $me);

        return view('kpi.marketing.sheet_ksbe', $data);
    }
}