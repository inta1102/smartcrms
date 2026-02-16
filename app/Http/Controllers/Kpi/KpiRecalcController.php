<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;
use App\Services\Kpi\RoKpiMonthlyBuilder;

class KpiRecalcController extends Controller
{
    /**
     * Terima period dari form:
     * - "YYYY-MM"     (input type=month)
     * - "YYYY-MM-01"  (hidden/legacy)
     *
     * return: [$periodYm, $periodYmd]
     * - $periodYm  => "YYYY-MM"
     * - $periodYmd => "YYYY-MM-01"
     */
    private function parsePeriod(Request $request, string $field = 'period'): array
    {
        $raw = trim((string) $request->input($field, ''));

        // terima YYYY-MM
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $period = Carbon::createFromFormat('Y-m', $raw)->startOfMonth();
            return [$period->format('Y-m'), $period->toDateString()];
        }

        // terima YYYY-MM-DD (kita normalisasi ke startOfMonth)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $period = Carbon::parse($raw)->startOfMonth();
            return [$period->format('Y-m'), $period->toDateString()];
        }

        abort(422, "Invalid period format. Use YYYY-MM or YYYY-MM-01.");
    }

    public function recalcSo(Request $request)
    {
        // samakan governance dengan RO/FE (kalau kamu sudah punya gate ini)
        $this->authorize('recalcMarketingKpi');

        [$periodYm, $periodYmd] = $this->parsePeriod($request);

        // panggil command yang benar
        Artisan::call('kpi:so-build', [
            '--period' => $periodYmd,
        ]);

        return redirect()
            ->route('kpi.marketing.sheet', [
                'role'   => 'SO',
                'period' => $periodYm,
            ])
            ->with('success', "Recalc KPI SO berhasil dijalankan ({$periodYm}).");
    }

    public function recalcAo(Request $request)
    {
        $this->authorize('recalcMarketingKpi');

        [$periodYm, $periodYmd] = $this->parsePeriod($request);

        Artisan::call('kpi:ao-build', [
            '--period' => $periodYmd,
        ]);

        return redirect()
            ->route('kpi.marketing.sheet', [
                'role'   => 'AO',
                'period' => $periodYm,
            ])
            ->with('success', "Recalc KPI AO berhasil dijalankan ({$periodYm}).");
    }

    public function recalcRo(Request $request, RoKpiMonthlyBuilder $builder)
    {
        $this->authorize('recalcMarketingKpi');

        [$periodYm, $periodYmd] = $this->parsePeriod($request);

        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOL);

        $period = Carbon::parse($periodYmd)->startOfMonth();
        $mode = $this->resolveRoMode($period);

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
            "Recalc KPI RO OK. saved={$result['saved']}, skipped_locked={$result['skipped_locked']}, total_ao={$result['total_ao']} (mode={$mode}, period={$periodYm})."
        );
    }

    public function recalcFe(Request $request)
    {
        $this->authorize('recalcMarketingKpi');

        [$periodYm, $periodYmd] = $this->parsePeriod($request);

        $svc = app(\App\Services\Kpi\FeKpiMonthlyService::class);
        $svc->buildForPeriod($periodYm, auth()->user());

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

        // role KBL (support enum/string)
        $lvl = strtoupper(trim((string)($user->roleValue() ?? '')));
        if ($lvl === '') {
            $raw = $user->level ?? null;
            $lvl = strtoupper(trim((string)($raw instanceof \BackedEnum ? $raw->value : $raw)));
        }
        abort_if($lvl !== 'KBL', 403);

        [$periodYm, $periodYmd] = $this->parsePeriod($request);

        // optional: recalc untuk 1 BE tertentu
        $only = $request->input('only');
        $only = $only !== null && $only !== '' ? (int)$only : null;

        $args = [
            '--period' => $periodYm,   // command BE kamu pakai YM, biarkan konsisten
            '--source' => 'recalc',
        ];
        if ($only) $args['--only'] = $only;

        Artisan::call('kpi:be-build-monthly', $args);

        return back()->with('success', "Recalc KPI BE berhasil dijalankan (KBL) ({$periodYm}).");
    }

    private function resolveRoMode(Carbon $period): string
    {
        return $period->greaterThanOrEqualTo(now()->startOfMonth()) ? 'realtime' : 'eom';
    }
}
