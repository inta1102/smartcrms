<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\RoVisit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        // LATEST + PREV (yang benar-benar ada snapshot)
        // =============================
        $latestDate = count($labels) ? $labels[count($labels) - 1] : null;
        
        // =============================
        // Posisi terakhir loan_accounts untuk tabel bawah
        // =============================
        $latestPosDate = $latestDate ? Carbon::parse($latestDate)->toDateString() : now()->toDateString();
        $prevSnapMonth = Carbon::parse($latestPosDate)
            ->subMonthNoOverflow()
            ->startOfMonth()
            ->toDateString(); 

        // =============================
        // DATA harian KPI (AO ini)
        // =============================
        $rows = DB::table('kpi_os_daily_aos')
            ->selectRaw("
                DATE(position_date) as d,
                ROUND(SUM(os_total)) as os_total,
                ROUND(SUM(os_l0))    as os_l0,
                ROUND(SUM(os_lt))    as os_lt
            ")
            ->whereBetween('position_date', [$from->toDateString(), $to->toDateString()])
            ->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [$ao])
            ->groupBy('d')
            ->orderBy('d')
            ->get();

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

        // series (null kalau tanggal bolong)
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
        // =============================
        $latestDate = count($labels) ? $labels[count($labels) - 1] : null;

        $prevAvailDate = null;
        if ($latestDate) {
            for ($i = count($labels) - 2; $i >= 0; $i--) {
                $d = $labels[$i] ?? null;
                if ($d && isset($byDate[$d])) {
                    $prevAvailDate = $d;
                    break;
                }
            }
        }

        $latestPack = $latestDate ? ($byDate[$latestDate] ?? null) : null;
        $prevPack   = $prevAvailDate ? ($byDate[$prevAvailDate] ?? null) : null;

        // fallback jika latestDate tidak ada data (misal range ke depan)
        if ($latestDate && !$latestPack) {
            for ($i = count($labels) - 1; $i >= 0; $i--) {
                $d = $labels[$i] ?? null;
                if ($d && isset($byDate[$d])) {
                    $latestDate = $d;
                    $latestPack = $byDate[$d];
                    break;
                }
            }
        }

        // kalau prev tidak ketemu, tetap null (delta tidak dihitung)
        $prevDate = $prevAvailDate;

        // =============================
        // Cards value & delta (H vs H-1 snapshot available)
        // =============================
        $latestOs    = (int)($latestPack['os_total'] ?? 0);
        $latestL0    = (int)($latestPack['os_l0'] ?? 0);
        $latestLT    = (int)($latestPack['os_lt'] ?? 0);
        $latestRR    = $latestPack['rr'] ?? null;
        $latestPctLt = $latestPack['pct_lt'] ?? null;

        $prevOs    = $prevPack ? (int)($prevPack['os_total'] ?? 0) : null;
        $prevL0    = $prevPack ? (int)($prevPack['os_l0'] ?? 0) : null;
        $prevLT    = $prevPack ? (int)($prevPack['os_lt'] ?? 0) : null;
        $prevRR    = $prevPack ? ($prevPack['rr'] ?? null) : null;
        $prevPctLt = $prevPack ? ($prevPack['pct_lt'] ?? null) : null;

        $deltaOs    = is_null($prevOs) ? null : ($latestOs - $prevOs);
        $deltaL0    = is_null($prevL0) ? null : ($latestL0 - $prevL0);
        $deltaLT    = is_null($prevLT) ? null : ($latestLT - $prevLT);
        $deltaRR    = (is_null($prevRR) || is_null($latestRR)) ? null : round(((float)$latestRR - (float)$prevRR), 2);
        $deltaPctLt = (is_null($prevPctLt) || is_null($latestPctLt)) ? null : round(((float)$latestPctLt - (float)$prevPctLt), 2);

        $cards = [
            'os' => ['label' => 'OS',       'value' => $latestOs,    'prev' => $prevOs,    'delta' => $deltaOs],
            'l0' => ['label' => 'L0',       'value' => $latestL0,    'prev' => $prevL0,    'delta' => $deltaL0],
            'lt' => ['label' => 'LT',       'value' => $latestLT,    'prev' => $prevLT,    'delta' => $deltaLT],
            'rr' => ['label' => 'RR (%L0)', 'value' => $latestRR,    'prev' => $prevRR,    'delta' => $deltaRR],
            'pct_lt' => ['label' => '%LT',  'value' => $latestPctLt, 'prev' => $prevPctLt, 'delta' => $deltaPctLt],
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
                ROUND(SUM(CASE WHEN COALESCE(m.ft_pokok,0)=1 OR COALESCE(m.ft_bunga,0)=1 THEN m.outstanding ELSE 0 END)) as lt
            ")
            ->whereDate('m.snapshot_month', $prevSnapMonth) // pakai prevSnapMonth yg sudah dihitung
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
                ROUND(SUM(d.os_lt)) as lt
            ")
            ->whereDate('d.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(d.ao_code),6,'0') = ?", [$ao])
            ->first();

        $lastOs = (int)($lastAgg->os ?? 0);
        $lastL0 = (int)($lastAgg->l0 ?? 0);
        $lastLt = (int)($lastAgg->lt ?? 0);

        $lastRr = $lastOs > 0 ? round(($lastL0 / $lastOs) * 100, 2) : null;
        $lastPl = $lastOs > 0 ? round(($lastLt / $lastOs) * 100, 2) : null;

        $cardsMtd = [
        'os' => [
            'value' => $lastOs,
            'base'  => $eomOs,
            'delta' => $lastOs - $eomOs,
            'label' => 'Growth (MtoD)',
        ],
        'l0' => [
            'value' => $lastL0,
            'base'  => $eomL0,
            'delta' => $lastL0 - $eomL0,
            'label' => 'Growth (MtoD)',
        ],
        'lt' => [
            'value' => $lastLt,
            'base'  => $eomLt,
            'delta' => $lastLt - $eomLt,
            'label' => 'Growth (MtoD)',
        ],
        'rr' => [
            'value' => $lastRr,
            'base'  => $eomRr,
            'delta' => (is_null($lastRr) || is_null($eomRr)) ? null : round($lastRr - $eomRr, 2), // points
            'label' => 'Growth (MtoD)',
        ],
        'pct_lt' => [
            'value' => $lastPl,
            'base'  => $eomPl,
            'delta' => (is_null($lastPl) || is_null($eomPl)) ? null : round($lastPl - $eomPl, 2), // points
            'label' => 'Growth (MtoD)',
        ],
        ];

        $cardsMtdMeta = [
        'eomMonth' => $eomMonth,
        'lastDate' => $latestPosDate,
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

        $lastVisitMap = RoVisit::query()
            ->selectRaw('account_no, MAX(visit_date) as last_visit_date')
            ->groupBy('account_no')
            ->pluck('last_visit_date', 'account_no')
            ->toArray();

        $plannedTodayMap = RoVisit::query()
            ->select(['account_no', 'status', 'visit_date'])
            ->where('user_id', (int)$me->id)
            ->whereDate('visit_date', $today)
            ->get()
            ->keyBy('account_no');

        $attachVisitMeta = function ($rows) use ($lastVisitMap, $plannedTodayMap) {
            return collect($rows)->map(function ($r) use ($lastVisitMap, $plannedTodayMap) {
                $acc = (string)($r->account_no ?? '');

                $r->last_visit_date = $acc !== '' ? ($lastVisitMap[$acc] ?? null) : null;

                $row = ($acc !== '' && $plannedTodayMap->has($acc)) ? $plannedTodayMap->get($acc) : null;
                $r->planned_today   = $row ? 1 : 0;
                $r->plan_visit_date = $row ? (string)($row->visit_date ?? null) : null;
                $r->plan_status     = $row ? (string)($row->status ?? 'planned') : null;

                return $r;
            });
        };

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
        // - LT EOM -> DPK hari ini (kritikal migrasi FE)
        // - LT EOM -> L0 hari ini (cure sementara, rawan bounce)
        // =============================
        $ltEomToDpkAgg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("COUNT(*) as noa, ROUND(SUM(la.outstanding)) as os")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->where(function ($q) {
                $q->where('m.ft_pokok', 1)->orWhere('m.ft_bunga', 1);
            })
            ->where(function ($q) {
                $q->where('la.ft_pokok', 2)->orWhere('la.ft_bunga', 2);
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

        // bounce risk flag (rule sederhana tapi TLRO friendly)
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

            // EOM -> Today (yang kamu jadikan fokus)
            'lt_eom_to_dpk_noa' => $ltEomToDpkNoa,
            'lt_eom_to_dpk_os'  => $ltEomToDpkOs,
            'lt_eom_to_l0_noa'  => $ltEomToL0Noa,
            'lt_eom_to_l0_os'   => $ltEomToL0Os,

            // sinyal: L0 naik & LT turun (indikasi cure), tapi ada JT dekat
            'signal_cure'   => (!is_null($deltaL0) && !is_null($deltaLT) && $deltaL0 > 0 && $deltaLT < 0),
            'signal_jtsoon' => ($jtNext2Noa > 0),
            'signal_bounce_risk' => (
                (!is_null($deltaL0) && !is_null($deltaLT) && $deltaL0 > 0 && $deltaLT < 0) && ($jtNext2Noa > 0)
            ),
        ];

        // =============================
        // TABEL 1) JT bulan ini (maturity_date)
        // =============================
        $now        = Carbon::parse($latestPosDate);
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd   = $now->copy()->endOfMonth()->toDateString();

        $dueThisMonth = DB::table('loan_accounts as la')
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                  ->whereDate('p.position_date', $prevPosDate);
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as outstanding"),
                'la.maturity_date',
                'la.dpd',
                'la.kolek',

                // prev for progress (H-1 -> H)
                DB::raw("COALESCE(p.ft_pokok, 0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga, 0) as prev_ft_bunga"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),
            ])
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereNotNull('la.maturity_date')
            ->whereBetween('la.maturity_date', [$monthStart, $monthEnd])
            ->orderBy('la.maturity_date')
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        // =============================
        // TABEL 2) COHORT: LT EOM bulan lalu (snapshot) -> status posisi terakhir
        // =============================
        $ltEom = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
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

                // snapshot EOM (basis cohort)
                DB::raw("COALESCE(m.ft_pokok,0) as eom_ft_pokok"),
                DB::raw("COALESCE(m.ft_bunga,0) as eom_ft_bunga"),

                // prev hari H-1 (opsional)
                DB::raw("COALESCE(p.ft_pokok,0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga,0) as prev_ft_bunga"),
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

                // prev for progress (H-1 -> H)
                DB::raw("COALESCE(p.ft_pokok, 0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga, 0) as prev_ft_bunga"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),
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
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as os"),
                'la.ft_pokok',
                'la.ft_bunga',
                'la.dpd',
                'la.kolek',

                // prev for progress (H-1 -> H)
                DB::raw("COALESCE(p.ft_pokok, 0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga, 0) as prev_ft_bunga"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),
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
        $dueThisMonth = $attachVisitMeta($dueThisMonth);
        $ltEom        = $attachVisitMeta($ltEom);
        $jtAngsuran   = $attachVisitMeta($jtAngsuran);
        $osBig        = $attachVisitMeta($osBig);

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

        return view('kpi.ro.os_daily', [
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),

            'labels' => $labels,
            'series' => $series,

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

            'cardsMtd' => $cardsMtd,                 // array 5 parameter
            'cardsMtdMeta' => [
            'eomMonth' => $eomMonth,              // 'YYYY-MM-01'
            'lastDate' => $latestPosDate,         // 'YYYY-MM-DD'
            ],

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
            // EOM -> DPK (paling kritikal)
            $eomToDpkNoa = (int)($bounce['lt_eom_to_dpk_noa'] ?? 0);
            $eomToDpkOs  = (int)($bounce['lt_eom_to_dpk_os'] ?? 0);
            if ($eomToDpkNoa > 0) {
                $risk[] = "Kritis: LT EOM → DPK (FT=2): {$eomToDpkNoa} NOA, OS ± Rp " . number_format($eomToDpkOs, 0, ',', '.') . " (potensi migrasi ke FE & OS RO turun).";
            }

            // EOM -> L0 (cure sementara)
            $eomToL0Noa = (int)($bounce['lt_eom_to_l0_noa'] ?? 0);
            $eomToL0Os  = (int)($bounce['lt_eom_to_l0_os'] ?? 0);
            if ($eomToL0Noa > 0) {
                $why[] = "Cure sementara: LT EOM → L0 hari ini: {$eomToL0Noa} NOA, OS ± Rp " . number_format($eomToL0Os, 0, ',', '.') . " (rawan bounce-back bila JT dekat tidak dibayar).";
            }

            // H-1 -> H: LT->DPK (harian)
            $ltToDpkNoa = (int)($bounce['lt_to_dpk_noa'] ?? 0);
            $ltToDpkOs  = (int)($bounce['lt_to_dpk_os'] ?? 0);
            if ($ltToDpkNoa > 0) {
                $risk[] = "Eskalasi harian: LT → DPK (FT=2): {$ltToDpkNoa} NOA, OS ± Rp " . number_format($ltToDpkOs, 0, ',', '.') . " → siapkan rencana migrasi/koordinasi FE & update LKH.";
            }

            // H-1 -> H: LT->L0 (harian)
            $ltToL0Noa = (int)($bounce['lt_to_l0_noa'] ?? 0);
            $ltToL0Os  = (int)($bounce['lt_to_l0_os'] ?? 0);
            if ($ltToL0Noa > 0) {
                $why[] = "Ada perbaikan LT → L0 hari ini (H-1→H): {$ltToL0Noa} NOA, OS ± Rp " . number_format($ltToL0Os, 0, ',', '.') . ".";
            }

            // JT dekat
            $jtNoa = (int)($bounce['jt_next2_noa'] ?? 0);
            $jtOs  = (int)($bounce['jt_next2_os'] ?? 0);
            if ($jtNoa > 0) {
                $risk[] = "Ada JT angsuran 1–2 hari ke depan: {$jtNoa} NOA, OS ± Rp " . number_format($jtOs, 0, ',', '.') . " → potensi LT naik lagi jika tidak bayar.";
            }

            // sinyal bounce-back
            $signalBounce = (bool)($bounce['signal_bounce_risk'] ?? false);
            if ($signalBounce) {
                $risk[] = "Sinyal *bounce-back*: L0 naik & LT turun (indikasi bayar/cure), tetapi ada JT dekat → besok RR bisa turun lagi jika gagal bayar.";
            }
        }

        return compact('good', 'bad', 'why', 'risk');
    }
}
