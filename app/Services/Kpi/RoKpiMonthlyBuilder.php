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

        // ---- 1) TopUp (pakai service yang sudah “kuat”)
        $topupByAo = $this->topupService->buildForMonth(
            $periodMonth,
            $topupTarget,
            $mode,
            $branchCode,
            $aoCode
        );

        // ---- 2) Repayment Rate (OS lancar / total OS) + Score
        $repaymentByAo = $this->calcRepaymentRateByAo($periodMonth, $mode, $branchCode, $aoCode);

        // ---- 3) NOA pengembangan (CIF baru: os_awal=0 -> os_akhir>0) + Score
        $noaByAo = $this->calcNoaPengembanganByAo($periodMonth, $mode, $branchCode, $aoCode);

        // ---- 4) Pemburukan DPK (kolek 1 -> kolek 2) / total OS akhir + Score
        $dpkByAo = $this->calcPemburukanDpkByAo($periodMonth, $mode, $branchCode, $aoCode);

        // ---- 5) Gabung AO codes (union dari semua komponen)
        $aoCodes = array_unique(array_merge(
            array_keys($topupByAo),
            array_keys($repaymentByAo),
            array_keys($noaByAo),
            array_keys($dpkByAo),
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
                'target' => $topupTarget,
                'pct' => 0.0,
                'score' => 1,

                'topup_cif_count' => 0,
                'topup_cif_new_count' => 0,
                'topup_max_cif_amount' => 0.0,
                'topup_concentration_pct' => 0.0,
                'topup_top3' => [],
            ];

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
                    'target' => $topupTarget,
                    'pct' => 0.0,
                    'score' => 1,

                    'topup_cif_count' => 0,
                    'topup_cif_new_count' => 0,
                    'topup_max_cif_amount' => 0.0,
                    'topup_concentration_pct' => 0.0,
                    'topup_top3' => [],
                ];

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
                ($topup['score'] * 0.20) +
                ($noa['score']   * 0.10) +
                ($dpk['score']   * 0.30);

            $payload = [
                'period_month' => $periodMonth,
                'branch_code'  => $branchCode,
                'ao_code'      => $ao,

                // TopUp
                'topup_realisasi' => (float) ($topup['realisasi_topup'] ?? 0),
                'topup_target'    => (float) ($topup['target'] ?? $topupTarget),
                'topup_pct'       => (float) ($topup['pct'] ?? 0),
                'topup_score'     => (int)   ($topup['score'] ?? 1),

                // ✅ TopUp detail
                'topup_cif_count' => (int)   ($topup['topup_cif_count'] ?? 0),
                'topup_cif_new_count' => (int) ($topup['topup_cif_new_count'] ?? 0),
                'topup_max_cif_amount' => (float) ($topup['topup_max_cif_amount'] ?? 0),
                'topup_concentration_pct' => (float) ($topup['topup_concentration_pct'] ?? 0),
                'topup_top3_json' => !empty($topup['topup_top3'])
                    ? json_encode($topup['topup_top3'])
                    : null,

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

            $rate = $total > 0 ? ($lancar / $total) : 0.0;
            $pct  = $rate * 100.0;

            $out[(string)$r->ao_code] = [
                'rate'  => $rate,
                'pct'   => $pct,
                'score' => $this->scoreRepaymentPct($pct),
                'total_os'  => $total,
                'os_lancar' => $lancar,
            ];
        }

        return $out;
    }

    protected function calcNoaPengembanganByAo(string $periodMonth, string $mode, ?string $branchCode, ?string $aoCode): array
    {
        $period = Carbon::parse($periodMonth)->startOfMonth();
        $snapPrev = $period->copy()->subMonth()->toDateString();

        $startQ = DB::table('loan_account_snapshots_monthly')
            ->select([
                'ao_code',
                'cif',
                DB::raw('SUM(outstanding) AS os_awal'),
            ])
            ->whereDate('snapshot_month', $snapPrev)
            ->whereNotNull('ao_code')->where('ao_code', '!=', '')
            ->whereNotNull('cif')->where('cif', '!=', '');

        if ($branchCode) $startQ->where('branch_code', $branchCode);
        if ($aoCode)     $startQ->where('ao_code', $aoCode);
        $startQ->groupBy('ao_code','cif');

        [$endTable, $endDateField, $endDateValue] = $this->resolveEndSet($periodMonth, $mode, $branchCode);
        if (!$endTable) return [];

        $endQ = DB::table($endTable)
            ->select([
                'ao_code',
                'cif',
                DB::raw('SUM(outstanding) AS os_akhir'),
            ])
            ->whereDate($endDateField, $endDateValue)
            ->whereNotNull('ao_code')->where('ao_code', '!=', '')
            ->whereNotNull('cif')->where('cif', '!=', '');

        if ($branchCode && $this->hasColumn($endTable, 'branch_code')) $endQ->where('branch_code', $branchCode);
        if ($aoCode) $endQ->where('ao_code', $aoCode);
        $endQ->groupBy('ao_code','cif');

        $rows = DB::query()
            ->fromSub($endQ, 'e')
            ->leftJoinSub($startQ, 's', function ($join) {
                $join->on('e.ao_code','=','s.ao_code')
                     ->on('e.cif','=','s.cif');
            })
            ->select([
                'e.ao_code',
                DB::raw("SUM(CASE WHEN IFNULL(s.os_awal,0)=0 AND e.os_akhir>0 THEN 1 ELSE 0 END) AS noa_baru"),
            ])
            ->groupBy('e.ao_code')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $noa = (int) ($r->noa_baru ?? 0);
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
