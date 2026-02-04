<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;

class KpiRecalcController extends Controller
{
    public function recalcSo(Request $request)
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
        ]);

        $periodYmd = Carbon::createFromFormat('Y-m', $data['period'])
            ->startOfMonth()
            ->toDateString();

        // 🔥 panggil command yang BENAR
        Artisan::call('kpi:so-build', [
            '--period' => $periodYmd,
        ]);

        return redirect()
            ->route('kpi.marketing.sheet', [
                'role'   => 'SO',
                'period' => $data['period'],
            ])
            ->with('success', 'Recalc KPI SO berhasil dijalankan.');
    }

    public function recalcAo(Request $request)
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
        ]);

        $periodYmd = Carbon::createFromFormat('Y-m', $data['period'])
            ->startOfMonth()
            ->toDateString();

        Artisan::call('kpi:ao-build', [
            '--period' => $periodYmd,
        ]);

        return redirect()
            ->route('kpi.marketing.sheet', [
                'role'   => 'AO',
                'period' => $data['period'],
            ])
            ->with('success', 'Recalc KPI AO berhasil dijalankan.');
    }
}
