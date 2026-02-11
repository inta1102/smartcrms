<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\OrgAssignment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TlOsDailyDashboardController extends Controller
{
    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        // ===== range default: last 30 days dari data yang ada =====
        $latest = DB::table('kpi_os_daily_aos')->max('position_date');
        $latest = $latest ? Carbon::parse($latest) : now();

        $to   = $request->query('to') ? Carbon::parse($request->query('to')) : $latest;
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : $to->copy()->subDays(29);

        $from = $from->startOfDay();
        $to   = $to->endOfDay();

        // ===== ambil staff bawahan TL =====
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

        // ao options (dropdown)
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

        // ===== labels tanggal lengkap (biar tanggal bolong tetap ada) =====
        $labels = [];
        $cursor = $from->copy()->startOfDay();
        $end    = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $labels[] = $cursor->toDateString();
            $cursor->addDay();
        }

        // ===== ambil data harian per ao_code =====
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

        // map[ao_code][date] = ['os_total'=>..., 'os_l0'=>..., 'os_lt'=>...]
        $map = [];
        foreach ($rows as $r) {
            $map[$r->ao_code][$r->d] = [
                'os_total' => (int)($r->os_total ?? 0),
                'os_l0'    => (int)($r->os_l0 ?? 0),
                'os_lt'    => (int)($r->os_lt ?? 0),
            ];
        }

        // ===== summary total (latest vs prev) across staff =====
        $latestDate = count($labels) ? $labels[count($labels) - 1] : null;
        $prevDate   = count($labels) >= 2 ? $labels[count($labels) - 2] : null;

        $latestOs = 0;
        $prevOs   = 0;

        if ($latestDate) {
            foreach ($aoCodes as $ao) {
                $latestOs += (int)($map[$ao][$latestDate]['os_total'] ?? 0);
                if ($prevDate) $prevOs += (int)($map[$ao][$prevDate]['os_total'] ?? 0);
            }
        }
        $delta = $latestOs - $prevOs;

        // ===== inject OS terakhir ke staff untuk sort legend lebih enak =====
        $staff = $staff->map(function ($u) use ($map, $latestDate) {
            $u->os_latest = $latestDate ? (int)($map[$u->ao_code][$latestDate]['os_total'] ?? 0) : 0;
            return $u;
        })->sortByDesc('os_latest')->values();

        // refresh aoCodes mengikuti urutan staff
        $aoCodes = $staff->pluck('ao_code')->unique()->values()->all();

        // ===== builder dataset per metric =====
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

                // kalau tidak ada snapshot di hari itu: null (biar putus)
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

                $den = (int)($osTotal ?? 0);
                $rr = $den > 0 ? ((int)($osL0 ?? 0) / $den) * 100 : null;
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

        // ===== posisi terakhir loan_accounts untuk tabel bawah =====
        $latestPosDate = $latestDate ? Carbon::parse($latestDate)->toDateString() : now()->toDateString();

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

        // 2) LT posisi terakhir
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
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes))
            ->where(function ($q) {
                $q->where('la.ft_pokok', 1)->orWhere('la.ft_bunga', 1);
            })
            ->orderByDesc('la.outstanding')
            ->limit(300)
            ->get();

        // 3) L0 -> LT bulan ini (pakai snapshot_monthly bulan lalu vs posisi terakhir)
        $prevSnapMonth = Carbon::parse($latestPosDate)->subMonth()->startOfMonth()->toDateString();
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

        // 4) JT angsuran minggu ini
        $weekStartC = Carbon::parse($latestPosDate)->startOfWeek(Carbon::MONDAY);
        $weekEndC   = Carbon::parse($latestPosDate)->endOfWeek(Carbon::SUNDAY);

        $weekStart = $weekStartC->toDateString();
        $weekEnd   = $weekEndC->toDateString();

        // due_date = YYYY-MM- + installment_day (dibaca pada bulan posisi terakhir)
        $ym = Carbon::parse($latestPosDate)->format('Y-m');

        // bikin expression due_date (MySQL)
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
            // ✅ FILTER MINGGU INI YANG BENAR
            ->whereBetween(DB::raw($dueDateExpr), [$weekStart, $weekEnd])
            ->orderBy(DB::raw($dueDateExpr))
            ->orderByDesc('la.outstanding')
            ->limit(300)
            ->get();

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

        return view('kpi.tl.os_daily', [
            'from'     => $from->toDateString(),
            'to'       => $to->toDateString(),

            'labels'   => $labels,
            'datasetsByMetric' => $datasetsByMetric,

            'latestOs' => $latestOs,
            'prevOs'   => $prevOs,
            'delta'    => $delta,

            'staff'    => $staff,
            'aoCount'  => count($aoCodes),

            'latestDate' => $latestDate,
            'prevDate'   => $prevDate,
            'latestPosDate' => $latestPosDate,

            'aoOptions' => $aoOptions,
            'aoFilter'  => $aoFilter,

            // tables
            'dueThisMonth' => $dueThisMonth,
            'dueMonthLabel' => Carbon::parse($latestPosDate)->translatedFormat('F Y'),

            'ltLatest' => $ltLatest,

            'migrasiTunggakan' => $migrasiTunggakan,
            'prevSnapMonth'    => $prevSnapMonth,

            'weekStart' => $weekStart,
            'weekEnd'   => $weekEnd,
            'jtAngsuran' => $jtAngsuran,

            'bigThreshold' => $bigThreshold,
            'osBig' => $osBig,
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
