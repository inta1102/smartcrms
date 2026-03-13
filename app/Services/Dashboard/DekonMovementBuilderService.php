<?php

namespace App\Services\Dashboard;

use App\Models\DashboardDekomMovement;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DekonMovementBuilderService
{
    public function build(string|Carbon $periodYm, string $mode = 'eom'): void
    {
        $period = $periodYm instanceof Carbon
            ? $periodYm->copy()->startOfMonth()
            : Carbon::parse($periodYm)->startOfMonth();

        $mode = strtolower(trim((string) $mode));
        if (!in_array($mode, ['eom', 'realtime', 'hybrid'], true)) {
            $mode = 'eom';
        }

        $prevPeriod = $period->copy()->subMonthNoOverflow()->startOfMonth();

        // previous selalu snapshot bulan sebelumnya
        $prevRows = $this->getSnapshotRows($prevPeriod);

        // current mengikuti periode + mode
        $currentRows = $this->useLiveSource($period, $mode)
            ? $this->getLiveLoanAccountRows()
            : $this->getSnapshotRows($period);

        DashboardDekomMovement::query()
            ->whereDate('period_month', $period->toDateString())
            ->where('mode', $mode)
            ->delete();

        $buffer = [];

        foreach ($prevRows as $accountNo => $prevRow) {
            $currRow = $currentRows->get($accountNo);

            $fromBucket = $this->bucket($prevRow);
            $toBucket   = $currRow ? $this->bucket($currRow) : 'LUNAS';

            // =========================================================
            // 1) FT / QUALITY MOVEMENT
            // =========================================================
            if ($fromBucket && $toBucket) {
                $movement = $this->resolveMovement($fromBucket, $toBucket);

                if ($movement) {
                    $buffer[] = $this->makeMovementRow(
                        $period,
                        $mode,
                        $movement['section'],
                        $movement['subgroup'],
                        $movement['line_key'],
                        $movement['line_label'],
                        1,
                        (float) ($prevRow->outstanding ?? 0),
                        0,
                        $movement['sort_order']
                    );
                }
            }

            // =========================================================
            // 2) NPL IMPROVEMENT SUMMARY
            // NPL = kolek >= 3
            // =========================================================
            $prevIsNpl = $this->isNpl($prevRow);
            $currIsNpl = $this->isNpl($currRow);

            if ($prevIsNpl && $currRow && !$currIsNpl) {
                $buffer[] = $this->makeMovementRow(
                    $period,
                    $mode,
                    'npl_improvement',
                    'summary',
                    'perbaikan',
                    'Perbaikan',
                    1,
                    (float) ($prevRow->outstanding ?? 0),
                    0,
                    10
                );
            }

            if ($prevIsNpl && !$currRow) {
                $buffer[] = $this->makeMovementRow(
                    $period,
                    $mode,
                    'npl_improvement',
                    'summary',
                    'pelunasan',
                    'Pelunasan',
                    1,
                    (float) ($prevRow->outstanding ?? 0),
                    0,
                    20
                );
            }
        }

        // =========================================================
        // 3) CREDIT ACTIVITY
        // =========================================================
        $creditActivityRows = $this->buildCreditActivity($period, $mode, $prevRows, $currentRows);

        foreach ($creditActivityRows as $row) {
            $buffer[] = $row;
        }

        // =========================================================
        // 4) AGGREGATE DETAIL ROWS
        // =========================================================
        $aggregated = $this->aggregateRows($buffer);

        foreach ($aggregated as $row) {
            DashboardDekomMovement::query()->updateOrCreate(
                [
                    'period_month' => $row['period_month'],
                    'mode'         => $row['mode'],
                    'section'      => $row['section'],
                    'subgroup'     => $row['subgroup'],
                    'line_key'     => $row['line_key'],
                ],
                $row
            );
        }

        // =========================================================
        // 5) BUILD TOTALS & GRAND TOTALS
        // =========================================================
        $this->buildTotals($period, $mode);
    }

    protected function getSnapshotRows(Carbon $period): Collection
    {
        if (!Schema::hasTable('loan_account_snapshots_monthly')) {
            return collect();
        }

        return DB::table('loan_account_snapshots_monthly')
            ->whereYear('snapshot_month', $period->year)
            ->whereMonth('snapshot_month', $period->month)
            ->select([
                'account_no',
                'cif',
                'outstanding',
                'kolek',
                'ft_pokok',
                'ft_bunga',
            ])
            ->get()
            ->keyBy(fn ($r) => trim((string) ($r->account_no ?? '')));
    }

    protected function getLiveLoanAccountRows(): Collection
    {
        if (!Schema::hasTable('loan_accounts')) {
            return collect();
        }

        $latestPositionDate = DB::table('loan_accounts')->max('position_date');

        if (!$latestPositionDate) {
            return collect();
        }

        return DB::table('loan_accounts')
            ->whereDate('position_date', $latestPositionDate)
            ->select([
                'account_no',
                'cif',
                'outstanding',
                'kolek',
                'ft_pokok',
                'ft_bunga',
            ])
            ->get()
            ->keyBy(fn ($r) => trim((string) ($r->account_no ?? '')));
    }

    protected function useLiveSource(Carbon $period, string $mode): bool
    {
        if ($mode === 'eom') {
            return false;
        }

        return $period->copy()->startOfMonth()->equalTo(now()->copy()->startOfMonth());
    }

    protected function shouldUseLiveLoanAccounts(Carbon $period): bool
    {
        return $period->copy()->startOfMonth()->equalTo(now()->copy()->startOfMonth());
    }

    protected function bucket(?object $row): ?string
    {
        if (!$row) {
            return null;
        }

        $kolek   = (int) ($row->kolek ?? 0);
        $ftPokok = (int) ($row->ft_pokok ?? 0);
        $ftBunga = (int) ($row->ft_bunga ?? 0);

        $maxFt = max($ftPokok, $ftBunga);

        // FT0
        if ($kolek === 1 && $maxFt === 0) {
            return 'FT0';
        }

        // FT1
        if ($kolek === 1 && $maxFt === 1) {
            return 'FT1';
        }

        // FT2
        if ($kolek === 2 && $maxFt === 2) {
            return 'FT2';
        }

        // FT3
        if ($kolek === 2 && $maxFt === 3) {
            return 'FT3';
        }

        // KL
        if ($kolek === 3) {
            return 'KL';
        }

        return null;
    }

    /**
     * NPL summary uses kolek >= 3
     */
    protected function isNpl(?object $row): bool
    {
        if (!$row) {
            return false;
        }

        return (int) ($row->kolek ?? 0) >= 3;
    }

    protected function resolveMovement(string $from, string $to): ?array
    {
        $order = [
            'FT0'   => 0,
            'FT1'   => 1,
            'FT2'   => 2,
            'FT3'   => 3,
            'KL'    => 4,
            'LUNAS' => -1,
        ];

        if (!isset($order[$from]) || !isset($order[$to])) {
            return null;
        }

        // =============================
        // IMPROVEMENT
        // =============================
        if ($order[$to] < $order[$from]) {
            return [
                'section'    => 'quality_improvement',
                'subgroup'   => strtolower($from),
                'line_key'   => strtolower("{$from}_to_{$to}"),
                'line_label' => "{$from} menjadi {$to}",
                'sort_order' => 10,
            ];
        }

        // =============================
        // DETERIORATION
        // =============================
        if ($order[$to] > $order[$from]) {
            return [
                'section'    => 'quality_deterioration',
                'subgroup'   => 'flow',
                'line_key'   => strtolower("{$from}_to_{$to}"),
                'line_label' => "{$from} menjadi {$to}",
                'sort_order' => 10,
            ];
        }

        return null;
    }

    protected function aggregateRows(array $rows): array
    {
        return collect($rows)
            ->groupBy(function ($r) {
                return implode('|', [
                    $r['period_month'],
                    $r['mode'],
                    $r['section'],
                    $r['subgroup'] ?? '',
                    $r['line_key'],
                ]);
            })
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'period_month' => $first['period_month'],
                    'mode'         => $first['mode'],
                    'section'      => $first['section'],
                    'subgroup'     => $first['subgroup'],
                    'line_key'     => $first['line_key'],
                    'line_label'   => $first['line_label'],
                    'noa_count'    => (int) $group->sum('noa_count'),
                    'os_amount'    => (float) $group->sum('os_amount'),
                    'plafond_baru' => (float) $group->sum('plafond_baru'),
                    'sort_order'   => (int) $first['sort_order'],
                    'is_total'     => false,
                    'meta'         => null,
                ];
            })
            ->values()
            ->all();
    }

    protected function buildTotals(Carbon $period, string $mode): void
    {
        $periodDate = $period->toDateString();

        $rows = DashboardDekomMovement::query()
            ->whereDate('period_month', $periodDate)
            ->where('mode', $mode)
            ->where('is_total', false)
            ->get();

        // total per subgroup
        $subgroups = $rows->groupBy(fn ($r) => $r->section . '|' . ($r->subgroup ?? ''));

        foreach ($subgroups as $key => $group) {
            [$section, $subgroup] = array_pad(explode('|', $key, 2), 2, null);

            DashboardDekomMovement::query()->updateOrCreate(
                [
                    'period_month' => $periodDate,
                    'mode'         => $mode,
                    'section'      => $section,
                    'subgroup'     => $subgroup ?: 'summary',
                    'line_key'     => 'total',
                ],
                [
                    'line_label'   => 'Total',
                    'noa_count'    => (int) $group->sum('noa_count'),
                    'os_amount'    => (float) $group->sum('os_amount'),
                    'plafond_baru' => (float) $group->sum('plafond_baru'),
                    'sort_order'   => 900,
                    'is_total'     => true,
                    'meta'         => null,
                ]
            );
        }

        // grand total per section
        $sections = $rows->groupBy('section');

        foreach ($sections as $section => $group) {
            DashboardDekomMovement::query()->updateOrCreate(
                [
                    'period_month' => $periodDate,
                    'mode'         => $mode,
                    'section'      => $section,
                    'subgroup'     => 'summary',
                    'line_key'     => 'grand_total',
                ],
                [
                    'line_label'   => 'Grand Total',
                    'noa_count'    => (int) $group->sum('noa_count'),
                    'os_amount'    => (float) $group->sum('os_amount'),
                    'plafond_baru' => (float) $group->sum('plafond_baru'),
                    'sort_order'   => 999,
                    'is_total'     => true,
                    'meta'         => null,
                ]
            );
        }
    }

    protected function makeMovementRow(
        Carbon $period,
        string $mode,
        string $section,
        ?string $subgroup,
        string $lineKey,
        string $lineLabel,
        int $noaCount,
        float $osAmount,
        float $plafondBaru,
        int $sortOrder
    ): array {
        return [
            'period_month' => $period->toDateString(),
            'mode'         => $mode,
            'section'      => $section,
            'subgroup'     => $subgroup,
            'line_key'     => $lineKey,
            'line_label'   => $lineLabel,
            'noa_count'    => $noaCount,
            'os_amount'    => $osAmount,
            'plafond_baru' => $plafondBaru,
            'sort_order'   => $sortOrder,
        ];
    }

    protected function buildCreditActivity(
        Carbon $period,
        string $mode,
        Collection $prevRows,
        Collection $currentRows
    ): array {
        $rows = [];

        if (!Schema::hasTable('loan_disbursements')) {
            return $rows;
        }

        $disbursements = DB::table('loan_disbursements')
            ->whereYear('disb_date', $period->year)
            ->whereMonth('disb_date', $period->month)
            ->select([
                'account_no',
                'cif',
                'amount',
            ])
            ->get();

        $prevCifs = $prevRows->pluck('cif')
            ->filter(fn ($v) => filled($v))
            ->map(fn ($v) => trim((string) $v))
            ->unique()
            ->values();

        $closedAccounts = $prevRows->filter(function ($prevRow, $accountNo) use ($currentRows) {
            return !$currentRows->has(trim((string) $accountNo));
        });

        $closedCifs = $closedAccounts->pluck('cif')
            ->filter(fn ($v) => filled($v))
            ->map(fn ($v) => trim((string) $v))
            ->unique()
            ->values();

        $creditLagiCifs = collect();

        // =====================================================
        // 1) TUTUP FASILITAS
        // =====================================================
        foreach ($closedAccounts as $accountNo => $prevRow) {
            $rows[] = $this->makeMovementRow(
                $period,
                $mode,
                'credit_activity',
                'pelunasan',
                'tutup_fasilitas',
                'Tutup Fasilitas',
                1,
                (float) ($prevRow->outstanding ?? 0),
                0,
                10
            );
        }

        // =====================================================
        // 2) KREDIT LAGI
        // CIF closed lalu ada disbursement baru di period ini
        // =====================================================
        foreach ($disbursements as $disb) {
            $cif = trim((string) ($disb->cif ?? ''));

            if ($cif === '') {
                continue;
            }

            if ($closedCifs->contains($cif)) {
                $creditLagiCifs->push($cif);

                $rows[] = $this->makeMovementRow(
                    $period,
                    $mode,
                    'credit_activity',
                    'pelunasan',
                    'kredit_lagi',
                    'Kredit Lagi',
                    1,
                    (float) ($disb->amount ?? 0),
                    (float) ($disb->amount ?? 0),
                    20
                );
            }
        }

        $creditLagiCifs = $creditLagiCifs->unique()->values();

        // =====================================================
        // 3) PEMBUKAAN BARU
        // - New CIF
        // - Nasabah Lama (exclude kredit lagi biar tidak double)
        // =====================================================
        foreach ($disbursements as $disb) {
            $cif = trim((string) ($disb->cif ?? ''));

            if ($cif === '') {
                continue;
            }

            if ($creditLagiCifs->contains($cif)) {
                continue;
            }

            $isExisting = $prevCifs->contains($cif);

            $rows[] = $this->makeMovementRow(
                $period,
                $mode,
                'credit_activity',
                'pembukaan',
                $isExisting ? 'nasabah_lama' : 'new_cif',
                $isExisting ? 'Nasabah Lama' : 'New CIF',
                1,
                (float) ($disb->amount ?? 0),
                (float) ($disb->amount ?? 0),
                $isExisting ? 20 : 10
            );
        }

        return $rows;
    }
}