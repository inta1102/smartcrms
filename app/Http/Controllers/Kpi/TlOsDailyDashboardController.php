<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\OrgAssignment;
use App\Models\RoVisit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TlOsDailyDashboardController extends Controller
{
    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        /**
         * =========================================================
         * 1) RANGE DEFAULT
         *    - from: tgl terakhir bulan lalu
         *    - to  : tgl terakhir yang ada di tabel kpi_os_daily_aos
         * =========================================================
         */
        $latestInKpi = DB::table('kpi_os_daily_aos')->max('position_date'); // date
        $latestInKpi = $latestInKpi ? Carbon::parse($latestInKpi)->startOfDay() : now()->startOfDay();

        $lastMonthEndC = Carbon::now()->subMonthNoOverflow()->endOfMonth()->startOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : $lastMonthEndC->copy()->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->startOfDay()
            : $latestInKpi->copy()->startOfDay();

        // guard kalau user input kebalik
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->startOfDay()];
        }

        /**
         * =========================================================
         * 2) STAFF SCOPE (bawahan TL)
         * =========================================================
         */
        $staff = $this->subordinateStaffForLeader((int) $me->id);

        // fallback: kalau tidak ada bawahan, pakai TL sendiri jika punya ao_code
        if ($staff->isEmpty()) {
            $selfAo = str_pad(trim((string) ($me->ao_code ?? '')), 6, '0', STR_PAD_LEFT);
            if ($selfAo !== '' && $selfAo !== '000000') {
                $staff = collect([(object) [
                    'id'      => (int) $me->id,
                    'name'    => (string) ($me->name ?? 'Saya'),
                    'level'   => (string) ($me->level ?? ''),
                    'ao_code' => $selfAo,
                ]]);
            }
        }

        // ===== AO filter (optional) =====
        $aoFilter = trim((string) $request->query('ao', ''));
        $aoFilter = $aoFilter !== '' ? str_pad($aoFilter, 6, '0', STR_PAD_LEFT) : '';

        // AO options (dropdown)
        $aoOptions = $staff->map(function ($u) {
            return [
                'ao_code' => $u->ao_code,
                'label'   => "{$u->name} ({$u->level}) - {$u->ao_code}",
            ];
        })->values()->all();

        // staff scope by filter
        if ($aoFilter !== '') {
            $staff = $staff->filter(fn($u) => $u->ao_code === $aoFilter)->values();
        }

        $aoCodes = $staff->pluck('ao_code')->unique()->values()->all();
        $aoCount = count($aoCodes);

        /**
         * =========================================================
         * 3) LABELS tanggal lengkap untuk chart (biar bolong tetap ada)
         * =========================================================
         */
        $labels = [];
        $cursor = $from->copy();
        $end    = $to->copy();
        while ($cursor->lte($end)) {
            $labels[] = $cursor->toDateString();
            $cursor->addDay();
        }

        // latest date berdasarkan ujung range (tanggal $to)
        $latestDate    = count($labels) ? $labels[count($labels) - 1] : $to->toDateString();
        $latestPosDate = $to->toDateString(); // ✅ tabel bawah mengikuti filter/range

        /**
         * =========================================================
         * 4) DATA HARIAN KPI (per ao_code) dari kpi_os_daily_aos
         * =========================================================
         */
        $rows = DB::table('kpi_os_daily_aos')
            ->selectRaw("
                DATE(position_date) as d,
                LPAD(TRIM(ao_code),6,'0') as ao_code,
                ROUND(SUM(os_total)) as os_total,
                ROUND(SUM(os_l0)) as os_l0,
                ROUND(SUM(os_lt)) as os_lt
            ")
            ->whereBetween('position_date', [$from->toDateString(), $to->toDateString()])
            ->when(!empty($aoCodes), fn ($q) => $q->whereIn(DB::raw("LPAD(TRIM(ao_code),6,'0')"), $aoCodes))
            ->groupBy('d', 'ao_code')
            ->orderBy('d')
            ->get();

        // map[ao_code][date] = metrics
        $map = [];
        foreach ($rows as $r) {
            $map[$r->ao_code][$r->d] = [
                'os_total' => (int)($r->os_total ?? 0),
                'os_l0'    => (int)($r->os_l0 ?? 0),
                'os_lt'    => (int)($r->os_lt ?? 0),
            ];
        }

        /**
         * =========================================================
         * 5) SUMMARY: OS Terakhir (dari latestDate)
         * =========================================================
         */
        $latestOs = 0;
        if ($latestDate) {
            foreach ($aoCodes as $ao) {
                $latestOs += (int)($map[$ao][$latestDate]['os_total'] ?? 0);
            }
        }

        /**
         * =========================================================
         * 6) OS CLOSING BULAN LALU (snapshot monthly)
         *    sumber: loan_account_snapshots_monthly
         * =========================================================
         */
        $prevSnapMonth = Carbon::parse($latestPosDate)
            ->subMonthNoOverflow()
            ->startOfMonth()
            ->toDateString(); // YYYY-MM-01

        $osLastMonth = (int) DB::table('loan_account_snapshots_monthly as m')
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(m.ao_code),6,'0')"), $aoCodes))
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->sum('m.outstanding');

        $prevOsLabel = Carbon::parse($prevSnapMonth)->translatedFormat('F Y');

        $prevOs = $osLastMonth;
        $delta  = $latestOs - $prevOs;

        /**
         * =========================================================
         * 7) Inject OS terakhir ke staff untuk urut legend
         * =========================================================
         */
        $staff = $staff->map(function ($u) use ($map, $latestDate) {
            $u->os_latest = $latestDate ? (int)($map[$u->ao_code][$latestDate]['os_total'] ?? 0) : 0;
            return $u;
        })->sortByDesc('os_latest')->values();

        $aoCodes = $staff->pluck('ao_code')->unique()->values()->all();

        /**
         * =========================================================
         * 8) DATASETS CHART per metric
         * =========================================================
         */
        $datasetsByMetric = [
            'os_total' => [],
            'os_l0'    => [],
            'os_lt'    => [],
            'rr'       => [],
            'pct_lt'   => [],
        ];

        foreach ($staff as $u) {
            $seriesTotal = [];
            $seriesL0    = [];
            $seriesLT    = [];
            $seriesRR    = [];
            $seriesPctLT = [];

            foreach ($labels as $d) {
                $osTotal = $map[$u->ao_code][$d]['os_total'] ?? null;
                $osL0    = $map[$u->ao_code][$d]['os_l0'] ?? null;
                $osLT    = $map[$u->ao_code][$d]['os_lt'] ?? null;

                if ($osTotal === null && $osL0 === null && $osLT === null) {
                    $seriesTotal[] = null;
                    $seriesL0[]    = null;
                    $seriesLT[]    = null;
                    $seriesRR[]    = null;
                    $seriesPctLT[] = null;
                    continue;
                }

                $seriesTotal[] = (int)($osTotal ?? 0);
                $seriesL0[]    = (int)($osL0 ?? 0);
                $seriesLT[]    = (int)($osLT ?? 0);

                $den   = (int)($osTotal ?? 0);
                $rr    = $den > 0 ? ((int)($osL0 ?? 0) / $den) * 100 : null;
                $pctLt = $den > 0 ? ((int)($osLT ?? 0) / $den) * 100 : null;

                $seriesRR[]    = $rr === null ? null : round($rr, 2);
                $seriesPctLT[] = $pctLt === null ? null : round($pctLt, 2);
            }

            $label = "{$u->name} ({$u->level})";

            $datasetsByMetric['os_total'][] = ['key' => $u->ao_code, 'label' => $label, 'data' => $seriesTotal];
            $datasetsByMetric['os_l0'][]    = ['key' => $u->ao_code, 'label' => $label, 'data' => $seriesL0];
            $datasetsByMetric['os_lt'][]    = ['key' => $u->ao_code, 'label' => $label, 'data' => $seriesLT];
            $datasetsByMetric['rr'][]       = ['key' => $u->ao_code, 'label' => $label, 'data' => $seriesRR];
            $datasetsByMetric['pct_lt'][]   = ['key' => $u->ao_code, 'label' => $label, 'data' => $seriesPctLT];
        }

        /**
         * =========================================================
         * 9) VISIT META (Last visit + Planned Today) - no N+1
         * =========================================================
         */
        $subUserIds = $staff->pluck('id')->map(fn($v) => (int)$v)->values()->all();
        $today = now()->toDateString();

        $lastVisitMap = RoVisit::query()
            ->selectRaw('account_no, MAX(visit_date) as last_visit_date')
            ->groupBy('account_no')
            ->pluck('last_visit_date', 'account_no')
            ->toArray();

        $plannedTodayMap = RoVisit::query()
            ->select(['account_no', 'status', 'visit_date', 'user_id'])
            ->whereDate('visit_date', $today)
            ->when(!empty($subUserIds), fn($q) => $q->whereIn('user_id', $subUserIds))
            ->get()
            ->groupBy('account_no')
            ->map(fn($rows) => $rows->first());

        $attachVisitMeta = function ($rows) use ($lastVisitMap, $plannedTodayMap) {
            return collect($rows)->map(function ($r) use ($lastVisitMap, $plannedTodayMap) {
                $acc = (string)($r->account_no ?? '');
                $r->last_visit_date = $acc !== '' ? ($lastVisitMap[$acc] ?? null) : null;

                $row = ($acc !== '' && $plannedTodayMap->has($acc)) ? $plannedTodayMap->get($acc) : null;
                $r->planned_today   = $row ? 1 : 0;
                $r->plan_visit_date = $row ? (string)($row->visit_date ?? null) : null;
                $r->plan_status     = $row ? (string)($row->status ?? 'planned') : null;
                $r->planned_by_user_id = $row ? (int)($row->user_id ?? 0) : null;

                return $r;
            });
        };

        /**
         * =========================================================
         * 10) TABLES (loan_accounts posisi terbaru = $latestPosDate)
         * =========================================================
         */

        // 1) JT bulan ini
        $now = Carbon::parse($latestPosDate);
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd   = $now->copy()->endOfMonth()->toDateString();

        $dueThisMonth = DB::table('loan_accounts as la')
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as outstanding"),
                'la.maturity_date',
                'la.kolek',
                'la.dpd',
            ])
            ->whereNotNull('la.maturity_date')
            ->whereBetween('la.maturity_date', [$monthStart, $monthEnd])
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes))
            ->orderBy('la.maturity_date')
            ->orderByDesc('la.outstanding')
            ->limit(300)
            ->get();

        $dueThisMonth = $attachVisitMeta($dueThisMonth);

        /**
         * 2) ✅ LT EOM bulan lalu (snapshot cohort) -> status hari ini
         *    Ini menggantikan "LT posisi terakhir"
         */
        $ltEom = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', function ($j) use ($latestPosDate) {
                $j->on('la.account_no', '=', 'm.account_no')
                  ->whereDate('la.position_date', $latestPosDate);
            })
            ->leftJoin('users as u', DB::raw("LPAD(TRIM(u.ao_code),6,'0')"), '=', DB::raw("LPAD(TRIM(la.ao_code),6,'0')"))
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as os"),
                'la.ft_pokok',
                'la.ft_bunga',
                'la.dpd',
                'la.kolek',

                // ✅ snapshot EOM (basis cohort)
                DB::raw("COALESCE(m.ft_pokok,0) as eom_ft_pokok"),
                DB::raw("COALESCE(m.ft_bunga,0) as eom_ft_bunga"),

                // optional: nama RO/AO pemilik ao_code (buat TL)
                DB::raw("COALESCE(u.name,'') as ao_name"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes))
            ->where(function ($q) {
                $q->where('m.ft_pokok', 1)->orWhere('m.ft_bunga', 1); // cohort LT di EOM
            })
            // ordering: DPK dulu, lalu LT, lalu L0 (biar TL langsung lihat migrasi FE)
            ->orderByRaw("
                CASE
                  WHEN la.ft_pokok = 2 OR la.ft_bunga = 2 THEN 0
                  WHEN la.ft_pokok = 1 OR la.ft_bunga = 1 THEN 1
                  ELSE 2
                END
            ")
            ->orderByDesc('la.dpd')
            ->orderByDesc('la.outstanding')
            ->limit(400)
            ->get();

        $ltEom = $attachVisitMeta($ltEom);

        // 3) L0 -> LT bulan ini (monthly snapshot bulan lalu vs posisi terakhir)
        $perPage = (int) $request->query('per_page', 25);
        if ($perPage <= 0) $perPage = 25;
        if ($perPage > 200) $perPage = 200;

        $migrasiTunggakan = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw('ROUND(la.outstanding) as os'),
                'la.ft_pokok',
                'la.ft_bunga',
                'la.dpd',
                'la.kolek',
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes))
            ->where('m.ft_pokok', 0)
            ->where('m.ft_bunga', 0)
            ->where(function ($q) {
                $q->where('la.ft_pokok', '>', 0)->orWhere('la.ft_bunga', '>', 0);
            })
            ->orderByDesc('os')
            ->paginate($perPage)
            ->appends($request->query());

        $migrasiTunggakan->setCollection(
            $attachVisitMeta($migrasiTunggakan->getCollection())
        );

        // 4) JT angsuran minggu ini
        $weekStartC = Carbon::parse($latestPosDate)->startOfWeek(Carbon::MONDAY);
        $weekEndC   = Carbon::parse($latestPosDate)->endOfWeek(Carbon::SUNDAY);

        $weekStart = $weekStartC->toDateString();
        $weekEnd   = $weekEndC->toDateString();

        $ym = Carbon::parse($latestPosDate)->format('Y-m');
        $dueDateExpr = "STR_TO_DATE(CONCAT('$ym','-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')";

        $jtAngsuran = DB::table('loan_accounts as la')
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as os"),
                'la.installment_day',
                'la.ft_pokok',
                'la.ft_bunga',
                'la.dpd',
                'la.kolek',
                DB::raw("$dueDateExpr as due_date"),
            ])
            ->whereDate('la.position_date', $latestPosDate)
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes))
            ->whereNotNull('la.installment_day')
            ->where('la.installment_day', '>=', 1)
            ->where('la.installment_day', '<=', 31)
            ->whereBetween(DB::raw($dueDateExpr), [$weekStart, $weekEnd])
            ->orderBy(DB::raw($dueDateExpr))
            ->orderByDesc('la.outstanding')
            ->limit(300)
            ->get();

        $jtAngsuran = $attachVisitMeta($jtAngsuran);

        // 5) OS >= 500jt
        $bigThreshold = 500000000;

        $osBig = DB::table('loan_accounts as la')
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as os"),
                'la.ft_pokok',
                'la.ft_bunga',
                'la.dpd',
                'la.kolek',
            ])
            ->whereDate('la.position_date', $latestPosDate)
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes))
            ->where('la.outstanding', '>=', $bigThreshold)
            ->orderByDesc('la.outstanding')
            ->limit(300)
            ->get();

        $osBig = $attachVisitMeta($osBig);

        /**
         * =========================================================
         * 11) RETURN VIEW
         * =========================================================
         */
        return view('kpi.tl.os_daily', [
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),

            'labels'           => $labels,
            'datasetsByMetric' => $datasetsByMetric,

            'latestOs'    => $latestOs,
            'prevOs'      => $prevOs,
            'prevOsLabel' => $prevOsLabel,
            'delta'       => $delta,

            'staff'   => $staff,
            'aoCount' => $aoCount,

            'latestDate'    => $latestDate,
            'latestPosDate' => $latestPosDate,

            'aoOptions' => $aoOptions,
            'aoFilter'  => $aoFilter,

            'today' => $today,

            'dueThisMonth'   => $dueThisMonth,
            'dueMonthLabel'  => Carbon::parse($latestPosDate)->translatedFormat('F Y'),

            // ✅ tetap pakai key lama biar blade tidak banyak ubah
            'ltLatest' => $ltEom,

            'migrasiTunggakan' => $migrasiTunggakan,
            'prevSnapMonth'    => $prevSnapMonth,

            'weekStart'  => $weekStart,
            'weekEnd'    => $weekEnd,
            'jtAngsuran' => $jtAngsuran,

            'bigThreshold' => $bigThreshold,
            'osBig'        => $osBig,
        ]);
    }

    private function subordinateStaffForLeader(int $leaderUserId)
    {
        $subIds = OrgAssignment::query()
            ->active()
            ->where('leader_id', $leaderUserId)
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        if (empty($subIds)) return collect();

        return DB::table('users')
            ->select(['id', 'name', 'level', 'ao_code'])
            ->whereIn('id', $subIds)
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code) <> ''")
            ->orderBy('name')
            ->get()
            ->map(function ($u) {
                $u->ao_code = str_pad(trim((string) $u->ao_code), 6, '0', STR_PAD_LEFT);
                return $u;
            })
            ->filter(fn ($u) => $u->ao_code !== '' && $u->ao_code !== '000000')
            ->values();
    }
}
