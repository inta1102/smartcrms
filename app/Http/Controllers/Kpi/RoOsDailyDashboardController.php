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

        // =============================
        // RANGE default: last 30 days
        // =============================
        $latest = DB::table('kpi_os_daily_aos')
            ->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [$ao])
            ->max('position_date');

        $latest = $latest ? Carbon::parse($latest) : now();

        $to   = $request->query('to')   ? Carbon::parse($request->query('to'))   : $latest;
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : $to->copy()->subDays(29);

        $from = $from->startOfDay();
        $to   = $to->endOfDay();

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
            'os' => [
                'label' => 'OS',
                'value' => $latestOs,
                'prev'  => $prevOs,
                'delta' => $deltaOs,
            ],
            'l0' => [
                'label' => 'L0',
                'value' => $latestL0,
                'prev'  => $prevL0,
                'delta' => $deltaL0,
            ],
            'lt' => [
                'label' => 'LT',
                'value' => $latestLT,
                'prev'  => $prevLT,
                'delta' => $deltaLT,
            ],
            'rr' => [
                'label' => 'RR (%L0)',
                'value' => $latestRR,
                'prev'  => $prevRR,
                'delta' => $deltaRR,
            ],
            'pct_lt' => [
                'label' => '%LT',
                'value' => $latestPctLt,
                'prev'  => $prevPctLt,
                'delta' => $deltaPctLt,
            ],
        ];

        // =============================
        // Posisi terakhir loan_accounts untuk tabel bawah
        // =============================
        $latestPosDate = $latestDate ? Carbon::parse($latestDate)->toDateString() : now()->toDateString();
        $prevSnapMonth = Carbon::parse($latestPosDate)->subMonth()->startOfMonth()->toDateString();

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
        // 1) LT -> L0 hari ini (dibanding posisi kemarin)
        // 2) JT angsuran 1-2 hari ke depan (potensi balik LT)
        // =============================
        $prevPosDate = $prevDate ?: Carbon::parse($latestPosDate)->subDay()->toDateString();

        // 1) LT -> L0 (posisi kemarin LT, hari ini L0)
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
            // prev LT
            ->where(function ($q) {
                $q->where('p.ft_pokok', '>', 0)->orWhere('p.ft_bunga', '>', 0);
            })
            // today L0
            ->where('t.ft_pokok', 0)
            ->where('t.ft_bunga', 0)
            ->first();

        $ltToL0Noa = (int)($ltToL0Agg->noa ?? 0);
        $ltToL0Os  = (int)($ltToL0Agg->os ?? 0);

        // 2) JT angsuran 1-2 hari ke depan (robust: next due date relative to latestPosDate)
        $pos = Carbon::parse($latestPosDate);
        $d1  = $pos->copy()->addDay()->toDateString();
        $d2  = $pos->copy()->addDays(2)->toDateString();

        $posLiteral = $pos->toDateString(); // dipakai di SQL literal

        // base due date bulan ini & bulan depan
        $dueBase = "STR_TO_DATE(CONCAT(DATE_FORMAT('$posLiteral','%Y-%m'),'-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')";
        $dueNext = "STR_TO_DATE(CONCAT(DATE_FORMAT(DATE_ADD('$posLiteral', INTERVAL 1 MONTH),'%Y-%m'),'-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')";

        // pilih due date terdekat >= posisi terakhir
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

        // bounce risk flag (rule sederhana tapi sangat “TLRO friendly”)
        $bounce = [
            'prevPosDate' => $prevPosDate,
            'd1' => $d1,
            'd2' => $d2,

            'lt_to_l0_noa' => $ltToL0Noa,
            'lt_to_l0_os'  => $ltToL0Os,

            'jt_next2_noa' => $jtNext2Noa,
            'jt_next2_os'  => $jtNext2Os,

            // sinyal: L0 naik & LT turun (indikasi bayar/cure), tapi ada JT dekat
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
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as outstanding"),
                'la.maturity_date',
                'la.dpd',
                'la.kolek',
            ])
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereNotNull('la.maturity_date')
            ->whereBetween('la.maturity_date', [$monthStart, $monthEnd])
            ->orderBy('la.maturity_date')
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        // =============================
        // TABEL 2) LT posisi terakhir
        // =============================
        $ltLatest = DB::table('loan_accounts as la')
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
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->where(function ($q) {
                $q->where('la.ft_pokok', 1)->orWhere('la.ft_bunga', 1);
            })
            ->orderByDesc('la.dpd')
            ->limit(200)
            ->get();

        // =============================
        // TABEL 3) JT Angsuran minggu ini
        // FIX: robust lintas bulan (pakai approach sebelumnya)
        // =============================
        $weekStart = Carbon::parse($latestPosDate)->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd   = Carbon::parse($latestPosDate)->endOfWeek(Carbon::SUNDAY)->toDateString();

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
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->where('la.outstanding', '>=', 500000000)
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        // =============================
        // Inject visit meta ke tabel-tabel
        // =============================
        $dueThisMonth = $attachVisitMeta($dueThisMonth);
        $ltLatest     = $attachVisitMeta($ltLatest);
        $jtAngsuran   = $attachVisitMeta($jtAngsuran);
        $osBig        = $attachVisitMeta($osBig);

        // =============================
        // Insight text (upgrade + bounce)
        // =============================
        $insight = $this->buildInsight([
            'latestOs'     => $latestOs,
            'prevOs'       => $prevOs ?? 0,
            'deltaOs'      => $deltaOs ?? 0,

            'latestL0'     => $latestL0,
            'prevL0'       => $prevL0 ?? 0,
            'deltaL0'      => $deltaL0,

            'latestLT'     => $latestLT,
            'prevLT'       => $prevLT ?? 0,
            'deltaLT'      => $deltaLT,

            'latestRR'     => $latestRR,
            'prevRR'       => $prevRR,
            'deltaRR'      => $deltaRR,

            'latestPctLt'  => $latestPctLt,
            'prevPctLt'    => $prevPctLt,
            'deltaPctLt'   => $deltaPctLt,

            'l0ToLtNoa'    => $l0ToLtNoa,
            'l0ToLtOs'     => $l0ToLtOs,

            // bounce pack
            'bounce' => $bounce,
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

            'ltLatest'   => $ltLatest,
            'jtAngsuran' => $jtAngsuran,
            'weekStart'  => $weekStart,
            'weekEnd'    => $weekEnd,

            'osBig'   => $osBig,

            // NEW
            'cards'   => $cards,
            'bounce'  => $bounce,
            'insight' => $insight,
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

        // Bounce back insight
        $bounce = (array)($x['bounce'] ?? []);
        if (!empty($bounce)) {
            $ltToL0Noa = (int)($bounce['lt_to_l0_noa'] ?? 0);
            $ltToL0Os  = (int)($bounce['lt_to_l0_os'] ?? 0);
            if ($ltToL0Noa > 0) {
                $why[] = "Ada perbaikan LT → L0 hari ini: {$ltToL0Noa} NOA, OS ± Rp " . number_format($ltToL0Os, 0, ',', '.') . ".";
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
}
