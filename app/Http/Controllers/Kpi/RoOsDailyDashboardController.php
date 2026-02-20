<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\RoVisit;
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

        $ao = str_pad(trim((string)($me->ao_code ?? '')), 6, '0', STR_PAD_LEFT);
        abort_unless($ao !== '' && $ao !== '000000', 403);

        /* 1) RANGE DEFAULT
         *    - from: tgl terakhir bulan lalu
         *    - to  : tgl terakhir yang ada di tabel kpi_os_daily_aos
         * =========================================================
         */
        $latestInKpi = DB::table('kpi_os_daily_aos')->max('position_date'); // date/datetime
        $latestInKpi = $latestInKpi ? Carbon::parse($latestInKpi)->startOfDay() : now()->startOfDay();

        $lastMonthEndC = Carbon::now()->subMonthNoOverflow()->endOfMonth()->startOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : $lastMonthEndC->copy()->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->startOfDay()
            : $latestInKpi->copy()->startOfDay();

            logger()->info('range_debug', [
                'from' => $from->toDateTimeString(),
                'to'   => $to->toDateTimeString(),
                'ao'   => $ao,
                'db'   => config('database.default'),
                'conn' => DB::connection()->getDatabaseName(),
                ]);

        // guard kalau user input kebalik
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->startOfDay()];
        }

        $mode = $request->input('mode', 'mtd'); // default: MtoD
        $mode = in_array($mode, ['mtd', 'h'], true) ? $mode : 'mtd';

        // =============================
        // LABELS tanggal lengkap
        // =============================
        $labels = [];
        $cursor = $from->copy()->startOfDay();
        $end    = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $labels[] = $cursor->toDateString();
            $cursor->addDay();
        }

        // =============================
        // Tentukan baseline snapshot month (EOM bulan lalu)
        // (dipakai untuk cohort & MtoD base)
        // =============================
        // Nanti kita overwrite lagi setelah latestPosDate final ketemu,
        // tapi ini jadi fallback aman.
        $latestPosDateFallback = (count($labels) ? $labels[count($labels) - 1] : $latestInKpi->toDateString());
        $prevSnapMonth = Carbon::parse($latestPosDateFallback)->subMonthNoOverflow()->startOfMonth()->toDateString();

        // =============================
        // DATA harian KPI (AO ini)
        // FIX UTAMA: pakai whereDate supaya aman untuk DATETIME
        // =============================
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
            ->where('ao_code', $ao)
            ->groupBy('d')
            ->orderBy('d')
            ->get();

            logger()->info('rows_debug', [
                'count' => $rows->count(),
                'first' => $rows->first(),
                'sql'   => $rows->count() ? 'has_rows' : 'no_rows',
                ]);

        $byDate = [];
        foreach ($rows as $r) {
            $osTotal = (int)($r->os_total ?? 0);
            $osL0    = (int)($r->os_l0 ?? 0);
            $osLt    = (int)($r->os_lt ?? 0);

            $rr    = ($osTotal > 0) ? round(($osL0 / $osTotal) * 100, 2) : null;
            $pctLt = ($osTotal > 0) ? round(($osLt / $osTotal) * 100, 2) : null;

            $byDate[(string)$r->d] = [
                'os_total' => $osTotal,
                'os_l0'    => $osL0,
                'os_lt'    => $osLt,
                'rr'       => $rr,
                'pct_lt'   => $pctLt,
            ];
        }

        // =============================
        // series (null kalau tanggal bolong)
        // =============================
        $series = [
            'os_total' => [],
            'os_l0'    => [],
            'os_lt'    => [],
            'rr'       => [],
            'pct_lt'   => [],
        ];

        foreach ($labels as $d) {
            $series['os_total'][] = $byDate[$d]['os_total'] ?? null;
            $series['os_l0'][]    = $byDate[$d]['os_l0'] ?? null;
            $series['os_lt'][]    = $byDate[$d]['os_lt'] ?? null;
            $series['rr'][]       = $byDate[$d]['rr'] ?? null;
            $series['pct_lt'][]   = $byDate[$d]['pct_lt'] ?? null;
        }

        // =============================
        // LATEST + PREV (yang benar-benar ada snapshot)
        // - latestDate harus tanggal terakhir yang ADA data (bukan sekadar label terakhir)
        // =============================
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
                if (!$d) continue;
                if ($d === $latestDate) continue;
                if (isset($byDate[$d])) {
                    $prevAvailDate = $d;
                    break;
                }
            }
        }

        $latestPack = $latestDate ? ($byDate[$latestDate] ?? null) : null;
        $prevPack   = $prevAvailDate ? ($byDate[$prevAvailDate] ?? null) : null;

        $prevDate = $prevAvailDate;

        // =============================
        // Posisi terakhir untuk join loan_accounts & baseline MtoD
        // =============================
        $latestPosDate = $latestDate
            ? Carbon::parse($latestDate)->toDateString()
            : $latestInKpi->toDateString(); // fallback aman

        // baseline snapshot month: startOfMonth dari bulan lalu
        $prevSnapMonth = Carbon::parse($latestPosDate)->subMonthNoOverflow()->startOfMonth()->toDateString();

        $aoCodes = [$ao]; // biar when(!empty($aoCodes)) aman

        // =============================
        // Cards value & delta (H vs H-1 snapshot available)
        // =============================
        $latestOs    = (int)($latestPack['os_total'] ?? 0);
        $latestL0    = (int)($latestPack['os_l0'] ?? 0);
        $latestLT    = (int)($latestPack['os_lt'] ?? 0);
        $latestRR    = $latestPack['rr'] ?? null;
        $latestPctLt = $latestPack['pct_lt'] ?? null;
        $latestDPK = (int)($latestPack['os_dpk'] ?? 0);
        $latestPctDpk = $latestOs > 0 ? round(($latestDPK / $latestOs) * 100, 2) : null;



       
        $prevDPK   = $prevPack ? (int)($prevPack['os_dpk'] ?? 0) : null;
        $prevOs    = $prevPack ? (int)($prevPack['os_total'] ?? 0) : null;
        $prevL0    = $prevPack ? (int)($prevPack['os_l0'] ?? 0) : null;
        $prevLT    = $prevPack ? (int)($prevPack['os_lt'] ?? 0) : null;
        $prevRR    = $prevPack ? ($prevPack['rr'] ?? null) : null;
        $prevPctLt = $prevPack ? ($prevPack['pct_lt'] ?? null) : null;
        $prevPctDpk   = (!is_null($prevOs) && $prevOs > 0 && !is_null($prevDPK)) ? round(($prevDPK / $prevOs) * 100, 2) : null;

        $deltaOs    = is_null($prevOs) ? null : ($latestOs - $prevOs);
        $deltaL0    = is_null($prevL0) ? null : ($latestL0 - $prevL0);
        $deltaLT    = is_null($prevLT) ? null : ($latestLT - $prevLT);
        $deltaRR    = (is_null($prevRR) || is_null($latestRR)) ? null : round(((float)$latestRR - (float)$prevRR), 2);
        $deltaPctLt = (is_null($prevPctLt) || is_null($latestPctLt)) ? null : round(((float)$latestPctLt - (float)$prevPctLt), 2);
        $deltaDPK  = is_null($prevDPK) ? null : ($latestDPK - $prevDPK);
        $deltaPctDpk  = (is_null($latestPctDpk) || is_null($prevPctDpk)) ? null : round($latestPctDpk - $prevPctDpk, 2);

        
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
                'rr' => ['label' => 'RR', 'value' => $latestRR, 'base' => $prevRR, 'delta' => $deltaRR], // points
                ],
            ],
            'lt' => [
                'label' => 'LT',
                'value' => $latestLT,
                'base'  => $prevLT,
                'delta' => $deltaLT,
                'extra' => [
                'pct_lt' => ['label' => '%LT', 'value' => $latestPctLt, 'base' => $prevPctLt, 'delta' => $deltaPctLt], // points
                ],
            ],
            'dpk' => [
                'label' => 'DPK',
                'value' => $latestDPK,
                'base'  => $prevDPK,
                'delta' => $deltaDPK,
                'extra' => [
                'pct_dpk' => ['label' => '%DPK', 'value' => $latestPctDpk, 'base' => $prevPctDpk, 'delta' => $deltaPctDpk], // points
                ],
            ],
        ];


        // =============================
        // Card Month to Date
        // =============================
        $eomMonth = Carbon::parse($latestPosDate)
            ->subMonthNoOverflow()
            ->startOfMonth()
            ->toDateString(); // YYYY-MM-01

        $eomAgg = DB::table('loan_account_snapshots_monthly as m')
            ->selectRaw("
                ROUND(SUM(m.outstanding)) as os,
                ROUND(SUM(CASE WHEN COALESCE(m.ft_pokok,0)=0 AND COALESCE(m.ft_bunga,0)=0 THEN m.outstanding ELSE 0 END)) as l0,
                ROUND(SUM(CASE WHEN COALESCE(m.ft_pokok,0)=1 OR COALESCE(m.ft_bunga,0)=1 THEN m.outstanding ELSE 0 END)) as lt,
                ROUND(SUM(CASE WHEN COALESCE(m.ft_pokok,0)=2 OR COALESCE(m.ft_bunga,0)=2 OR COALESCE(m.kolek,0)=2 THEN m.outstanding ELSE 0 END)) as dpk
            ")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereRaw("LPAD(TRIM(m.ao_code),6,'0') = ?", [$ao])
            ->first();

        $eomOs = (int)($eomAgg->os ?? 0);
        $eomL0 = (int)($eomAgg->l0 ?? 0);
        $eomLt = (int)($eomAgg->lt ?? 0);

        $eomRr = $eomOs > 0 ? round(($eomL0 / $eomOs) * 100, 2) : null;
        $eomPl = $eomOs > 0 ? round(($eomLt / $eomOs) * 100, 2) : null;

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

        $lastOs = (int)($lastAgg->os ?? 0);
        $lastL0 = (int)($lastAgg->l0 ?? 0);
        $lastLt = (int)($lastAgg->lt ?? 0);

        $lastRr = $lastOs > 0 ? round(($lastL0 / $lastOs) * 100, 2) : null;
        $lastPl = $lastOs > 0 ? round(($lastLt / $lastOs) * 100, 2) : null;

        $eomDpk  = (int)($eomAgg->dpk ?? 0);
        $lastDpk = (int)($lastAgg->dpk ?? 0);

        $eomPctDpk  = $eomOs > 0 ? round(($eomDpk / $eomOs) * 100, 2) : null;
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

        $cardsMtdMeta = [
            'eomMonth' => $eomMonth,
            'lastDate' => $latestPosDate,
        ];

        
        // =============================
        // datasetsbymetric
        // =============================

        // 1) ambil rows keyed by date
        // $byDate = $rows->keyBy(fn($r) => (string)$r->position_date);

        // 2) buat labels full range (biar tanggal tanpa snapshot tetap muncul sebagai gap)
        $labels = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
        $labels[] = $cursor->toDateString();
        $cursor->addDay();
        }

        // 3) helper ambil nilai dengan gap null
        $get = function(string $d, string $col) use ($byDate) {
        if (!isset($byDate[$d])) return null;
        $v = $byDate[$d]->{$col} ?? null;
        if ($v === null) return null;
        return is_numeric($v) ? (float)$v : null;
        };

        // 4) build arrays per metric
        $osTotal = array_map(fn($d) => $get($d,'os_total'), $labels);
        $osL0    = array_map(fn($d) => $get($d,'os_l0'),    $labels);
        $osLt    = array_map(fn($d) => $get($d,'os_lt'),    $labels);

        // RR dan %LT kalau mau dihitung on-the-fly
        $rrL0 = [];
        $pctLt = [];
        foreach ($labels as $d) {
        $osT = $get($d,'os_total');
        $l0  = $get($d,'os_l0');
        $lt  = $get($d,'os_lt');

        $rrL0[]  = ($osT && $l0 !== null) ? round(($l0 / $osT) * 100, 2) : null;
        $pctLt[] = ($osT && $lt !== null) ? round(($lt / $osT) * 100, 2) : null;
        }

        $datasetsByMetric = [
        'os_total' => ['label'=>'OS Total', 'data'=>$osTotal],
        'os_l0'    => ['label'=>'OS L0',    'data'=>$osL0],
        'os_lt'    => ['label'=>'OS LT',    'data'=>$osLt],
        'rr_l0'    => ['label'=>'RR (% L0)','data'=>$rrL0],
        'pct_lt'   => ['label'=>'% LT',     'data'=>$pctLt],
        ];

       

        // =============================
        // Bounce compare date for per-account progress (H-1 snapshot available)
        // =============================
        $prevPosDate = $prevDate ?: Carbon::parse($latestPosDate)->subDay()->toDateString();

        // =============================
        // Bucket CASE SQL (L0/LT/DPK) untuk tabel-tabel harian
        // =============================
        $bucketSql = function (string $alias): string {
            return "(
                CASE
                  WHEN {$alias}.ft_pokok = 2 OR {$alias}.ft_bunga = 2 THEN 'DPK'
                  WHEN {$alias}.ft_pokok = 1 OR {$alias}.ft_bunga = 1 THEN 'LT'
                  ELSE 'L0'
                END
            )";
        };

        // =============================
        // VISIT META
        // - last visit GLOBAL per account
        // - planned/done hari ini khusus RO login
        // =============================
        $today = now()->toDateString();

        // $lastVisitMap = RoVisit::query()
        //     ->selectRaw('account_no, MAX(visit_date) as last_visit_date')
        //     ->groupBy('account_no')
        //     ->pluck('last_visit_date', 'account_no')
        //     ->toArray();

        // $plannedTodayMap = RoVisit::query()
        //     ->select(['account_no', 'status', 'visit_date'])
        //     ->where('user_id', (int)$me->id)
        //     ->whereDate('visit_date', $today)
        //     ->get()
        //     ->keyBy('account_no');

        // $attachVisitMeta = function ($rows) use ($lastVisitMap, $plannedTodayMap) {
        //     return collect($rows)->map(function ($r) use ($lastVisitMap, $plannedTodayMap) {
        //         $acc = (string)($r->account_no ?? '');

        //         // last visit (boleh isi kalau kosong)
        //         if (!property_exists($r, 'last_visit_at') && !property_exists($r, 'last_visit_date')) {
        //             $r->last_visit_date = $acc !== '' ? ($lastVisitMap[$acc] ?? null) : null;
        //         }

        //         // ✅ planned_today: JANGAN TIMPA kalau sudah ada dari SELECT SQL
        //         if (!property_exists($r, 'planned_today')) {
        //             $row = ($acc !== '' && $plannedTodayMap->has($acc)) ? $plannedTodayMap->get($acc) : null;

        //             // ✅ jangan override kalau query sudah menyediakan (RKH-based)
        //             if (!isset($r->planned_today) || $r->planned_today === null) {
        //                 $r->planned_today = $row ? 1 : 0;
        //             }
        //             if (!isset($r->plan_visit_date) || $r->plan_visit_date === null) {
        //                 $r->plan_visit_date = $row ? (string)($row->visit_date ?? null) : null;
        //             }
        //             if (!isset($r->plan_status) || $r->plan_status === null) {
        //                 $r->plan_status = $row ? (string)($row->status ?? 'planned') : null;
        //             }

        //         }

        //         return $r;
        //     });
        // };


        // =============================
        // Insight penyebab (feasible)
        // L0 -> LT bulan ini (snapshot month-1 vs posisi terakhir)
        // =============================
        $l0ToLtAgg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(la.outstanding)) as os
            ")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->where('m.ft_pokok', 0)
            ->where('m.ft_bunga', 0)
            ->where(function ($q) {
                $q->where('la.ft_pokok', '>', 0)->orWhere('la.ft_bunga', '>', 0);
            })
            ->first();

        $l0ToLtNoa = (int)($l0ToLtAgg->noa ?? 0);
        $l0ToLtOs  = (int)($l0ToLtAgg->os ?? 0);

        // =============================
        // Bounce-back metrics (buat TLRO):
        // 1) LT -> L0 (H-1 -> H)
        // 2) LT -> DPK (H-1 -> H)
        // 3) JT angsuran 1-2 hari ke depan
        // =============================

        // 1) LT -> L0 (posisi kemarin LT (FT=1), hari ini L0)
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
            ->where(function ($q) {
                $q->where('p.ft_pokok', 1)->orWhere('p.ft_bunga', 1);
            })
            ->where('t.ft_pokok', 0)
            ->where('t.ft_bunga', 0)
            ->first();

        $ltToL0Noa = (int)($ltToL0Agg->noa ?? 0);
        $ltToL0Os  = (int)($ltToL0Agg->os ?? 0);

        // 2) LT -> DPK (posisi kemarin LT (FT=1), hari ini DPK (FT=2))
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
            ->where(function ($q) {
                $q->where('p.ft_pokok', 1)->orWhere('p.ft_bunga', 1);
            })
            ->where(function ($q) {
                $q->where('t.ft_pokok', 2)->orWhere('t.ft_bunga', 2)->orWhere('t.kolek', 2);
            })
            ->first();

        $ltToDpkNoa = (int)($ltToDpkAgg->noa ?? 0);
        $ltToDpkOs  = (int)($ltToDpkAgg->os ?? 0);

        // 3) JT angsuran 1-2 hari ke depan (robust: next due date relative to latestPosDate)
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

        // =============================
        // Cohort LT EOM bulan lalu -> status hari ini:
        // =============================
        $ltEomToDpkAgg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("COUNT(*) as noa, ROUND(COALESCE(SUM(la.outstanding),0)) as os")
            ->whereDate('m.snapshot_month', $prevSnapMonth)        // EOM bulan lalu
            ->whereDate('la.position_date', $latestPosDate)        // posisi hari ini
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->where(function ($q) {
                $q->where('m.ft_pokok', 1)->orWhere('m.ft_bunga', 1); // LT saat EOM
            })
            ->where(function ($q) {
                $q->where('la.ft_pokok', 2)->orWhere('la.ft_bunga', 2)->orWhere('la.kolek', 2); // jadi DPK hari ini
            })
            ->first();

        $ltEomToDpkNoa = (int)($ltEomToDpkAgg->noa ?? 0);
        $ltEomToDpkOs  = (int)($ltEomToDpkAgg->os ?? 0);

        $ltEomToL0Agg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("COUNT(*) as noa, ROUND(SUM(la.outstanding)) as os")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->where(function ($q) {
                $q->where('m.ft_pokok', 1)->orWhere('m.ft_bunga', 1);
            })
            ->where('la.ft_pokok', 0)
            ->where('la.ft_bunga', 0)
            ->first();

        $ltEomToL0Noa = (int)($ltEomToL0Agg->noa ?? 0);
        $ltEomToL0Os  = (int)($ltEomToL0Agg->os ?? 0);


        // =========================================================
        // ✅ Planned source: RKH hari ini (SINGLE SOURCE OF TRUTH)
        // =========================================================
        $today = now()->toDateString();
        $uid   = (int) auth()->id();

        $subPlanToday = DB::table('rkh_headers as h')
            ->join('rkh_details as d', 'd.rkh_id', '=', 'h.id')
            ->where('h.user_id', $uid)
            ->whereDate('h.tanggal', $today)
            ->selectRaw("
                TRIM(d.account_no) as account_no,
                1 as planned_today,
                h.tanggal as plan_visit_date,
                h.status as plan_status
            ");


        // =========================================================
        // ✅ LIST: LT EOM -> DPK (detail list) + planned from RKH
        // =========================================================
        $ltEomToDpk = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')

            ->leftJoinSub($subPlanToday, 'pl', function($j){
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })

            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("ROUND(la.outstanding) as os"),
                'la.dpd',
                'la.kolek',
                'la.ft_pokok',
                'la.ft_bunga',

                // ✅ planned meta from RKH ONLY
                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])

            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])

            // EOM: LT (FT=1)
            ->where(function ($q) {
                $q->where('m.ft_pokok', 1)->orWhere('m.ft_bunga', 1);
            })

            // Hari ini: DPK (FT=2) / kolek=2
            ->where(function ($q) {
                $q->where('la.ft_pokok', 2)->orWhere('la.ft_bunga', 2)->orWhere('la.kolek', 2);
            })

            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();


        // =============================
        // bounce risk flag (TETAP)
        // =============================
        $bounce = [
            'prevPosDate' => $prevPosDate,
            'd1' => $d1,
            'd2' => $d2,

            // H-1 -> H
            'lt_to_l0_noa'  => $ltToL0Noa,
            'lt_to_l0_os'   => $ltToL0Os,
            'lt_to_dpk_noa' => $ltToDpkNoa,
            'lt_to_dpk_os'  => $ltToDpkOs,

            // JT soon
            'jt_next2_noa' => $jtNext2Noa,
            'jt_next2_os'  => $jtNext2Os,

            // EOM -> Today
            'lt_eom_to_dpk_noa' => $ltEomToDpkNoa,
            'lt_eom_to_dpk_os'  => $ltEomToDpkOs,
            'lt_eom_to_l0_noa'  => $ltEomToL0Noa,
            'lt_eom_to_l0_os'   => $ltEomToL0Os,

            'signal_cure'   => (!is_null($deltaL0) && !is_null($deltaLT) && $deltaL0 > 0 && $deltaLT < 0),
            'signal_jtsoon' => ($jtNext2Noa > 0),
            'signal_bounce_risk' => (
                (!is_null($deltaL0) && !is_null($deltaLT) && $deltaL0 > 0 && $deltaLT < 0) && ($jtNext2Noa > 0)
            ),
        ];


        // =============================
        // TABEL 1) JT bulan ini (maturity_date) + Plan/Last Visit
        // =============================
        $now        = Carbon::parse($latestPosDate);
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd   = $now->copy()->endOfMonth()->toDateString();

        $dueThisMonth = DB::table('loan_accounts as la')
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                ->whereDate('p.position_date', $prevPosDate);
            })

            // ✅ planned meta from RKH today
            ->leftJoinSub($subPlanToday, 'pl', function($j){
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })

            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as outstanding"),
                'la.maturity_date',
                'la.dpd',
                'la.kolek',

                DB::raw("COALESCE(p.ft_pokok, 0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga, 0) as prev_ft_bunga"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),

                // ✅ LAST VISIT (rkh_visit_logs)
                DB::raw("(
                    SELECT MAX(v.visited_at)
                    FROM rkh_details d
                    JOIN rkh_visit_logs v ON v.rkh_detail_id = d.id
                    WHERE TRIM(d.account_no) = TRIM(la.account_no)
                ) as last_visit_at"),

                // ✅ planned from RKH
                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereNotNull('la.maturity_date')
            ->whereBetween('la.maturity_date', [$monthStart, $monthEnd])
            ->orderBy('la.maturity_date')
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();


        // =============================
        // TABEL 2) COHORT: LT EOM bulan lalu -> status posisi terakhir
        // ✅ planned from RKH, last_visit from rkh_visit_logs
        // =============================
        $ltEom = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')

            // ✅ planned meta from RKH today (GANTI ro_visits)
            ->leftJoinSub($subPlanToday, 'pl', function($j){
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })

            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                ->whereDate('p.position_date', $prevPosDate);
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

                DB::raw("COALESCE(p.ft_pokok,0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga,0) as prev_ft_bunga"),

                // ✅ last visit from rkh_visit_logs (bukan ro_visits)
                DB::raw("(
                    SELECT MAX(v.visited_at)
                    FROM rkh_details d
                    JOIN rkh_visit_logs v ON v.rkh_detail_id = d.id
                    WHERE TRIM(d.account_no) = TRIM(la.account_no)
                ) as last_visit_at"),

                // ✅ planned from RKH
                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_status as plan_status"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) {
                $q->where('m.ft_pokok', 1)->orWhere('m.ft_bunga', 1);
            })
            ->orderByDesc('la.dpd')
            ->orderByDesc('la.outstanding')
            ->limit(300)
            ->get();


        // =============================
        // PARTISI: LT EOM -> (DPK only) + (LT only) ; L0 drop
        // =============================
        $isDpk = function ($r) {
            return ((int)($r->ft_pokok ?? 0) === 2)
                || ((int)($r->ft_bunga ?? 0) === 2)
                || ((int)($r->kolek ?? 0) === 2);
        };

        $isL0 = function ($r) {
            return ((int)($r->ft_pokok ?? 0) === 0)
                && ((int)($r->ft_bunga ?? 0) === 0);
        };

        $isLtOnly = function ($r) use ($isDpk, $isL0) {
            if ($isDpk($r)) return false;
            if ($isL0($r))  return false;
            return ((int)($r->ft_pokok ?? 0) === 1) || ((int)($r->ft_bunga ?? 0) === 1);
        };

        $ltToDpk   = collect($ltEom)->filter($isDpk)->values();
        $ltStillLt = collect($ltEom)->filter($isLtOnly)->values();

        $ltToDpkNoa = (int) $ltToDpk->count();
        $ltToDpkOs  = (int) $ltToDpk->sum(fn($r) => (int)($r->os ?? 0));


       // =============================
        // TABEL 2A) COHORT: L0 EOM bulan lalu -> status posisi terakhir
        // ✅ planned from RKH (plan_today), last_visit from ro_visits (DONE)
        // =============================

        // =============================
        // TABEL 2A) COHORT: L0 EOM bulan lalu -> status posisi terakhir
        // =============================

        $today = now()->toDateString();
        $uid   = (int) auth()->id();

        // 1) Latest posisi hari ini (loan_accounts)
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

        // 2) Posisi H-1 (untuk progress H-1 -> H)
        $subLaPrevAccKey = DB::table('loan_accounts')
            ->whereDate('position_date', $prevPosDate)
            ->selectRaw("
                TRIM(LEADING '0' FROM account_no) as acc_key,
                COALESCE(ft_pokok,0) as prev_ft_pokok,
                COALESCE(ft_bunga,0) as prev_ft_bunga
            ");

        // 3) Planned hari ini dari RKH (plan_today)
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

        // 4) Last visit (DONE) dari ro_visits
        $subLastVisitAccKey = DB::table('ro_visits as rv')
            ->where('rv.user_id', $uid)
            ->where('rv.status', 'done')
            ->selectRaw("
                TRIM(LEADING '0' FROM rv.account_no) as acc_key,
                MAX(COALESCE(rv.visited_at, rv.updated_at)) as last_visit_at
            ")
            ->groupBy('acc_key');

            $debugSnapTotal = DB::table('loan_account_snapshots_monthly')
                ->whereDate('snapshot_month', $prevSnapMonth)
                ->count();

            $debugSnapL0 = DB::table('loan_account_snapshots_monthly')
                ->whereDate('snapshot_month', $prevSnapMonth)
                ->whereRaw("COALESCE(ft_pokok,0)=0 AND COALESCE(ft_bunga,0)=0")
                ->count();

            $debugSnapL0Ao = DB::table('loan_account_snapshots_monthly')
                ->whereDate('snapshot_month', $prevSnapMonth)
                ->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [$ao])
                ->whereRaw("COALESCE(ft_pokok,0)=0 AND COALESCE(ft_bunga,0)=0")
                ->count();

            $debugLaLatestAo = DB::table('loan_accounts')
                ->whereDate('position_date', $latestPosDate)
                ->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [$ao])
                ->count();

            logger()->info('L0EOM_CHAIN_DEBUG', [
                'prevSnapMonth' => $prevSnapMonth,
                'latestPosDate' => $latestPosDate,
                'snap_total'    => $debugSnapTotal,
                'snap_l0_total' => $debugSnapL0,
                'snap_l0_ao'    => $debugSnapL0Ao,
                'la_latest_ao'  => $debugLaLatestAo,
            ]);

        // 5) Query utama: cohort dari monthly (m), join ke posisi latest (la) via acc_key
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

                DB::raw("COALESCE(p.prev_ft_pokok,0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.prev_ft_bunga,0) as prev_ft_bunga"),

                DB::raw("lv.last_visit_at as last_visit_at"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_status as plan_status"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)

            // scope robust
            ->whereRaw("
            LPAD(TRIM(COALESCE(NULLIF(m.ao_code,''), la.ao_code)),6,'0') = ?
            ", [$ao])

            // L0 EOM
            ->whereRaw("COALESCE(m.ft_pokok,0)=0 AND COALESCE(m.ft_bunga,0)=0")

            ->orderByDesc('la.dpd')
            ->orderByDesc('la.os')
            ->limit(300)
            ->get();
                
        // =============================
        // TABEL 3) JT Angsuran minggu ini
        // =============================
        $weekStart = Carbon::parse($latestPosDate)->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd   = Carbon::parse($latestPosDate)->endOfWeek(Carbon::SUNDAY)->toDateString();

        $ym = Carbon::parse($latestPosDate)->format('Y-m');
        $dueDateExpr = "STR_TO_DATE(CONCAT('$ym','-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')";

        $jtAngsuran = DB::table('loan_accounts as la')
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                ->whereDate('p.position_date', $prevPosDate);
            })

            ->leftJoinSub($subPlanToday, 'pl', function($j){
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
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

                DB::raw("COALESCE(p.ft_pokok, 0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga, 0) as prev_ft_bunga"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),

                DB::raw("(
                    SELECT MAX(v.visited_at)
                    FROM rkh_details d
                    JOIN rkh_visit_logs v ON v.rkh_detail_id = d.id
                    WHERE TRIM(d.account_no) = TRIM(la.account_no)
                ) as last_visit_at"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereNotNull('la.installment_day')
            ->where('la.installment_day', '>=', 1)
            ->where('la.installment_day', '<=', 31)
            ->whereBetween(DB::raw($dueDateExpr), [$weekStart, $weekEnd])
            ->orderBy(DB::raw($dueDateExpr))
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();


        // =============================
        // TABEL 4) OS >= 500jt
        // =============================
        $osBig = DB::table('loan_accounts as la')
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                ->whereDate('p.position_date', $prevPosDate);
            })

            ->leftJoinSub($subPlanToday, 'pl', function($j){
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
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

                DB::raw("COALESCE(p.ft_pokok, 0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga, 0) as prev_ft_bunga"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),

                DB::raw("(
                    SELECT MAX(v.visited_at)
                    FROM rkh_details d
                    JOIN rkh_visit_logs v ON v.rkh_detail_id = d.id
                    WHERE TRIM(d.account_no) = TRIM(la.account_no)
                ) as last_visit_at"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->where('la.outstanding', '>=', 500000000)
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        // =============================
        // Inject visit meta ke tabel-tabel
        // =============================
        // $dueThisMonth = $attachVisitMeta($dueThisMonth);
        // $ltEom        = $attachVisitMeta($ltEom);
        // $jtAngsuran   = $attachVisitMeta($jtAngsuran);
        // $osBig        = $attachVisitMeta($osBig);

        // =============================
        // Insight text (upgrade + bounce + EOM->DPK)
        // =============================
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

        $datasetsByMetric = [
            'os_total' => [
                [
                    'label' => 'OS Total',
                    'data'  => $series['os_total'],
                ],
            ],
            'os_l0' => [
                [
                    'label' => 'OS L0',
                    'data'  => $series['os_l0'],
                ],
            ],
            'os_lt' => [
                [
                    'label' => 'OS LT',
                    'data'  => $series['os_lt'],
                ],
            ],
            'rr' => [
                [
                    'label' => 'RR (% L0)',
                    'data'  => $series['rr'],
                ],
            ],
            'pct_lt' => [
                [
                    'label' => '% LT',
                    'data'  => $series['pct_lt'],
                ],
            ],
        ];


        logger()->info('chart_debug', [
            'labels_count' => count($labels ?? []),
            'sample_labels'=> array_slice($labels ?? [], 0, 3),
            'dataset_keys' => array_keys($datasetByMetric ?? []),
            'sample_os_total' => array_slice($datasetByMetric['os_total']['data'] ?? [], 0, 5),
        ]);

        return view('kpi.ro.os_daily', [
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),

            'labels' => $labels,
            // 'series' => $series,
            'datasetsByMetric' => $datasetsByMetric,
        
            'latestDate' => $latestDate,
            'prevDate'   => $prevDate,

            // legacy vars (biar blade lama aman)
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

            // ✅ keep key name: ltLatest (biar blade tidak pecah)
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

            'ltToDpk'=>$ltToDpk, 
            'ltStillLt'=>$ltStillLt, 
            'ltToDpkNoa'=>$ltToDpkNoa, 
            'ltToDpkOs'=>$ltToDpkOs,
            'ltEomToDpk'=>$ltEomToDpk,
            'l0Eom' => $l0Eom,
        ]);
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

        // RR level
        $rr = $x['latestRR'] ?? null;
        if (!is_null($rr)) {
            if ($rr >= 95) $good[] = "RR sangat baik (≥95%).";
            elseif ($rr >= 90) $good[] = "RR cukup baik (90–95%).";
            else $bad[] = "RR menurun/perlu perhatian (<90%).";
        }

        // RR delta
        $dRR = $x['deltaRR'] ?? null;
        if (!is_null($dRR)) {
            if ($dRR > 0) $good[] = "RR membaik vs snapshot sebelumnya (+" . number_format((float)$dRR, 2, ',', '.') . " pts).";
            if ($dRR < 0) $bad[]  = "RR memburuk vs snapshot sebelumnya (" . number_format((float)$dRR, 2, ',', '.') . " pts).";
        }

        // %LT level
        $pctLt = $x['latestPctLt'] ?? null;
        if (!is_null($pctLt)) {
            if ($pctLt <= 5) $good[] = "%LT rendah (≤5%) – kualitas bagus.";
            elseif ($pctLt <= 10) $good[] = "%LT masih terkendali (5–10%).";
            else $bad[] = "%LT tinggi (>10%) – ada risiko kualitas portofolio.";
        }

        // %LT delta
        $dPctLt = $x['deltaPctLt'] ?? null;
        if (!is_null($dPctLt)) {
            if ($dPctLt < 0) $good[] = "%LT turun (membaik) vs snapshot sebelumnya (" . number_format((float)$dPctLt, 2, ',', '.') . " pts).";
            if ($dPctLt > 0) $bad[]  = "%LT naik (memburuk) vs snapshot sebelumnya (+" . number_format((float)$dPctLt, 2, ',', '.') . " pts).";
        }

        // indikasi L0 -> LT month-over-month
        $noa = (int)($x['l0ToLtNoa'] ?? 0);
        $os  = (int)($x['l0ToLtOs'] ?? 0);
        if ($noa > 0) {
            $why[] = "Indikasi L0 → LT bulan ini: {$noa} NOA, OS ± Rp " . number_format($os, 0, ',', '.') . " (menekan RR & menaikkan %LT).";
        } else {
            $why[] = "Tidak ada indikasi L0 → LT (bulan ini) berdasarkan snapshot bulan lalu vs posisi terakhir (baik untuk stabilitas RR).";
        }

        // Bounce pack + fokus EOM->DPK
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

    public function planToday(Request $request)
    {
        $user = $request->user();
        abort_if(!$user, 401);

        $data = $request->validate([
            'account_no' => ['required','string','max:30'],
            'nama_nasabah' => ['nullable','string','max:255'],
            'kolektibilitas' => ['nullable','in:L0,LT'],
            'jenis_kegiatan' => ['nullable','string','max:255'],
            'tujuan_kegiatan' => ['nullable','string','max:255'],
        ]);

        $today = now()->toDateString();

        // 1) header
        $rkhId = DB::table('rkh_headers')->where([
            ['user_id', '=', $user->id],
            ['tanggal', '=', $today],
        ])->value('id');

        if (!$rkhId) {
            $rkhId = DB::table('rkh_headers')->insertGetId([
                'user_id' => $user->id,
                'tanggal' => $today,
                'total_jam' => 0,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2) detail (upsert by rkh_id + account_no)
        $exists = DB::table('rkh_details')->where([
            ['rkh_id', '=', $rkhId],
            ['account_no', '=', $data['account_no']],
        ])->exists();

        if (!$exists) {
            DB::table('rkh_details')->insert([
                'rkh_id'         => $rkhId,
                'account_no'     => $data['account_no'],
                'nama_nasabah'   => $data['nama_nasabah'] ?? null,
                'kolektibilitas' => $data['kolektibilitas'] ?? 'LT',
                'jenis_kegiatan' => $data['jenis_kegiatan'] ?? 'Visit',
                'tujuan_kegiatan'=> $data['tujuan_kegiatan'] ?? 'Penagihan / Monitoring',
                'jam_mulai'      => now()->format('H:i:s'),
                'jam_selesai'    => now()->format('H:i:s'),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

        }

        return response()->json([
            'ok' => true,
            'planned_today' => true,
            'plan_visit_date' => $today,
            'locked' => false,
        ]);
    }


    /**
     * Masukkan ke RKH Header+Detail hari ini (auto create kalau belum ada)
     */
    private function pushPlannedToRkh(int $userId, string $acc, string $date): void
    {
        // contoh struktur (sesuaikan dengan tabelmu)
        $headerId = DB::table('rkh_headers')->where('user_id',$userId)->whereDate('tanggal',$date)->value('id');
        if (!$headerId) {
            $headerId = DB::table('rkh_headers')->insertGetId([
                'user_id' => $userId,
                'tanggal' => $date,
                'status'  => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // detail: jangan dobel
        $exists = DB::table('rkh_details')
            ->where('header_id', $headerId)
            ->where('account_no', $acc)
            ->exists();

        if (!$exists) {
            DB::table('rkh_details')->insert([
                'header_id'  => $headerId,
                'account_no' => $acc,
                'jenis'      => 'visit',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

}
