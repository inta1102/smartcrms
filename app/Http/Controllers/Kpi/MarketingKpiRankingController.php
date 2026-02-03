<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\MarketingKpiMonthly;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Kpi\MarketingKpiMonthlyService;
use Illuminate\Support\Facades\Gate;

class MarketingKpiRankingController extends Controller
{
    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $period = $request->filled('period')
            ? Carbon::parse($request->period)->startOfMonth()
            : now()->startOfMonth();

        $tab = $request->get('tab', 'score'); // score | growth

        $prevPeriod = $period->copy()->subMonth()->startOfMonth();

        // =========================
        // TAB A: Ranking KPI (Score)
        // =========================
        $scoreRows = MarketingKpiMonthly::query()
            ->with(['user:id,name,ao_code'])
            ->whereDate('period', $period->toDateString())
            ->whereNotNull('target_id')
            ->orderByDesc('score_total')
            ->orderByDesc('score_os')
            ->orderByDesc('score_noa')
            ->orderByDesc('os_growth')
            ->get()
            ->values()
            ->map(function ($r, $idx) {
                $r->rank = $idx + 1;
                $r->user_id = $r->user?->id;               // ✅ samakan
                $r->ao_name = $r->user?->name ?? '-';      // ✅ samakan
                return $r;
            });

        // =========================
        // TAB B: Ranking Growth (Realisasi)
        // =========================
        // Prev (snapshot) per AO
        $prevAgg = DB::table('loan_account_snapshots_monthly')
            ->selectRaw('ao_code, ROUND(SUM(outstanding)) as os_prev, COUNT(*) as noa_prev')
            ->where('snapshot_month', $prevPeriod->toDateString())
            ->groupBy('ao_code');

        // Now (live) per AO
        $nowAgg = DB::table('loan_accounts')
            ->selectRaw('ao_code, ROUND(SUM(outstanding)) as os_now, COUNT(*) as noa_now')
            ->groupBy('ao_code');

        // Gabungkan + join users untuk nama
        $growthRows = DB::query()
            ->fromSub($nowAgg, 'n')
            ->leftJoinSub($prevAgg, 'p', 'p.ao_code', '=', 'n.ao_code')
            ->leftJoin('users as u', 'u.ao_code', '=', 'n.ao_code')

            // ✅ JOIN ke target period ini untuk dapat target_id
            ->leftJoin('marketing_kpi_targets as t', function ($join) use ($period) {
                $join->on('t.user_id', '=', 'u.id')
                    ->whereDate('t.period', '=', $period->toDateString());
            })

            ->selectRaw("
                t.id as target_id,               -- ✅ INI KUNCINYA
                u.id as user_id,                 -- (boleh tetep disimpan)
                n.ao_code,
                COALESCE(u.name, CONCAT('AO ', n.ao_code)) as ao_name,

                COALESCE(p.os_prev, 0) as os_prev,
                COALESCE(n.os_now, 0) as os_now,
                (COALESCE(n.os_now,0) - COALESCE(p.os_prev,0)) as os_growth,

                COALESCE(p.noa_prev, 0) as noa_prev,
                COALESCE(n.noa_now, 0) as noa_now,
                (COALESCE(n.noa_now,0) - COALESCE(p.noa_prev,0)) as noa_growth
            ")
            ->orderByDesc('os_growth')
            ->orderByDesc('noa_growth')
            ->get()
            ->values()
            ->map(function ($r, $idx) {
                $r->rank = $idx + 1;
                return $r;
            });

        return view('kpi.marketing.ranking.index', [
            'period'     => $period,
            'prevPeriod' => $prevPeriod,
            'tab'        => $tab,
            'scoreRows'  => $scoreRows,
            'growthRows' => $growthRows,
        ]);
    }

    public function recalcAll(Request $request, MarketingKpiMonthlyService $svc)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        // ✅ cukup 1 sumber otorisasi
        $this->authorize('recalcMarketingKpi');

        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
        ]);

        $period = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth();

        $aos = User::query()
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '')
            ->whereIn('level', ['AO','RO','SO','FE','BE'])   // sesuaikan
            ->get(['id','ao_code','name','level']);

        foreach ($aos as $ao) {
            $svc->recalcForUserAndPeriod((int)$ao->id, $period);
        }

        return back()->with('status', 'Recalc KPI Marketing berhasil.');
    }

}
