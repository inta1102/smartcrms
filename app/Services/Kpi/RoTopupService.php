<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RoTopupService
{
    /**
     * ✅ RULE FINAL (sesuai kasus Sugeng):
     * TopUp KPI bulan ini dihitung per CIF:
     *
     *   topup_amount_cif = CASE
     *       WHEN os_awal_cif (snapshot bulan lalu) > 0
     *       THEN GREATEST( SUM(disbursement_amount_bulan_ini) - os_awal_cif, 0 )
     *       ELSE 0
     *   END
     *
     * Jadi:
     * - CIF baru (os_awal=0 / tidak ada snapshot) -> TopUp = 0
     * - Existing CIF -> hanya ambil kenaikan di atas OS bulan lalu
     *
     * Output per AO:
     * - realisasi_topup, target, pct, score
     * - topup_cif_count, topup_cif_new_count, topup_max_cif_amount
     * - topup_concentration_pct (top1 cif amount / total)
     * - topup_top3 (array)
     * - meta
     */
    public function buildForMonth(
        string $periodYmd,
        float $targetTopup = 750_000_000,
        string $mode = 'eom', // disisakan untuk meta / builder
        ?string $branchCode = null,
        ?string $aoCode = null
    ): array {

        $period      = Carbon::parse($periodYmd)->startOfMonth();
        $periodStart = $period->toDateString();                 // YYYY-MM-01
        $periodEnd   = $period->copy()->endOfMonth()->toDateString();

        $snapCurr = $periodStart;
        $snapPrev = $period->copy()->subMonth()->toDateString();

        $meta = [
            'mode' => ($mode === 'realtime') ? 'realtime' : 'eom',
            'start_snapshot_month' => $snapPrev,
            'end_snapshot_month'   => ($mode === 'eom') ? $snapCurr : null,
            'latest_position_date' => null,
        ];

        if ($mode === 'realtime') {
            $latestPosQ = DB::table('loan_accounts');
            if ($branchCode && $this->hasColumn('loan_accounts', 'branch_code')) {
                $latestPosQ->where('branch_code', $branchCode);
            }
            $meta['latest_position_date'] = $latestPosQ->max('position_date');
        }

        /**
         * ============================================
         * 1) Snapshot bulan lalu per CIF (OS Awal CIF)
         *    - join hanya by CIF (anti mutasi AO/RO)
         * ============================================
         */
        $prevSnapQ = DB::table('loan_account_snapshots_monthly')
            ->select([
                'cif',
                DB::raw('SUM(outstanding) as os_awal'),
            ])
            ->whereDate('snapshot_month', $snapPrev)
            ->whereNotNull('cif')->where('cif', '!=', '')
            ->groupBy('cif');

        if ($branchCode && $this->hasColumn('loan_account_snapshots_monthly', 'branch_code')) {
            $prevSnapQ->where('branch_code', $branchCode);
        }

        /**
         * ============================================
         * 2) Disbursement bulan KPI (per AO + CIF)
         *    - pakai disb_date sebagai sumber tanggal utama
         *    - period hanya sebagai penguat bila ada
         * ============================================
         */
        $disbQ = DB::table('loan_disbursements')
            ->whereBetween('disb_date', [$periodStart, $periodEnd])
            ->whereNotNull('ao_code')->where('ao_code', '!=', '')
            ->whereNotNull('cif')->where('cif', '!=', '');

        if ($branchCode && $this->hasColumn('loan_disbursements', 'branch_code')) {
            $disbQ->where('branch_code', $branchCode);
        }

        if ($this->hasColumn('loan_disbursements', 'period')) {
            // optional penguat (aman)
            $disbQ->whereDate('period', $snapCurr);
        }

        if ($aoCode) {
            $disbQ->whereRaw(
                "LPAD(TRIM(ao_code),6,'0') = ?",
                [str_pad(trim((string)$aoCode), 6, '0', STR_PAD_LEFT)]
            );
        }

        $perCifDisbQ = (clone $disbQ)
            ->select([
                DB::raw("LPAD(TRIM(ao_code),6,'0') as ao_code"),
                'cif',
                DB::raw('SUM(COALESCE(amount,0)) as disb_amount'),
            ])
            ->groupBy('ao_code', 'cif');

        /**
         * ============================================
         * 3) Hitung TopUp per CIF:
         *    topup = existing_only ? max(disb - os_awal,0) : 0
         * ============================================
         */
        $perCifTopupQ = DB::query()
            ->fromSub($perCifDisbQ, 'd')
            ->leftJoinSub($prevSnapQ, 'p', function ($join) {
                $join->on('d.cif', '=', 'p.cif');
            })
            ->select([
                'd.ao_code',
                'd.cif',
                DB::raw('IFNULL(p.os_awal,0) as os_awal'),
                DB::raw('d.disb_amount as disb_amount'),
                DB::raw("
                    CASE
                        WHEN IFNULL(p.os_awal,0) > 0
                        THEN GREATEST(d.disb_amount - IFNULL(p.os_awal,0), 0)
                        ELSE 0
                    END as topup_amount
                "),
                DB::raw("
                    CASE
                        WHEN IFNULL(p.os_awal,0) > 0 THEN 1 ELSE 0
                    END as is_existing_cif
                "),
            ]);

        /**
         * ============================================
         * 4) Aggregate per AO
         * ============================================
         */
        $aggRows = DB::query()
            ->fromSub($perCifTopupQ, 'x')
            ->select([
                'x.ao_code',
                DB::raw('SUM(x.topup_amount) as realisasi_topup'),
                DB::raw('SUM(CASE WHEN x.topup_amount > 0 THEN 1 ELSE 0 END) as topup_cif_count'),
                // CIF baru = os_awal=0 / null (tapi topup_amount dipaksa 0 anyway)
                DB::raw('SUM(CASE WHEN x.is_existing_cif = 0 THEN 1 ELSE 0 END) as topup_cif_new_count'),
                DB::raw('MAX(x.topup_amount) as topup_max_cif_amount'),
            ])
            ->groupBy('x.ao_code')
            ->get();

        /**
         * ============================================
         * 5) Top 3 CIF per AO berdasarkan topup_amount
         * ============================================
         */
        $topRows = DB::query()
            ->fromSub($perCifTopupQ, 'x')
            ->where('x.topup_amount', '>', 0)
            ->orderBy('x.ao_code')
            ->orderByDesc('x.topup_amount')
            ->get(['x.ao_code', 'x.cif', 'x.topup_amount']);

        $top3Map = [];
        foreach ($topRows as $r) {
            $ao = (string) $r->ao_code;
            if (!isset($top3Map[$ao])) $top3Map[$ao] = [];
            if (count($top3Map[$ao]) < 3) {
                $top3Map[$ao][] = [
                    'cif'    => (string) ($r->cif ?? ''),
                    'amount' => (float) ($r->topup_amount ?? 0),
                ];
            }
        }

        /**
         * ============================================
         * 6) Build output
         * ============================================
         */
        $out = [];
        foreach ($aggRows as $r) {
            $ao = (string) $r->ao_code;

            $realisasi = (float) ($r->realisasi_topup ?? 0);
            $pct       = KpiScoreHelper::achievementPct($realisasi, (float)$targetTopup);
            $score     = KpiScoreHelper::scoreBand1to6($pct);

            $maxAmt  = (float) ($r->topup_max_cif_amount ?? 0);
            $concPct = $realisasi > 0 ? round(($maxAmt / $realisasi) * 100.0, 2) : 0.0;

            $out[$ao] = [
                'realisasi_topup' => $realisasi,
                'target' => (float) $targetTopup,
                'pct' => $pct,
                'score' => $score,

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

    /**
     * cek kolom ada/tidak (cache biar irit)
     */
    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $k = $table . ':' . $column;
        if (array_key_exists($k, $cache)) return $cache[$k];

        try {
            $cols = DB::getSchemaBuilder()->getColumnListing($table);
            return $cache[$k] = in_array($column, $cols, true);
        } catch (\Throwable $e) {
            return $cache[$k] = false;
        }
    }
}