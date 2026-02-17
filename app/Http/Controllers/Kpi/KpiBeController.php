<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Services\Kpi\BeKpiInterpretationService; // nanti kalau mau dibuat

class KpiBeController extends Controller
{
    public function show(Request $request, int $beUserId)
    {
        $user = User::findOrFail($beUserId);
        Gate::authorize('kpi-be-view', $user);

        $periodYmd = $request->input('period');
        if (empty($periodYmd)) $periodYmd = now()->startOfMonth()->toDateString();
        $periodYmd = Carbon::parse($periodYmd)->startOfMonth()->toDateString();

        // row monthly + user + target (fix)
        $row = DB::table('kpi_be_monthlies as m')
            ->join('users as u', 'u.id', '=', 'm.be_user_id')
            ->leftJoin('kpi_be_targets as t', function ($j) {
                $j->on('t.period', '=', 'm.period')
                  ->on('t.be_user_id', '=', 'm.be_user_id');
            })
            ->whereDate('m.period', $periodYmd)
            ->where('m.be_user_id', $beUserId)
            ->select([
                'm.*',
                'u.name',

                // ✅ target fix (monthly BE tidak simpan target, jadi fallback 0)
                DB::raw('COALESCE(t.target_os_selesai, 0) as target_os_selesai_fix'),
                DB::raw('COALESCE(t.target_noa_selesai, 0) as target_noa_selesai_fix'),
                DB::raw('COALESCE(t.target_bunga_masuk, 0) as target_bunga_masuk_fix'),
                DB::raw('COALESCE(t.target_denda_masuk, 0) as target_denda_masuk_fix'),
            ])
            ->first();

        // baseline note + interpretasi ringkas (mirip FE)
        $baselineNote = null;
        $bullets = [];
        $badges = null;
        $insights = [];

        if (!$row) {
            $baselineNote = "Data KPI BE untuk periode ini belum tersedia (belum dihitung / belum ada monthly).";
        } else {
            $pack = (new BeKpiInterpretationService())->build($row);
            $bullets = $pack['bullets'];
            $badges = $pack['badges'];
            $insights = $pack['insights'];
        }

        return view('kpi.be.show', compact(
            'periodYmd', 'row', 'baselineNote', 'bullets', 'badges', 'insights'
        ) + [
            'periodLabel' => Carbon::parse($periodYmd)->translatedFormat('F Y'),
        ]);
    }
}
