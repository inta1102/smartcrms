<?php

namespace App\Services\Kpi;

use App\Models\MarketingKpiResult;
use App\Models\MarketingKpiSnapshot;
use App\Models\MarketingKpiTarget;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MarketingKpiCalculatorService
{
    public function calculateForPeriod(string $periodYmd, ?int $onlyUserId = null, int $calculatedBy = null): array
    {
        $period = Carbon::parse($periodYmd)->startOfMonth()->toDateString();

        $targetsQ = MarketingKpiTarget::query()
            ->whereDate('period', $period)
            ->where('status', MarketingKpiTarget::STATUS_APPROVED)
            ->where('is_locked', false);

        if ($onlyUserId) $targetsQ->where('user_id', $onlyUserId);

        $targets = $targetsQ->get();

        $upsert = 0;

        DB::transaction(function () use ($targets, $period, $calculatedBy, &$upsert) {
            foreach ($targets as $t) {
                $snap = MarketingKpiSnapshot::query()
                    ->whereDate('period', $period)
                    ->where('user_id', $t->user_id)
                    ->first();

                // kalau snapshot belum ada, anggap 0 agar jelas
                $realOsGrowth = $snap ? (float) $snap->os_growth : 0.0;
                $realNoaNew   = $snap ? (int) $snap->noa_new : 0;

                $cap = (float) ($t->cap_ratio ?? 1.20); // kalau nanti cap ada di target, kalau belum: 1.2
                if ($cap <= 0) $cap = 1.20;

                $weightOs  = (int) $t->weight_os;
                $weightNoa = (int) $t->weight_noa;

                $targetOs  = (float) $t->target_os_growth;
                $targetNoa = (int) $t->target_noa;

                // ratio = real/target (hindari div 0)
                $ratioOs  = $targetOs > 0 ? ($realOsGrowth / $targetOs) : 0.0;
                $ratioNoa = $targetNoa > 0 ? ($realNoaNew / $targetNoa) : 0.0;

                // cap max 120%
                $ratioOsCapped  = min(max($ratioOs, 0), $cap);
                $ratioNoaCapped = min(max($ratioNoa, 0), $cap);

                // skor = ratio * bobot(%)  (contoh: 1.10 * 60 = 66)
                $scoreOs  = round($ratioOsCapped * $weightOs, 2);
                $scoreNoa = round($ratioNoaCapped * $weightNoa, 2);
                $total    = round($scoreOs + $scoreNoa, 2);

                MarketingKpiResult::query()->updateOrCreate(
                    ['period' => $period, 'user_id' => $t->user_id],
                    [
                        'target_os_growth' => $targetOs,
                        'target_noa'       => $targetNoa,

                        'real_os_growth'   => $realOsGrowth,
                        'real_noa_new'     => $realNoaNew,

                        'ratio_os'         => round($ratioOs, 4),
                        'ratio_noa'        => round($ratioNoa, 4),

                        'score_os'         => $scoreOs,
                        'score_noa'        => $scoreNoa,
                        'score_total'      => $total,

                        'cap_ratio'        => $cap,

                        'status'           => MarketingKpiResult::STATUS_DRAFT,
                        'calculated_by'    => $calculatedBy,
                        'calculated_at'    => now(),
                        'is_locked'        => false,
                    ]
                );

                $upsert++;
            }
        });

        return [
            'period' => $period,
            'targets'=> $targets->count(),
            'upsert' => $upsert,
        ];
    }
}
