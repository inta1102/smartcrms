<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\KpiThreshold;
use Illuminate\Support\Facades\Gate;


class KpiAoController extends Controller
{
    private function resolvePeriodYmd(Request $request): string
    {
        $raw = trim((string) $request->query('period', ''));

        if ($raw === '') {
            return now()->startOfMonth()->toDateString();
        }

        // Accept YYYY-MM
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m', $raw)->startOfMonth()->toDateString();
        }

        // Accept full date
        try {
            return Carbon::parse($raw)->startOfMonth()->toDateString();
        } catch (\Throwable $e) {
            return now()->startOfMonth()->toDateString();
        }
    }

    public function show(Request $request, User $user)
    {
         

        // ✅ pembatasan akses AO sheet
        abort_unless(Gate::allows('kpi-ao-view', $user), 403);
        
        $periodYmd = $this->resolvePeriodYmd($request);
        $rrTh = KpiThreshold::for('rr_pct');


        // ✅ 1 row KPI AO pada bulan itu (period = DATE)
        $kpi = DB::table('kpi_ao_monthlies')
            ->where('user_id', $user->id)
            ->whereDate('period', '=', $periodYmd)
            ->first();

        $target = DB::table('kpi_ao_targets')
            ->where('user_id', $user->id)
            ->whereDate('period', $periodYmd)
            ->first();

        return view('kpi.ao.show', [
            'aoUser'      => $user,
            'kpi'         => $kpi,
            'target'      => $target,
            'periodYmd'   => $periodYmd,
            'periodLabel' => Carbon::parse($periodYmd)->translatedFormat('F Y'),
            'rrTh' => $rrTh,
        ]);
    }
}
