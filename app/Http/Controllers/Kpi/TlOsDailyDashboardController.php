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
        $prevDate      = count($labels) >= 2 ? $labels[count($labels) - 2] : null;

        // untuk tabel bawah & compare loan_accounts, gunakan $to (posisi terakhir)
        $latestPosDate = $to->toDateString();

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
         * 8.5) CARDS AGREGAT (Latest + Growth H vs H-1)
         * =========================================================
         */
        $latestPack = ['os_total' => 0, 'os_l0' => 0, 'os_lt' => 0];
        $prevPack   = ['os_total' => 0, 'os_l0' => 0, 'os_lt' => 0];

        if ($latestDate) {
            foreach ($aoCodes as $ao) {
                $latestPack['os_total'] += (int)($map[$ao][$latestDate]['os_total'] ?? 0);
                $latestPack['os_l0']    += (int)($map[$ao][$latestDate]['os_l0'] ?? 0);
                $latestPack['os_lt']    += (int)($map[$ao][$latestDate]['os_lt'] ?? 0);
            }
        }

        if ($prevDate) {
            foreach ($aoCodes as $ao) {
                $prevPack['os_total'] += (int)($map[$ao][$prevDate]['os_total'] ?? 0);
                $prevPack['os_l0']    += (int)($map[$ao][$prevDate]['os_l0'] ?? 0);
                $prevPack['os_lt']    += (int)($map[$ao][$prevDate]['os_lt'] ?? 0);
            }
        } else {
            $prevPack = ['os_total' => null, 'os_l0' => null, 'os_lt' => null];
        }

        $latestRR = ($latestPack['os_total'] ?? 0) > 0 ? round(($latestPack['os_l0'] / $latestPack['os_total']) * 100, 2) : null;
        $latestPL = ($latestPack['os_total'] ?? 0) > 0 ? round(($latestPack['os_lt'] / $latestPack['os_total']) * 100, 2) : null;

        $prevRR = (!is_null($prevPack['os_total']) && (int)$prevPack['os_total'] > 0) ? round(($prevPack['os_l0'] / $prevPack['os_total']) * 100, 2) : null;
        $prevPL = (!is_null($prevPack['os_total']) && (int)$prevPack['os_total'] > 0) ? round(($prevPack['os_lt'] / $prevPack['os_total']) * 100, 2) : null;

        $cards = [
            'os' => ['value' => (int)($latestPack['os_total'] ?? 0), 'delta' => is_null($prevPack['os_total']) ? null : ((int)$latestPack['os_total'] - (int)$prevPack['os_total'])],
            'l0' => ['value' => (int)($latestPack['os_l0'] ?? 0),    'delta' => is_null($prevPack['os_l0'])    ? null : ((int)$latestPack['os_l0']    - (int)$prevPack['os_l0'])],
            'lt' => ['value' => (int)($latestPack['os_lt'] ?? 0),    'delta' => is_null($prevPack['os_lt'])    ? null : ((int)$latestPack['os_lt']    - (int)$prevPack['os_lt'])],
            // RR/%LT delta dalam "points"
            'rr'     => ['value' => $latestRR, 'delta' => is_null($prevRR) ? null : round($latestRR - $prevRR, 2)],
            'pct_lt' => ['value' => $latestPL, 'delta' => is_null($prevPL) ? null : round($latestPL - $prevPL, 2)],
        ];

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
         * 9.5) PREV POSITION DATE (loan_accounts) untuk bounce compare
         * - cari tanggal posisi terakhir sebelum $latestPosDate (yang benar-benar ada)
         * =========================================================
         */
        $prevPosDate = DB::table('loan_accounts')
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(ao_code),6,'0')"), $aoCodes))
            ->whereDate('position_date', '<', $latestPosDate)
            ->max('position_date');

        $prevPosDate = $prevPosDate ? Carbon::parse($prevPosDate)->toDateString() : null;

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
                DB::raw("COALESCE(m.ft_pokok,0) as eom_ft_pokok"),
                DB::raw("COALESCE(m.ft_bunga,0) as eom_ft_bunga"),
                DB::raw("COALESCE(u.name,'') as ao_name"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes))
            ->where(function ($q) {
                $q->where('m.ft_pokok', 1)->orWhere('m.ft_bunga', 1);
            })
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

        // 3) L0 -> LT bulan ini
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
         * 10.5) ✅ Bounce & JT Next2 Lists (untuk TLRO)
         * - bounce list: LT(prevPosDate) -> L0(latestPosDate)
         * - JT next2: due_date (installment) H..H+2 (robust lintas bulan)
         * =========================================================
         */
        $bounce = [
            'prev_pos_date' => $prevPosDate,
            'lt_to_l0_noa'  => 0,
            'lt_to_l0_os'   => 0,
            'jt_next2_noa'  => 0,
            'jt_next2_os'   => 0,
            'signal_bounce_risk' => false,
        ];

        $ltToL0List = collect();
        $jtNext2List = collect();
        $topRiskTomorrow = collect();

        // JT next2 date range
        $posC = Carbon::parse($latestPosDate);
        $jtStart = $posC->copy()->toDateString();               // termasuk hari ini
        $jtEnd   = $posC->copy()->addDays(2)->toDateString();   // sampai H+2

        // robust due_date for installment_day (lintas bulan):
        // if installment_day < day(pos) => pakai bulan depan, else bulan ini
        $ymThis = $posC->format('Y-m');
        $ymNext = $posC->copy()->addMonthNoOverflow()->format('Y-m');
        $dayPos = (int)$posC->format('d');

        $dueDateExpr2 = "
            CASE
              WHEN la.installment_day IS NULL THEN NULL
              WHEN la.installment_day < {$dayPos}
                THEN STR_TO_DATE(CONCAT('{$ymNext}','-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')
              ELSE STR_TO_DATE(CONCAT('{$ymThis}','-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')
            END
        ";

        // A) LT -> L0 list (butuh prevPosDate)
        if ($prevPosDate) {
            $ltToL0List = DB::table('loan_accounts as cur')
                ->join('loan_accounts as prev', function ($j) use ($prevPosDate) {
                    $j->on('prev.account_no', '=', 'cur.account_no')
                      ->whereDate('prev.position_date', $prevPosDate);
                })
                ->select([
                    'cur.account_no',
                    'cur.customer_name',
                    DB::raw("LPAD(TRIM(cur.ao_code),6,'0') as ao_code"),
                    DB::raw("ROUND(cur.outstanding) as os"),
                    'cur.dpd',
                    'cur.kolek',
                    'cur.ft_pokok',
                    'cur.ft_bunga',
                    DB::raw("ROUND(prev.outstanding) as prev_os"),
                    'prev.dpd as prev_dpd',
                    'prev.kolek as prev_kolek',
                    'prev.ft_pokok as prev_ft_pokok',
                    'prev.ft_bunga as prev_ft_bunga',
                ])
                ->whereDate('cur.position_date', $latestPosDate)
                ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(cur.ao_code),6,'0')"), $aoCodes))
                // prev = LT (ft>0), cur = L0 (ft=0)
                ->where(function ($q) {
                    $q->where('prev.ft_pokok', '>', 0)->orWhere('prev.ft_bunga', '>', 0);
                })
                ->where('cur.ft_pokok', 0)
                ->where('cur.ft_bunga', 0)
                ->orderByDesc(DB::raw("ROUND(cur.outstanding)"))
                ->limit(30)
                ->get();

            $ltToL0List = $attachVisitMeta($ltToL0List);

            $bounce['lt_to_l0_noa'] = $ltToL0List->count();
            $bounce['lt_to_l0_os']  = (int) $ltToL0List->sum(fn($r) => (int)($r->os ?? 0));
        }

        // B) JT next2 list (posisi latestPosDate)
        $jtNext2List = DB::table('loan_accounts as la')
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as os"),
                'la.installment_day',
                DB::raw("$dueDateExpr2 as due_date"),
                'la.dpd',
                'la.kolek',
                'la.ft_pokok',
                'la.ft_bunga',
            ])
            ->whereDate('la.position_date', $latestPosDate)
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes))
            ->whereNotNull('la.installment_day')
            ->where('la.installment_day', '>=', 1)
            ->where('la.installment_day', '<=', 31)
            ->whereBetween(DB::raw($dueDateExpr2), [$jtStart, $jtEnd])
            ->orderBy(DB::raw($dueDateExpr2))
            ->orderByDesc('la.outstanding')
            ->limit(30)
            ->get();

        $jtNext2List = $attachVisitMeta($jtNext2List);

        $bounce['jt_next2_noa'] = $jtNext2List->count();
        $bounce['jt_next2_os']  = (int) $jtNext2List->sum(fn($r) => (int)($r->os ?? 0));

        // C) signal bounce risk (rule TLRO):
        // L0 naik & LT turun (vs prevDate) + JT next2 ada
        $l0Up = !is_null($cards['l0']['delta']) && (int)$cards['l0']['delta'] > 0;
        $ltDown = !is_null($cards['lt']['delta']) && (int)$cards['lt']['delta'] < 0;
        $bounce['signal_bounce_risk'] = ($l0Up && $ltDown && $bounce['jt_next2_noa'] > 0);

        // D) mini table "Top Risiko Besok"
        // logic: prioritas
        // 1) cure LT->L0 yang JT next2 (paling rawan bounce)
        // 2) JT next2 yang sudah DPD>0 atau masih FT (indikasi gagal bayar)
        $jtIndex = $jtNext2List->keyBy(fn($r) => (string)$r->account_no);

        $riskRows = collect();

        foreach ($ltToL0List as $r) {
            $acc = (string)($r->account_no ?? '');
            $jt = $acc !== '' ? ($jtIndex[$acc] ?? null) : null;
            if (!$jt) continue;

            $riskRows->push((object)[
                'account_no' => $acc,
                'customer_name' => (string)($r->customer_name ?? ''),
                'ao_code' => (string)($r->ao_code ?? ''),
                'os' => (int)($r->os ?? 0),
                'due_date' => (string)($jt->due_date ?? null),
                'dpd' => (int)($jt->dpd ?? 0),
                'kolek' => (string)($jt->kolek ?? ''),
                'ft_flag' => ((int)($jt->ft_pokok ?? 0) > 0 || (int)($jt->ft_bunga ?? 0) > 0) ? 1 : 0,
                'risk_reason' => 'Cure LT→L0 + JT dekat (rawan balik LT)',
                'last_visit_date' => $r->last_visit_date ?? null,
                'planned_today'   => $r->planned_today ?? 0,
                'plan_visit_date' => $r->plan_visit_date ?? null,
                'plan_status'     => $r->plan_status ?? null,
            ]);
        }

        foreach ($jtNext2List as $r) {
            $acc = (string)($r->account_no ?? '');
            // skip jika sudah masuk dari cure+JT
            if ($riskRows->firstWhere('account_no', $acc)) continue;

            $ft = ((int)($r->ft_pokok ?? 0) > 0 || (int)($r->ft_bunga ?? 0) > 0);
            $dpd = (int)($r->dpd ?? 0);

            if ($dpd > 0 || $ft) {
                $riskRows->push((object)[
                    'account_no' => $acc,
                    'customer_name' => (string)($r->customer_name ?? ''),
                    'ao_code' => (string)($r->ao_code ?? ''),
                    'os' => (int)($r->os ?? 0),
                    'due_date' => (string)($r->due_date ?? null),
                    'dpd' => $dpd,
                    'kolek' => (string)($r->kolek ?? ''),
                    'ft_flag' => $ft ? 1 : 0,
                    'risk_reason' => $ft ? 'JT dekat + masih FT (indikasi risiko)' : 'JT dekat + DPD>0 (indikasi risiko)',
                    'last_visit_date' => $r->last_visit_date ?? null,
                    'planned_today'   => $r->planned_today ?? 0,
                    'plan_visit_date' => $r->plan_visit_date ?? null,
                    'plan_status'     => $r->plan_status ?? null,
                ]);
            }
        }

        // LT cohort (EOM prev month) -> DPK today
        $prevEomMonth = Carbon::parse($latestPosDate)
            ->subMonthNoOverflow()
            ->startOfMonth()
            ->toDateString(); // contoh: 2026-01-01

        $ltToDpkQ = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', function ($j) use ($latestPosDate) {
                $j->on('la.account_no', '=', 'm.account_no')
                ->whereDate('la.position_date', $latestPosDate);
            })
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            // cohort LT di EOM
            ->where(function ($q) {
                $q->where('m.ft_pokok', 1)->orWhere('m.ft_bunga', 1);
            })
            // status hari ini = DPK
            ->where(function ($q) {
                $q->where('la.ft_pokok', 2)->orWhere('la.ft_bunga', 2)->orWhere('la.kolek', 2);
            })
            // ✅ WAJIB: scope TL (aoCodes bawahan)
            ->when(!empty($aoCodes), fn($q) =>
                $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes)
            );

        $bounce['lt_to_dpk_noa'] = (int) (clone $ltToDpkQ)->count();
        $bounce['lt_to_dpk_os']  = (int) (clone $ltToDpkQ)->sum('la.outstanding');

        $ltToDpkList = (clone $ltToDpkQ)
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as os"),
                'la.dpd',
                'la.kolek',
                'la.ft_pokok',
                'la.ft_bunga',
                DB::raw("COALESCE(m.ft_pokok,0) as eom_ft_pokok"),
                DB::raw("COALESCE(m.ft_bunga,0) as eom_ft_bunga"),
            ])
            ->orderByDesc(DB::raw("ROUND(la.outstanding)"))
            ->limit(50)
            ->get();

        // kalau mau meta visit juga:
        $ltToDpkList = $attachVisitMeta($ltToDpkList);

        // detail list (top by OS)
        // $ltToDpkList = $ltToDpkQ->clone()
        // ->select([
        //     'la.account_no','la.customer_name','la.ao_code','la.os','la.dpd','la.kolek',
        //     'la.ft_pokok','la.ft_bunga','la.last_visit_date'
        // ])
        // ->orderByDesc('la.os')
        // ->limit(50)
        // ->get();

        $topRiskTomorrow = $riskRows
            ->sortByDesc(fn($r) => (int)($r->os ?? 0))
            ->take(12)
            ->values();

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
            'prevDate'      => $prevDate,
            'latestPosDate' => $latestPosDate,
            'prevPosDate'   => $prevPosDate,

            'aoOptions' => $aoOptions,
            'aoFilter'  => $aoFilter,

            'today' => $today,

            // cards + bounce summary
            'cards'  => $cards,
            'bounce' => $bounce,

            // lists TLRO
            'ltToL0List'      => $ltToL0List,
            'jtNext2List'     => $jtNext2List,
            'topRiskTomorrow' => $topRiskTomorrow,

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

            // JT next2 range label
            'jtNext2Start' => $jtStart,
            'jtNext2End'   => $jtEnd,

            'ltToDpkList' => $ltToDpkList,
            'prevEomMonth' => $prevEomMonth,
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
