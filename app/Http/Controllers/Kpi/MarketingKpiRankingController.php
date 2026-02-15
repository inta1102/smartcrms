<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\MarketingKpiMonthly;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Kpi\MarketingKpiMonthlyService;

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
        if (!in_array($tab, ['score', 'growth'], true)) $tab = 'score';

        // ✅ role selector: AO|SO|RO (default AO)
        $role = strtoupper(trim((string) $request->get('role', 'AO')));
        if (!in_array($role, ['AO', 'SO', 'RO'], true)) $role = 'AO';

        $prevPeriod = $period->copy()->subMonth()->startOfMonth();

        // ==========================================================
        // ROLE RO: ambil dari kpi_ro_monthly (score & growth versi RO)
        // Mode RO AUTO:
        // - bulan ini => realtime
        // - bulan lalu ke bawah => eom
        // ==========================================================
        if ($role === 'RO') {
            $mode = $this->resolveRoMode($period);

            // =========================
            // TAB A: Ranking KPI (Score) - RO
            // =========================
            $scoreRows = DB::table('kpi_ro_monthly as k')
                ->join('users as u', function ($join) {
                    $join->on('u.ao_code', '=', 'k.ao_code');
                })
                ->whereDate('k.period_month', $period->toDateString())
                ->where('k.calc_mode', $mode)
                ->where('u.level', 'RO') // ✅ hanya RO beneran
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    k.ao_code,
                    COALESCE(u.name, CONCAT('RO ', k.ao_code)) as ao_name,

                    k.total_score_weighted as score_total,

                    k.repayment_rate,
                    k.repayment_pct,
                    k.repayment_score,

                    k.topup_realisasi,
                    k.topup_target,
                    k.topup_pct,
                    k.topup_score,

                    k.noa_realisasi,
                    k.noa_target,
                    k.noa_pct,
                    k.noa_score,

                    k.dpk_pct,
                    k.dpk_score,

                    k.dpk_migrasi_count,
                    k.dpk_migrasi_os,
                    k.dpk_total_os_akhir,

                    k.baseline_ok,
                    k.baseline_note
                ")
                ->orderByDesc('k.total_score_weighted')
                ->orderBy('k.ao_code')
                ->get()
                ->values()
                ->map(function ($r, $idx) {
                    $r->rank = $idx + 1;
                    return $r;
                });

            // =========================
            // TAB B: Ranking Growth (Realisasi) - RO
            // =========================
            // Untuk RO: growth = realisasi KPI bulan berjalan:
            // urut: TopUp → NOA
            $growthRows = DB::table('kpi_ro_monthly as k')
                ->join('users as u', function ($join) {
                    $join->on('u.ao_code', '=', 'k.ao_code');
                })
                ->whereDate('k.period_month', $period->toDateString())
                ->where('k.calc_mode', $mode)
                ->where('u.level', 'RO') // ✅ hanya RO beneran
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    k.ao_code,
                    COALESCE(u.name, CONCAT('RO ', k.ao_code)) as ao_name,

                    k.topup_realisasi as os_growth,   -- reuse kolom growth existing blade
                    k.noa_realisasi  as noa_growth,

                    k.repayment_pct,
                    k.dpk_pct,

                    k.dpk_migrasi_count,
                    k.dpk_migrasi_os,
                    k.dpk_total_os_akhir,

                    k.baseline_ok,
                    k.baseline_note
                ")
                ->orderByDesc('k.topup_realisasi')
                ->orderByDesc('k.noa_realisasi')
                ->orderBy('k.ao_code')
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
                'role'       => $role,
                'mode'       => $mode, // boleh tetap dikirim untuk info kecil (tanpa dropdown)
                'scoreRows'  => $scoreRows,
                'growthRows' => $growthRows,
            ]);
        }

        // ==========================================================
        // ROLE AO / SO: Marketing KPI Monthly
        // ==========================================================

        // =========================
        // TAB A: Ranking KPI (Score)
        // =========================
        $scoreRows = MarketingKpiMonthly::query()
            ->with(['user:id,name,ao_code,level'])
            ->whereDate('period', $period->toDateString())
            ->whereNotNull('target_id')
            ->whereHas('user', function ($q) use ($role) {
                $q->where('level', $role);
            })
            ->orderByDesc('score_total')
            ->orderByDesc('score_os')
            ->orderByDesc('score_noa')
            ->orderByDesc('os_growth')
            ->get()
            ->values()
            ->map(function ($r, $idx) {
                $r->rank = $idx + 1;
                $r->user_id = $r->user?->id;
                $r->ao_name = $r->user?->name ?? '-';
                $r->ao_code = $r->user?->ao_code ?? ($r->ao_code ?? null);
                return $r;
            });

        // =========================
        // TAB B: Ranking Growth (Realisasi)
        // =========================
        $prevAgg = DB::table('loan_account_snapshots_monthly')
            ->selectRaw('ao_code, ROUND(SUM(outstanding)) as os_prev, COUNT(*) as noa_prev')
            ->where('snapshot_month', $prevPeriod->toDateString())
            ->groupBy('ao_code');

        $nowAgg = DB::table('loan_accounts')
            ->selectRaw('ao_code, ROUND(SUM(outstanding)) as os_now, COUNT(*) as noa_now')
            ->groupBy('ao_code');

        $roleLabel = $role; // AO / SO (whitelist)

        $growthRows = DB::query()
            ->fromSub($nowAgg, 'n')
            ->leftJoinSub($prevAgg, 'p', function ($join) {
                $join->on('p.ao_code', '=', 'n.ao_code');
            })
            ->leftJoin('users as u', 'u.ao_code', '=', 'n.ao_code')
            ->whereNotNull('u.id')
            ->where('u.level', $role)
            ->leftJoin('marketing_kpi_targets as t', function ($join) use ($period) {
                $join->on('t.user_id', '=', 'u.id')
                    ->whereDate('t.period', '=', $period->toDateString());
            })
            ->selectRaw("
                t.id as target_id,
                u.id as user_id,
                n.ao_code,
                COALESCE(u.name, CONCAT('$roleLabel ', n.ao_code)) as ao_name,

                COALESCE(p.os_prev, 0) as os_prev,
                COALESCE(n.os_now, 0) as os_now,
                (COALESCE(n.os_now,0) - COALESCE(p.os_prev,0)) as os_growth,

                COALESCE(p.noa_prev, 0) as noa_prev,
                COALESCE(n.noa_now, 0) as noa_now,
                (COALESCE(n.noa_now,0) - COALESCE(p.noa_prev,0)) as noa_growth
            ")
            ->orderByDesc('os_growth')
            ->orderByDesc('noa_growth')
            ->orderBy('n.ao_code')
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
            'role'       => $role,
            'mode'       => null, // ✅ tidak dipakai lagi untuk dropdown
            'scoreRows'  => $scoreRows,
            'growthRows' => $growthRows,
        ]);
    }

    public function recalcAll(Request $request, MarketingKpiMonthlyService $svc)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $this->authorize('recalcMarketingKpi');

        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
        ]);

        $period = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth();

        $aos = User::query()
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '')
            ->whereIn('level', ['AO', 'RO', 'SO', 'FE', 'BE'])
            ->get(['id', 'ao_code', 'name', 'level']);

        foreach ($aos as $ao) {
            $svc->recalcForUserAndPeriod((int) $ao->id, $period);
        }

        return back()->with('status', 'Recalc KPI Marketing berhasil.');
    }

    private function resolveRoMode(Carbon $period): string
    {
        $thisMonth = now()->startOfMonth();
        return $period->greaterThanOrEqualTo($thisMonth) ? 'realtime' : 'eom';
    }
}
