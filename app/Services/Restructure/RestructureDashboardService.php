<?php

namespace App\Services\Restructure;

use App\Models\LoanAccount;
use Illuminate\Database\Eloquent\Builder;
use App\Models\RsActionStatus;
use Illuminate\Support\Facades\Cache;


class RestructureDashboardService
{
    /**
     * Entry point untuk view.
     */
    public function buildSummary(array $filter, ?array $visibleAoCodes = null): array
    {
        $q = $this->baseQueryRs($filter, $visibleAoCodes);

        // ✅ KPI #1: Total OS RS
        $kpiTotalOsRs = (float) (clone $q)->sum('loan_accounts.outstanding');

        // ✅ KPI #2: Total Rekening RS
        $kpiTotalRekRs = (int) (clone $q)->count('loan_accounts.id');

        // ✅ KPI #3: RS Baru R0-30 (rek & os)
        // $qR0_30 = (clone $q)->whereRaw('DATEDIFF(loan_accounts.position_date, loan_accounts.last_restructure_date) BETWEEN 0 AND 30');
        $qR0_30 = (clone $q)
            ->whereNotNull('loan_accounts.last_restructure_date')
            ->whereRaw('DATEDIFF(loan_accounts.position_date, loan_accounts.last_restructure_date) BETWEEN 0 AND 30');
        $kpiR0_30Rek = (int) (clone $qR0_30)->count('loan_accounts.id');
        $kpiR0_30Os  = (float) (clone $qR0_30)->sum('loan_accounts.outstanding');

        // ✅ KPI #4: RS Baru R0-30 yang sudah DPD > 0 (rek & os)
        $qR0_30Dpd = (clone $qR0_30)->where('loan_accounts.dpd', '>', 0);
        $kpiR0_30DpdRek = (int) (clone $qR0_30Dpd)->count('loan_accounts.id');
        $kpiR0_30DpdOs  = (float) (clone $qR0_30Dpd)->sum('loan_accounts.outstanding');

        // ✅ KPI #5: RS High Risk (rek & os)
        // Rule HighRisk: DPD>=15 OR Kolek>=3 OR Frek>=2
        $qHighRisk = (clone $q)->where(function ($w) {
            $w->where('loan_accounts.dpd', '>=', 15)
              ->orWhere('loan_accounts.kolek', '>=', 3)
              ->orWhere('loan_accounts.restructure_freq', '>=', 2);
        });
        $kpiHighRiskRek = (int) (clone $qHighRisk)->count('loan_accounts.id');
        $kpiHighRiskOs  = (float) (clone $qHighRisk)->sum('loan_accounts.outstanding');

        // ✅ KPI #6: RS Kritis (rek & os)
        // Rule Kritis: DPD>=60 OR Kolek>=4
        $qKritis = (clone $q)->where(function ($w) {
            $w->where('loan_accounts.dpd', '>=', 60)
              ->orWhere('loan_accounts.kolek', '>=', 4);
        });
        $kpiKritisRek = (int) (clone $qKritis)->count('loan_accounts.id');
        $kpiKritisOs  = (float) (clone $qKritis)->sum('loan_accounts.outstanding');

        // % helper (hindari div0)
        $pct = fn(float $num, float $den) => $den > 0 ? round(($num / $den) * 100, 2) : 0.0;

        $qNoDate = (clone $q)->whereNull('loan_accounts.last_restructure_date');
        $kpiNoDateRek = (int) (clone $qNoDate)->count('loan_accounts.id');
        $kpiNoDateOs  = (float) (clone $qNoDate)->sum('loan_accounts.outstanding');

        return [
            'filters' => $filter,

            'kpi' => [
                'total_os_rs' => [
                    'value' => $kpiTotalOsRs,
                    'desc'  => 'Outstanding restruktur sesuai filter',
                ],
                'total_rek_rs' => [
                    'value' => $kpiTotalRekRs,
                    'desc'  => 'Jumlah rekening restruktur sesuai filter',
                ],
                'r0_30' => [
                    'rek'    => $kpiR0_30Rek,
                    'os'     => $kpiR0_30Os,
                    'pct_os' => $pct($kpiR0_30Os, $kpiTotalOsRs),
                    'desc'   => 'RS baru (0–30 hari) dari tanggal restruktur terakhir',
                ],
                'r0_30_dpd' => [
                    'rek'    => $kpiR0_30DpdRek,
                    'os'     => $kpiR0_30DpdOs,
                    'pct_os' => $pct($kpiR0_30DpdOs, $kpiTotalOsRs),
                    'desc'   => 'RS baru (0–30) yang sudah mulai menunggak (DPD > 0)',
                ],
                'high_risk' => [
                    'rek'    => $kpiHighRiskRek,
                    'os'     => $kpiHighRiskOs,
                    'pct_os' => $pct($kpiHighRiskOs, $kpiTotalOsRs),
                    'desc'   => 'RS berisiko tinggi (DPD≥15 / Kolek≥3 / Frek≥2)',
                ],
                'kritis' => [
                    'rek'    => $kpiKritisRek,
                    'os'     => $kpiKritisOs,
                    'pct_os' => $pct($kpiKritisOs, $kpiTotalOsRs),
                    'desc'   => 'RS kritis (DPD≥60 / Kolek≥4)',
                ],
            ],
        ];
    }

    /**
     * Base query khusus RS.
     *
     * ✅ IMPORTANT: $visibleAoCodes adalah 3-state
     * - null  => ALL ACCESS (pimpinan) -> tidak difilter
     * - []    => NO ACCESS (visibility kosong) -> query dikosongkan (1=0)
     * - [..]  => FILTER sesuai AO codes
     */
    public function baseQueryRs(array $filter, ?array $visibleAoCodes = null): Builder
    {
        $posDate    = $filter['position_date'] ?? null;
        $branchCode = $filter['branch_code'] ?? null;
        $aoCode     = $filter['ao_code'] ?? null;

        $q = LoanAccount::query()
            ->from('loan_accounts')
            ->where('loan_accounts.is_active', 1)
            ->where('loan_accounts.is_restructured', 1);
            // ->whereNotNull('loan_accounts.last_restructure_date');

        // position_date wajib (kalau kosong, jangan bikin query liar)
        if ($posDate) {
            $q->whereDate('loan_accounts.position_date', $posDate);
        } else {
            // kalau position_date kosong: lebih aman kosongkan
            $q->whereRaw('1=0');
            return $q;
        }

        if (!empty($branchCode)) {
            $q->where('loan_accounts.branch_code', $branchCode);
        }

        if (!empty($aoCode)) {
            $q->where('loan_accounts.ao_code', $aoCode);
        }

        // ✅ visibility AO
        // NOTE: kalau $visibleAoCodes null -> berarti all access (tidak filter)
        if (is_array($visibleAoCodes)) {
            if (count($visibleAoCodes) === 0) {
                // visibility kosong untuk non-privileged: harus kosong, bukan global
                $q->whereRaw('1=0');
            } else {
                $q->whereIn('loan_accounts.ao_code', $visibleAoCodes);
            }
        }

        return $q;
    }

    public function emptySummary(): array
    {
        return [
            'total_os_rs'   => 0,
            'total_rek_rs'  => 0,
            'rs_r0_30_os'   => 0,
            'rs_r0_30_rek'  => 0,
            'r0_30_dpd_os'  => 0,
            'r0_30_dpd_rek' => 0,
            'high_risk_os'  => 0,
            'high_risk_rek' => 0,
            'kritis_os'     => 0,
            'kritis_rek'    => 0,
        ];
    }

    public function detailByScope(?string $scope, array $filter, ?array $visibleAoCodes = null)
    {
        $q = $this->baseQueryRs($filter, $visibleAoCodes);

        if (!$scope) return collect();

        switch ($scope) {
            case 'r0_30':
                $q->whereRaw('DATEDIFF(loan_accounts.position_date, loan_accounts.last_restructure_date) BETWEEN 0 AND 30');
                break;

            case 'r0_30_dpd':
                $q->whereRaw('DATEDIFF(loan_accounts.position_date, loan_accounts.last_restructure_date) BETWEEN 0 AND 30')
                  ->where('loan_accounts.dpd', '>', 0);
                break;

            case 'high_risk':
                $q->where(function ($w) {
                    $w->where('loan_accounts.dpd', '>=', 15)
                      ->orWhere('loan_accounts.kolek', '>=', 3)
                      ->orWhere('loan_accounts.restructure_freq', '>=', 2);
                });
                break;

            case 'kritis':
                $q->where(function ($w) {
                    $w->where('loan_accounts.dpd', '>=', 60)
                      ->orWhere('loan_accounts.kolek', '>=', 4);
                });
                break;

            default:
                return collect();
        }

        $rows = $q
            ->orderByDesc('loan_accounts.outstanding')
            ->limit(50)
            ->get([
                'loan_accounts.id',
                'loan_accounts.account_no',
                'loan_accounts.customer_name',
                'loan_accounts.ao_code',
                'loan_accounts.ao_name',
                'loan_accounts.kolek',
                'loan_accounts.dpd',
                'loan_accounts.restructure_freq',
                'loan_accounts.last_restructure_date',
                'loan_accounts.position_date',
                'loan_accounts.outstanding',
            ]);

        $posDate = $filter['position_date'] ?? now()->toDateString();
        $map = $this->loadActionStatusMap($rows->pluck('id')->all(), $posDate);

        $rows->transform(function ($r) use ($map) {
            $row = $map[$r->id] ?? null;

            $r->action_status  = $row['status'] ?? 'none';
            $r->action_channel = $row['channel'] ?? null;
            $r->action_note    = $row['note'] ?? null;

            return $r;
        });

        return $rows;
    }

    protected function loadActionStatusMap(array $loanAccountIds, string $positionDate): array
    {
        if (empty($loanAccountIds)) return [];

        // ✅ return array map: [loan_account_id => ['status'=>..,'channel'=>..,'note'=>..]]
        return RsActionStatus::query()
            ->whereDate('position_date', $positionDate)
            ->whereIn('loan_account_id', $loanAccountIds)
            ->get(['loan_account_id', 'status', 'channel', 'note'])
            ->keyBy('loan_account_id')
            ->map(fn($m) => [
                'status'  => $m->status,
                'channel' => $m->channel,
                'note'    => $m->note,
            ])
            ->all();
    }

    public function upsertActionStatus(
        int $loanAccountId,
        string $positionDate,
        string $status,
        ?string $channel,
        ?string $note,
        ?int $updatedBy
    ): void {
        RsActionStatus::updateOrCreate(
            [
                'loan_account_id' => $loanAccountId,
                'position_date'   => $positionDate,
            ],
            [
                'status'     => $status,
                'channel'    => $channel,
                'note'       => $note,
                'updated_by' => $updatedBy,
            ]
        );
    }

    // public function kritisRatio(array $filter, ?array $visibleAoCodes = null): float
    // {
    //     $q = $this->baseQueryRs($filter, $visibleAoCodes);

    //     $totalOs = (float) (clone $q)->sum('loan_accounts.outstanding');

    //     if ($totalOs <= 0) return 0.0;

    //     $kritisOs = (float) (clone $q)->where(function ($w) {
    //         $w->where('loan_accounts.dpd', '>=', 60)
    //         ->orWhere('loan_accounts.kolek', '>=', 4);
    //     })->sum('loan_accounts.outstanding');

    //     return round(($kritisOs / $totalOs) * 100, 2);
    // }


    public function kritisMeta(array $filter, ?array $visibleAoCodes = null): array
    {
        $q = $this->baseQueryRs($filter, $visibleAoCodes);

        // total OS RS (semua restruk)
        $osRs = (float) (clone $q)->sum('loan_accounts.outstanding');

        // OS + Rek RS kritis
        $qKritis = (clone $q)->where(function ($w) {
            $w->where('loan_accounts.dpd', '>=', 60)
            ->orWhere('loan_accounts.kolek', '>=', 4);
        });

        $osKritis  = (float) (clone $qKritis)->sum('loan_accounts.outstanding');
        $rekKritis = (int) (clone $qKritis)->count('loan_accounts.id');

        $ratio = $osRs > 0 ? round(($osKritis / $osRs) * 100, 2) : 0.0;

        return [
            'ratio'     => $ratio,     // persen
            'os_rs'     => $osRs,
            'os_kritis' => $osKritis,
            'rek_kritis'=> $rekKritis,
        ];
    }

    /**
     * Kalau kamu masih butuh yang lama:
     * return float ratio saja.
     */
    public function kritisRatio(array $filter, ?array $visibleAoCodes = null): float
    {
        return (float)($this->kritisMeta($filter, $visibleAoCodes)['ratio'] ?? 0);
    }

}
