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

            // RR di sini definisinya %L0 (sesuai chart kamu RR (%L0))
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
        // LATEST vs H-1 (summary)
        // ✅ FIX: pakai 2 tanggal terakhir yang BENAR-BENAR ADA DATA
        // (bukan sekadar last label), supaya delta & card growth valid
        // =============================
        $dataDates = array_keys($byDate);
        sort($dataDates); // ascending

        $latestDate = count($dataDates) ? $dataDates[count($dataDates) - 1] : null;
        $prevDate   = count($dataDates) >= 2 ? $dataDates[count($dataDates) - 2] : null;

        $latestPack = $latestDate ? ($byDate[$latestDate] ?? null) : null;
        $prevPack   = $prevDate ? ($byDate[$prevDate] ?? null) : null;

        $deltaMoney = function ($a, $b) {
            if ($a === null || $b === null) return null;
            return (int)$a - (int)$b;
        };

        $deltaPct = function ($a, $b) {
            if ($a === null || $b === null) return null;
            return round(((float)$a - (float)$b), 2); // percentage point (pp)
        };

        $latestOs     = $latestPack['os_total'] ?? null;
        $prevOs       = $prevPack['os_total'] ?? null;
        $deltaOs      = $deltaMoney($latestOs, $prevOs);

        $latestL0     = $latestPack['os_l0'] ?? null;
        $prevL0       = $prevPack['os_l0'] ?? null;
        $deltaL0      = $deltaMoney($latestL0, $prevL0);

        $latestLT     = $latestPack['os_lt'] ?? null;
        $prevLT       = $prevPack['os_lt'] ?? null;
        $deltaLT      = $deltaMoney($latestLT, $prevLT);

        $latestRR     = $latestPack['rr'] ?? null;
        $prevRR       = $prevPack['rr'] ?? null;
        $deltaRR      = $deltaPct($latestRR, $prevRR);

        $latestPctLt  = $latestPack['pct_lt'] ?? null;
        $prevPctLt    = $prevPack['pct_lt'] ?? null;
        $deltaPctLt   = $deltaPct($latestPctLt, $prevPctLt);

        // ✅ Cards: value + growth harian (buat TLRO)
        $cards = [
            'os' => [
                'title' => 'Latest OS',
                'type'  => 'money',
                'value' => is_null($latestOs) ? null : (int)$latestOs,
                'prev'  => is_null($prevOs) ? null : (int)$prevOs,
                'delta' => is_null($deltaOs) ? null : (int)$deltaOs,
            ],
            'l0' => [
                'title' => 'Latest L0',
                'type'  => 'money',
                'value' => is_null($latestL0) ? null : (int)$latestL0,
                'prev'  => is_null($prevL0) ? null : (int)$prevL0,
                'delta' => is_null($deltaL0) ? null : (int)$deltaL0,
            ],
            'lt' => [
                'title' => 'Latest LT',
                'type'  => 'money',
                'value' => is_null($latestLT) ? null : (int)$latestLT,
                'prev'  => is_null($prevLT) ? null : (int)$prevLT,
                'delta' => is_null($deltaLT) ? null : (int)$deltaLT,
            ],
            'rr' => [
                'title' => 'RR (%L0)',
                'type'  => 'pct',
                'value' => is_null($latestRR) ? null : (float)$latestRR,
                'prev'  => is_null($prevRR) ? null : (float)$prevRR,
                'delta' => is_null($deltaRR) ? null : (float)$deltaRR, // pp
            ],
            'pct_lt' => [
                'title' => '%LT',
                'type'  => 'pct',
                'value' => is_null($latestPctLt) ? null : (float)$latestPctLt,
                'prev'  => is_null($prevPctLt) ? null : (float)$prevPctLt,
                'delta' => is_null($deltaPctLt) ? null : (float)$deltaPctLt, // pp
            ],
        ];

        // Posisi terakhir loan_accounts untuk tabel bawah
        // ✅ pakai latestDate berbasis DATA; fallback ke tanggal "to" kalau tidak ada data
        $latestPosDate = $latestDate
            ? Carbon::parse($latestDate)->toDateString()
            : $to->copy()->toDateString();

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
        // FIX: robust lintas bulan
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
        // Insight text
        // =============================
        $insight = $this->buildInsight([
            'latestOs'     => $latestOs,
            'prevOs'       => $prevOs,
            'deltaOs'      => $deltaOs,

            'latestL0'     => $latestL0,
            'prevL0'       => $prevL0,
            'deltaL0'      => $deltaL0,

            'latestLT'     => $latestLT,
            'prevLT'       => $prevLT,
            'deltaLT'      => $deltaLT,

            'latestRR'     => $latestRR,
            'prevRR'       => $prevRR,
            'deltaRR'      => $deltaRR,

            'latestPctLt'  => $latestPctLt,
            'prevPctLt'    => $prevPctLt,
            'deltaPctLt'   => $deltaPctLt,

            'l0ToLtNoa'    => $l0ToLtNoa,
            'l0ToLtOs'     => $l0ToLtOs,
        ]);

        return view('kpi.ro.os_daily', [
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),

            'labels' => $labels,
            'series' => $series,

            'latestDate' => $latestDate,
            'prevDate'   => $prevDate,

            // ✅ untuk card baru (value + growth)
            'cards' => $cards,

            // === backward compatible (kalau blade lama masih pakai ini) ===
            'latestOs' => is_null($latestOs) ? 0 : (int)$latestOs,
            'prevOs'   => is_null($prevOs) ? 0 : (int)$prevOs,
            'deltaOs'  => is_null($deltaOs) ? 0 : (int)$deltaOs,

            'latestL0' => is_null($latestL0) ? 0 : (int)$latestL0,
            'latestLT' => is_null($latestLT) ? 0 : (int)$latestLT,
            'latestRR' => $latestRR,
            'latestPctLt' => $latestPctLt,

            // growth tambahan (biar gampang dipakai di UI / insight box)
            'prevL0'     => $prevL0,
            'prevLT'     => $prevLT,
            'prevRR'     => $prevRR,
            'prevPctLt'  => $prevPctLt,
            'deltaL0'    => $deltaL0,
            'deltaLT'    => $deltaLT,
            'deltaRR'    => $deltaRR,
            'deltaPctLt' => $deltaPctLt,

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
            'insight' => $insight,
        ]);
    }

    private function buildInsight(array $x): array
    {
        $good = [];
        $bad  = [];
        $why  = [];

        // ========= OS =========
        $dOs = $x['deltaOs'] ?? null;
        if (!is_null($dOs)) {
            if ($dOs > 0) $good[] = "OS naik vs H-1 sebesar Rp " . number_format((int)$dOs, 0, ',', '.');
            if ($dOs < 0) $bad[]  = "OS turun vs H-1 sebesar Rp " . number_format(abs((int)$dOs), 0, ',', '.');
        }

        // ========= L0 / LT Growth =========
        $dL0 = $x['deltaL0'] ?? null;
        if (!is_null($dL0)) {
            if ($dL0 > 0) $bad[]  = "L0 naik harian Rp " . number_format((int)$dL0, 0, ',', '.') . " → cek debitur pemicu & follow-up.";
            if ($dL0 < 0) $good[] = "L0 turun harian Rp " . number_format(abs((int)$dL0), 0, ',', '.') . " (indikasi pembayaran/penurunan tunggakan).";
        }

        $dLT = $x['deltaLT'] ?? null;
        if (!is_null($dLT)) {
            if ($dLT > 0) $bad[]  = "LT naik harian Rp " . number_format((int)$dLT, 0, ',', '.') . " → prioritas kunjungan/penagihan & input RKH.";
            if ($dLT < 0) $good[] = "LT turun harian Rp " . number_format(abs((int)$dLT), 0, ',', '.') . " (indikasi perbaikan portofolio).";
        }

        // ========= RR (%L0) =========
        $rr = $x['latestRR'] ?? null;
        if (!is_null($rr)) {
            if ($rr >= 95) $good[] = "RR sangat baik (≥95%).";
            elseif ($rr >= 90) $good[] = "RR cukup baik (90–95%).";
            else $bad[] = "RR perlu perhatian (<90%).";
        }

        $dRR = $x['deltaRR'] ?? null; // pp
        if (!is_null($dRR)) {
            if ($dRR > 0) $good[] = "RR membaik +" . number_format((float)$dRR, 2, ',', '.') . " pp (H vs H-1).";
            if ($dRR < 0) $bad[]  = "RR turun -" . number_format(abs((float)$dRR), 2, ',', '.') . " pp (H vs H-1).";
        }

        // ========= %LT =========
        $pctLt = $x['latestPctLt'] ?? null;
        if (!is_null($pctLt)) {
            if ($pctLt <= 5) $good[] = "%LT rendah (≤5%) – kualitas bagus.";
            elseif ($pctLt <= 10) $good[] = "%LT masih terkendali (5–10%).";
            else $bad[] = "%LT tinggi (>10%) – ada risiko kualitas portofolio.";
        }

        $dPctLt = $x['deltaPctLt'] ?? null; // pp
        if (!is_null($dPctLt)) {
            if ($dPctLt > 0) $bad[]  = "%LT naik +" . number_format((float)$dPctLt, 2, ',', '.') . " pp (H vs H-1) → sinyal risiko meningkat.";
            if ($dPctLt < 0) $good[] = "%LT turun -" . number_format(abs((float)$dPctLt), 2, ',', '.') . " pp (H vs H-1) → kualitas membaik.";
        }

        // ========= WHY (indikasi penyebab feasible) =========
        $noa = (int)($x['l0ToLtNoa'] ?? 0);
        $os  = (int)($x['l0ToLtOs'] ?? 0);
        if ($noa > 0) {
            $why[] = "Ada indikasi L0 → LT bulan ini sebanyak {$noa} NOA dengan OS ± Rp " . number_format($os, 0, ',', '.') . ". Ini biasanya menekan RR dan menaikkan %LT.";
        } else {
            $why[] = "Tidak ada indikasi L0 → LT (bulan ini) berdasarkan snapshot bulan lalu vs posisi terakhir (bagus untuk stabilitas RR).";
        }

        // tambahan korelasi sederhana
        if (!is_null($dOs) && $dOs > 0 && !is_null($dL0) && $dL0 > 0) {
            $why[] = "OS naik tapi L0 ikut naik → kemungkinan ada rollover/geser status di existing atau keterlambatan pembayaran pada sebagian account.";
        }
        if (!is_null($dLT) && $dLT > 0 && !is_null($dRR) && $dRR < 0) {
            $why[] = "LT naik dan RR turun → kemungkinan beberapa account bergeser ke tunggakan (cek aging DPD & account kontribusi terbesar).";
        }

        return compact('good', 'bad', 'why');
    }
}
