<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\MarketingKpiTarget;
use App\Models\MarketingKpiMonthly;
use App\Services\Kpi\MarketingKpiAchievementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\Kpi\MarketingKpiMonthlyService;

class MarketingKpiAchievementController extends Controller
{
    public function __construct(
        protected MarketingKpiAchievementService $svc,
        protected MarketingKpiMonthlyService $monthlySvc, 
    ) {}

    public function show(Request $request, MarketingKpiTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        // AO hanya boleh lihat miliknya
        abort_unless((int)$target->user_id === (int)$me->id, 403);

        // Hitung on-demand (bulan berjalan estimasi, bulan closed snapshot = final)
        $ach = $this->svc->computeForTarget($target, $request->boolean('force'));

        // ====== SERIES 12 BULAN (UNTUK GRAFIK) ======
        $period = Carbon::parse($target->period)->startOfMonth();
        for ($i = 11; $i >= 0; $i--) {
            $this->monthlySvc->recalcForUserAndPeriod((int)$target->user_id, $period->copy()->subMonths($i));
        }

        // $period = Carbon::parse($target->period)->startOfMonth();
        $start  = $period->copy()->subMonths(11)->startOfMonth();

        // Ambil data existing dari DB monthly
        $rows = MarketingKpiMonthly::query()
            ->where('user_id', $target->user_id)
            ->whereBetween('period', [$start->toDateString(), $period->toDateString()])
            ->orderBy('period')
            ->get()
            ->keyBy(fn($r) => Carbon::parse($r->period)->format('Y-m-01'));

        /**
         * Normalize: pastikan 12 bulan selalu ada (kalau belum dihitung -> isi 0)
         * supaya grafik gak lompat-lompat.
         */
        $series = [];
        $cursor = $start->copy();
        while ($cursor->lte($period)) {
            $key = $cursor->format('Y-m-01');

            $r = $rows->get($key);

            $series[] = [
                'period'          => $key,
                'label'           => $cursor->translatedFormat('M y'),

                'os_growth'       => (int) ($r->os_growth ?? 0),
                'target_os_growth'=> (int) ($r->target_os_growth ?? 0),
                'os_ach_pct'      => (float) ($r->os_ach_pct ?? 0),

                'noa_growth'      => (int) ($r->noa_growth ?? 0),
                'target_noa'      => (int) ($r->target_noa ?? 0),
                'noa_ach_pct'     => (float) ($r->noa_ach_pct ?? 0),

                'is_final'        => (bool) ($r->is_final ?? false),
                'source_now'      => (string) ($r->os_source_now ?? ''), // live|snapshot
            ];

            $cursor->addMonth();
        }

        // Pastikan monthly table terisi untuk period ini (biar chart Jan ada nilai)
        // $this->monthlySvc->recalcForUserAndPeriod((int)$target->user_id, Carbon::parse($target->period)->startOfMonth());


        return view('kpi.marketing.targets.achievement', compact('target', 'ach', 'series'));
    }
}
