<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RoTopupService
{
    /**
     * MODE:
     * - eom: histori resmi (pakai snapshots monthly prev->curr)
     * - realtime: running bulan ini (start pakai snapshot prev, end pakai loan_accounts latest)
     */
    public function buildForMonth(
        string $periodYmd,
        float $targetTopup = 750_000_000,
        string $mode = 'eom', // 'eom' | 'realtime'
        ?string $branchCode = null,
        ?string $aoCode = null
    ): array {
        $period = Carbon::parse($periodYmd)->startOfMonth();
        $snapCurr = $period->toDateString();                 // YYYY-MM-01 (bulan KPI)
        $snapPrev = $period->copy()->subMonth()->toDateString(); // YYYY-MM-01 (bulan sebelumnya)

        // ====== START (OS_awal) selalu pakai snapshot prev (EOM bulan lalu) ======
        $startQ = DB::table('loan_account_snapshots_monthly')
            ->select([
                'ao_code',
                'cif',
                DB::raw('SUM(outstanding) AS os_awal'),
            ])
            ->whereDate('snapshot_month', $snapPrev)
            ->whereNotNull('cif')->where('cif', '!=', '')
            ->whereNotNull('ao_code')->where('ao_code', '!=', '');

        if ($branchCode) $startQ->where('branch_code', $branchCode);
        if ($aoCode)     $startQ->where('ao_code', $aoCode);

        $startQ->groupBy('ao_code', 'cif');

        // ====== END (OS_akhir) tergantung mode ======
        if ($mode === 'eom') {
            // histori resmi: snapshot current (EOM bulan KPI)
            $endQ = DB::table('loan_account_snapshots_monthly')
                ->select([
                    'ao_code',
                    'cif',
                    DB::raw('SUM(outstanding) AS os_akhir'),
                ])
                ->whereDate('snapshot_month', $snapCurr)
                ->whereNotNull('cif')->where('cif', '!=', '')
                ->whereNotNull('ao_code')->where('ao_code', '!=', '');

            if ($branchCode) $endQ->where('branch_code', $branchCode);
            if ($aoCode)     $endQ->where('ao_code', $aoCode);

            $endQ->groupBy('ao_code', 'cif');

            $meta = [
                'mode' => 'eom',
                'start_snapshot_month' => $snapPrev,
                'end_snapshot_month'   => $snapCurr,
                'latest_position_date' => null,
            ];
        } else {
            // realtime: end pakai loan_accounts latest
            $latestPosQ = DB::table('loan_accounts');
            if ($branchCode) $latestPosQ->where('branch_code', $branchCode);
            $latestPositionDate = $latestPosQ->max('position_date');

            if (!$latestPositionDate) return [];

            $endQ = DB::table('loan_accounts')
                ->select([
                    'ao_code',
                    'cif',
                    DB::raw('SUM(outstanding) AS os_akhir'),
                ])
                ->whereDate('position_date', $latestPositionDate)
                ->whereNotNull('cif')->where('cif', '!=', '')
                ->whereNotNull('ao_code')->where('ao_code', '!=', '');

            if ($branchCode) $endQ->where('branch_code', $branchCode);
            if ($aoCode)     $endQ->where('ao_code', $aoCode);

            $endQ->groupBy('ao_code', 'cif');

            $meta = [
                'mode' => 'realtime',
                'start_snapshot_month' => $snapPrev,
                'end_snapshot_month'   => null,
                'latest_position_date' => $latestPositionDate,
            ];
        }

        // ====== JOIN end-driven agar CIF baru tetap masuk ======
        $rows = DB::query()
            ->fromSub($endQ, 'e')
            ->leftJoinSub($startQ, 's', function ($join) {
                $join->on('e.ao_code', '=', 's.ao_code')
                     ->on('e.cif', '=', 's.cif');
            })
            ->select([
                'e.ao_code',
                DB::raw('SUM(GREATEST(e.os_akhir - IFNULL(s.os_awal, 0), 0)) AS realisasi_topup'),
            ])
            ->groupBy('e.ao_code')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $ao = (string) $r->ao_code;
            $realisasi = (float) $r->realisasi_topup;
            $pct = $targetTopup > 0 ? ($realisasi / $targetTopup) * 100.0 : 0.0;

            $out[$ao] = [
                'realisasi_topup' => $realisasi,
                'target' => (float) $targetTopup,
                'pct' => $pct,
                'score' => $this->scoreTopupPct($pct),
            ] + $meta;
        }

        return $out;
    }

    private function scoreTopupPct(float $pct): int
    {
        if ($pct < 25) return 1;
        if ($pct < 50) return 2;
        if ($pct < 75) return 3;
        if ($pct < 100) return 4;
        if (abs($pct - 100) < 1e-9) return 5;
        return 6;
    }
}
