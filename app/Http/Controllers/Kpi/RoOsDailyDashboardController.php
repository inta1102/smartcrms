<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class RoOsDailyDashboardController extends Controller
{
    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $uid = (int) $me->id;
        $ao  = str_pad(trim((string)($me->ao_code ?? '')), 6, '0', STR_PAD_LEFT);
        abort_unless($ao !== '' && $ao !== '000000', 403);

        /**
         * =========================================================
         * 1) RANGE DEFAULT
         * =========================================================
         */
        $latestInKpi = DB::table('kpi_os_daily_aos')->max('position_date');
        $latestInKpi = $latestInKpi ? Carbon::parse($latestInKpi)->startOfDay() : now()->startOfDay();

        $lastMonthEndC = Carbon::now()->subMonthNoOverflow()->endOfMonth()->startOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : $lastMonthEndC->copy()->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->startOfDay()
            : $latestInKpi->copy()->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->startOfDay()];
        }

        $mode = $request->input('mode', 'mtd');
        $mode = in_array($mode, ['mtd', 'h'], true) ? $mode : 'mtd';

        /**
         * =========================================================
         * 2) LABELS RANGE
         * =========================================================
         */
        $labels = [];
        $cursor = $from->copy()->startOfDay();
        $end    = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $labels[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $latestPosDateFallback = count($labels)
            ? $labels[count($labels) - 1]
            : $latestInKpi->toDateString();

        $prevSnapMonth = Carbon::parse($latestPosDateFallback)
            ->subMonthNoOverflow()
            ->startOfMonth()
            ->toDateString();

        /**
         * =========================================================
         * 3) DATA KPI HARIAN
         * =========================================================
         */
        $rows = DB::table('kpi_os_daily_aos')
            ->selectRaw("
                DATE(position_date) as d,
                ROUND(SUM(os_total)) as os_total,
                ROUND(SUM(os_l0))    as os_l0,
                ROUND(SUM(os_lt))    as os_lt,
                ROUND(SUM(os_dpk))   as os_dpk
            ")
            ->whereDate('position_date', '>=', $from->toDateString())
            ->whereDate('position_date', '<=', $to->toDateString())
            ->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [$ao])
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $byDate = [];
        foreach ($rows as $r) {
            $osTotal = (int)($r->os_total ?? 0);
            $osL0    = (int)($r->os_l0 ?? 0);
            $osLt    = (int)($r->os_lt ?? 0);
            $osDpk   = (int)($r->os_dpk ?? 0);

            $rr      = ($osTotal > 0) ? round(($osL0 / $osTotal) * 100, 2) : null;
            $pctLt   = ($osTotal > 0) ? round(($osLt / $osTotal) * 100, 2) : null;
            $pctDpk  = ($osTotal > 0) ? round(($osDpk / $osTotal) * 100, 2) : null;

            $byDate[(string)$r->d] = (object) [
                'os_total' => $osTotal,
                'os_l0'    => $osL0,
                'os_lt'    => $osLt,
                'os_dpk'   => $osDpk,
                'rr'       => $rr,
                'pct_lt'   => $pctLt,
                'pct_dpk'  => $pctDpk,
            ];
        }

        $series = [
            'os_total' => [],
            'os_l0'    => [],
            'os_lt'    => [],
            'os_dpk'   => [],
            'rr'       => [],
            'pct_lt'   => [],
            'pct_dpk'  => [],
        ];

        foreach ($labels as $d) {
            $series['os_total'][] = $byDate[$d]->os_total ?? null;
            $series['os_l0'][]    = $byDate[$d]->os_l0 ?? null;
            $series['os_lt'][]    = $byDate[$d]->os_lt ?? null;
            $series['os_dpk'][]   = $byDate[$d]->os_dpk ?? null;
            $series['rr'][]       = $byDate[$d]->rr ?? null;
            $series['pct_lt'][]   = $byDate[$d]->pct_lt ?? null;
            $series['pct_dpk'][]  = $byDate[$d]->pct_dpk ?? null;
        }

        /**
         * =========================================================
         * 4) LATEST & PREV SNAPSHOT AVAILABLE
         * =========================================================
         */
        $latestDate = null;
        if (!empty($byDate)) {
            $datesWithData = array_keys($byDate);
            sort($datesWithData);
            $latestDate = end($datesWithData) ?: null;
        }

        $prevAvailDate = null;
        if ($latestDate) {
            for ($i = count($labels) - 1; $i >= 0; $i--) {
                $d = $labels[$i] ?? null;
                if (!$d || $d === $latestDate) {
                    continue;
                }
                if (isset($byDate[$d])) {
                    $prevAvailDate = $d;
                    break;
                }
            }
        }

        $latestPack = $latestDate ? ($byDate[$latestDate] ?? null) : null;
        $prevPack   = $prevAvailDate ? ($byDate[$prevAvailDate] ?? null) : null;
        $prevDate   = $prevAvailDate;

        $latestPosDate = $latestDate
            ? Carbon::parse($latestDate)->toDateString()
            : $latestInKpi->toDateString();

        $prevSnapMonth = Carbon::parse($latestPosDate)
            ->subMonthNoOverflow()
            ->startOfMonth()
            ->toDateString();

        $prevPosDate = $prevDate ?: Carbon::parse($latestPosDate)->subDay()->toDateString();
        $today       = now()->toDateString();

        /**
         * =========================================================
         * 5) CARDS H vs H-1
         * =========================================================
         */
        $latestOs      = (int)($latestPack->os_total ?? 0);
        $latestL0      = (int)($latestPack->os_l0 ?? 0);
        $latestLT      = (int)($latestPack->os_lt ?? 0);
        $latestDPK     = (int)($latestPack->os_dpk ?? 0);
        $latestRR      = $latestPack->rr ?? null;
        $latestPctLt   = $latestPack->pct_lt ?? null;
        $latestPctDpk  = $latestPack->pct_dpk ?? null;

        $prevOs        = $prevPack->os_total ?? null;
        $prevL0        = $prevPack->os_l0 ?? null;
        $prevLT        = $prevPack->os_lt ?? null;
        $prevDPK       = $prevPack->os_dpk ?? null;
        $prevRR        = $prevPack->rr ?? null;
        $prevPctLt     = $prevPack->pct_lt ?? null;
        $prevPctDpk    = $prevPack->pct_dpk ?? null;

        $deltaOs       = is_null($prevOs) ? null : ($latestOs - $prevOs);
        $deltaL0       = is_null($prevL0) ? null : ($latestL0 - $prevL0);
        $deltaLT       = is_null($prevLT) ? null : ($latestLT - $prevLT);
        $deltaDPK      = is_null($prevDPK) ? null : ($latestDPK - $prevDPK);
        $deltaRR       = (is_null($prevRR) || is_null($latestRR)) ? null : round($latestRR - $prevRR, 2);
        $deltaPctLt    = (is_null($prevPctLt) || is_null($latestPctLt)) ? null : round($latestPctLt - $prevPctLt, 2);
        $deltaPctDpk   = (is_null($prevPctDpk) || is_null($latestPctDpk)) ? null : round($latestPctDpk - $prevPctDpk, 2);

        $cards = [
            'os' => [
                'label' => 'OS',
                'value' => $latestOs,
                'base'  => $prevOs,
                'delta' => $deltaOs,
            ],
            'l0' => [
                'label' => 'L0',
                'value' => $latestL0,
                'base'  => $prevL0,
                'delta' => $deltaL0,
                'extra' => [
                    'rr' => [
                        'label' => 'RR',
                        'value' => $latestRR,
                        'base'  => $prevRR,
                        'delta' => $deltaRR,
                    ],
                ],
            ],
            'lt' => [
                'label' => 'LT',
                'value' => $latestLT,
                'base'  => $prevLT,
                'delta' => $deltaLT,
                'extra' => [
                    'pct_lt' => [
                        'label' => '%LT',
                        'value' => $latestPctLt,
                        'base'  => $prevPctLt,
                        'delta' => $deltaPctLt,
                    ],
                ],
            ],
            'dpk' => [
                'label' => 'DPK',
                'value' => $latestDPK,
                'base'  => $prevDPK,
                'delta' => $deltaDPK,
                'extra' => [
                    'pct_dpk' => [
                        'label' => '%DPK',
                        'value' => $latestPctDpk,
                        'base'  => $prevPctDpk,
                        'delta' => $deltaPctDpk,
                    ],
                ],
            ],
        ];

        /**
         * =========================================================
         * 6) CARDS MTD (EOM bulan lalu vs latest)
         *    DEFINISI TERBARU
         * =========================================================
         */
        $eomMonth = Carbon::parse($latestPosDate)
            ->subMonthNoOverflow()
            ->startOfMonth()
            ->toDateString();

        $sqlL0m  = $this->sqlBucketL0('m');
        $sqlLTm  = $this->sqlBucketLt('m');
        $sqlDPKm = $this->sqlBucketDpk('m');

        $eomAgg = DB::table('loan_account_snapshots_monthly as m')
            ->selectRaw("
                ROUND(SUM(m.outstanding)) as os,
                ROUND(SUM(CASE WHEN {$sqlL0m}  THEN m.outstanding ELSE 0 END)) as l0,
                ROUND(SUM(CASE WHEN {$sqlLTm}  THEN m.outstanding ELSE 0 END)) as lt,
                ROUND(SUM(CASE WHEN {$sqlDPKm} THEN m.outstanding ELSE 0 END)) as dpk
            ")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereRaw("LPAD(TRIM(m.ao_code),6,'0') = ?", [$ao])
            ->first();

        $lastAgg = DB::table('kpi_os_daily_aos as d')
            ->selectRaw("
                ROUND(SUM(d.os_total)) as os,
                ROUND(SUM(d.os_l0)) as l0,
                ROUND(SUM(d.os_lt)) as lt,
                ROUND(SUM(d.os_dpk)) as dpk
            ")
            ->whereDate('d.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(d.ao_code),6,'0') = ?", [$ao])
            ->first();

        $eomOs = (int)($eomAgg->os ?? 0);
        $eomL0 = (int)($eomAgg->l0 ?? 0);
        $eomLt = (int)($eomAgg->lt ?? 0);
        $eomDpk = (int)($eomAgg->dpk ?? 0);

        $lastOs = (int)($lastAgg->os ?? 0);
        $lastL0 = (int)($lastAgg->l0 ?? 0);
        $lastLt = (int)($lastAgg->lt ?? 0);
        $lastDpk = (int)($lastAgg->dpk ?? 0);

        $eomRr = $eomOs > 0 ? round(($eomL0 / $eomOs) * 100, 2) : null;
        $eomPl = $eomOs > 0 ? round(($eomLt / $eomOs) * 100, 2) : null;
        $eomPctDpk = $eomOs > 0 ? round(($eomDpk / $eomOs) * 100, 2) : null;

        $lastRr = $lastOs > 0 ? round(($lastL0 / $lastOs) * 100, 2) : null;
        $lastPl = $lastOs > 0 ? round(($lastLt / $lastOs) * 100, 2) : null;
        $lastPctDpk = $lastOs > 0 ? round(($lastDpk / $lastOs) * 100, 2) : null;

        $cardsMtd = [
            'os' => [
                'label' => 'OS',
                'value' => $lastOs,
                'base'  => $eomOs,
                'delta' => $lastOs - $eomOs,
            ],
            'l0' => [
                'label' => 'L0',
                'value' => $lastL0,
                'base'  => $eomL0,
                'delta' => $lastL0 - $eomL0,
                'extra' => [
                    'rr' => [
                        'label' => 'RR',
                        'value' => $lastRr,
                        'base'  => $eomRr,
                        'delta' => (is_null($lastRr) || is_null($eomRr)) ? null : round($lastRr - $eomRr, 2),
                    ],
                ],
            ],
            'lt' => [
                'label' => 'LT',
                'value' => $lastLt,
                'base'  => $eomLt,
                'delta' => $lastLt - $eomLt,
                'extra' => [
                    'pct_lt' => [
                        'label' => '%LT',
                        'value' => $lastPl,
                        'base'  => $eomPl,
                        'delta' => (is_null($lastPl) || is_null($eomPl)) ? null : round($lastPl - $eomPl, 2),
                    ],
                ],
            ],
            'dpk' => [
                'label' => 'DPK',
                'value' => $lastDpk,
                'base'  => $eomDpk,
                'delta' => $lastDpk - $eomDpk,
                'extra' => [
                    'pct_dpk' => [
                        'label' => '%DPK',
                        'value' => $lastPctDpk,
                        'base'  => $eomPctDpk,
                        'delta' => (is_null($lastPctDpk) || is_null($eomPctDpk)) ? null : round($lastPctDpk - $eomPctDpk, 2),
                    ],
                ],
            ],
        ];

        /**
         * =========================================================
         * 7) DATASET CHART
         * =========================================================
         */
        $get = function (string $d, string $col) use ($byDate) {
            if (!isset($byDate[$d])) {
                return null;
            }
            $v = $byDate[$d]->{$col} ?? null;
            return is_numeric($v) ? (float) $v : null;
        };

        $osTotal = array_map(fn($d) => $get($d, 'os_total'), $labels);
        $osL0    = array_map(fn($d) => $get($d, 'os_l0'), $labels);
        $osLt    = array_map(fn($d) => $get($d, 'os_lt'), $labels);

        $rrL0 = [];
        $pctLt = [];
        foreach ($labels as $d) {
            $osT = $get($d, 'os_total');
            $l0  = $get($d, 'os_l0');
            $lt  = $get($d, 'os_lt');

            $rrL0[]  = ($osT && $l0 !== null) ? round(($l0 / $osT) * 100, 2) : null;
            $pctLt[] = ($osT && $lt !== null) ? round(($lt / $osT) * 100, 2) : null;
        }

        $datasetsByMetric = [
            'os_total' => [[ 'label' => 'OS Total', 'data' => $osTotal ]],
            'os_l0'    => [[ 'label' => 'OS L0',    'data' => $osL0 ]],
            'os_lt'    => [[ 'label' => 'OS LT',    'data' => $osLt ]],
            'rr'       => [[ 'label' => 'RR (% L0)','data' => $rrL0 ]],
            'pct_lt'   => [[ 'label' => '% LT',     'data' => $pctLt ]],
        ];

        /**
         * =========================================================
         * 8) SQL HELPER SUBQUERY CEPAT
         * =========================================================
         */
        $bucketSql = $this->bucketSql();

        // Planned hari ini dari RKH
        $subPlanToday = $this->subPlanToday($uid, $today);

        // Last visit per account dari RKH Visit Logs
        $subLastVisitMeta = $this->subLastVisitMeta();

        // Latest posisi hari ini keyed by normalized account
        $subLaLatestAccKey = DB::table('loan_accounts')
            ->whereDate('position_date', $latestPosDate)
            ->selectRaw("
                TRIM(LEADING '0' FROM account_no) as acc_key,
                account_no,
                customer_name,
                LPAD(TRIM(ao_code),6,'0') as ao_code,
                ROUND(outstanding) as os,
                ft_pokok,
                ft_bunga,
                dpd,
                kolek,
                position_date
            ");

        // Posisi H-1 keyed by normalized account
        $subLaPrevAccKey = DB::table('loan_accounts')
            ->whereDate('position_date', $prevPosDate)
            ->selectRaw("
                TRIM(LEADING '0' FROM account_no) as acc_key,
                COALESCE(ft_pokok,0) as prev_ft_pokok,
                COALESCE(ft_bunga,0) as prev_ft_bunga,
                COALESCE(kolek,0) as prev_kolek
            ");

        // Planned hari ini keyed by normalized account
        $subPlanTodayAccKey = DB::table('rkh_headers as h')
            ->join('rkh_details as d', 'd.rkh_id', '=', 'h.id')
            ->where('h.user_id', $uid)
            ->whereDate('h.tanggal', $today)
            ->selectRaw("
                TRIM(LEADING '0' FROM d.account_no) as acc_key,
                1 as planned_today,
                MAX(h.tanggal) as plan_visit_date,
                MAX(h.status) as plan_status
            ")
            ->groupBy('acc_key');

        // Last visit keyed by normalized account dari RKH logs
        $subLastVisitAccKey = DB::query()
            ->fromSub($subLastVisitMeta, 'lv0')
            ->selectRaw("
                TRIM(LEADING '0' FROM lv0.account_no) as acc_key,
                lv0.last_visit_at,
                lv0.hasil_kunjungan
            ");

        /**
         * =========================================================
         * 9) INSIGHT FEASIBLE / BOUNCE AGG
         * =========================================================
         */
        $sqlL0m2   = $this->sqlBucketL0('m');
        $sqlLTla   = $this->sqlBucketLt('la');
        $sqlLTp    = $this->sqlBucketLt('p');
        $sqlL0t    = $this->sqlBucketL0('t');
        $sqlDPKt   = $this->sqlBucketDpk('t');
        $sqlDPKla  = $this->sqlBucketDpk('la');
        $sqlLTm3   = $this->sqlBucketLt('m');

        $l0ToLtAgg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(la.outstanding)) as os
            ")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereRaw($sqlL0m2)
            ->whereRaw($sqlLTla)
            ->first();

        $l0ToLtNoa = (int)($l0ToLtAgg->noa ?? 0);
        $l0ToLtOs  = (int)($l0ToLtAgg->os ?? 0);

        $ltToL0Agg = DB::table('loan_accounts as t')
            ->join('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 't.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(t.outstanding)) as os
            ")
            ->whereDate('t.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(t.ao_code),6,'0') = ?", [$ao])
            ->whereRaw($sqlLTp)
            ->whereRaw($sqlL0t)
            ->first();

        $ltToL0Noa = (int)($ltToL0Agg->noa ?? 0);
        $ltToL0Os  = (int)($ltToL0Agg->os ?? 0);

        $ltToDpkAgg = DB::table('loan_accounts as t')
            ->join('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 't.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(t.outstanding)) as os
            ")
            ->whereDate('t.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(t.ao_code),6,'0') = ?", [$ao])
            ->whereRaw($sqlLTp)
            ->whereRaw($sqlDPKt)
            ->first();

        $ltToDpkNoaDaily = (int)($ltToDpkAgg->noa ?? 0);
        $ltToDpkOsDaily  = (int)($ltToDpkAgg->os ?? 0);

        $pos = Carbon::parse($latestPosDate);
        $d1  = $pos->copy()->addDay()->toDateString();
        $d2  = $pos->copy()->addDays(2)->toDateString();

        $posLiteral = $pos->toDateString();
        $dueBase = "STR_TO_DATE(CONCAT(DATE_FORMAT('$posLiteral','%Y-%m'),'-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')";
        $dueNext = "STR_TO_DATE(CONCAT(DATE_FORMAT(DATE_ADD('$posLiteral', INTERVAL 1 MONTH),'%Y-%m'),'-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')";
        $dueSmart = "(
            CASE
              WHEN $dueBase IS NULL THEN $dueNext
              WHEN $dueBase < '$posLiteral' THEN $dueNext
              ELSE $dueBase
            END
        )";

        $jtNext2Agg = DB::table('loan_accounts as la')
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(la.outstanding)) as os
            ")
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereNotNull('la.installment_day')
            ->where('la.installment_day', '>=', 1)
            ->where('la.installment_day', '<=', 31)
            ->whereBetween(DB::raw($dueSmart), [$d1, $d2])
            ->first();

        $jtNext2Noa = (int)($jtNext2Agg->noa ?? 0);
        $jtNext2Os  = (int)($jtNext2Agg->os ?? 0);

        $ltEomToDpkAgg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("COUNT(*) as noa, ROUND(COALESCE(SUM(la.outstanding),0)) as os")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereRaw($sqlLTm3)
            ->whereRaw($sqlDPKla)
            ->first();

        $ltEomToDpkNoa = (int)($ltEomToDpkAgg->noa ?? 0);
        $ltEomToDpkOs  = (int)($ltEomToDpkAgg->os ?? 0);

        $ltEomToL0Agg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("COUNT(*) as noa, ROUND(SUM(la.outstanding)) as os")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereRaw($sqlLTm3)
            ->whereRaw($this->sqlBucketL0('la'))
            ->first();

        $ltEomToL0Noa = (int)($ltEomToL0Agg->noa ?? 0);
        $ltEomToL0Os  = (int)($ltEomToL0Agg->os ?? 0);

        /**
         * =========================================================
         * 10) DETAIL LIST - OPTIMIZED
         * =========================================================
         */
        // 10A. LT EOM -> DPK
        $ltEomToDpk = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->leftJoinSub($subLastVisitMeta, 'lv', function ($j) {
                $j->on(DB::raw("TRIM(lv.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("ROUND(la.outstanding) as os"),
                'la.dpd',
                'la.kolek',
                'la.ft_pokok',
                'la.ft_bunga',

                DB::raw("COALESCE(m.ft_pokok,0) as eom_ft_pokok"),
                DB::raw("COALESCE(m.ft_bunga,0) as eom_ft_bunga"),
                DB::raw("COALESCE(m.kolek,0) as eom_kolek"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereRaw($this->sqlBucketLt('m'))
            ->whereRaw($this->sqlBucketDpk('la'))
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        // 10B. JT bulan ini
        $now        = Carbon::parse($latestPosDate);
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd   = $now->copy()->endOfMonth()->toDateString();

        $dueThisMonth = DB::table('loan_accounts as la')
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->leftJoinSub($subLastVisitMeta, 'lv', function ($j) {
                $j->on(DB::raw("TRIM(lv.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as outstanding"),
                'la.maturity_date',
                'la.dpd',
                'la.kolek',
                'la.ft_pokok',
                'la.ft_bunga',

                DB::raw("COALESCE(p.ft_pokok,0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga,0) as prev_ft_bunga"),
                DB::raw("COALESCE(p.kolek,0) as prev_kolek"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereDate('la.position_date', $latestPosDate)
            ->whereNotNull('la.maturity_date')
            ->whereBetween('la.maturity_date', [$monthStart, $monthEnd])
            ->orderBy('la.maturity_date')
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        // 10C. LT EOM bulan lalu -> status terakhir
        $ltEom = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->leftJoinSub($subLastVisitMeta, 'lv', function ($j) {
                $j->on(DB::raw("TRIM(lv.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
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
                DB::raw("COALESCE(m.kolek,0) as eom_kolek"),

                DB::raw("COALESCE(p.ft_pokok,0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga,0) as prev_ft_bunga"),
                DB::raw("COALESCE(p.kolek,0) as prev_kolek"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_status as plan_status"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereDate('la.position_date', $latestPosDate)
            ->where('la.outstanding', '>', 0)
            ->whereRaw($this->sqlBucketLt('m'))
            ->orderByDesc('la.dpd')
            ->orderByDesc('la.outstanding')
            ->limit(300)
            ->get();

        $isDpk = fn($r) => $this->rowIsDpk($r);
        $isL0 = fn($r) => $this->rowIsL0($r);
        $isLtOnly = fn($r) => $this->rowIsLt($r);

        $ltToDpk   = collect($ltEom)->filter($isDpk)->values();
        $ltStillLt = collect($ltEom)->filter($isLtOnly)->values();

        $ltToDpkNoa = (int) $ltToDpk->count();
        $ltToDpkOs  = (int) $ltToDpk->sum(fn($r) => (int)($r->os ?? 0));

        // 10D. L0 EOM -> status terakhir
        $l0Eom = DB::table('loan_account_snapshots_monthly as m')
            ->joinSub($subLaLatestAccKey, 'la', function ($j) {
                $j->on(
                    DB::raw("TRIM(LEADING '0' FROM m.account_no)"),
                    '=',
                    DB::raw("la.acc_key")
                );
            })
            ->leftJoinSub($subPlanTodayAccKey, 'pl', function ($j) {
                $j->on(DB::raw("la.acc_key"), '=', DB::raw("pl.acc_key"));
            })
            ->leftJoinSub($subLastVisitAccKey, 'lv', function ($j) {
                $j->on(DB::raw("la.acc_key"), '=', DB::raw("lv.acc_key"));
            })
            ->leftJoinSub($subLaPrevAccKey, 'p', function ($j) {
                $j->on(DB::raw("la.acc_key"), '=', DB::raw("p.acc_key"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                'la.ao_code',
                'la.os',
                'la.ft_pokok',
                'la.ft_bunga',
                'la.dpd',
                'la.kolek',

                DB::raw("COALESCE(m.ft_pokok,0) as eom_ft_pokok"),
                DB::raw("COALESCE(m.ft_bunga,0) as eom_ft_bunga"),
                DB::raw("COALESCE(m.kolek,0) as eom_kolek"),

                DB::raw("COALESCE(p.prev_ft_pokok,0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.prev_ft_bunga,0) as prev_ft_bunga"),
                DB::raw("COALESCE(p.prev_kolek,0) as prev_kolek"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_status as plan_status"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereRaw("LPAD(TRIM(COALESCE(NULLIF(m.ao_code,''), la.ao_code)),6,'0') = ?", [$ao])
            ->whereRaw($this->sqlBucketL0('m'))
            ->orderByDesc('la.dpd')
            ->orderByDesc('la.os')
            ->limit(300)
            ->get();

        // 10E. JT angsuran minggu ini
        $weekStart = Carbon::parse($latestPosDate)->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd   = Carbon::parse($latestPosDate)->endOfWeek(Carbon::SUNDAY)->toDateString();

        $ym = Carbon::parse($latestPosDate)->format('Y-m');
        $dueDateExpr = "STR_TO_DATE(CONCAT('$ym','-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')";

        $jtAngsuran = DB::table('loan_accounts as la')
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->leftJoinSub($subLastVisitMeta, 'lv', function ($j) {
                $j->on(DB::raw("TRIM(lv.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
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

                DB::raw("COALESCE(p.ft_pokok,0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga,0) as prev_ft_bunga"),
                DB::raw("COALESCE(p.kolek,0) as prev_kolek"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->where('la.outstanding', '>', 0)
            ->whereNotNull('la.installment_day')
            ->where('la.installment_day', '>=', 1)
            ->where('la.installment_day', '<=', 31)
            ->whereBetween(DB::raw($dueDateExpr), [$weekStart, $weekEnd])
            ->orderBy(DB::raw($dueDateExpr))
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        // 10F. OS besar
        $osBig = DB::table('loan_accounts as la')
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->leftJoinSub($subLastVisitMeta, 'lv', function ($j) {
                $j->on(DB::raw("TRIM(lv.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as os"),
                'la.ft_pokok',
                'la.ft_bunga',
                'la.dpd',
                'la.kolek',

                DB::raw("COALESCE(p.ft_pokok,0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga,0) as prev_ft_bunga"),
                DB::raw("COALESCE(p.kolek,0) as prev_kolek"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->where('la.outstanding', '>', 0)
            ->where('la.outstanding', '>=', 500000000)
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        /**
         * =========================================================
         * 11) BOUNCE PACK
         * =========================================================
         */
        $bounce = [
            'prevPosDate' => $prevPosDate,
            'd1' => $d1,
            'd2' => $d2,

            'lt_to_l0_noa'  => $ltToL0Noa,
            'lt_to_l0_os'   => $ltToL0Os,
            'lt_to_dpk_noa' => $ltToDpkNoaDaily,
            'lt_to_dpk_os'  => $ltToDpkOsDaily,

            'jt_next2_noa' => $jtNext2Noa,
            'jt_next2_os'  => $jtNext2Os,

            'lt_eom_to_dpk_noa' => $ltEomToDpkNoa,
            'lt_eom_to_dpk_os'  => $ltEomToDpkOs,
            'lt_eom_to_l0_noa'  => $ltEomToL0Noa,
            'lt_eom_to_l0_os'   => $ltEomToL0Os,

            'signal_cure' => (!is_null($deltaL0) && !is_null($deltaLT) && $deltaL0 > 0 && $deltaLT < 0),
            'signal_jtsoon' => ($jtNext2Noa > 0),
            'signal_bounce_risk' => (
                (!is_null($deltaL0) && !is_null($deltaLT) && $deltaL0 > 0 && $deltaLT < 0)
                && ($jtNext2Noa > 0)
            ),
        ];

        /**
         * =========================================================
         * 12) INSIGHT
         * =========================================================
         */
        $insight = $this->buildInsight([
            'deltaOs'      => $deltaOs,
            'latestRR'     => $latestRR,
            'deltaRR'      => $deltaRR,
            'latestPctLt'  => $latestPctLt,
            'deltaPctLt'   => $deltaPctLt,
            'l0ToLtNoa'    => $l0ToLtNoa,
            'l0ToLtOs'     => $l0ToLtOs,
            'bounce'       => $bounce,
        ]);

        /**
         * =========================================================
         * 13) RETURN VIEW
         * =========================================================
         */
        return view('kpi.ro.os_daily', [
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),

            'labels' => $labels,
            'datasetsByMetric' => $datasetsByMetric,

            'latestDate' => $latestDate,
            'prevDate'   => $prevDate,

            'latestOs' => $latestOs,
            'prevOs'   => $prevOs ?? 0,
            'deltaOs'  => $deltaOs ?? 0,

            'latestL0' => $latestL0,
            'latestLT' => $latestLT,
            'latestRR' => $latestRR,
            'latestPctLt' => $latestPctLt,

            'latestPosDate' => $latestPosDate,
            'prevSnapMonth' => $prevSnapMonth,

            'l0ToLtNoa' => $l0ToLtNoa,
            'l0ToLtOs'  => $l0ToLtOs,

            'dueThisMonth'  => $dueThisMonth,
            'dueMonthLabel' => $now->translatedFormat('F Y'),

            'ltLatest'   => $ltEom,
            'jtAngsuran' => $jtAngsuran,
            'weekStart'  => $weekStart,
            'weekEnd'    => $weekEnd,

            'osBig'   => $osBig,

            'cards'   => $cards,
            'bounce'  => $bounce,
            'insight' => $insight,

            'cardsMtd' => $cardsMtd,
            'cardsMtdMeta' => [
                'eomMonth' => $eomMonth,
                'lastDate' => $latestPosDate,
            ],
            'mode' => $mode,

            'ltToDpk'     => $ltToDpk,
            'ltStillLt'   => $ltStillLt,
            'ltToDpkNoa'  => $ltToDpkNoa,
            'ltToDpkOs'   => $ltToDpkOs,
            'ltEomToDpk'  => $ltEomToDpk,
            'l0Eom'       => $l0Eom,
        ]);
    }

    /**
     * =========================================================
     * HELPER: planned hari ini dari RKH
     * =========================================================
     */
    private function subPlanToday(int $uid, string $today)
    {
        return DB::table('rkh_headers as h')
            ->join('rkh_details as d', 'd.rkh_id', '=', 'h.id')
            ->where('h.user_id', $uid)
            ->whereDate('h.tanggal', $today)
            ->selectRaw("
                TRIM(d.account_no) as account_no,
                1 as planned_today,
                h.tanggal as plan_visit_date,
                h.status as plan_status
            ");
    }

    /**
     * =========================================================
     * HELPER: last visit + hasil kunjungan terakhir per account
     * dari rkh_visit_logs
     * =========================================================
     */
    private function subLastVisitMeta()
    {
        $latestKey = DB::table('ro_visits as rv')
            ->selectRaw("
                TRIM(rv.account_no) as account_no,
                MAX(
                    CONCAT(
                        DATE_FORMAT(
                            COALESCE(rv.visited_at, rv.visit_date, rv.updated_at, rv.created_at),
                            '%Y-%m-%d %H:%i:%s'
                        ),
                        '#',
                        LPAD(rv.id, 12, '0')
                    )
                ) as max_key
            ")
            ->whereNotNull('rv.account_no')
            ->groupBy(DB::raw("TRIM(rv.account_no)"));

        return DB::table('ro_visits as rv')
            ->joinSub($latestKey, 'x', function ($join) {
                $join->on(DB::raw("TRIM(rv.account_no)"), '=', 'x.account_no')
                    ->whereRaw("
                        CONCAT(
                            DATE_FORMAT(
                                COALESCE(rv.visited_at, rv.visit_date, rv.updated_at, rv.created_at),
                                '%Y-%m-%d %H:%i:%s'
                            ),
                            '#',
                            LPAD(rv.id, 12, '0')
                        ) = x.max_key
                    ");
            })
            ->selectRaw("
                TRIM(rv.account_no) as account_no,
                COALESCE(rv.visited_at, rv.visit_date, rv.updated_at, rv.created_at) as last_visit_at,
                rv.lkh_note as hasil_kunjungan
            ");
    }

    /**
     * =========================================================
     * HELPER: bucket SQL sesuai definisi terbaru
     * =========================================================
     */
    private function bucketSql(): \Closure
    {
        return function (string $alias): string {
            return "(
                CASE
                  WHEN COALESCE({$alias}.kolek,0)=1
                       AND COALESCE({$alias}.ft_pokok,0)=0
                       AND COALESCE({$alias}.ft_bunga,0)=0
                    THEN 'L0'

                  WHEN COALESCE({$alias}.kolek,0)=1
                       AND (
                           COALESCE({$alias}.ft_pokok,0)>0
                           OR COALESCE({$alias}.ft_bunga,0)>0
                       )
                    THEN 'LT'

                  WHEN COALESCE({$alias}.kolek,0)=2
                       AND (
                           COALESCE({$alias}.ft_pokok,0)=2
                           OR COALESCE({$alias}.ft_bunga,0)=2
                       )
                    THEN 'DPK'

                  WHEN COALESCE({$alias}.kolek,0)=2
                       AND (
                           COALESCE({$alias}.ft_pokok,0)=3
                           OR COALESCE({$alias}.ft_bunga,0)=3
                       )
                    THEN 'POTENSI'

                  WHEN COALESCE({$alias}.kolek,0)=3 THEN 'KL'
                  WHEN COALESCE({$alias}.kolek,0)=4 THEN 'D'
                  WHEN COALESCE({$alias}.kolek,0)=5 THEN 'M'
                  ELSE 'UNK'
                END
            )";
        };
    }

    private function sqlBucketL0(string $alias = ''): string
    {
        $a = $alias !== '' ? trim($alias) . '.' : '';
        return "COALESCE({$a}kolek,0)=1 AND COALESCE({$a}ft_pokok,0)=0 AND COALESCE({$a}ft_bunga,0)=0";
    }

    private function sqlBucketLt(string $alias = ''): string
    {
        $a = $alias !== '' ? trim($alias) . '.' : '';
        return "COALESCE({$a}kolek,0)=1 AND (COALESCE({$a}ft_pokok,0)>0 OR COALESCE({$a}ft_bunga,0)>0)";
    }

    private function sqlBucketDpk(string $alias = ''): string
    {
        $a = $alias !== '' ? trim($alias) . '.' : '';
        return "COALESCE({$a}kolek,0)=2 AND (COALESCE({$a}ft_pokok,0)=2 OR COALESCE({$a}ft_bunga,0)=2)";
    }

    private function rowIsL0(object $r): bool
    {
        $kolek   = (int) ($r->kolek ?? 0);
        $ftPokok = (int) ($r->ft_pokok ?? 0);
        $ftBunga = (int) ($r->ft_bunga ?? 0);

        return $kolek === 1 && $ftPokok === 0 && $ftBunga === 0;
    }

    private function rowIsLt(object $r): bool
    {
        $kolek   = (int) ($r->kolek ?? 0);
        $ftPokok = (int) ($r->ft_pokok ?? 0);
        $ftBunga = (int) ($r->ft_bunga ?? 0);

        return $kolek === 1 && ($ftPokok > 0 || $ftBunga > 0);
    }

    private function rowIsDpk(object $r): bool
    {
        $kolek   = (int) ($r->kolek ?? 0);
        $ftPokok = (int) ($r->ft_pokok ?? 0);
        $ftBunga = (int) ($r->ft_bunga ?? 0);

        return $kolek === 2 && ($ftPokok === 2 || $ftBunga === 2);
    }

    private function buildInsight(array $x): array
    {
        $good = [];
        $bad  = [];
        $why  = [];
        $risk = [];

        $deltaOs = (int)($x['deltaOs'] ?? 0);
        if ($deltaOs > 0) $good[] = "OS naik vs snapshot sebelumnya sebesar Rp " . number_format($deltaOs, 0, ',', '.');
        if ($deltaOs < 0) $bad[]  = "OS turun vs snapshot sebelumnya sebesar Rp " . number_format(abs($deltaOs), 0, ',', '.');

        $rr = $x['latestRR'] ?? null;
        if (!is_null($rr)) {
            if ($rr >= 95) $good[] = "RR sangat baik (≥95%).";
            elseif ($rr >= 90) $good[] = "RR cukup baik (90–95%).";
            else $bad[] = "RR menurun/perlu perhatian (<90%).";
        }

        $dRR = $x['deltaRR'] ?? null;
        if (!is_null($dRR)) {
            if ($dRR > 0) $good[] = "RR membaik vs snapshot sebelumnya (+" . number_format((float)$dRR, 2, ',', '.') . " pts).";
            if ($dRR < 0) $bad[]  = "RR memburuk vs snapshot sebelumnya (" . number_format((float)$dRR, 2, ',', '.') . " pts).";
        }

        $pctLt = $x['latestPctLt'] ?? null;
        if (!is_null($pctLt)) {
            if ($pctLt <= 5) $good[] = "%LT rendah (≤5%) – kualitas bagus.";
            elseif ($pctLt <= 10) $good[] = "%LT masih terkendali (5–10%).";
            else $bad[] = "%LT tinggi (>10%) – ada risiko kualitas portofolio.";
        }

        $dPctLt = $x['deltaPctLt'] ?? null;
        if (!is_null($dPctLt)) {
            if ($dPctLt < 0) $good[] = "%LT turun (membaik) vs snapshot sebelumnya (" . number_format((float)$dPctLt, 2, ',', '.') . " pts).";
            if ($dPctLt > 0) $bad[]  = "%LT naik (memburuk) vs snapshot sebelumnya (+" . number_format((float)$dPctLt, 2, ',', '.') . " pts).";
        }

        $noa = (int)($x['l0ToLtNoa'] ?? 0);
        $os  = (int)($x['l0ToLtOs'] ?? 0);
        if ($noa > 0) {
            $why[] = "Indikasi L0 → LT bulan ini: {$noa} NOA, OS ± Rp " . number_format($os, 0, ',', '.') . " (menekan RR & menaikkan %LT).";
        } else {
            $why[] = "Tidak ada indikasi L0 → LT (bulan ini) berdasarkan snapshot bulan lalu vs posisi terakhir (baik untuk stabilitas RR).";
        }

        $bounce = (array)($x['bounce'] ?? []);
        if (!empty($bounce)) {
            $eomToDpkNoa = (int)($bounce['lt_eom_to_dpk_noa'] ?? 0);
            $eomToDpkOs  = (int)($bounce['lt_eom_to_dpk_os'] ?? 0);
            if ($eomToDpkNoa > 0) {
                $risk[] = "Kritis: LT EOM → DPK (FT=2): {$eomToDpkNoa} NOA, OS ± Rp " . number_format($eomToDpkOs, 0, ',', '.') . " (potensi migrasi ke FE & OS RO turun).";
            }

            $eomToL0Noa = (int)($bounce['lt_eom_to_l0_noa'] ?? 0);
            $eomToL0Os  = (int)($bounce['lt_eom_to_l0_os'] ?? 0);
            if ($eomToL0Noa > 0) {
                $why[] = "Cure sementara: LT EOM → L0 hari ini: {$eomToL0Noa} NOA, OS ± Rp " . number_format($eomToL0Os, 0, ',', '.') . " (rawan bounce-back bila JT dekat tidak dibayar).";
            }

            $ltToDpkNoa = (int)($bounce['lt_to_dpk_noa'] ?? 0);
            $ltToDpkOs  = (int)($bounce['lt_to_dpk_os'] ?? 0);
            if ($ltToDpkNoa > 0) {
                $risk[] = "Eskalasi harian: LT → DPK (FT=2): {$ltToDpkNoa} NOA, OS ± Rp " . number_format($ltToDpkOs, 0, ',', '.') . " → siapkan rencana migrasi/koordinasi FE & update LKH.";
            }

            $ltToL0Noa = (int)($bounce['lt_to_l0_noa'] ?? 0);
            $ltToL0Os  = (int)($bounce['lt_to_l0_os'] ?? 0);
            if ($ltToL0Noa > 0) {
                $why[] = "Ada perbaikan LT → L0 hari ini (H-1→H): {$ltToL0Noa} NOA, OS ± Rp " . number_format($ltToL0Os, 0, ',', '.') . ".";
            }

            $jtNoa = (int)($bounce['jt_next2_noa'] ?? 0);
            $jtOs  = (int)($bounce['jt_next2_os'] ?? 0);
            if ($jtNoa > 0) {
                $risk[] = "Ada JT angsuran 1–2 hari ke depan: {$jtNoa} NOA, OS ± Rp " . number_format($jtOs, 0, ',', '.') . " → potensi LT naik lagi jika tidak bayar.";
            }

            $signalBounce = (bool)($bounce['signal_bounce_risk'] ?? false);
            if ($signalBounce) {
                $risk[] = "Sinyal *bounce-back*: L0 naik & LT turun (indikasi bayar/cure), tetapi ada JT dekat → besok RR bisa turun lagi jika gagal bayar.";
            }
        }

        return compact('good', 'bad', 'why', 'risk');
    }

    public function planToday(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if(!$user, 401);

        $data = $request->validate([
            'account_no'      => ['required', 'string', 'max:30'],
            'nama_nasabah'    => ['nullable', 'string', 'max:255'],
            'kolektibilitas'  => ['nullable', 'in:L0,LT'],
            'jenis_kegiatan'  => ['nullable', 'string', 'max:255'],
            'tujuan_kegiatan' => ['nullable', 'string', 'max:255'],
        ]);

        $today = now()->toDateString();

        $rkhId = DB::table('rkh_headers')->where([
            ['user_id', '=', $user->id],
            ['tanggal', '=', $today],
        ])->value('id');

        if (!$rkhId) {
            $rkhId = DB::table('rkh_headers')->insertGetId([
                'user_id'    => $user->id,
                'tanggal'    => $today,
                'total_jam'  => 0,
                'status'     => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $exists = DB::table('rkh_details')->where([
            ['rkh_id', '=', $rkhId],
            ['account_no', '=', $data['account_no']],
        ])->exists();

        if (!$exists) {
            DB::table('rkh_details')->insert([
                'rkh_id'          => $rkhId,
                'account_no'      => $data['account_no'],
                'nama_nasabah'    => $data['nama_nasabah'] ?? null,
                'kolektibilitas'  => $data['kolektibilitas'] ?? 'LT',
                'jenis_kegiatan'  => $data['jenis_kegiatan'] ?? 'Visit',
                'tujuan_kegiatan' => $data['tujuan_kegiatan'] ?? 'Penagihan / Monitoring',
                'jam_mulai'       => now()->format('H:i:s'),
                'jam_selesai'     => now()->format('H:i:s'),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        return response()->json([
            'ok'              => true,
            'planned_today'   => true,
            'plan_visit_date' => $today,
            'locked'          => false,
        ]);
    }

    private function pushPlannedToRkh(int $userId, string $acc, string $date): void
    {
        $headerId = DB::table('rkh_headers')
            ->where('user_id', $userId)
            ->whereDate('tanggal', $date)
            ->value('id');

        if (!$headerId) {
            $headerId = DB::table('rkh_headers')->insertGetId([
                'user_id'    => $userId,
                'tanggal'    => $date,
                'status'     => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $exists = DB::table('rkh_details')
            ->where('rkh_id', $headerId)
            ->where('account_no', $acc)
            ->exists();

        if (!$exists) {
            DB::table('rkh_details')->insert([
                'rkh_id'      => $headerId,
                'account_no'  => $acc,
                'jenis_kegiatan' => 'visit',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }
}