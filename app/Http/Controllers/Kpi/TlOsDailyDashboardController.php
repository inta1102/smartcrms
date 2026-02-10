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

        // range default: last 30 days dari data yang ada
        $latest = DB::table('kpi_os_daily_aos')->max('position_date');
        $latest = $latest ? Carbon::parse($latest) : now();

        $to   = $request->query('to') ? Carbon::parse($request->query('to')) : $latest;
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : $to->copy()->subDays(29);

        // pakai date saja (Y-m-d)
        $from = $from->startOfDay();
        $to   = $to->endOfDay();

        // ====== ambil bawahan leader (TL/Wisnu) ======
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

        $aoCodes = $staff->pluck('ao_code')->unique()->values()->all();

        // ====== labels tanggal lengkap (biar tanggal bolong tetap ada) ======
        $labels = [];
        $cursor = $from->copy()->startOfDay();
        $end    = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $labels[] = $cursor->toDateString();
            $cursor->addDay();
        }

        // ====== ambil data per hari per ao_code (multi series) ======
        // NOTE: pakai SUM untuk safety kalau ada duplikasi row ao_code+date
        $rows = DB::table('kpi_os_daily_aos')
            ->selectRaw("
                position_date as d,
                LPAD(TRIM(ao_code),6,'0') as ao_code,
                ROUND(SUM(os_total)) as os_total,
                ROUND(SUM(noa_total)) as noa_total
            ")
            ->whereBetween('position_date', [$from->toDateString(), $to->toDateString()])
            ->when(!empty($aoCodes), fn ($q) => $q->whereIn(DB::raw("LPAD(TRIM(ao_code),6,'0')"), $aoCodes))
            ->groupBy('d', 'ao_code')
            ->orderBy('d')
            ->get();

        // map[ao_code][date] = os_total
        $map = [];
        foreach ($rows as $r) {
            $map[$r->ao_code][$r->d] = (int) $r->os_total;
        }

        // ====== summary card (TOTAL: latest vs prev day, across all staff) ======
        $latestOs = 0;
        $prevOs   = 0;
        $delta    = 0;

        $latestDate = count($labels) ? $labels[count($labels) - 1] : null;
        $prevDate   = count($labels) >= 2 ? $labels[count($labels) - 2] : null;

        if ($latestDate) {
            foreach ($aoCodes as $ao) {
                $latestOs += (int) ($map[$ao][$latestDate] ?? 0);
                if ($prevDate) {
                    $prevOs += (int) ($map[$ao][$prevDate] ?? 0);
                }
            }
            $delta = $latestOs - $prevOs;
        }

        // ====== inject OS terakhir ke staff (buat UI card: tampil OS bukan ao_code) ======
        $staff = $staff->map(function ($u) use ($map, $latestDate) {
            $u->os_latest = $latestDate ? (int) ($map[$u->ao_code][$latestDate] ?? 0) : 0;
            return $u;
        })
        ->sortByDesc('os_latest')
        ->values();

        // refresh aoCodes mengikuti urutan staff (biar dataset/card consistent)
        $aoCodes = $staff->pluck('ao_code')->unique()->values()->all();

        // ====== datasets per staff (1 garis per orang/ao_code) ======
        // missing date: null (bukan 0) supaya chart putus, tidak misleading
        $datasets = $staff->map(function ($u) use ($labels, $map) {
            $data = [];
            foreach ($labels as $d) {
                $data[] = $map[$u->ao_code][$d] ?? null;
            }

            return [
                'key'   => $u->ao_code, // buat toggling
                'label' => "{$u->name} ({$u->level})", // lebih bersih (AO code nanti di card aja)
                'data'  => $data,
            ];
        })->values()->all();

        // ====== Debitur jatuh tempo bulan ini (scope TL) ======
        $now = now();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd   = $now->copy()->endOfMonth()->toDateString();

        $dueThisMonth = DB::table('loan_accounts as la')
            ->select([
                'la.account_no',
                'la.customer_name',      // sesuaikan kolom
                'la.ao_code',
                'la.outstanding',          // sesuaikan kolom OS di loan_accounts
                'la.maturity_date',
                'la.kolek',             // optional
                'la.dpd',               // optional
            ])
            ->whereNotNull('la.maturity_date')
            ->whereBetween('la.maturity_date', [$monthStart, $monthEnd])
            ->when(!empty($aoCodes), function ($q) use ($aoCodes) {
                $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes);
            })
            ->orderBy('la.maturity_date')
            ->orderByDesc('la.outstanding')
            ->limit(200) // biar aman, bisa paginate nanti
            ->get();


        // ====== top AO pada latestDate (OS terbesar) ======
        $topAo = [];
        if ($latestDate && !empty($aoCodes)) {
            $topAo = DB::table('kpi_os_daily_aos as d')
                ->leftJoin('users as u', DB::raw("LPAD(TRIM(u.ao_code),6,'0')"), '=', DB::raw("LPAD(TRIM(d.ao_code),6,'0')"))
                ->select([
                    DB::raw("LPAD(TRIM(d.ao_code),6,'0') as ao_code"),
                    'u.name',
                    DB::raw("ROUND(SUM(d.os_total)) as os_total"),
                    DB::raw("ROUND(SUM(d.noa_total)) as noa_total"),
                ])
                ->whereDate('d.position_date', $latestDate)
                ->whereIn(DB::raw("LPAD(TRIM(d.ao_code),6,'0')"), $aoCodes)
                ->groupBy('ao_code', 'u.name')
                ->orderByDesc('os_total')
                ->limit(15)
                ->get();
        }

        // =========================
        // Debitur "Migrasi Tunggakan":
        // bulan lalu ft_pokok=0 & ft_bunga=0 -> posisi terakhir jadi >0
        // =========================
        $latestPosDate = $latestDate ? Carbon::parse($latestDate)->toDateString() : now()->toDateString();

        // bulan ini (untuk label section)
        $monthStart = Carbon::parse($latestPosDate)->startOfMonth()->toDateString();
        $monthEnd   = Carbon::parse($latestPosDate)->endOfMonth()->toDateString();

        // baseline bulan lalu = snapshot_month = startOfMonth(prev)
        $prevSnapMonth = Carbon::parse($latestPosDate)->subMonth()->startOfMonth()->toDateString();

        $perPage = (int) $request->query('per_page', 10);
        if ($perPage <= 0) $perPage = 10;
        if ($perPage > 200) $perPage = 200; // safety

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
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes))
            ->where('m.ft_pokok', 0)
            ->where('m.ft_bunga', 0)
            ->where(function ($q) {
                $q->where('la.ft_pokok', '>', 0)
                ->orWhere('la.ft_bunga', '>', 0);
            })
            ->whereDate('la.position_date', $latestPosDate)
            ->orderByDesc('os')
            ->paginate($perPage)
            ->appends($request->query()); // penting: keep from/to/per_page


        return view('kpi.tl.os_daily', [
            'from'     => $from->toDateString(),
            'to'       => $to->toDateString(),
            'labels'   => $labels,
            'datasets' => $datasets,

            'latestOs' => $latestOs,
            'prevOs'   => $prevOs,
            'delta'    => $delta,

            'topAo'    => $topAo,
            'staff'    => $staff,
            'aoCount'  => count($aoCodes),

            // opsional kalau kamu mau tampilkan di view
            'latestDate' => $latestDate,
            'prevDate'   => $prevDate,
            'dueThisMonth' => $dueThisMonth,
            'dueMonthLabel' => now()->translatedFormat('F Y'),

            'migrasiTunggakan' => $migrasiTunggakan,
            'prevSnapMonth'    => $prevSnapMonth,
            'latestPosDate'    => $latestPosDate,
        ]);
    }

    /**
     * Ambil staff bawahan leader dari org_assignments aktif,
     * hasil: collection of {id,name,level,ao_code}
     */
    private function subordinateStaffForLeader(int $leaderUserId)
    {
        $subIds = OrgAssignment::query()
            ->active()
            ->where('leader_id', $leaderUserId) // sesuai struktur tabelmu
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
