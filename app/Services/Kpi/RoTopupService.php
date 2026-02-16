<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RoTopupService
{
    /**
     * MODE:
     * - eom: histori resmi (tetap boleh dipakai, tapi TopUp tetap by disbursement bulan itu)
     * - realtime: running bulan ini (TopUp tetap by disbursement bulan itu)
     *
     * ✅ NEW RULE (Fix mutasi RO / limpahan portofolio):
     * TopUp dihitung berdasarkan transaksi disbursement TOPUP pada bulan KPI,
     * bukan delta OS snapshot prev->curr.
     *
     * realisasi_topup = SUM(amount) transaksi topup bulan itu per AO
     *
     * Output per AO:
     * - realisasi_topup, target, pct, score
     * - topup_cif_count, topup_cif_new_count, topup_max_cif_amount
     * - topup_concentration_pct (top1 cif amount / total)
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
        $periodStart = $period->toDateString();                 // YYYY-MM-01
        $periodEnd   = $period->copy()->endOfMonth()->toDateString();
        $snapCurr = $periodStart;
        $snapPrev = $period->copy()->subMonth()->toDateString();

        // meta (tetap dipertahankan karena dipakai builder)
        $meta = [
            'mode' => ($mode === 'realtime') ? 'realtime' : 'eom',
            'start_snapshot_month' => $snapPrev,
            'end_snapshot_month'   => ($mode === 'eom') ? $snapCurr : null,
            'latest_position_date' => null,
        ];

        // realtime: isi latest_position_date untuk info (opsional)
        if ($mode === 'realtime') {
            $latestPosQ = DB::table('loan_accounts');
            if ($branchCode && $this->hasColumn('loan_accounts', 'branch_code')) {
                $latestPosQ->where('branch_code', $branchCode);
            }
            $meta['latest_position_date'] = $latestPosQ->max('position_date');
        }

        // ===========================
        // 1) Base query: disbursement topup bulan period
        // ===========================
        $q = DB::table('loan_disbursements');

        // filter by branch jika kolomnya ada
        if ($branchCode && $this->hasColumn('loan_disbursements', 'branch_code')) {
            $q->where('branch_code', $branchCode);
        }

        // filter by AO jika diminta
        if ($aoCode) {
            // normalisasi: beberapa data bisa ada spasi / tidak pad; kita match fleksibel
            $q->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [str_pad(trim((string)$aoCode), 6, '0', STR_PAD_LEFT)]);
        }

        // ✅ Filter bulan KPI: paling aman pakai period (karena sistemmu banyak pakai period=YYYY-MM-01)
        if ($this->hasColumn('loan_disbursements', 'period')) {
            $q->whereDate('period', $snapCurr);
        }

        // ✅ Kalau ada kolom tanggal disbursement, kita pakai untuk memperketat (anti data period yang “kotor”)
        $dateCol = $this->firstExistingColumn('loan_disbursements', [
            'disbursed_at',
            'disbursement_date',
            'tanggal_disbursement',
            'disb_date',
            'trx_date',
            'created_at', // fallback terakhir (kalau memang disbursement dibuat sesuai tanggal cair)
        ]);

        if ($dateCol) {
            $q->whereBetween(DB::raw("DATE($dateCol)"), [$periodStart, $periodEnd]);
        }

        // ✅ Filter TOPUP (multi-skema, tergantung kolom yang ada di tabelmu)
        $q->where(function ($qq) {
            $has = false;

            if ($this->hasColumn('loan_disbursements', 'is_topup')) {
                $qq->orWhere('is_topup', 1);
                $has = true;
            }

            if ($this->hasColumn('loan_disbursements', 'disbursement_type')) {
                $qq->orWhere('disbursement_type', 'TOPUP');
                $has = true;
            }

            if ($this->hasColumn('loan_disbursements', 'trx_type')) {
                $qq->orWhere('trx_type', 'TOPUP');
                $has = true;
            }

            if ($this->hasColumn('loan_disbursements', 'purpose')) {
                // kalau purpose ada: 'TOPUP' atau 'TU' dsb (boleh kamu sesuaikan)
                $qq->orWhereIn('purpose', ['TOPUP', 'TU', 'TOP UP', 'TOP-UP']);
                $has = true;
            }

            // kalau tidak ada satupun kolom indikator, jangan memfilter (biar tidak kosong)
            // tapi ini berbahaya -> lebih baik kamu pastikan minimal salah satu indikator ada.
            if (!$has) {
                $qq->whereRaw('1=1');
            }
        });

        // pastikan cif & ao_code ada
        if ($this->hasColumn('loan_disbursements', 'cif')) {
            $q->whereNotNull('cif')->where('cif', '!=', '');
        }
        $q->whereNotNull('ao_code')->where('ao_code', '!=', '');

        // ===========================
        // 2) Agregasi per CIF (per AO) bulan itu
        // ===========================
        $perCifQ = (clone $q)
            ->select([
                DB::raw("LPAD(TRIM(ao_code),6,'0') as ao_code"),
                $this->hasColumn('loan_disbursements', 'cif') ? 'cif' : DB::raw("'' as cif"),
                DB::raw('CAST(ROUND(SUM(amount)) AS DECIMAL(18,2)) as topup_amount'),
            ])
            ->groupBy('ao_code', 'cif');

        // ===========================
        // 3) start snapshot prev untuk deteksi "CIF baru" (optional)
        // ===========================
        $startSnapQ = DB::table('loan_account_snapshots_monthly')
            ->select([
                DB::raw("LPAD(TRIM(ao_code),6,'0') as ao_code"),
                'cif',
                DB::raw('SUM(outstanding) as os_awal'),
            ])
            ->whereDate('snapshot_month', $snapPrev)
            ->whereNotNull('cif')->where('cif', '!=', '')
            ->whereNotNull('ao_code')->where('ao_code', '!=', '')
            ->groupBy('ao_code', 'cif');

        if ($branchCode && $this->hasColumn('loan_account_snapshots_monthly', 'branch_code')) {
            $startSnapQ->where('branch_code', $branchCode);
        }
        if ($aoCode) {
            $startSnapQ->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [str_pad(trim((string)$aoCode), 6, '0', STR_PAD_LEFT)]);
        }

        // ===========================
        // 4) Agg per AO: total + count + max + cif baru
        // ===========================
        $aggRows = DB::query()
            ->fromSub($perCifQ, 'x')
            ->leftJoinSub($startSnapQ, 's', function ($join) {
                $join->on('x.ao_code', '=', 's.ao_code')
                     ->on('x.cif', '=', 's.cif');
            })
            ->select([
                'x.ao_code',
                DB::raw('SUM(x.topup_amount) as realisasi_topup'),
                DB::raw('SUM(CASE WHEN x.topup_amount > 0 THEN 1 ELSE 0 END) as topup_cif_count'),
                // CIF baru = tidak punya os_awal di snapshot prev (atau os_awal = 0)
                DB::raw('SUM(CASE WHEN IFNULL(s.os_awal,0)=0 AND x.topup_amount > 0 THEN 1 ELSE 0 END) as topup_cif_new_count'),
                DB::raw('MAX(x.topup_amount) as topup_max_cif_amount'),
            ])
            ->groupBy('x.ao_code')
            ->get();

        // Top 3 per AO
        $topRows = DB::query()
            ->fromSub($perCifQ, 'x')
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
                    'cif' => (string) ($r->cif ?? ''),
                    'amount' => (float) ($r->topup_amount ?? 0),
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

    /**
     * ambil kolom pertama yang tersedia dari list kandidat
     */
    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if ($this->hasColumn($table, $c)) return $c;
        }
        return null;
    }
}
