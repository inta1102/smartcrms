<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
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

        // range default: last 30 days dari data yang ada (untuk ao ini)
        $latest = DB::table('kpi_os_daily_aos')
            ->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [$ao])
            ->max('position_date');

        $latest = $latest ? Carbon::parse($latest) : now();

        $to   = $request->query('to') ? Carbon::parse($request->query('to')) : $latest;
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : $to->copy()->subDays(29);

        $from = $from->startOfDay();
        $to   = $to->endOfDay();

        // labels lengkap
        $labels = [];
        $cursor = $from->copy()->startOfDay();
        $end    = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $labels[] = $cursor->toDateString();
            $cursor->addDay();
        }

        // ambil rows harian untuk AO ini
        $rows = DB::table('kpi_os_daily_aos')
            ->selectRaw("
                position_date as d,
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

            $rr = ($osTotal > 0) ? round(($osL0 / $osTotal) * 100, 2) : null;
            $pctLt = ($osTotal > 0) ? round(($osLt / $osTotal) * 100, 2) : null;

            $byDate[$r->d] = [
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

        // latest vs H-1 (untuk KPI summary + insight)
        $latestDate = count($labels) ? $labels[count($labels)-1] : null;
        $prevDate   = count($labels) >= 2 ? $labels[count($labels)-2] : null;

        $latestPack = $latestDate ? ($byDate[$latestDate] ?? null) : null;
        $prevPack   = $prevDate   ? ($byDate[$prevDate] ?? null) : null;

        $latestOs = (int)($latestPack['os_total'] ?? 0);
        $prevOs   = (int)($prevPack['os_total'] ?? 0);
        $deltaOs  = $latestOs - $prevOs;

        $latestL0 = (int)($latestPack['os_l0'] ?? 0);
        $latestLT = (int)($latestPack['os_lt'] ?? 0);
        $latestRR = $latestPack['rr'] ?? null;
        $latestPctLt = $latestPack['pct_lt'] ?? null;

        // ===== Insight penyebab (yang feasible dari data sekarang) =====
        // L0 -> LT bulan ini: pakai snapshot_monthly bulan lalu 0 lalu sekarang ft > 0
        $latestPosDate = $latestDate ?: now()->toDateString();
        $prevSnapMonth = Carbon::parse($latestPosDate)->subMonth()->startOfMonth()->toDateString();

        $l0ToLtAgg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(la.outstanding)) as os
            ")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->where('m.ft_pokok', 0)
            ->where('m.ft_bunga', 0)
            ->where(function ($q) {
                $q->where('la.ft_pokok', '>', 0)->orWhere('la.ft_bunga', '>', 0);
            })
            ->whereDate('la.position_date', $latestPosDate)
            ->first();

        $l0ToLtNoa = (int)($l0ToLtAgg->noa ?? 0);
        $l0ToLtOs  = (int)($l0ToLtAgg->os ?? 0);

        // ===== JT Bulan ini (maturity_date) =====
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

        // ===== JT Angsuran Minggu Ini (FIX) =====
        // definisi: ambil installment_day, hitung tanggal jatuh temponya di bulan posisi terakhir
        // lalu FILTER <= weekEnd
        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd   = $now->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

        $startDay = (int)Carbon::parse($weekStart)->day;
        $endDay   = (int)Carbon::parse($weekEnd)->day;

        // asumsi minggu ini masih di bulan yang sama (kalau lintas bulan, nanti kita improve)
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
            ])
            ->whereDate('la.position_date', $latestPosDate)
            ->whereRaw("LPAD(TRIM(la.ao_code),6,'0') = ?", [$ao])
            ->whereNotNull('la.installment_day')
            ->whereBetween('la.installment_day', [$startDay, $endDay]) // ✅ ini yang kemarin belum ada
            ->orderBy('la.installment_day')
            ->orderByDesc('os')
            ->limit(200)
            ->get();

        // ===== OS > 500jt =====
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
            ->orderByDesc('os')
            ->limit(200)
            ->get();

        // ===== LT posisi terakhir =====
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
                $q->where('la.ft_pokok', '=', 1)->orWhere('la.ft_bunga', '=', 1);
            })
            ->orderByDesc('os')
            ->limit(200)
            ->get();

        // ===== Insight text (baik/buruk) =====
        $insight = $this->buildInsight([
            'latestOs' => $latestOs,
            'prevOs'   => $prevOs,
            'deltaOs'  => $deltaOs,
            'latestRR' => $latestRR,
            'latestPctLt' => $latestPctLt,
            'l0ToLtNoa' => $l0ToLtNoa,
            'l0ToLtOs'  => $l0ToLtOs,
        ]);

        return view('kpi.ro.os_daily', [
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),

            'labels' => $labels,
            'series' => $series,

            'latestDate' => $latestDate,
            'prevDate'   => $prevDate,

            'latestOs' => $latestOs,
            'prevOs'   => $prevOs,
            'deltaOs'  => $deltaOs,

            'latestL0' => $latestL0,
            'latestLT' => $latestLT,
            'latestRR' => $latestRR,
            'latestPctLt' => $latestPctLt,

            'latestPosDate' => $latestPosDate,
            'prevSnapMonth' => $prevSnapMonth,

            'l0ToLtNoa' => $l0ToLtNoa,
            'l0ToLtOs'  => $l0ToLtOs,

            'dueThisMonth' => $dueThisMonth,
            'dueMonthLabel' => $now->translatedFormat('F Y'),

            'ltLatest' => $ltLatest,
            'jtAngsuran' => $jtAngsuran,
            'weekStart' => $weekStart,
            'weekEnd'   => $weekEnd,
            'osBig' => $osBig,

            'insight' => $insight,
        ]);
    }

    private function buildInsight(array $x): array
    {
        $good = [];
        $bad  = [];
        $why  = [];

        if (($x['deltaOs'] ?? 0) > 0) $good[] = "OS naik vs H-1 sebesar Rp " . number_format((int)$x['deltaOs'],0,',','.');
        if (($x['deltaOs'] ?? 0) < 0) $bad[]  = "OS turun vs H-1 sebesar Rp " . number_format(abs((int)$x['deltaOs']),0,',','.');

        $rr = $x['latestRR'];
        if (!is_null($rr)) {
            if ($rr >= 95) $good[] = "RR sangat baik (≥95%).";
            elseif ($rr >= 90) $good[] = "RR cukup baik (90–95%).";
            else $bad[] = "RR menurun/perlu perhatian (<90%).";
        }

        $pctLt = $x['latestPctLt'];
        if (!is_null($pctLt)) {
            if ($pctLt <= 5) $good[] = "%LT rendah (≤5%) – kualitas bagus.";
            elseif ($pctLt <= 10) $good[] = "%LT masih terkendali (5–10%).";
            else $bad[] = "%LT tinggi (>10%) – ada risiko kualitas portofolio.";
        }

        $noa = (int)($x['l0ToLtNoa'] ?? 0);
        $os  = (int)($x['l0ToLtOs'] ?? 0);
        if ($noa > 0) {
            $why[] = "Ada indikasi L0 → LT bulan ini sebanyak {$noa} NOA dengan OS ± Rp " . number_format($os,0,',','.') . ". Ini biasanya menekan RR dan menaikkan %LT.";
        } else {
            $why[] = "Tidak ada indikasi L0 → LT (bulan ini) berdasarkan snapshot bulan lalu vs posisi terakhir (bagus untuk stabilitas RR).";
        }

        // catatan kejujuran data
        // $why[] = "Catatan: penyebab OS naik/turun per rekening (harian) belum bisa diuraikan akurat kalau loan_accounts hanya menyimpan posisi terakhir. Kalau mau analisis sebab OS harian (rekening mana naik/turun), kita perlu snapshot harian per rekening.";

        return compact('good','bad','why');
    }
}
