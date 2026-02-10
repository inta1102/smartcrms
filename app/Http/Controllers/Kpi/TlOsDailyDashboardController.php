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

        // ====== ambil bawahan TL (list user + ao_code) ======
        $staff = $this->subordinateStaffForLeader((int)$me->id);

        // fallback: kalau tidak ada bawahan, pakai TL sendiri jika punya ao_code
        if ($staff->isEmpty()) {
            $selfAo = str_pad(trim((string)($me->ao_code ?? '')), 6, '0', STR_PAD_LEFT);
            if ($selfAo !== '' && $selfAo !== '000000') {
                $staff = collect([(object)[
                    'id'      => (int)$me->id,
                    'name'    => (string)($me->name ?? 'Saya'),
                    'level'   => (string)($me->level ?? ''),
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
            ->when(!empty($aoCodes), fn($q) => $q->whereIn(DB::raw("LPAD(TRIM(ao_code),6,'0')"), $aoCodes))
            ->groupBy('d', 'ao_code')
            ->orderBy('d')
            ->get();

        // map[ao_code][date] = os_total
        $map = [];
        foreach ($rows as $r) {
            $map[$r->ao_code][$r->d] = (int)$r->os_total;
        }

        // ====== datasets per staff (1 garis per orang/ao_code) ======
        // missing date: null (bukan 0) supaya chart putus, tidak misleading
        $datasets = $staff->map(function ($u) use ($labels, $map) {
            $data = [];
            foreach ($labels as $d) {
                $data[] = $map[$u->ao_code][$d] ?? null;
            }

            return [
                'key'   => $u->ao_code, // buat legend / toggling
                'label' => "{$u->name} ({$u->level}) - {$u->ao_code}",
                'data'  => $data,
            ];
        })->values()->all();

        // ====== summary card (TOTAL: latest vs prev day, across all staff) ======
        $latestOs = 0;
        $prevOs   = 0;

        if (count($labels) > 0) {
            $latestDate = $labels[count($labels) - 1];
            $prevDate   = count($labels) >= 2 ? $labels[count($labels) - 2] : null;

            foreach ($aoCodes as $ao) {
                $latestOs += (int)($map[$ao][$latestDate] ?? 0);
                if ($prevDate) $prevOs += (int)($map[$ao][$prevDate] ?? 0);
            }
        }

        $delta = $latestOs - $prevOs;

        // ====== top AO pada latestDate (OS terbesar) ======
        $topAo = [];
        if (!empty($labels) && !empty($aoCodes)) {
            $latestDate = $labels[count($labels) - 1];

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
        ]);
    }

    /**
     * Ambil staff bawahan leader (TL/Wisnu) dari org_assignments aktif,
     * hasil: collection of {id,name,level,ao_code}
     */
    private function subordinateStaffForLeader(int $leaderUserId)
    {
        // ambil user_id bawahan aktif (leader_id sesuai struktur tabelmu)
        $subIds = OrgAssignment::query()
            ->active()
            ->where('leader_id', $leaderUserId)
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        if (empty($subIds)) return collect();

        return DB::table('users')
            ->select(['id','name','level','ao_code'])
            ->whereIn('id', $subIds)
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code) <> ''")
            ->orderBy('name')
            ->get()
            ->map(function ($u) {
                $u->ao_code = str_pad(trim((string)$u->ao_code), 6, '0', STR_PAD_LEFT);
                return $u;
            })
            ->filter(fn($u) => $u->ao_code !== '' && $u->ao_code !== '000000')
            ->values();
    }
}
