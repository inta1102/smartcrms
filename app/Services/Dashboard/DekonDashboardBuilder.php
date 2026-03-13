<?php

namespace App\Services\Dashboard;

use App\Models\DashboardDekomSnapshot;
use App\Models\RbbCreditTarget;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\DashboardDekomTarget;

class DekonDashboardBuilder
{
    /**
     * Rule FT3 default:
     * - kolek = 2 dan max(ft_pokok, ft_bunga) = 3
     */
    protected int $ft3BungaValue = 3;

    public function buildForPeriod(string|Carbon $periodYm, ?string $mode = 'eom'): DashboardDekomSnapshot
    {
        $period = $periodYm instanceof Carbon
            ? $periodYm->copy()->startOfMonth()
            : Carbon::parse($periodYm)->startOfMonth();

        $mode = strtolower(trim((string) ($mode ?: 'eom')));
        if (!in_array($mode, ['eom', 'realtime', 'hybrid'], true)) {
            $mode = 'eom';
        }

        $asOfDate   = $this->resolveAsOfDate($period, $mode);
        $snapshot   = $this->aggregateSnapshot($period, $asOfDate, $mode);
        $realisasi  = $this->aggregateRealisasi($period, $asOfDate, $mode);
        $target     = $this->getRbbTarget($period);
        $growth     = $this->computeGrowth($period, (float) ($snapshot['total_os'] ?? 0), $mode);

        $portfolioSource = $this->shouldUseLiveLoanAccounts($mode)
            ? 'loan_accounts'
            : 'loan_account_snapshots_monthly';

        $targetYtd = (float) ($target['target_os'] ?? 0);

        $payload = [
            'period_month'  => $period->toDateString(),
            'as_of_date'    => $asOfDate?->toDateString(),
            'mode'          => $mode,

            'total_os'      => (float) ($snapshot['total_os'] ?? 0),
            'total_noa'     => (int) ($snapshot['total_noa'] ?? 0),

            'npl_os'        => (float) ($snapshot['npl_os'] ?? 0),
            'npl_pct'       => (float) ($snapshot['npl_pct'] ?? 0),

            'l_os'          => (float) ($snapshot['l_os'] ?? 0),
            'dpk_os'        => (float) ($snapshot['dpk_os'] ?? 0),
            'kl_os'         => (float) ($snapshot['kl_os'] ?? 0),
            'd_os'          => (float) ($snapshot['d_os'] ?? 0),
            'm_os'          => (float) ($snapshot['m_os'] ?? 0),

            'ft0_os'        => (float) ($snapshot['ft0_os'] ?? 0),
            'ft1_os'        => (float) ($snapshot['ft1_os'] ?? 0),
            'ft2_os'        => (float) ($snapshot['ft2_os'] ?? 0),
            'ft3_os'        => (float) ($snapshot['ft3_os'] ?? 0),

            'restr_os'      => (float) ($snapshot['restr_os'] ?? 0),
            'restr_noa'     => (int) ($snapshot['restr_noa'] ?? 0),

            'dpd6_os'       => (float) ($snapshot['dpd6_os'] ?? 0),
            'dpd12_os'      => (float) ($snapshot['dpd12_os'] ?? 0),

            'target_ytd'    => $targetYtd,
            'realisasi_mtd' => (float) ($realisasi['mtd_real_os'] ?? 0),
            'realisasi_ytd' => (float) ($realisasi['ytd_real_os'] ?? 0),

            'meta' => [
                'builder' => static::class,
                'built_at' => now()->toDateTimeString(),
                'portfolio_source' => $portfolioSource,
                'as_of_date' => $asOfDate?->toDateString(),
                'effective_mode' => $mode,

                'ft3_rule' => [
                    'kolek'    => 2,
                    'ft_pokok' => 3,
                    'ft_bunga' => $this->ft3BungaValue,
                ],

                'growth' => [
                    'mom_os_growth_pct' => (float) ($growth['mom_os_growth_pct'] ?? 0),
                    'yoy_os_growth_pct' => (float) ($growth['yoy_os_growth_pct'] ?? 0),
                ],

                'target' => [
                    'target_disbursement' => (float) ($target['target_disbursement'] ?? 0),
                    'target_os'           => (float) ($target['target_os'] ?? 0),
                    'target_npl_pct'      => (float) ($target['target_npl_pct'] ?? 0),
                    'ach_os_pct'          => ((float) ($target['target_os'] ?? 0)) > 0
                        ? round((((float) ($snapshot['total_os'] ?? 0)) / (float) $target['target_os']) * 100, 4)
                        : 0,
                ],

                'source' => [
                    'snapshot_table'  => 'loan_account_snapshots_monthly',
                    'live_table'      => 'loan_accounts',
                    'realisasi_table' => Schema::hasTable('loan_disbursements') ? 'loan_disbursements' : null,
                    'target_table'    => 'rbb_credit_targets',
                ],
            ],
        ];

        DashboardDekomSnapshot::query()->updateOrCreate(
            [
                'period_month' => $period->toDateString(),
                'mode'         => $mode,
            ],
            $payload
        );

        return DashboardDekomSnapshot::query()
            ->whereDate('period_month', $period->toDateString())
            ->where('mode', $mode)
            ->firstOrFail();
    }

    public function buildLatest(?string $mode = 'eom'): ?DashboardDekomSnapshot
    {
        if (!Schema::hasTable('loan_account_snapshots_monthly')) {
            return null;
        }

        $latest = DB::table('loan_account_snapshots_monthly')->max('snapshot_month');
        if (!$latest) {
            return null;
        }

        return $this->buildForPeriod(Carbon::parse($latest)->startOfMonth(), $mode);
    }

    public function rebuildRange(string|Carbon $fromYm, string|Carbon $toYm, ?string $mode = 'eom'): array
    {
        $from = $fromYm instanceof Carbon
            ? $fromYm->copy()->startOfMonth()
            : Carbon::parse($fromYm)->startOfMonth();

        $to = $toYm instanceof Carbon
            ? $toYm->copy()->startOfMonth()
            : Carbon::parse($toYm)->startOfMonth();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $rows = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $rows[] = $this->buildForPeriod($cursor, $mode);
            $cursor->addMonthNoOverflow();
        }

        return $rows;
    }

    protected function resolveAsOfDate(Carbon $period, string $mode): ?Carbon
    {
        if ($this->shouldUseLiveLoanAccounts($mode)) {
            $table = 'loan_accounts';

            if (!Schema::hasTable($table)) {
                return null;
            }

            $positionDateCol = $this->firstExistingColumn($table, [
                'position_date',
                'as_of_date',
                'source_position_date',
            ]);

            if (!$positionDateCol) {
                return null;
            }

            $latest = DB::table($table)->max($positionDateCol);

            return $latest ? Carbon::parse($latest)->startOfDay() : null;
        }

        if (!Schema::hasTable('loan_account_snapshots_monthly')) {
            return null;
        }

        $q = DB::table('loan_account_snapshots_monthly');
        $q = $this->applySnapshotMonthFilter($q, $period, 'snapshot_month');

        $date = $q->max('source_position_date');

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    protected function aggregateSnapshot(Carbon $period, ?Carbon $asOfDate, string $mode): array
    {
        if ($this->shouldUseLiveLoanAccounts($mode)) {
            return $this->aggregateFromLoanAccounts($period, $asOfDate);
        }

        return $this->aggregateFromMonthlySnapshot($period, $asOfDate);
    }

    protected function aggregateFromMonthlySnapshot(Carbon $period, ?Carbon $asOfDate): array
    {
        $defaults = $this->emptySnapshotResult();

        $table = 'loan_account_snapshots_monthly';
        if (!Schema::hasTable($table)) {
            return $defaults;
        }

        $outstandingCol = 'outstanding';
        $accountNoCol   = 'account_no';
        $kolekCol       = 'kolek';
        $ftPokokCol     = 'ft_pokok';
        $ftBungaCol     = 'ft_bunga';
        $dpdCol         = 'dpd';
        $positionDateCol = $this->firstExistingColumn($table, [
            'source_position_date',
            'position_date',
            'snapshot_month',
        ]);

        $restrBoolCol   = $this->firstExistingColumn($table, [
            'is_restructured',
            'is_restrukturisasi',
            'restructured',
            'restrukturisasi_flag',
            'restructure_flag',
        ]);

        $restrFreqCol   = $this->firstExistingColumn($table, [
            'restructure_freq',
            'restrukturisasi_freq',
            'restr_freq',
        ]);

        $restrDateCol   = $this->firstExistingColumn($table, [
            'last_restructure_date',
            'restructure_date',
            'last_restruktur_date',
        ]);

        $base = DB::table("{$table} as s");
        $base = $this->applySnapshotMonthFilter($base, $period, 'snapshot_month');

        $countRows = (clone $base)->count();

        logger()->info('DEKOM SNAPSHOT SOURCE', [
            'source'     => $table,
            'period'     => $period->format('Y-m'),
            'count_rows' => $countRows,
        ]);

        if ($countRows <= 0) {
            return $defaults;
        }

        return $this->aggregatePortfolioFromQuery(
            clone $base,
            's',
            $outstandingCol,
            $accountNoCol,
            $kolekCol,
            $ftPokokCol,
            $ftBungaCol,
            $positionDateCol,
            $restrBoolCol,
            $restrFreqCol,
            $restrDateCol,
            $dpdCol
        );
    }

    protected function aggregateFromLoanAccounts(Carbon $period, ?Carbon $asOfDate): array
    {
        $defaults = $this->emptySnapshotResult();

        $table = 'loan_accounts';
        if (!Schema::hasTable($table)) {
            return $defaults;
        }

        $outstandingCol = $this->firstExistingColumn($table, ['outstanding', 'os', 'balance']);
        $accountNoCol   = $this->firstExistingColumn($table, ['account_no', 'loan_no', 'rekening_no']);
        $kolekCol       = $this->firstExistingColumn($table, ['kolek', 'collectibility']);
        $ftPokokCol     = $this->firstExistingColumn($table, ['ft_pokok', 'ft_principal']);
        $ftBungaCol     = $this->firstExistingColumn($table, ['ft_bunga', 'ft_interest']);
        $dpdCol         = $this->firstExistingColumn($table, ['dpd', 'days_past_due']);
        $positionDateCol = $this->firstExistingColumn($table, [
            'position_date',
            'as_of_date',
            'source_position_date',
        ]);

        $restrBoolCol   = $this->firstExistingColumn($table, [
            'is_restructured',
            'is_restrukturisasi',
            'restructured',
            'restrukturisasi_flag',
            'restructure_flag',
        ]);

        $restrFreqCol   = $this->firstExistingColumn($table, [
            'restructure_freq',
            'restrukturisasi_freq',
            'restr_freq',
        ]);

        $restrDateCol   = $this->firstExistingColumn($table, [
            'last_restructure_date',
            'restructure_date',
            'last_restruktur_date',
        ]);

        if (
            !$outstandingCol ||
            !$accountNoCol ||
            !$kolekCol ||
            !$ftPokokCol ||
            !$ftBungaCol ||
            !$positionDateCol
        ) {
            logger()->warning('DEKOM LOAN ACCOUNTS SOURCE INVALID', [
                'table' => $table,
                'outstandingCol' => $outstandingCol,
                'accountNoCol' => $accountNoCol,
                'kolekCol' => $kolekCol,
                'ftPokokCol' => $ftPokokCol,
                'ftBungaCol' => $ftBungaCol,
                'positionDateCol' => $positionDateCol,
            ]);

            return $defaults;
        }

        $latestPositionDate = DB::table($table)->max($positionDateCol);

        if (!$latestPositionDate) {
            logger()->warning('DEKOM LOAN ACCOUNTS NO POSITION DATE DATA', [
                'table' => $table,
                'positionDateCol' => $positionDateCol,
            ]);

            return $defaults;
        }

        $base = DB::table("{$table} as l")
            ->whereDate("l.{$positionDateCol}", $latestPositionDate)
            ->where(function ($q) use ($outstandingCol) {
                $q->whereNotNull("l.{$outstandingCol}")
                    ->where("l.{$outstandingCol}", '>', 0);
            });

        $countRows = (clone $base)->count();

        logger()->info('DEKOM LIVE SOURCE', [
            'source' => $table,
            'period' => $period->format('Y-m'),
            'position_date_col' => $positionDateCol,
            'latest_position_date' => $latestPositionDate,
            'count_rows' => $countRows,
        ]);

        if ($countRows <= 0) {
            return $defaults;
        }

        return $this->aggregatePortfolioFromQuery(
            clone $base,
            'l',
            $outstandingCol,
            $accountNoCol,
            $kolekCol,
            $ftPokokCol,
            $ftBungaCol,
            $positionDateCol,
            $restrBoolCol,
            $restrFreqCol,
            $restrDateCol,
            $dpdCol
        );
    }

    protected function aggregatePortfolioFromQuery(
        $base,
        string $alias,
        string $outstandingCol,
        string $accountNoCol,
        string $kolekCol,
        string $ftPokokCol,
        string $ftBungaCol,
        ?string $positionDateCol,
        ?string $restrBoolCol,
        ?string $restrFreqCol,
        ?string $restrDateCol,
        ?string $dpdCol
    ): array {
        $row = $base->selectRaw("
            COALESCE(SUM(COALESCE({$alias}.{$outstandingCol},0)),0) as total_os,
            COUNT(DISTINCT {$alias}.{$accountNoCol}) as total_noa,

            COALESCE(SUM(CASE
                WHEN {$alias}.{$kolekCol}=1 AND COALESCE({$alias}.{$ftPokokCol},0)=0 AND COALESCE({$alias}.{$ftBungaCol},0)=0
                THEN COALESCE({$alias}.{$outstandingCol},0) ELSE 0 END),0) as ft0_os,
            COUNT(DISTINCT CASE
                WHEN {$alias}.{$kolekCol}=1 AND COALESCE({$alias}.{$ftPokokCol},0)=0 AND COALESCE({$alias}.{$ftBungaCol},0)=0
                THEN {$alias}.{$accountNoCol} END) as ft0_noa,

            COALESCE(SUM(CASE
                WHEN {$alias}.{$kolekCol}=1 AND (COALESCE({$alias}.{$ftPokokCol},0)>0 OR COALESCE({$alias}.{$ftBungaCol},0)>0)
                THEN COALESCE({$alias}.{$outstandingCol},0) ELSE 0 END),0) as ft1_os,
            COUNT(DISTINCT CASE
                WHEN {$alias}.{$kolekCol}=1 AND (COALESCE({$alias}.{$ftPokokCol},0)>0 OR COALESCE({$alias}.{$ftBungaCol},0)>0)
                THEN {$alias}.{$accountNoCol} END) as ft1_noa,

            COALESCE(SUM(CASE
                WHEN {$alias}.{$kolekCol}=2 AND (COALESCE({$alias}.{$ftPokokCol},0)=2 OR COALESCE({$alias}.{$ftBungaCol},0)=2)
                THEN COALESCE({$alias}.{$outstandingCol},0) ELSE 0 END),0) as ft2_os,
            COUNT(DISTINCT CASE
                WHEN {$alias}.{$kolekCol}=2 AND (COALESCE({$alias}.{$ftPokokCol},0)=2 OR COALESCE({$alias}.{$ftBungaCol},0)=2)
                THEN {$alias}.{$accountNoCol} END) as ft2_noa,

            COALESCE(SUM(CASE
                WHEN {$alias}.{$kolekCol}=2 AND (COALESCE({$alias}.{$ftPokokCol},0)=3 OR COALESCE({$alias}.{$ftBungaCol},0)={$this->ft3BungaValue})
                THEN COALESCE({$alias}.{$outstandingCol},0) ELSE 0 END),0) as ft3_os,
            COUNT(DISTINCT CASE
                WHEN {$alias}.{$kolekCol}=2 AND (COALESCE({$alias}.{$ftPokokCol},0)=3 OR COALESCE({$alias}.{$ftBungaCol},0)={$this->ft3BungaValue})
                THEN {$alias}.{$accountNoCol} END) as ft3_noa,

            COALESCE(SUM(CASE WHEN {$alias}.{$kolekCol}=1 THEN COALESCE({$alias}.{$outstandingCol},0) ELSE 0 END),0) as l_os,
            COUNT(DISTINCT CASE WHEN {$alias}.{$kolekCol}=1 THEN {$alias}.{$accountNoCol} END) as l_noa,

            COALESCE(SUM(CASE WHEN {$alias}.{$kolekCol}=2 THEN COALESCE({$alias}.{$outstandingCol},0) ELSE 0 END),0) as dpk_os,
            COUNT(DISTINCT CASE WHEN {$alias}.{$kolekCol}=2 THEN {$alias}.{$accountNoCol} END) as dpk_noa,

            COALESCE(SUM(CASE WHEN {$alias}.{$kolekCol}=3 THEN COALESCE({$alias}.{$outstandingCol},0) ELSE 0 END),0) as kl_os,
            COUNT(DISTINCT CASE WHEN {$alias}.{$kolekCol}=3 THEN {$alias}.{$accountNoCol} END) as kl_noa,

            COALESCE(SUM(CASE WHEN {$alias}.{$kolekCol}=4 THEN COALESCE({$alias}.{$outstandingCol},0) ELSE 0 END),0) as d_os,
            COUNT(DISTINCT CASE WHEN {$alias}.{$kolekCol}=4 THEN {$alias}.{$accountNoCol} END) as d_noa,

            COALESCE(SUM(CASE WHEN {$alias}.{$kolekCol}=5 THEN COALESCE({$alias}.{$outstandingCol},0) ELSE 0 END),0) as m_os,
            COUNT(DISTINCT CASE WHEN {$alias}.{$kolekCol}=5 THEN {$alias}.{$accountNoCol} END) as m_noa,

            COALESCE(SUM(CASE WHEN {$alias}.{$kolekCol}>=3 THEN COALESCE({$alias}.{$outstandingCol},0) ELSE 0 END),0) as npl_os,
            COUNT(DISTINCT CASE WHEN {$alias}.{$kolekCol}>=3 THEN {$alias}.{$accountNoCol} END) as npl_noa
        ")->first();

        $totalOs = (float) ($row->total_os ?? 0);
        $nplOs   = (float) ($row->npl_os ?? 0);
        $nplPct  = $totalOs > 0 ? round(($nplOs / $totalOs) * 100, 4) : 0.0;

        $restr = $this->aggregateRestrukturisasiByBaseQuery(
            clone $base,
            $alias,
            $outstandingCol,
            $accountNoCol,
            $kolekCol,
            $restrBoolCol,
            $restrFreqCol,
            $restrDateCol
        );

        $dpd = $this->aggregateDpdWindowsByBaseQuery(
            clone $base,
            $alias,
            $outstandingCol,
            $accountNoCol,
            $ftPokokCol,
            $ftBungaCol,
            $positionDateCol,
            $dpdCol
        );

        return [
            'total_os'   => $totalOs,
            'total_noa'  => (int) ($row->total_noa ?? 0),

            'ft0_os'     => (float) ($row->ft0_os ?? 0),
            'ft0_noa'    => (int) ($row->ft0_noa ?? 0),
            'ft1_os'     => (float) ($row->ft1_os ?? 0),
            'ft1_noa'    => (int) ($row->ft1_noa ?? 0),
            'ft2_os'     => (float) ($row->ft2_os ?? 0),
            'ft2_noa'    => (int) ($row->ft2_noa ?? 0),
            'ft3_os'     => (float) ($row->ft3_os ?? 0),
            'ft3_noa'    => (int) ($row->ft3_noa ?? 0),

            'l_os'       => (float) ($row->l_os ?? 0),
            'l_noa'      => (int) ($row->l_noa ?? 0),
            'dpk_os'     => (float) ($row->dpk_os ?? 0),
            'dpk_noa'    => (int) ($row->dpk_noa ?? 0),
            'kl_os'      => (float) ($row->kl_os ?? 0),
            'kl_noa'     => (int) ($row->kl_noa ?? 0),
            'd_os'       => (float) ($row->d_os ?? 0),
            'd_noa'      => (int) ($row->d_noa ?? 0),
            'm_os'       => (float) ($row->m_os ?? 0),
            'm_noa'      => (int) ($row->m_noa ?? 0),

            'npl_os'     => $nplOs,
            'npl_noa'    => (int) ($row->npl_noa ?? 0),
            'npl_pct'    => $nplPct,

            'kkr_pct'    => $this->computeKkrPct([
                'total_os' => $totalOs,
                'restr_l_os' => (float) ($restr['restr_l_os'] ?? 0),
                'dpk_os'   => (float) ($row->dpk_os ?? 0),
                'kl_os'    => (float) ($row->kl_os ?? 0),
                'd_os'     => (float) ($row->d_os ?? 0),
                'm_os'     => (float) ($row->m_os ?? 0),
            ]),

            'restr_os'   => (float) ($restr['restr_os'] ?? 0),
            'restr_noa'  => (int) ($restr['restr_noa'] ?? 0),

            'dpd6_os'    => (float) ($dpd['dpd6_os'] ?? 0),
            'dpd6_noa'   => (int) ($dpd['dpd6_noa'] ?? 0),
            'dpd12_os'   => (float) ($dpd['dpd12_os'] ?? 0),
            'dpd12_noa'  => (int) ($dpd['dpd12_noa'] ?? 0),
        ];
    }

    protected function aggregateRestrukturisasiByBaseQuery(
        $base,
        string $alias,
        string $outstandingCol,
        string $accountNoCol,
        string $kolekCol,
        ?string $restrBoolCol,
        ?string $restrFreqCol,
        ?string $restrDateCol
    ): array {
        if (!$restrBoolCol && !$restrFreqCol && !$restrDateCol) {
            return [
                'restr_os' => 0,
                'restr_noa' => 0,
                'restr_l_os' => 0,
                'restr_l_noa' => 0,
            ];
        }

        $base->where(function ($q) use ($alias, $restrBoolCol, $restrFreqCol, $restrDateCol) {
            if ($restrBoolCol) {
                $q->orWhere("{$alias}.{$restrBoolCol}", 1)
                  ->orWhere("{$alias}.{$restrBoolCol}", true)
                  ->orWhere("{$alias}.{$restrBoolCol}", '1');
            }

            if ($restrFreqCol) {
                $q->orWhere("{$alias}.{$restrFreqCol}", '>', 0);
            }

            if ($restrDateCol) {
                $q->orWhereNotNull("{$alias}.{$restrDateCol}");
            }
        });

        $row = $base->selectRaw("
            COALESCE(SUM(COALESCE({$alias}.{$outstandingCol},0)),0) as restr_os,
            COUNT(DISTINCT {$alias}.{$accountNoCol}) as restr_noa,

            COALESCE(SUM(CASE
                WHEN {$alias}.{$kolekCol}=1 THEN COALESCE({$alias}.{$outstandingCol},0)
                ELSE 0 END),0) as restr_l_os,

            COUNT(DISTINCT CASE
                WHEN {$alias}.{$kolekCol}=1 THEN {$alias}.{$accountNoCol}
                END) as restr_l_noa
        ")->first();

        return [
            'restr_os'    => (float) ($row->restr_os ?? 0),
            'restr_noa'   => (int) ($row->restr_noa ?? 0),
            'restr_l_os'  => (float) ($row->restr_l_os ?? 0),
            'restr_l_noa' => (int) ($row->restr_l_noa ?? 0),
        ];
    }

    protected function aggregateDpdWindowsByBaseQuery(
        $base,
        string $alias,
        string $outstandingCol,
        string $accountNoCol,
        string $ftPokokCol,
        string $ftBungaCol,
        ?string $positionDateCol,
        ?string $dpdCol
    ): array {
        if (!$positionDateCol) {
            return [
                'dpd6_os' => 0,
                'dpd6_noa' => 0,
                'dpd12_os' => 0,
                'dpd12_noa' => 0,
            ];
        }

        $disbDateMap = DB::table('loan_disbursements')
            ->whereNotNull('account_no')
            ->whereNotNull('disb_date')
            ->selectRaw('account_no, MIN(disb_date) as disb_date')
            ->groupBy('account_no');

        $row = $base
            ->leftJoinSub($disbDateMap, 'dmap', function ($join) use ($alias, $accountNoCol) {
                $join->on("dmap.account_no", '=', "{$alias}.{$accountNoCol}");
            })
            ->selectRaw("
                COALESCE(SUM(CASE
                    WHEN GREATEST(COALESCE({$alias}.{$ftPokokCol},0), COALESCE({$alias}.{$ftBungaCol},0)) > 0
                     AND dmap.disb_date IS NOT NULL
                     AND TIMESTAMPDIFF(MONTH, dmap.disb_date, {$alias}.{$positionDateCol}) <= 6
                    THEN COALESCE({$alias}.{$outstandingCol},0)
                    ELSE 0
                END),0) as dpd6_os,

                COUNT(DISTINCT CASE
                    WHEN GREATEST(COALESCE({$alias}.{$ftPokokCol},0), COALESCE({$alias}.{$ftBungaCol},0)) > 0
                     AND dmap.disb_date IS NOT NULL
                     AND TIMESTAMPDIFF(MONTH, dmap.disb_date, {$alias}.{$positionDateCol}) <= 6
                    THEN {$alias}.{$accountNoCol}
                END) as dpd6_noa,

                COALESCE(SUM(CASE
                    WHEN GREATEST(COALESCE({$alias}.{$ftPokokCol},0), COALESCE({$alias}.{$ftBungaCol},0)) > 0
                     AND dmap.disb_date IS NOT NULL
                     AND TIMESTAMPDIFF(MONTH, dmap.disb_date, {$alias}.{$positionDateCol}) <= 12
                    THEN COALESCE({$alias}.{$outstandingCol},0)
                    ELSE 0
                END),0) as dpd12_os,

                COUNT(DISTINCT CASE
                    WHEN GREATEST(COALESCE({$alias}.{$ftPokokCol},0), COALESCE({$alias}.{$ftBungaCol},0)) > 0
                     AND dmap.disb_date IS NOT NULL
                     AND TIMESTAMPDIFF(MONTH, dmap.disb_date, {$alias}.{$positionDateCol}) <= 12
                    THEN {$alias}.{$accountNoCol}
                END) as dpd12_noa
            ")
            ->first();

        return [
            'dpd6_os'   => (float) ($row->dpd6_os ?? 0),
            'dpd6_noa'  => (int) ($row->dpd6_noa ?? 0),
            'dpd12_os'  => (float) ($row->dpd12_os ?? 0),
            'dpd12_noa' => (int) ($row->dpd12_noa ?? 0),
        ];
    }

    protected function aggregateRealisasi(Carbon $period, ?Carbon $asOfDate, string $mode): array
    {
        $defaults = [
            'mtd_real_os'  => 0,
            'mtd_real_noa' => 0,
            'ytd_real_os'  => 0,
            'ytd_real_noa' => 0,
        ];

        if (!$asOfDate || !Schema::hasTable('loan_disbursements')) {
            return $defaults;
        }

        $table = 'loan_disbursements';

        $dateCol = 'disb_date';
        $amtCol  = 'amount';
        $accCol  = 'account_no';
        $cifCol  = 'cif';

        $mtdStart = $period->copy()->startOfMonth()->toDateString();
        $ytdStart = $period->copy()->startOfYear()->toDateString();
        $endDate  = $asOfDate->toDateString();

        $distinctCol = $accCol ?: $cifCol;
        $distinctExpr = $distinctCol ? "COUNT(DISTINCT {$distinctCol})" : "COUNT(*)";

        $mtd = DB::table($table)
            ->whereBetween($dateCol, [$mtdStart, $endDate])
            ->selectRaw("
                COALESCE(SUM(COALESCE({$amtCol},0)),0) as os,
                {$distinctExpr} as noa
            ")
            ->first();

        $ytd = DB::table($table)
            ->whereBetween($dateCol, [$ytdStart, $endDate])
            ->selectRaw("
                COALESCE(SUM(COALESCE({$amtCol},0)),0) as os,
                {$distinctExpr} as noa
            ")
            ->first();

        return [
            'mtd_real_os'  => (float) ($mtd->os ?? 0),
            'mtd_real_noa' => (int) ($mtd->noa ?? 0),
            'ytd_real_os'  => (float) ($ytd->os ?? 0),
            'ytd_real_noa' => (int) ($ytd->noa ?? 0),
        ];
    }

    protected function getRbbTarget(Carbon $period): array
    {
        $target = DashboardDekomTarget::query()
            ->whereDate('period_month', $period->toDateString())
            ->first();

        return [
            'target_disbursement' => (float) ($target->target_disbursement ?? 0),
            'target_os'           => (float) ($target->target_os ?? 0),
            'target_npl_pct'      => (float) ($target->target_npl_pct ?? 0),
            'ach_os_pct'          => 0,
        ];
    }

    protected function computeGrowth(Carbon $period, float $currentTotalOs, string $mode): array
    {
        $prevMonth = DashboardDekomSnapshot::query()
            ->where('mode', $mode)
            ->whereDate('period_month', $period->copy()->subMonthNoOverflow()->startOfMonth()->toDateString())
            ->first();

        $prevYear = DashboardDekomSnapshot::query()
            ->where('mode', $mode)
            ->whereDate('period_month', $period->copy()->subYear()->startOfMonth()->toDateString())
            ->first();

        $momBase = (float) ($prevMonth->total_os ?? 0);
        $yoyBase = (float) ($prevYear->total_os ?? 0);

        return [
            'mom_os_growth_pct' => $momBase > 0
                ? round((($currentTotalOs - $momBase) / $momBase) * 100, 4)
                : 0,

            'yoy_os_growth_pct' => $yoyBase > 0
                ? round((($currentTotalOs - $yoyBase) / $yoyBase) * 100, 4)
                : 0,
        ];
    }

    protected function computeKkrPct(array $x): float
    {
        $totalOs = (float) ($x['total_os'] ?? 0);
        if ($totalOs <= 0) {
            return 0.0;
        }

        $numerator =
            (float) ($x['restr_l_os'] ?? 0) +
            (float) ($x['dpk_os'] ?? 0) +
            (float) ($x['kl_os'] ?? 0) +
            (float) ($x['d_os'] ?? 0) +
            (float) ($x['m_os'] ?? 0);

        return round(($numerator / $totalOs) * 100, 4);
    }

    protected function firstExistingColumn(string $table, array $candidates): ?string
    {
        if (!Schema::hasTable($table)) {
            return null;
        }

        foreach ($candidates as $col) {
            if (Schema::hasColumn($table, $col)) {
                return $col;
            }
        }

        return null;
    }

    protected function applySnapshotMonthFilter($query, Carbon $period, string $column = 'snapshot_month')
    {
        return $query
            ->whereYear($column, $period->year)
            ->whereMonth($column, $period->month);
    }

    protected function shouldUseLiveLoanAccounts(string $mode): bool
    {
        return $mode === 'realtime' || $mode === 'hybrid';
    }

    protected function emptySnapshotResult(): array
    {
        return [
            'total_os'   => 0,
            'total_noa'  => 0,

            'ft0_os'     => 0,
            'ft0_noa'    => 0,
            'ft1_os'     => 0,
            'ft1_noa'    => 0,
            'ft2_os'     => 0,
            'ft2_noa'    => 0,
            'ft3_os'     => 0,
            'ft3_noa'    => 0,

            'l_os'       => 0,
            'l_noa'      => 0,
            'dpk_os'     => 0,
            'dpk_noa'    => 0,
            'kl_os'      => 0,
            'kl_noa'     => 0,
            'd_os'       => 0,
            'd_noa'      => 0,
            'm_os'       => 0,
            'm_noa'      => 0,

            'npl_os'     => 0,
            'npl_noa'    => 0,
            'npl_pct'    => 0,
            'kkr_pct'    => 0,

            'restr_os'   => 0,
            'restr_noa'  => 0,

            'dpd6_os'    => 0,
            'dpd6_noa'   => 0,
            'dpd12_os'   => 0,
            'dpd12_noa'  => 0,
        ];
    }
}