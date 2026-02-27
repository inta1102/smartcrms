<?php 

namespace App\Services\Kpi;

use App\Models\KpiRoMonthly;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RoKpiMonthlyBuilder
{
    public function __construct(
        protected RoTopupService $topupService
    ) {}

    /**
     * Build & store KPI RO ke tabel kpi_ro_monthly.
     *
     * $mode:
     * - 'realtime' => OS akhir pakai loan_accounts latest (running)
     * - 'eom'      => OS akhir pakai snapshot_month bulan KPI (locked historis)
     *
     * Rules:
     * - realtime: tidak overwrite record yang sudah locked_at (kecuali $force=true)
     * - eom: akan set locked_at jika belum locked (atau overwrite jika $force=true)
     */
    public function buildAndStore(
        string $periodYmd,
        string $mode = 'realtime',
        ?string $branchCode = null,
        ?string $aoCode = null,
        bool $force = false,
        float $topupTarget = 750_000_000
    ): array {
        $period = Carbon::parse($periodYmd)->startOfMonth();
        $periodMonth = $period->toDateString();                      // YYYY-MM-01
        $snapPrev    = $period->copy()->subMonth()->toDateString();  // prev YYYY-MM-01

        // baseline AO set (prev snapshot)
        $baselineAos = array_flip($this->baselineAoSet($snapPrev, $branchCode, $aoCode));

        // ---- 1) TopUp (✅ by disbursement bulan KPI)
        $topupByAo = $this->topupService->buildForMonth(
            $periodMonth,
            $topupTarget,
            $mode,
            $branchCode,
            $aoCode
        );

        // ---- 2) Repayment Rate (OS lancar / total OS) + Score
        $repaymentByAo = $this->calcRepaymentRateByAo($periodMonth, $mode, $branchCode, $aoCode);

        // ---- 3) ✅ NOA pengembangan (FIX): disbursement NEW bulan KPI (bukan snapshot delta)
        $noaByAo = $this->calcNoaPengembanganByAo($periodMonth, $mode, $branchCode, $aoCode);

        // ---- 4) Pemburukan DPK (kolek 1 -> kolek 2) / total OS akhir + Score
        $dpkByAo = $this->calcPemburukanDpkByAo($periodMonth, $mode, $branchCode, $aoCode);

        $adjRows = DB::table('kpi_ro_topup_adj_lines as l')
            ->join('kpi_ro_topup_adj_batches as b', 'b.id', '=', 'l.batch_id')
            ->whereDate('b.period_month', $periodMonth)
            ->where('b.status', 'approved')
            ->selectRaw("
                l.cif,
                l.source_ao_code,
                l.target_ao_code,
                SUM(l.amount_frozen) as amount_frozen,
                MAX(l.calc_as_of_date) as calc_as_of_date,
                MAX(l.reason) as reason,
                MIN(l.batch_id) as batch_id
            ")
            ->groupBy('l.cif','l.source_ao_code','l.target_ao_code')
            ->get();

        $adjIn = [];
        $adjOut = [];

        foreach ($adjRows as $r) {
            $srcRaw = trim((string)($r->source_ao_code ?? ''));
            $tgtRaw = trim((string)($r->target_ao_code ?? ''));

            $src = $srcRaw !== '' ? str_pad($srcRaw, 6, '0', STR_PAD_LEFT) : null;
            $tgt = $tgtRaw !== '' ? str_pad($tgtRaw, 6, '0', STR_PAD_LEFT) : null;

            $amt = (float)($r->amount_frozen ?? 0);

            // IN → masuk ke target AO
            if ($tgt && $tgt !== '000000') {
                $adjIn[$tgt]['sum'] = ($adjIn[$tgt]['sum'] ?? 0) + $amt;
                $adjIn[$tgt]['rows'][] = [
                    'cif' => (string)$r->cif,
                    'amount' => $amt,
                    'source_ao' => ($src && $src !== '000000') ? $src : null,
                    'batch_id' => (int)$r->batch_id,
                    'as_of' => $r->calc_as_of_date,
                    'reason' => $r->reason,
                ];
            }

            // OUT → keluar dari source AO
            if ($src && $src !== '000000') {
                $adjOut[$src]['sum'] = ($adjOut[$src]['sum'] ?? 0) + $amt;
                $adjOut[$src]['rows'][] = [
                    'cif' => (string)$r->cif,
                    'amount' => $amt,
                    'target_ao' => ($tgt && $tgt !== '000000') ? $tgt : null,
                    'batch_id' => (int)$r->batch_id,
                    'as_of' => $r->calc_as_of_date,
                    'reason' => $r->reason,
                ];
            }
        }

        // ---- 5) Gabung AO codes (union dari semua komponen)
        $aoCodes = array_unique(array_merge(
            array_keys($topupByAo),
            array_keys($repaymentByAo),
            array_keys($noaByAo),
            array_keys($dpkByAo),
            array_keys($adjIn),
            array_keys($adjOut),
        ));
        sort($aoCodes);

        $saved = 0;
        $skippedLocked = 0;

        foreach ($aoCodes as $ao) {
            $row = KpiRoMonthly::query()
                ->whereDate('period_month', $periodMonth)
                ->where('ao_code', $ao)
                ->first();

            // skip kalau realtime tapi sudah locked
            if ($mode === 'realtime' && $row && $row->locked_at && !$force) {
                $skippedLocked++;
                continue;
            }

            $hasBaseline = isset($baselineAos[$ao]);

            // meta dari topup service (kalau ada)
            $meta = $topupByAo[$ao] ?? null;
            $startSnapshotMonth = $meta['start_snapshot_month'] ?? $snapPrev;
            $endSnapshotMonth   = $meta['end_snapshot_month'] ?? ($mode === 'eom' ? $periodMonth : null);
            $srcPosDate         = $meta['latest_position_date'] ?? null;

            $baselineOk   = $hasBaseline ? 1 : 0;
            $baselineNote = $hasBaseline ? null : "Snapshot prev not found: {$snapPrev}";

            // ===== default komponen =====
            $topup = $topupByAo[$ao] ?? [
                'realisasi_topup' => 0.0,
                'realisasi_topup_base' => 0.0,
                'topup_adj_in'  => 0.0,
                'topup_adj_out' => 0.0,
                'topup_adj_net' => 0.0,

                'target' => $topupTarget,
                'pct' => 0.0,
                'score' => 1,

                'topup_cif_count' => 0,
                'topup_cif_new_count' => 0,
                'topup_max_cif_amount' => 0.0,
                'topup_concentration_pct' => 0.0,
                'topup_top3' => [],
            ];

            // ✅ BASE dari service (murni), fallback kalau service lama belum punya base
            $topupBase = (float)($topup['realisasi_topup_base'] ?? ($topup['realisasi_topup'] ?? 0));

            $adjInSum  = (float)($adjIn[$ao]['sum'] ?? 0);
            $adjOutSum = (float)($adjOut[$ao]['sum'] ?? 0);
            $adjNet    = $adjInSum - $adjOutSum;

            // Detail untuk audit / blade
            $inRows  = $adjIn[$ao]['rows'] ?? [];
            $outRows = $adjOut[$ao]['rows'] ?? [];
            $adjJson = (!empty($inRows) || !empty($outRows))
                ? json_encode(['in' => $inRows, 'out' => $outRows])
                : null;

            // ✅ logger setelah rows siap
            if ($ao === '000039') {
                logger()->info('ADJ 000039', [
                    'base' => $topupBase,
                    'in' => $adjInSum,
                    'out' => $adjOutSum,
                    'net' => $adjNet,
                    'in_rows' => $inRows,
                    'out_rows' => $outRows,
                ]);
            }

            // TopUp final dipakai KPI
            $topupFinal = max($topupBase + $adjNet, 0);

            // Recompute pct & score berdasarkan FINAL
            $topupPctFinal   = KpiScoreHelper::achievementPct($topupFinal, (float)$topupTarget);
            $topupScoreFinal = KpiScoreHelper::scoreBand1to6($topupPctFinal);

            $repay = $repaymentByAo[$ao] ?? [
                'rate'  => 0.0,
                'pct'   => 0.0,
                'score' => 1,
                'total_os' => 0.0,
                'os_lancar' => 0.0,
            ];

            $noa = $noaByAo[$ao] ?? [
                'realisasi' => 0,
                'target'    => 2,
                'pct'       => 0.0,
                'score'     => 1,
            ];

            $dpk = $dpkByAo[$ao] ?? [
                'pct' => 0.0,
                'score' => 6,
                'migrasi_count' => 0,
                'migrasi_os' => 0.0,
                'total_os_akhir' => 0.0,
            ];

            // RULE A: kalau baseline kosong, jangan percaya TopUp & DPK migrasi & NOA
            if (!$hasBaseline) {
                $topup = [
                    'realisasi_topup' => 0.0,
                    'realisasi_topup_base' => 0.0,
                    'topup_adj_in'  => 0.0,
                    'topup_adj_out' => 0.0,
                    'topup_adj_net' => 0.0,

                    'target' => $topupTarget,
                    'pct' => 0.0,
                    'score' => 1,

                    'topup_cif_count' => 0,
                    'topup_cif_new_count' => 0,
                    'topup_max_cif_amount' => 0.0,
                    'topup_concentration_pct' => 0.0,
                    'topup_top3' => [],
                ];

                // IMPORTANT: reset juga base/final agar konsisten
                $topupBase  = 0.0;
                $topupFinal = 0.0;

                $topupPctFinal   = 0.0;
                $topupScoreFinal = 1;

                $dpk = [
                    'pct' => 0.0,
                    'score' => 1,
                    'migrasi_count' => 0,
                    'migrasi_os' => 0.0,
                    'total_os_akhir' => 0.0,
                ];

                $noa = [
                    'realisasi' => 0,
                    'target'    => 2,
                    'pct'       => 0.0,
                    'score'     => 1,
                ];
            }

            // weighted score sesuai bobot slide
            $totalWeighted =
                ($repay['score'] * 0.40) +
                ($topupScoreFinal * 0.20) +
                ($noa['score']   * 0.10) +
                ($dpk['score']   * 0.30);

            $payload = [
                'period_month' => $periodMonth,
                'branch_code'  => $branchCode,
                'ao_code'      => $ao,

                // TopUp FINAL untuk KPI
                'topup_realisasi' => $topupFinal,
                'topup_target'    => (float)$topupTarget,
                'topup_pct'       => (float)$topupPctFinal,
                'topup_score'     => (int)$topupScoreFinal,

                // Breakdown (recommended)
                'topup_realisasi_base' => $topupBase,
                'topup_adj_in'         => $adjInSum,
                'topup_adj_out'        => $adjOutSum,
                'topup_adj_net'        => $adjNet,

                // Optional detail
                'topup_adj_json'       => $adjJson,

                // Repayment
                'repayment_rate'  => (float) ($repay['rate'] ?? 0),   // 0..1
                'repayment_pct'   => (float) ($repay['pct'] ?? 0),    // 0..100
                'repayment_score' => (int)   ($repay['score'] ?? 1),

                // ✅ Repayment detail
                'repayment_total_os' => (float) ($repay['total_os'] ?? 0),
                'repayment_os_lancar'=> (float) ($repay['os_lancar'] ?? 0),

                // NOA
                'noa_realisasi' => (int)   ($noa['realisasi'] ?? 0),
                'noa_target'    => (int)   ($noa['target'] ?? 2),
                'noa_pct'       => (float) ($noa['pct'] ?? 0),
                'noa_score'     => (int)   ($noa['score'] ?? 1),

                // DPK
                'dpk_pct'   => (float) ($dpk['pct'] ?? 0),    // 0..100
                'dpk_score' => (int)   ($dpk['score'] ?? 1),

                'dpk_migrasi_count' => (int)   ($dpk['migrasi_count'] ?? 0),
                'dpk_migrasi_os'    => (float) ($dpk['migrasi_os'] ?? 0),
                'dpk_total_os_akhir'=> (float) ($dpk['total_os_akhir'] ?? 0),

                'total_score_weighted' => (float) round($totalWeighted, 2),

                // meta
                'calc_mode'               => $mode,
                'start_snapshot_month'    => $startSnapshotMonth,
                'end_snapshot_month'      => $endSnapshotMonth,
                'calc_source_position_date' => $srcPosDate ? Carbon::parse($srcPosDate)->toDateString() : null,

                'baseline_ok'   => $baselineOk,
                'baseline_note' => $baselineNote,
            ];

            // mode eom => set locked_at
            if ($mode === 'eom') {
                $payload['locked_at'] = $row?->locked_at ?? now();
                $payload['end_snapshot_month'] = $periodMonth;
            }

            // upsert
            KpiRoMonthly::query()->updateOrCreate(
                ['period_month' => $periodMonth, 'ao_code' => $ao],
                $payload
            );

            $saved++;
        }

        // ✅ logger base yang benar
        logger()->info('TOPUP BASE 000039', [
            'base' => $topupByAo['000039']['realisasi_topup_base'] ?? ($topupByAo['000039']['realisasi_topup'] ?? null),
            'top3' => $topupByAo['000039']['topup_top3'] ?? null,
        ]);

        return [
            'period_month' => $periodMonth,
            'mode' => $mode,
            'branch_code' => $branchCode,
            'ao_code_filter' => $aoCode,
            'saved' => $saved,
            'skipped_locked' => $skippedLocked,
            'total_ao' => count($aoCodes),
        ];
    }

    /**
     * Repayment Rate = OS lancar / Total OS
     * - Lancar: ft_pokok=0 AND ft_bunga=0
     */
    protected function calcRepaymentRateByAo(string $periodMonth, string $mode, ?string $branchCode, ?string $aoCode): array
    {
        [$table, $dateField, $dateValue] = $this->resolveEndSet($periodMonth, $mode, $branchCode);
        if (!$table) return [];

        $base = DB::table($table)
            ->select([
                'ao_code',
                DB::raw('SUM(outstanding) AS total_os'),
                DB::raw('SUM(CASE WHEN ft_pokok = 0 AND ft_bunga = 0 THEN outstanding ELSE 0 END) AS os_lancar'),
            ])
            ->whereDate($dateField, $dateValue)
            ->whereNotNull('ao_code')->where('ao_code', '!=', '')
            ->whereNotNull('cif')->where('cif', '!=', '');

        if ($branchCode && $this->hasColumn($table, 'branch_code')) $base->where('branch_code', $branchCode);
        if ($aoCode) $base->where('ao_code', $aoCode);

        $rows = $base->groupBy('ao_code')->get();

        $out = [];
        foreach ($rows as $r) {
            $total  = (float) ($r->total_os ?? 0);
            $lancar = (float) ($r->os_lancar ?? 0);

            // RR = os_lancar / total_os
            $rate = $total > 0 ? ($lancar / $total) : 0.0;

            // pct dalam skala 0..100
            $pct = round($rate * 100.0, 2);

            $out[(string) $r->ao_code] = [
                'rate'      => $rate,                 // 0..1
                'pct'       => $pct,                  // 0..100
                'score'     => $this->scoreRepaymentPct($pct),
                'total_os'  => $total,
                'os_lancar' => $lancar,
            ];
        }

        return $out;
    }

    /**
     * ✅ FIX NOA Pengembangan (anti mutasi / limpahan):
     * dihitung dari DISBURSEMENT bulan KPI saja, dan yang dihitung = NEW (non-topup).
     *
     * Default count = COUNT(DISTINCT account_no) per AO.
     * Jika account_no tidak ada, fallback ke COUNT(DISTINCT cif).
     */
    protected function calcNoaPengembanganByAo(string $periodMonth, string $mode, ?string $branchCode, ?string $aoCode): array
    {
        $period = Carbon::parse($periodMonth)->startOfMonth();
        $periodStart = $period->toDateString();
        $periodEnd   = $period->copy()->endOfMonth()->toDateString();

        $q = DB::table('loan_disbursements');

        // filter branch (kalau kolom ada)
        if ($branchCode && $this->hasColumn('loan_disbursements', 'branch_code')) {
            $q->where('branch_code', $branchCode);
        }

        // filter AO
        if ($aoCode) {
            $q->whereRaw("LPAD(TRIM(ao_code),6,'0') = ?", [str_pad(trim((string)$aoCode), 6, '0', STR_PAD_LEFT)]);
        }

        // filter bulan KPI (utama pakai period)
        if ($this->hasColumn('loan_disbursements', 'period')) {
            $q->whereDate('period', $periodMonth);
        }

        // perketat pakai tanggal kalau ada
        $dateCol = $this->firstExistingColumn('loan_disbursements', [
            'disbursed_at',
            'disbursement_date',
            'tanggal_disbursement',
            'disb_date',
            'trx_date',
            'created_at',
        ]);
        if ($dateCol) {
            $q->whereBetween(DB::raw("DATE($dateCol)"), [$periodStart, $periodEnd]);
        }

        // ✅ NOA pengembangan = NEW (non-topup)
        $q->where(function ($qq) {
            $has = false;

            if ($this->hasColumn('loan_disbursements', 'is_topup')) {
                $qq->orWhere('is_topup', 0);
                $has = true;
            }

            if ($this->hasColumn('loan_disbursements', 'disbursement_type')) {
                // paling aman: exclude TOPUP
                $qq->orWhere('disbursement_type', '!=', 'TOPUP');
                $has = true;
            }

            if ($this->hasColumn('loan_disbursements', 'trx_type')) {
                $qq->orWhere('trx_type', '!=', 'TOPUP');
                $has = true;
            }

            if ($this->hasColumn('loan_disbursements', 'purpose')) {
                $qq->orWhereNotIn('purpose', ['TOPUP', 'TU', 'TOP UP', 'TOP-UP']);
                $has = true;
            }

            // kalau tidak ada indikator jenis sama sekali, jangan filter biar tidak kosong
            if (!$has) {
                $qq->whereRaw('1=1');
            }
        });

        // basic sanity
        $q->whereNotNull('ao_code')->where('ao_code', '!=', '');

        // hitung distinct by account_no kalau ada; kalau tidak, fallback cif
        $useAccount = $this->hasColumn('loan_disbursements', 'account_no');

        $rows = (clone $q)
            ->select([
                DB::raw("LPAD(TRIM(ao_code),6,'0') as ao_code"),
                $useAccount
                    ? DB::raw("COUNT(DISTINCT account_no) as noa_new")
                    : DB::raw("COUNT(DISTINCT cif) as noa_new"),
            ])
            ->groupBy('ao_code')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $noa = (int) ($r->noa_new ?? 0);

            // NOTE: target masih hardcoded 2 sesuai versi kamu sebelumnya.
            $target = 2;

            $pct = $target > 0 ? ($noa / $target) * 100.0 : 0.0;

            $out[(string)$r->ao_code] = [
                'realisasi' => $noa,
                'target'    => $target,
                'pct'       => $pct,
                'score'     => $this->scoreNoa($noa),
            ];
        }

        return $out;
    }

    protected function calcPemburukanDpkByAo(string $periodMonth, string $mode, ?string $branchCode, ?string $aoCode): array
    {
        $period   = Carbon::parse($periodMonth)->startOfMonth();
        $snapPrev = $period->copy()->subMonth()->toDateString();

        $startQ = DB::table('loan_account_snapshots_monthly')
            ->select([
                'account_no',
                'ft_pokok',
                'ft_bunga',
            ])
            ->whereDate('snapshot_month', $snapPrev)
            ->whereNotNull('account_no')->where('account_no','!=','')
            ->where(function ($q) {
                $q->where('ft_pokok', 1)
                  ->orWhere('ft_bunga', 1);
            });

        if ($branchCode) $startQ->where('branch_code', $branchCode);
        if ($aoCode)     $startQ->where('ao_code', $aoCode);

        [$endTable, $endDateField, $endDateValue] = $this->resolveEndSet($periodMonth, $mode, $branchCode);
        if (!$endTable) return [];

        $endQ = DB::table($endTable)
            ->select([
                'ao_code',
                'account_no',
                'kolek',
                'ft_pokok',
                'ft_bunga',
                DB::raw('outstanding AS os_akhir_acc'),
            ])
            ->whereDate($endDateField, $endDateValue)
            ->whereNotNull('ao_code')->where('ao_code','!=','')
            ->whereNotNull('account_no')->where('account_no','!=','');

        if ($branchCode && $this->hasColumn($endTable,'branch_code')) $endQ->where('branch_code', $branchCode);
        if ($aoCode) $endQ->where('ao_code', $aoCode);

        $rows = DB::query()
            ->fromSub($endQ, 'e')
            ->leftJoinSub($startQ, 's', function ($join) {
                $join->on('s.account_no','=','e.account_no');
            })
            ->select([
                'e.ao_code',
                DB::raw('SUM(e.os_akhir_acc) AS total_os_akhir'),
                DB::raw("
                    SUM(
                        CASE
                            WHEN (
                                (s.ft_pokok = 1 OR s.ft_bunga = 1)
                                AND (
                                    CAST(e.kolek AS UNSIGNED) = 2
                                    OR e.ft_pokok = 2
                                    OR e.ft_bunga = 2
                                )
                            )
                            THEN e.os_akhir_acc
                            ELSE 0
                        END
                    ) AS os_migrasi_lt_ke_dpk
                "),
                DB::raw("
                    SUM(
                        CASE
                            WHEN (
                                (s.ft_pokok = 1 OR s.ft_bunga = 1)
                                AND (
                                    CAST(e.kolek AS UNSIGNED) = 2
                                    OR e.ft_pokok = 2
                                    OR e.ft_bunga = 2
                                )
                            )
                            THEN 1
                            ELSE 0
                        END
                    ) AS migrasi_cnt
                "),
            ])
            ->groupBy('e.ao_code')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $total  = (float) ($r->total_os_akhir ?? 0);
            $migOs  = (float) ($r->os_migrasi_lt_ke_dpk ?? 0);
            $migCnt = (int)   ($r->migrasi_cnt ?? 0);

            $pct = $total > 0 ? ($migOs / $total) * 100.0 : 0.0;

            $out[(string)$r->ao_code] = [
                'pct' => $pct,
                'score' => $this->scoreDpkPct($pct),
                'migrasi_count' => $migCnt,
                'migrasi_os'    => $migOs,
                'total_os_akhir'=> $total,
            ];
        }

        return $out;
    }

    protected function resolveEndSet(string $periodMonth, string $mode, ?string $branchCode): array
    {
        if ($mode === 'eom') {
            return ['loan_account_snapshots_monthly', 'snapshot_month', $periodMonth];
        }

        $q = DB::table('loan_accounts');
        if ($branchCode) $q->where('branch_code', $branchCode);

        $latest = $q->max('position_date');
        if (!$latest) return [null, null, null];

        return ['loan_accounts', 'position_date', $latest];
    }

    protected function scoreRepaymentPct(float $pct): int
    {
        if ($pct < 70) return 1;
        if ($pct < 80) return 2;
        if ($pct < 90) return 3;
        if ($pct < 95) return 4;
        if ($pct < 100) return 5;
        return 6;
    }

    protected function scoreNoa(int $noa): int
    {
        if ($noa <= 0) return 1;
        if ($noa === 1) return 4;
        if ($noa === 2) return 5;
        return 6;
    }

    protected function scoreDpkPct(float $pct): int
    {
        if ($pct <= 0) return 6;
        if ($pct < 1)  return 5;
        if ($pct < 2)  return 4;
        if ($pct < 3)  return 3;
        if ($pct < 4)  return 2;
        return 1;
    }

    protected function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $k = $table . ':' . $column;
        if (array_key_exists($k, $cache)) return $cache[$k];

        $cols = DB::getSchemaBuilder()->getColumnListing($table);
        return $cache[$k] = in_array($column, $cols, true);
    }

    protected function firstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if ($this->hasColumn($table, $c)) return $c;
        }
        return null;
    }

    protected function baselineAoSet(string $snapshotMonth, ?string $branchCode = null, ?string $aoCode = null): array
    {
        $q = DB::table('loan_account_snapshots_monthly')
            ->select('ao_code')
            ->whereDate('snapshot_month', $snapshotMonth)
            ->whereNotNull('ao_code')->where('ao_code', '!=', '')
            ->whereNotNull('cif')->where('cif', '!=', '')
            ->groupBy('ao_code');

        if ($branchCode) $q->where('branch_code', $branchCode);
        if ($aoCode)     $q->where('ao_code', $aoCode);

        return $q->pluck('ao_code')->map(fn($x) => (string)$x)->all();
    }
}