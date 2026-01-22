<?php

namespace App\Http\Controllers\Lending;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LendingTrendController extends Controller
{
    public function index(Request $request)
    {
        // ==========================================================
        // 0) Params
        // ==========================================================
        $month = $request->filled('month')
            ? Carbon::parse($request->month)->startOfMonth()->toDateString()
            : now()->startOfMonth()->toDateString();

        $monthStart = Carbon::parse($month)->startOfMonth();
        $todayMonthStart = now()->startOfMonth();

        // flag: apakah month yang dipilih adalah bulan berjalan?
        $isCurrentMonth = $monthStart->equalTo($todayMonthStart);

        $monthsBack = (int)($request->get('months', 12));
        $monthsBack = max(3, min(36, $monthsBack)); // guard biar aman & ga kebablasan

        $startMonth = Carbon::parse($month)
            ->subMonths($monthsBack - 1)
            ->startOfMonth()
            ->toDateString();

        $branch = $request->get('branch', 'ALL');
        $ao     = $request->get('ao', 'ALL');

        // ==========================================================
        // Helper: apply filter branch/ao (dipakai di query builder)
        // ==========================================================
        $applyFilter = function ($q) use ($branch, $ao) {
            if ($branch !== 'ALL') $q->where('branch_code', $branch);
            if ($ao !== 'ALL')     $q->where('ao_code', $ao);
        };

        // ==========================================================
        // 1) Trend chart (bulanan) dari snapshot_monthly
        // ==========================================================
        $trendSnapshot = DB::table('loan_account_snapshots_monthly')
            ->selectRaw('snapshot_month, SUM(outstanding) AS os, COUNT(DISTINCT account_no) AS noa')
            ->whereBetween('snapshot_month', [$startMonth, $month])
            ->when($branch !== 'ALL', fn($q) => $q->where('branch_code', $branch))
            ->when($ao !== 'ALL', fn($q) => $q->where('ao_code', $ao))
            ->groupBy('snapshot_month')
            ->orderBy('snapshot_month')
            ->get();

        $trend = collect($trendSnapshot);

        $latestPos = DB::table('loan_accounts')->max('position_date'); // date string / null
        $latestPos = $latestPos ? Carbon::parse($latestPos)->toDateString() : null;
     // latest position date dari loan_accounts (scope filter branch/ao)
        $latestPosRow = DB::table('loan_accounts')
            ->selectRaw('MAX(position_date) as max_pos')
            ->when($branch !== 'ALL', fn($q) => $q->where('branch_code', $branch))
            ->when($ao !== 'ALL', fn($q) => $q->where('ao_code', $ao))
            ->first();

        $latestPosDate = $latestPosRow?->max_pos; // "YYYY-MM-DD" atau null
        $latestPosMonthStart = $latestPosDate ? Carbon::parse($latestPosDate)->startOfMonth()->toDateString() : null;

        // kalau month yang dipilih adalah bulan berjalan, tambahkan data bulan ini dari loan_accounts posisi terakhir
        if ($isCurrentMonth && $latestPosDate) {
            $curLive = DB::table('loan_accounts')
                ->selectRaw('SUM(outstanding) AS os, COUNT(DISTINCT account_no) AS noa')
                ->where('position_date', $latestPosDate)
                ->when($branch !== 'ALL', fn($q) => $q->where('branch_code', $branch))
                ->when($ao !== 'ALL', fn($q) => $q->where('ao_code', $ao))
                ->first();

            // pastikan snapshot_month yang dipakai tetap format bulan: YYYY-MM-01
            $trend->push((object)[
                'snapshot_month' => $todayMonthStart->toDateString(),
                'os' => (float)($curLive->os ?? 0),
                'noa' => (int)($curLive->noa ?? 0),
                // label tambahan buat tooltip (opsional)
                'source' => 'live',
                'position_date' => $latestPosDate,
            ]);
        }

        // re-sort by snapshot_month just in case
        $trend = $trend->sortBy('snapshot_month')->values();

        // ==========================================================
        // 2) Growth KPI (MoD)
        //    - CURRENT: loan_accounts posisi terakhir (latest position_date)
        //    - PREV   : closing snapshot bulan sebelumnya
        // ==========================================================
        $prevMonth = Carbon::parse($month)->subMonth()->startOfMonth()->toDateString();     

        $cur = DB::table('loan_accounts')
            ->selectRaw('SUM(outstanding) AS os, COUNT(DISTINCT account_no) AS noa')
            ->when($latestPos, fn($q) => $q->whereDate('position_date', $latestPos))
            ->when(true, fn($q) => $applyFilter($q))
            ->first();

        $prev = DB::table('loan_account_snapshots_monthly')
            ->selectRaw('SUM(outstanding) AS os, COUNT(DISTINCT account_no) AS noa')
            ->whereDate('snapshot_month', $prevMonth)
            ->when(true, fn($q) => $applyFilter($q))
            ->first();

        $curOs  = (float)($cur->os ?? 0);
        $prevOs = (float)($prev->os ?? 0);

        $curNoa  = (int)($cur->noa ?? 0);
        $prevNoa = (int)($prev->noa ?? 0);

        $kpi = [
            'month' => $month,

            // label MoD
            'latest_position_date' => $latestPos,  // current source
            'prev_snapshot_month'  => $prevMonth,  // baseline closing

            // OS
            'os' => $curOs,
            'os_prev' => $prevOs,
            'os_growth_abs' => $curOs - $prevOs,
            'os_growth_pct' => ($prevOs > 0) ? round((($curOs - $prevOs) / $prevOs) * 100, 2) : null,

            // NOA
            'noa' => $curNoa,
            'noa_prev' => $prevNoa,
            'noa_growth_abs' => $curNoa - $prevNoa,
            'noa_growth_pct' => ($prevNoa > 0) ? round((($curNoa - $prevNoa) / $prevNoa) * 100, 2) : null,
        ];

        // ==========================================================
        // 3) Ranking AO Growth (MoD) - top 20
        //    - CURRENT: loan_accounts latestPos
        //    - PREV   : snapshot prevMonth
        //    Catatan:
        //    - Jika filter AO dipilih, ranking tetap ditampilkan global/branch
        //      (biar ranking tetap bermakna). Tapi kalau kamu mau ranking ikut AO filter,
        //      tinggal tambahkan AND ao_code = ? pada 2 subquery.
        // ==========================================================
        $aoRank = [];

        if ($latestPos) {
            $bindings = [];
            $sqlBranchCur  = '';
            $sqlBranchPrev = '';

            if ($branch !== 'ALL') {
                $sqlBranchCur  = " AND branch_code = ? ";
                $sqlBranchPrev = " AND branch_code = ? ";
                $bindings[] = $latestPos;
                $bindings[] = $branch;
                $bindings[] = $prevMonth;
                $bindings[] = $branch;
            } else {
                $bindings[] = $latestPos;
                $bindings[] = $prevMonth;
            }

            // NOTE: AO filter tidak dipakai untuk ranking supaya ranking tetap "Top 20"
            $sql = "
                SELECT
                  x.ao_code,
                  COALESCE(x.os_cur,0)  AS os,
                  COALESCE(x.os_prev,0) AS os_prev,
                  (COALESCE(x.os_cur,0)-COALESCE(x.os_prev,0)) AS os_growth_abs,
                  CASE WHEN COALESCE(x.os_prev,0) > 0
                       THEN ROUND((COALESCE(x.os_cur,0)-COALESCE(x.os_prev,0))/COALESCE(x.os_prev,0)*100,2)
                  END AS os_growth_pct,

                  COALESCE(x.noa_cur,0)  AS noa,
                  COALESCE(x.noa_prev,0) AS noa_prev,
                  (COALESCE(x.noa_cur,0)-COALESCE(x.noa_prev,0)) AS noa_growth_abs,
                  CASE WHEN COALESCE(x.noa_prev,0) > 0
                       THEN ROUND((COALESCE(x.noa_cur,0)-COALESCE(x.noa_prev,0))/COALESCE(x.noa_prev,0)*100,2)
                  END AS noa_growth_pct
                FROM (
                  SELECT
                    ao_code,
                    SUM(CASE WHEN src='cur'  THEN os  ELSE 0 END) AS os_cur,
                    SUM(CASE WHEN src='prev' THEN os  ELSE 0 END) AS os_prev,
                    SUM(CASE WHEN src='cur'  THEN noa ELSE 0 END) AS noa_cur,
                    SUM(CASE WHEN src='prev' THEN noa ELSE 0 END) AS noa_prev
                  FROM (
                    SELECT 'cur' AS src, ao_code, SUM(outstanding) os, COUNT(DISTINCT account_no) noa
                    FROM loan_accounts
                    WHERE position_date = ?
                    {$sqlBranchCur}
                    GROUP BY ao_code

                    UNION ALL

                    SELECT 'prev' AS src, ao_code, SUM(outstanding) os, COUNT(DISTINCT account_no) noa
                    FROM loan_account_snapshots_monthly
                    WHERE snapshot_month = ?
                    {$sqlBranchPrev}
                    GROUP BY ao_code
                  ) t
                  GROUP BY ao_code
                ) x
                ORDER BY os_growth_abs DESC
                LIMIT 20
            ";

            $aoRank = DB::select($sql, $bindings);
        }
        // ======================================
        // Map AO Code -> AO Name
        // ======================================
        $aoCodes = collect($aoRank)
            ->pluck('ao_code')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $aoNames = DB::table('users')
            ->whereIn('ao_code', $aoCodes)
            ->pluck('name', 'ao_code'); // ['000077' => 'Budi Santoso']


        // ==========================================================
        // 4) Filter options (untuk dropdown di blade)
        // ==========================================================
        $branchOptions = DB::table('loan_account_snapshots_monthly')
            ->select('branch_code')
            ->distinct()
            ->orderBy('branch_code')
            ->pluck('branch_code')
            ->all();

        // AO master (kode + nama)
        $aoRows = DB::table('users')
            ->selectRaw("ao_code, MAX(name) AS ao_name")
            ->whereNotNull('ao_code')
            ->groupBy('ao_code')
            ->orderBy('ao_code')
            ->get();

        $aoNameMap = $aoRows->pluck('ao_name', 'ao_code')->toArray();

        // Dropdown options: gabungkan dari snapshot biar hanya AO yg memang punya data
        $aoOptions = DB::table('loan_account_snapshots_monthly')
            ->select('ao_code')
            ->when($branch !== 'ALL', fn($q) => $q->where('branch_code', $branch))
            ->whereNotNull('ao_code')
            ->distinct()
            ->orderBy('ao_code')
            ->pluck('ao_code')
            ->all();

        // ==========================================================
        // 5) Return view
        // ==========================================================
        $trendMeta = $trend->map(function($r){
            return [
                'snapshot_month' => $r->snapshot_month,
                'source' => $r->source ?? 'snapshot',
                'position_date' => $r->position_date ?? null,
            ];
        })->values();

        return view('lending.performance.trend', [
            'kpi' => $kpi,
            'trend' => $trend,
            'aoRank' => $aoRank,
            'aoNames' => $aoNames,
            'aoNameMap' => $aoNameMap,

            'branch' => $branch,
            'ao' => $ao,
            'month' => $month,
            'monthsBack' => $monthsBack,

            'branchOptions' => $branchOptions,
            'aoOptions' => $aoOptions,
            'trendMeta' => $trendMeta,
        ]);
    }
}
