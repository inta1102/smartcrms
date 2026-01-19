<?php

namespace App\Services\Supervision;

use App\Models\CaseResolutionTarget;
use Illuminate\Support\Carbon;

class KasiDecisionPanelService
{
    public function getPanel(): array
    {
        $tlSlaDays   = (int) config('supervision.sla_days.tl_approval', 2);
        $kasiSlaDays = (int) config('supervision.sla_days.kasi_approval', 2);

        // 1) Pending counts
        $pendingTlCount   = CaseResolutionTarget::pendingTl()->count();
        $pendingKasiCount = CaseResolutionTarget::pendingKasi()->count();

        // 2) SLA breach counts
        $pendingTlOverSla = CaseResolutionTarget::pendingTl()
            ->where('created_at', '<', now()->subDays($tlSlaDays))
            ->count();

        $pendingKasiOverSla = CaseResolutionTarget::pendingKasi()
            ->where('created_at', '<', now()->subDays($kasiSlaDays))
            ->count();

        $slaBreachTotal = $pendingTlOverSla + $pendingKasiOverSla;

        // 3) Rejected (time-boxed, biar relevan)
        $rejectedLast7d = CaseResolutionTarget::rejected()
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();

        // 4) Age summary (optional tapi “kelas KASI”)
        // Avg umur pending untuk sense beban keputusan
        $avgAgePendingKasiHours = (int) round(
            CaseResolutionTarget::pendingKasi()
                ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, NOW())) as avg_h')
                ->value('avg_h') ?? 0
        );

        return [
            'pending_tl' => [
                'count' => $pendingTlCount,
                'over_sla' => $pendingTlOverSla,
                'sla_days' => $tlSlaDays,
            ],
            'pending_kasi' => [
                'count' => $pendingKasiCount,
                'over_sla' => $pendingKasiOverSla,
                'sla_days' => $kasiSlaDays,
                'avg_age_hours' => $avgAgePendingKasiHours,
            ],
            'sla_breach_total' => [
                'count' => $slaBreachTotal,
                'breakdown' => [
                    'tl' => $pendingTlOverSla,
                    'kasi' => $pendingKasiOverSla,
                ],
            ],
            'rejected_7d' => [
                'count' => $rejectedLast7d,
            ],
            'generated_at' => Carbon::now()->toDateTimeString(),
        ];
    }
}
