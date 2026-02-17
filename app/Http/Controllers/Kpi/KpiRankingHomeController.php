<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class KpiRankingHomeController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        abort_if(!$u, 401);

        // role normalize (support enum / string)
        $lvl = strtoupper(trim((string)($u->level instanceof \BackedEnum ? $u->level->value : $u->level)));

        // opsional: bisa paksa via ?role=AO (untuk admin)
        $forced = strtoupper(trim((string)$request->query('role', '')));
        if ($forced !== '') $lvl = $forced;

        // mapping role -> route name
        $map = [
            // staff
            'AO' => 'kpi.ranking.ao',
            'RO' => 'kpi.ranking.ro',
            'SO' => 'kpi.ranking.so',
            'FE' => 'kpi.ranking.fe',
            'BE' => 'kpi.ranking.be',

            // TL variants (nanti makin detail)
            'TL'   => 'kpi.ranking.tl',
            'TLL'  => 'kpi.ranking.tl',
            'TLR'  => 'kpi.ranking.tl',
            'TLF'  => 'kpi.ranking.tl',
            'TLRO' => 'kpi.ranking.tl',
            'TLSO' => 'kpi.ranking.tl',
            'TLFE' => 'kpi.ranking.tl',
            'TLBE' => 'kpi.ranking.tl',
            'TLUM' => 'kpi.ranking.tl',
        ];

        $route = $map[$lvl] ?? 'kpi.ranking.ao'; // fallback aman

        // kalau route belum ada (lagi dikerjain), fallback ke AO
        if (!\Illuminate\Support\Facades\Route::has($route)) {
            $route = 'kpi.ranking.ao';
        }

        // bawa period jika ada
        $period = $request->query('period');
        if (!empty($period)) {
            return redirect()->route($route, ['period' => $period]);
        }

        return redirect()->route($route);
    }
}
