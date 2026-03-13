<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DekomCreditConditionBuilderService
{
    public function build(string|Carbon $periodYm): array
    {
        $period = $periodYm instanceof Carbon
            ? $periodYm->copy()->startOfMonth()
            : Carbon::parse($periodYm)->startOfMonth();

        $year = (int) $period->year;

        $rows = $this->makeEmptyRows();
        $baseRows = $this->getBaseRows($period);

        /**
         * Realisasi tahun berjalan:
         * - daftar account diambil dari loan_disbursements (filter disb_date tahun berjalan s/d cutoff period)
         * - nominal yang dijumlahkan tetap dari source posisi (snapshot/live)
         * - bucket kualitas mengikuti kolek pada source posisi
         */
        $realisasiAccounts = $this->getRealisasiAccountMap($year, $period);
        $disbDateMap = $this->getDisbursementDateMap();

        foreach ($baseRows as $row) {
            $bucket = $this->mapKolBucket($row);

            if (!isset($rows[$bucket])) {
                continue;
            }

           

            $accountNo = trim((string) ($row->account_no ?? ''));
            $os = (float) ($row->outstanding ?? 0);

            // =========================
            // TOTAL
            // =========================
            $rows[$bucket]['total_os'] += $os;
            $rows[$bucket]['total_noa']++;

            // =========================
            // RESTRUKTURISASI
            // =========================
            if ($this->isRestructured($row)) {
                $rows[$bucket]['restruktur_os'] += $os;
                $rows[$bucket]['restruktur_noa']++;
            }

            // =========================
            // REALISASI TAHUN BERJALAN
            // - akun berasal dari loan_disbursements
            // - OS berasal dari source posisi (snapshot/live)
            // =========================
            if ($accountNo !== '' && $realisasiAccounts->contains($accountNo)) {
                $rows[$bucket]['realisasi_os'] += $os;
                $rows[$bucket]['realisasi_noa']++;
            }

           

            $isDpd = $this->isDpd($row);
            $ageMonths = $this->creditAgeMonths($row, $disbDateMap);

            // =========================
            // DPD > 6 BULAN
            // definisi sementara:
            // last_payment_date <= cutoff 6 bulan
            // =========================
            if ($isDpd && $ageMonths !== null && $ageMonths <= 6) {
                $rows[$bucket]['dpd6_os'] += $os;
                $rows[$bucket]['dpd6_noa']++;
            }


            // =========================
            // DPD > 12 BULAN
            // =========================
            if ($isDpd && $ageMonths !== null && $ageMonths <= 12) {
                $rows[$bucket]['dpd12_os'] += $os;
                $rows[$bucket]['dpd12_noa']++;
            }
        }

        $totals = $this->sumRows($rows);

        // =========================
        // NPL = KL + D + M
        // =========================
        $nplAmount = (float) (
            ($rows['KL']['total_os'] ?? 0)
            + ($rows['D']['total_os'] ?? 0)
            + ($rows['M']['total_os'] ?? 0)
        );

        $nplNoa = (int) (
            ($rows['KL']['total_noa'] ?? 0)
            + ($rows['D']['total_noa'] ?? 0)
            + ($rows['M']['total_noa'] ?? 0)
        );

        $nplRestrukturOs = (float) (
            ($rows['KL']['restruktur_os'] ?? 0)
            + ($rows['D']['restruktur_os'] ?? 0)
            + ($rows['M']['restruktur_os'] ?? 0)
        );

        $nplRestrukturNoa = (int) (
            ($rows['KL']['restruktur_noa'] ?? 0)
            + ($rows['D']['restruktur_noa'] ?? 0)
            + ($rows['M']['restruktur_noa'] ?? 0)
        );

        $nplPercent = (float) ($totals['total_os'] ?? 0) > 0
            ? ($nplAmount / (float) $totals['total_os']) * 100
            : 0;

        // =========================
        // KKR
        // definisi:
        // (kolek 1 restruktur + kolek 2 + kolek 3 + kolek 4 + kolek 5) / total OS
        // =========================
        $kkrBaseOs = (float) ($totals['total_os'] ?? 0);

        $kol1RestrukturOs = (float) ($rows['L']['restruktur_os'] ?? 0);
        $kol2Os = (float) ($rows['DPK']['total_os'] ?? 0);
        $kol3Os = (float) ($rows['KL']['total_os'] ?? 0);
        $kol4Os = (float) ($rows['D']['total_os'] ?? 0);
        $kol5Os = (float) ($rows['M']['total_os'] ?? 0);

        $kkrAmount = $kol1RestrukturOs + $kol2Os + $kol3Os + $kol4Os + $kol5Os;

        $kkrPercent = $kkrBaseOs > 0
            ? ($kkrAmount / $kkrBaseOs) * 100
            : 0;

        return [
            'rows' => $rows,
            'totals' => $totals,
            'npl' => [
                'amount' => $nplAmount,
                'noa' => $nplNoa,
                'restruktur_os' => $nplRestrukturOs,
                'restruktur_noa' => $nplRestrukturNoa,
                'percent' => $nplPercent,
            ],
            'kkr' => [
                'percent' => $kkrPercent,
                'amount' => $kkrAmount,
                'kol1_restruktur_os' => $kol1RestrukturOs,
                'kol2_os' => $kol2Os,
                'kol3_os' => $kol3Os,
                'kol4_os' => $kol4Os,
                'kol5_os' => $kol5Os,
            ],
        ];
    }

    protected function makeEmptyRows(): array
    {
        return [
            'L'   => $this->makeEmptyRow(),
            'DPK' => $this->makeEmptyRow(),
            'KL'  => $this->makeEmptyRow(),
            'D'   => $this->makeEmptyRow(),
            'M'   => $this->makeEmptyRow(),
        ];
    }

    protected function makeEmptyRow(): array
    {
        return [
            'total_os' => 0,
            'total_noa' => 0,
            'restruktur_os' => 0,
            'restruktur_noa' => 0,
            'realisasi_os' => 0,
            'realisasi_noa' => 0,
            'dpd6_os' => 0,
            'dpd6_noa' => 0,
            'dpd12_os' => 0,
            'dpd12_noa' => 0,
        ];
    }

    protected function sumRows(array $rows): array
    {
        $total = $this->makeEmptyRow();

        foreach ($rows as $row) {
            foreach (array_keys($total) as $key) {
                $total[$key] += $row[$key] ?? 0;
            }
        }

        return $total;
    }

    protected function getBaseRows(Carbon $period): Collection
    {
        $isCurrentMonth = $this->isCurrentMonth($period);

        // =========================
        // CURRENT MONTH => loan_accounts live
        // =========================
        if ($isCurrentMonth && Schema::hasTable('loan_accounts')) {
            $latestPositionDate = DB::table('loan_accounts')->max('position_date');

            if (!$latestPositionDate) {
                return collect();
            }

            $cols = [
                'account_no',
                'cif',
                'outstanding',
                'kolek',
                'is_restructured',
                'restructure_freq',
                'last_restructure_date',
                'last_payment_date',
                'dpd',
                'position_date',
                'ft_pokok',
                'ft_bunga',
            ];

            foreach (['booking_date', 'disb_date', 'realization_date'] as $col) {
                if (Schema::hasColumn('loan_accounts', $col)) {
                    $cols[] = $col;
                }
            }

            return DB::table('loan_accounts')
                ->whereDate('position_date', $latestPositionDate)
                ->select($cols)
                ->get();
        }

        // =========================
        // HISTORICAL => snapshot monthly
        // =========================
        if (Schema::hasTable('loan_account_snapshots_monthly')) {
            $snapshotCols = [
                'account_no',
                'cif',
                'outstanding',
                'kolek',
                'dpd',
                'ft_pokok',
                'ft_bunga',
                'source_position_date',
            ];

            foreach ([
                'is_restructured',
                'restructure_freq',
                'last_restructure_date',
                'last_payment_date',
                'position_date',
                'booking_date',
                'disb_date',
                'realization_date',
            ] as $col) {
                if (Schema::hasColumn('loan_account_snapshots_monthly', $col)) {
                    $snapshotCols[] = $col;
                }
            }

            return DB::table('loan_account_snapshots_monthly')
                ->whereYear('snapshot_month', $period->year)
                ->whereMonth('snapshot_month', $period->month)
                ->select($snapshotCols)
                ->get();
        }

        return collect();
    }

    protected function getRealisasiAccountMap(int $year, Carbon $period): Collection
    {
        if (!Schema::hasTable('loan_disbursements')) {
            return collect();
        }

        $from = Carbon::create($year, 1, 1)->startOfDay();

        if ($this->isCurrentMonth($period) && Schema::hasTable('loan_accounts')) {
            $latestPositionDate = DB::table('loan_accounts')->max('position_date');

            $to = $latestPositionDate
                ? Carbon::parse($latestPositionDate)->endOfDay()
                : $period->copy()->endOfMonth()->endOfDay();
        } else {
            $to = $period->copy()->endOfMonth()->endOfDay();
        }

        return DB::table('loan_disbursements')
            ->whereDate('disb_date', '>=', $from->toDateString())
            ->whereDate('disb_date', '<=', $to->toDateString())
            ->whereNotNull('account_no')
            ->select('account_no')
            ->distinct()
            ->get()
            ->pluck('account_no')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values();
    }

    protected function mapKolBucket(object $row): string
    {
        $kolek = (int) ($row->kolek ?? 0);

        return match ($kolek) {
            1 => 'L',
            2 => 'DPK',
            3 => 'KL',
            4 => 'D',
            5 => 'M',
            default => 'L',
        };
    }

    protected function isRestructured(object $row): bool
    {
        return (int) ($row->is_restructured ?? 0) === 1
            || !empty($row->last_restructure_date ?? null);
    }

    protected function isCurrentMonth(Carbon $period): bool
    {
        return $period->copy()->startOfMonth()->equalTo(now()->copy()->startOfMonth());
    }

    protected function isDpd(object $row): bool
    {
        $ftPokok = (float) ($row->ft_pokok ?? 0);
        $ftBunga = (float) ($row->ft_bunga ?? 0);

        return max($ftPokok, $ftBunga) > 0;
    }

    protected function creditAgeMonths(object $row, Collection $disbDateMap): ?int
    {
        $accountNo = trim((string) ($row->account_no ?? ''));

        if ($accountNo === '' || !$disbDateMap->has($accountNo)) {
            return null;
        }

        $positionDate = $this->getRowPositionDate($row);
        if (!$positionDate) {
            return null;
        }

        try {
            $disbDate = Carbon::parse($disbDateMap->get($accountNo)->disb_date)->startOfDay();
            return $disbDate->diffInMonths($positionDate);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getDisbursementDateMap(): Collection
    {
        if (!Schema::hasTable('loan_disbursements')) {
            return collect();
        }

        return DB::table('loan_disbursements')
            ->whereNotNull('account_no')
            ->whereNotNull('disb_date')
            ->selectRaw('account_no, MIN(disb_date) as disb_date')
            ->groupBy('account_no')
            ->get()
            ->keyBy(fn ($r) => trim((string) $r->account_no));
    }

    protected function getRowPositionDate(object $row): ?Carbon
    {
        foreach (['source_position_date', 'position_date'] as $field) {
            if (!empty($row->{$field} ?? null)) {
                try {
                    return Carbon::parse($row->{$field})->startOfDay();
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        return null;
    }
}