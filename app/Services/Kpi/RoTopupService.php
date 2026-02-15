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
     *
     * TopUp (CIF-based delta OS):
     *   delta_topup = GREATEST(os_akhir - os_awal, 0)
     *   realisasi_topup = SUM(delta_topup) per AO
     *
     * Output per AO:
     * - realisasi_topup, target, pct, score
     * - topup_cif_count, topup_cif_new_count, topup_max_cif_amount
     * - topup_concentration_pct (top1 delta / total)
     * - topup_top3 (array)
     * - meta: start_snapshot_month, end_snapshot_month, latest_position_date
     */
    public function buildForMonth(
        string $periodYmd,
        float $targetTopup = 750_000_000,
        string $mode = 'eom', // 'eom' | 'realtime'
        ?string $branchCode = null,
        ?string $aoCode = null
    ): array {
        $period   = Carbon::parse($periodYmd)->startOfMonth();
        $snapCurr = $period->toDateString();                     // YYYY-MM-01 (bulan KPI)
        $snapPrev = $period->copy()->subMonth()->toDateString(); // YYYY-MM-01 (bulan sebelumnya)

        // ====== START (OS_awal) selalu pakai snapshot prev ======
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
            // realtime: pakai latest position_date (boleh dibatasi branch kalau ada)
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
        $perCifQ = DB::query()
            ->fromSub($endQ, 'e')
            ->leftJoinSub($startQ, 's', function ($join) {
                $join->on('e.ao_code', '=', 's.ao_code')
                    ->on('e.cif', '=', 's.cif');
            })
            ->select([
                'e.ao_code',
                'e.cif',
                DB::raw('IFNULL(s.os_awal,0) AS os_awal'),
                DB::raw('e.os_akhir AS os_akhir'),

                // pakai CAST biar perbandingan & MAX/SUM konsisten di MySQL
                DB::raw('CAST(GREATEST(e.os_akhir - IFNULL(s.os_awal,0), 0) AS DECIMAL(18,2)) AS delta_topup'),
            ]);

        // 1) agregat per AO (total + counts + max)
        $aggRows = DB::query()
            ->fromSub($perCifQ, 'x')
            ->select([
                'x.ao_code',
                DB::raw('SUM(x.delta_topup) AS realisasi_topup'),

                // hitung CIF yg beneran topup
                DB::raw('SUM(CASE WHEN x.delta_topup > 0 THEN 1 ELSE 0 END) AS topup_cif_count'),

                // CIF baru aktif (os_awal=0, os_akhir>0) meskipun delta_topup bisa besar
                DB::raw('SUM(CASE WHEN x.os_awal = 0 AND x.os_akhir > 0 THEN 1 ELSE 0 END) AS topup_cif_new_count'),

                DB::raw('MAX(x.delta_topup) AS topup_max_cif_amount'),
            ])
            ->groupBy('x.ao_code')
            ->get();

        // 2) Top 3 per AO (tanpa window function, ambil semua lalu limit 3 per AO di PHP)
        $topRows = DB::query()
            ->fromSub($perCifQ, 'x')
            ->where('x.delta_topup', '>', 0)
            ->orderBy('x.ao_code')
            ->orderByDesc('x.delta_topup')
            ->get(['x.ao_code', 'x.cif', 'x.delta_topup']);

        $top3Map = [];
        foreach ($topRows as $r) {
            $ao = (string) $r->ao_code;
            if (!isset($top3Map[$ao])) $top3Map[$ao] = [];
            if (count($top3Map[$ao]) < 3) {
                $top3Map[$ao][] = [
                    'cif' => (string) $r->cif,
                    'amount' => (float) $r->delta_topup,
                ];
            }
        }

        // build output
        $out = [];
        foreach ($aggRows as $r) {
            $ao = (string) $r->ao_code;

            $realisasi = (float) ($r->realisasi_topup ?? 0);
            $pct = $targetTopup > 0 ? ($realisasi / $targetTopup) * 100.0 : 0.0;

            $maxAmt  = (float) ($r->topup_max_cif_amount ?? 0);
            $concPct = $realisasi > 0 ? round(($maxAmt / $realisasi) * 100.0, 2) : 0.0;

            $out[$ao] = [
                'realisasi_topup' => $realisasi,
                'target' => (float) $targetTopup,
                'pct' => $pct,
                'score' => $this->scoreTopupPct($pct),

                'topup_cif_count' => (int) ($r->topup_cif_count ?? 0),
                'topup_cif_new_count' => (int) ($r->topup_cif_new_count ?? 0),
                'topup_max_cif_amount' => $maxAmt,
                'topup_concentration_pct' => $concPct,
                'topup_top3' => $top3Map[$ao] ?? [],
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
