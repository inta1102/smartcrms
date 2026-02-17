<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Services\Kpi\FeKpiInterpretationService;

class KpiFeController
{
    public function show(Request $request, int $feUserId)
    {
        $user = User::findOrFail($feUserId);
        Gate::authorize('kpi-fe-view', $user);

        $periodYmd = $request->input('period');
        if (empty($periodYmd)) $periodYmd = now()->startOfMonth()->toDateString();
        $periodYmd = Carbon::parse($periodYmd)->startOfMonth()->toDateString();

        // row monthly + user
        $row = DB::table('kpi_fe_monthlies as m')
            ->join('users as u', 'u.id', '=', 'm.fe_user_id')
            ->leftJoin('kpi_fe_targets as t', function ($j) {
                $j->on('t.period', '=', 'm.period')
                  ->on('t.fe_user_id', '=', 'm.fe_user_id');
            })
            ->whereDate('m.period', $periodYmd)
            ->where('m.fe_user_id', $feUserId)
            ->select([
                'm.*',
                'u.name',

                DB::raw('COALESCE(t.target_os_turun_kol2, m.target_os_turun_kol2) as target_os_turun_kol2_fix'),
                DB::raw('COALESCE(t.target_os_turun_kol2_pct, m.target_os_turun_kol2_pct) as target_os_turun_kol2_pct_fix'),
                DB::raw('COALESCE(t.target_migrasi_npl_pct, m.target_migrasi_npl_pct) as target_migrasi_npl_pct_fix'),
                DB::raw('COALESCE(t.target_penalty_paid, m.target_penalty_paid) as target_penalty_paid_fix'),
            ])
            ->first();

        // kalau belum ada (builder belum jalan / baseline belum siap)
        $baselineNote = null;
        if (!$row) {
            $baselineNote = "Data KPI FE untuk periode ini belum tersedia (belum dihitung / baseline belum siap).";
        }

        // interpretasi ringkas (konsisten seperti SO)
        $baselineNote = null;
        $bullets = [];
        $badges = null;
        $insights = [];

        if (!$row) {
            $baselineNote = "Data KPI FE untuk periode ini belum tersedia (belum dihitung / baseline belum siap).";
        } else {
            $pack = (new FeKpiInterpretationService())->build($row);
            $bullets = $pack['bullets'];
            $badges = $pack['badges'];
            $insights = $pack['insights'];
        }

        return view('kpi.fe.show', compact(
            'periodYmd','row','baselineNote','bullets','badges','insights'
        ) + [
            'periodLabel' => Carbon::parse($periodYmd)->translatedFormat('F Y'),
        ]);
    }
}
