<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;
use App\Services\Kpi\RoKpiMonthlyBuilder;

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

    public function recalcRo(Request $request, RoKpiMonthlyBuilder $builder)
    {
        $this->authorize('recalcMarketingKpi');

        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'force'  => ['nullable', 'boolean'],
        ]);

        $period = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth();

        // ✅ AUTO mode:
        // - bulan ini => realtime
        // - bulan lalu ke bawah => eom
        $mode = $period->greaterThanOrEqualTo(now()->startOfMonth()) ? 'realtime' : 'eom';

        $force = (bool)($data['force'] ?? false);

        // YYYY-MM-01
        $periodYmd = $period->toDateString();

        $result = $builder->buildAndStore(
            $periodYmd,
            $mode,
            null,          // branchCode (all)
            null,          // aoCode (all RO)
            $force,        // force overwrite locked jika true
            750_000_000    // topupTarget
        );

        return back()->with(
            'status',
            "Recalc KPI RO OK. saved={$result['saved']}, skipped_locked={$result['skipped_locked']}, total_ao={$result['total_ao']} (mode={$mode})."
        );
    }

    public function recalcFe(Request $request)
    {
        $this->authorize('recalcMarketingKpi');

        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
        ]);

        $periodYm = $data['period'];

        $svc = app(\App\Services\Kpi\FeKpiMonthlyService::class);
        $svc->buildForPeriod($periodYm, auth()->user()); // ✅ hanya 2 argumen

        return redirect()
            ->route('kpi.marketing.sheet', [
                'role'   => 'FE',
                'period' => $periodYm,
            ])
            ->with('success', "Recalc FE untuk periode {$periodYm} berhasil.");
    }

    public function recalcBe(Request $request)
    {
        $user = $request->user();
        abort_if(!$user, 401);

        // ✅ role KBL (support enum/string)
        $lvl = strtoupper(trim((string)($user->roleValue() ?? '')));
        if ($lvl === '') {
            $raw = $user->level ?? null;
            $lvl = strtoupper(trim((string)($raw instanceof \BackedEnum ? $raw->value : $raw)));
        }
        abort_if($lvl !== 'KBL', 403);

        $periodYm = (string)($request->input('period') ?? now()->format('Y-m'));
        abort_if(!preg_match('/^\d{4}-\d{2}$/', $periodYm), 422);

        // optional: recalc untuk 1 BE tertentu
        $only = $request->input('only');
        $only = $only !== null && $only !== '' ? (int)$only : null;

        $args = [
            '--period' => $periodYm,
            '--source' => 'recalc',
        ];
        if ($only) $args['--only'] = $only;

        Artisan::call('kpi:be-build-monthly', $args);

        return redirect()
            ->back()
            ->with('success', 'Recalc KPI BE berhasil dijalankan (KBL).');
    }
    
    private function resolveRoMode(Carbon $period): string
    {
        $thisMonth = now()->startOfMonth();

        // periode bulan ini atau lebih baru => realtime
        if ($period->greaterThanOrEqualTo($thisMonth)) return 'realtime';

        // periode bulan lalu kebawah => eom (historis)
        return 'eom';
    }

}
