<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Services\Kpi\TlBeMonthlyService;
use Illuminate\Http\Request;

class TlBeSheetController extends Controller
{
    public function index(Request $request, TlBeMonthlyService $svc)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $lvl = strtoupper(trim((string)($me->level instanceof \BackedEnum ? $me->level->value : $me->level)));
        abort_unless($lvl === 'TLBE', 403);

        $periodYm = (string)$request->query('period', now()->format('Y-m'));

        $pack = $svc->buildForPeriod($periodYm, $me);

        return view('kpi.marketing.sheet_tlbe', [
            'period' => $pack['period'],
            'weights' => $pack['weights'],
            'leader' => $pack['leader'],
            'recap' => $pack['recap'],
            'rankings' => $pack['rankings'],
        ]);
    }

    public function recalc(Request $request, TlBeMonthlyService $svc)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $lvl = strtoupper(trim((string)($me->level instanceof \BackedEnum ? $me->level->value : $me->level)));
        abort_unless($lvl === 'TLBE', 403);

        $periodYm = (string)$request->input('period', now()->format('Y-m'));

        $svc->recalcAndUpsert($periodYm, $me);

        return back()->with('success', 'Recalc TLBE sukses.');
    }
}