<?php

namespace App\Http\Controllers\Kpi;

use App\Models\KpiRoMonthly;
use Carbon\Carbon;
use Illuminate\Http\Request;


class KpiRoController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        abort_unless($u && !empty($u->ao_code), 403);

        $ao = str_pad(trim((string)$u->ao_code), 6, '0', STR_PAD_LEFT);

        $period = $request->get('period')
            ? Carbon::parse($request->get('period'))->startOfMonth()
            : now()->startOfMonth();

        $periodMonth = $period->toDateString();
        $prevMonth   = $period->copy()->subMonth()->startOfMonth()->toDateString();

        // realtime bulan ini
        $rt = KpiRoMonthly::query()
            ->whereDate('period_month', $periodMonth)
            ->where('ao_code', $ao)
            ->where('calc_mode', 'realtime')
            ->orderByDesc('updated_at')
            ->first();

        // EOM bulan lalu (locked)
        $eomPrev = KpiRoMonthly::query()
            ->whereDate('period_month', $prevMonth)
            ->where('ao_code', $ao)
            ->where('calc_mode', 'eom')
            ->whereNotNull('locked_at')
            ->orderByDesc('locked_at')
            ->first();

        // fallback: kalau belum ada eom, tampilkan realtime bulan lalu (biar RO tetap lihat historis)
        $rtPrev = null;
        if (!$eomPrev) {
            $rtPrev = KpiRoMonthly::query()
                ->whereDate('period_month', $prevMonth)
                ->where('ao_code', $ao)
                ->where('calc_mode', 'realtime')
                ->orderByDesc('updated_at')
                ->first();
        }

        return view('kpi.ro.index', compact('ao', 'periodMonth', 'prevMonth', 'rt', 'eomPrev', 'rtPrev'));
    }
}
