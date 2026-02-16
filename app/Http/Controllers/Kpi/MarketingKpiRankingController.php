<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Kpi\MarketingKpiMonthlyService;

class MarketingKpiRankingController extends Controller
{
    public function goto(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $level = $this->normalizeLevel($me->level ?? null);
        $role  = $this->baseRoleFromViewerLevel($level);

        $period = $request->filled('period')
            ? Carbon::parse($request->period)->startOfMonth()->format('Y-m')
            : now()->startOfMonth()->format('Y-m');

        $tab = $request->get('tab', 'score');
        if (!in_array($tab, ['score', 'growth'], true)) $tab = 'score';

        return redirect()->route('kpi.marketing.ranking.index', [
            'role'         => $role,
            'period'       => $period,
            'tab'          => $tab,
            'viewer_level' => $level,
        ]);
    }

    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $period = $request->filled('period')
            ? Carbon::parse($request->period)->startOfMonth()
            : now()->startOfMonth();

        $tab = $request->get('tab', 'score');
        if (!in_array($tab, ['score', 'growth'], true)) $tab = 'score';

        $viewerLevel = $request->filled('viewer_level')
            ? strtoupper(trim((string)$request->get('viewer_level')))
            : $this->normalizeLevel($me->level ?? null);

        $isTlMode = Str::startsWith($viewerLevel, 'TL');

        $defaultRole = $this->baseRoleFromViewerLevel($viewerLevel);
        $role = strtoupper(trim((string)$request->get('role', $defaultRole)));
        if (!in_array($role, ['AO', 'SO', 'RO', 'FE', 'BE'], true)) $role = $defaultRole;

        $prevPeriod = $period->copy()->subMonth()->startOfMonth();

        // ==========================================================
        // RO
        // ==========================================================
        if ($role === 'RO') {
            $mode = $this->resolveRoMode($period);

            $scoreRows = DB::table('kpi_ro_monthly as k')
                ->join('users as u', function ($join) {
                    $join->on('u.ao_code', '=', 'k.ao_code');
                })
                ->whereDate('k.period_month', $period->toDateString())
                ->where('k.calc_mode', $mode)
                ->whereRaw("UPPER(TRIM(u.level)) = 'RO'")
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    k.ao_code,
                    COALESCE(u.name, CONCAT('RO ', k.ao_code)) as ao_name,

                    k.total_score_weighted as score_total,

                    k.repayment_pct,
                    k.topup_realisasi,
                    k.noa_realisasi,
                    k.dpk_pct,
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
                ->map(function ($r, $idx) { $r->rank = $idx + 1; return $r; });

            $growthRows = DB::table('kpi_ro_monthly as k')
                ->join('users as u', function ($join) {
                    $join->on('u.ao_code', '=', 'k.ao_code');
                })
                ->whereDate('k.period_month', $period->toDateString())
                ->where('k.calc_mode', $mode)
                ->whereRaw("UPPER(TRIM(u.level)) = 'RO'")
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    k.ao_code,
                    COALESCE(u.name, CONCAT('RO ', k.ao_code)) as ao_name,

                    k.topup_realisasi as os_growth,
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
                ->map(function ($r, $idx) { $r->rank = $idx + 1; return $r; });

            return view('kpi.marketing.ranking.index', compact(
                'period','prevPeriod','tab','role','viewerLevel','isTlMode','mode','scoreRows','growthRows'
            ));
        }

        // ==========================================================
        // SO (kpi_so_monthlies)
        // ==========================================================
        if ($role === 'SO') {
            $scoreRows = DB::table('kpi_so_monthlies as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->whereDate('m.period', $period->toDateString())
                ->whereRaw("UPPER(TRIM(u.level)) = 'SO'")
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    u.ao_code as ao_code,
                    u.name as ao_name,

                    m.score_total as score_total,

                    m.os_disbursement as os_disb,
                    m.noa_disbursement as noa_disb,
                    m.rr_pct as rr_pct,

                    m.score_os as score_os,
                    m.score_noa as score_noa,
                    m.score_rr as score_rr
                ")
                ->orderByDesc('m.score_total')
                ->orderByDesc('m.score_os')
                ->orderByDesc('m.score_noa')
                ->orderBy('u.name')
                ->get()
                ->values()
                ->map(function ($r, $idx) { $r->rank = $idx + 1; return $r; });

            // growth SO (optional) – kalau mau, bisa pakai os_disbursement/noa_disbursement sebagai “realisasi”
            $growthRows = DB::table('kpi_so_monthlies as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->whereDate('m.period', $period->toDateString())
                ->whereRaw("UPPER(TRIM(u.level)) = 'SO'")
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    u.ao_code as ao_code,
                    u.name as ao_name,

                    0 as os_prev,
                    0 as os_now,
                    m.os_disbursement as os_growth,

                    0 as noa_prev,
                    0 as noa_now,
                    m.noa_disbursement as noa_growth,
                    
                    m.score_total as score_total,

                    m.os_disbursement as os_growth,
                    m.noa_disbursement as noa_growth,

                    m.rr_pct as rr_pct
                ")
                ->orderByDesc('m.os_disbursement')
                ->orderByDesc('m.noa_disbursement')
                ->orderBy('u.name')
                ->get()
                ->values()
                ->map(function ($r, $idx) { $r->rank = $idx + 1; return $r; });

            $mode = null;

            return view('kpi.marketing.ranking.index', compact(
                'period','prevPeriod','tab','role','viewerLevel','isTlMode','mode','scoreRows','growthRows'
            ));
        }

        // ==========================================================
        // AO (kpi_ao_monthlies – scheme AO_UMKM)
        // ==========================================================
        if ($role === 'AO') {
            $scoreRows = DB::table('kpi_ao_monthlies as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->whereDate('m.period', $period->toDateString())
                ->whereRaw("UPPER(TRIM(u.level)) = 'AO'")
                ->where('m.scheme', 'AO_UMKM')
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    u.ao_code as ao_code,
                    u.name as ao_name,

                    m.score_total as score_total,

                    m.os_disbursement as os_disb,
                    m.noa_disbursement as noa_disb,
                    m.rr_pct as rr_pct,

                    m.score_os as score_os,
                    m.score_noa as score_noa,
                    m.score_rr as score_rr,
                    m.score_community as score_community
                ")
                ->orderByDesc('m.score_total')
                ->orderByDesc('m.score_os')
                ->orderByDesc('m.score_noa')
                ->orderBy('u.name')
                ->get()
                ->values()
                ->map(function ($r, $idx) { $r->rank = $idx + 1; return $r; });

            // growth AO: pakai os_disbursement/noa_disbursement sebagai realisasi pertumbuhan
            $growthRows = DB::table('kpi_ao_monthlies as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->whereDate('m.period', $period->toDateString())
                ->whereRaw("UPPER(TRIM(u.level)) = 'AO'")
                ->where('m.scheme', 'AO_UMKM')
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    u.ao_code as ao_code,
                    u.name as ao_name,

                    m.os_disbursement as os_growth,
                    m.noa_disbursement as noa_growth,

                    m.rr_pct as rr_pct
                ")
                ->orderByDesc('m.os_disbursement')
                ->orderByDesc('m.noa_disbursement')
                ->orderBy('u.name')
                ->get()
                ->values()
                ->map(function ($r, $idx) { $r->rank = $idx + 1; return $r; });

            $mode = null;

            return view('kpi.marketing.ranking.index', compact(
                'period','prevPeriod','tab','role','viewerLevel','isTlMode','mode','scoreRows','growthRows'
            ));
        }

        // ==========================================================
        // FE (kpi_fe_monthlies)
        // ==========================================================
        if ($role === 'FE') {
            $scoreRows = DB::table('kpi_fe_monthlies as k')
                ->join('users as u', 'u.id', '=', 'k.fe_user_id')
                ->whereDate('k.period', $period->toDateString())
                ->whereRaw("UPPER(TRIM(u.level)) = 'FE'")
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    COALESCE(k.ao_code, u.ao_code) as ao_code,
                    COALESCE(u.name, CONCAT('FE ', COALESCE(k.ao_code, u.ao_code))) as ao_name,

                    k.total_score_weighted as score_total,

                    k.os_kol2_turun_murni,
                    k.os_kol2_turun_pct,
                    k.migrasi_npl_os,
                    k.migrasi_npl_pct,
                    k.penalty_paid_total,

                    k.baseline_ok,
                    k.baseline_note
                ")
                ->orderByDesc('k.total_score_weighted')
                ->orderByDesc('k.os_kol2_turun_murni')
                // ✅ FIX: bukan orderByAsc()
                ->orderBy('k.migrasi_npl_os', 'asc')
                ->orderBy('u.name')
                ->get()
                ->values()
                ->map(function ($r, $idx) { $r->rank = $idx + 1; return $r; });

            // growth FE: ranking “hasil kerja” (os turun terbesar, migrasi terkecil)
            $growthRows = DB::table('kpi_fe_monthlies as k')
                ->join('users as u', 'u.id', '=', 'k.fe_user_id')
                ->whereDate('k.period', $period->toDateString())
                ->whereRaw("UPPER(TRIM(u.level)) = 'FE'")
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    COALESCE(k.ao_code, u.ao_code) as ao_code,
                    COALESCE(u.name, CONCAT('FE ', COALESCE(k.ao_code, u.ao_code))) as ao_name,

                    k.os_kol2_turun_murni as os_growth,
                    0 as noa_growth,

                    k.migrasi_npl_os,
                    k.penalty_paid_total
                ")
                ->orderByDesc('k.os_kol2_turun_murni')
                ->orderBy('k.migrasi_npl_os', 'asc')
                ->orderBy('u.name')
                ->get()
                ->values()
                ->map(function ($r, $idx) { $r->rank = $idx + 1; return $r; });

            $mode = null;

            return view('kpi.marketing.ranking.index', compact(
                'period','prevPeriod','tab','role','viewerLevel','isTlMode','mode','scoreRows','growthRows'
            ));
        }

        // ==========================================================
        // BE (kpi_be_monthlies)
        // ==========================================================
        if ($role === 'BE') {
            $scoreRows = DB::table('kpi_be_monthlies as k')
                ->join('users as u', 'u.id', '=', 'k.be_user_id')
                ->whereDate('k.period', $period->toDateString())
                ->whereRaw("UPPER(TRIM(u.level)) = 'BE'")
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    u.ao_code as ao_code,
                    u.name as ao_name,

                    k.total_pi as score_total,

                    k.actual_os_selesai,
                    k.actual_noa_selesai,
                    k.actual_bunga_masuk,
                    k.actual_denda_masuk,

                    k.net_npl_drop,
                    k.os_npl_prev,
                    k.os_npl_now,

                    k.status
                ")
                ->orderByDesc('k.total_pi')
                ->orderByDesc('k.actual_os_selesai')
                ->orderByDesc('k.actual_noa_selesai')
                ->orderBy('u.name')
                ->get()
                ->values()
                ->map(function ($r, $idx) { $r->rank = $idx + 1; return $r; });

            // growth BE: fokus “hasil” (net_npl_drop terbesar / os_selesai terbesar)
            $growthRows = DB::table('kpi_be_monthlies as k')
                ->join('users as u', 'u.id', '=', 'k.be_user_id')
                ->whereDate('k.period', $period->toDateString())
                ->whereRaw("UPPER(TRIM(u.level)) = 'BE'")
                ->selectRaw("
                    NULL as target_id,
                    u.id as user_id,
                    u.ao_code as ao_code,
                    u.name as ao_name,

                    k.net_npl_drop as os_growth,
                    k.actual_noa_selesai as noa_growth,

                    k.actual_bunga_masuk,
                    k.actual_denda_masuk,
                    k.status
                ")
                ->orderByDesc('k.net_npl_drop')
                ->orderByDesc('k.actual_noa_selesai')
                ->orderBy('u.name')
                ->get()
                ->values()
                ->map(function ($r, $idx) { $r->rank = $idx + 1; return $r; });

            $mode = null;

            return view('kpi.marketing.ranking.index', compact(
                'period','prevPeriod','tab','role','viewerLevel','isTlMode','mode','scoreRows','growthRows'
            ));
        }

        // fallback (harusnya gak kepakai)
        abort(404);
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

    private function normalizeLevel($level): string
    {
        $raw = $level;
        $val = (string)($raw instanceof \BackedEnum ? $raw->value : $raw);
        return strtoupper(trim($val));
    }

    private function baseRoleFromViewerLevel(string $viewerLevel): string
    {
        $viewerLevel = strtoupper(trim($viewerLevel));

        $map = [
            'AO'   => 'AO',
            'SO'   => 'SO',
            'RO'   => 'RO',
            'FE'   => 'FE',
            'BE'   => 'BE',

            'TLRO' => 'RO',
            'TLSO' => 'SO',
            'TLFE' => 'FE',
            'TLBE' => 'BE',
        ];

        if (!isset($map[$viewerLevel]) && Str::startsWith($viewerLevel, 'TL')) {
            if (Str::contains($viewerLevel, 'RO')) return 'RO';
            if (Str::contains($viewerLevel, 'SO')) return 'SO';
            if (Str::contains($viewerLevel, 'FE')) return 'FE';
            if (Str::contains($viewerLevel, 'BE')) return 'BE';
        }

        return $map[$viewerLevel] ?? 'AO';
    }
}
