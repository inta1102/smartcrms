<?php

namespace App\Services\Ews;

use App\Models\LoanAccount;
use Illuminate\Support\Facades\DB;

class EwsMacetService
{
    /**
     * Base query untuk snapshot posisi tertentu + kolek macet.
     * @param  array{position_date:string,branch_code?:string|null,ao_code?:string|null}  $filter
     * @param  array<string>|null  $visibleAoCodes  null=ALL, []=NONE, ['000047',..]=subset
     */
    public function baseQuery(array $filter, ?array $visibleAoCodes)
    {
        $positionDate = $filter['position_date'] ?? null;

        $q = LoanAccount::query()
            ->where('kolek', 5)
            ->whereNotNull('tgl_kolek');

        if ($positionDate) {
            $q->whereDate('position_date', $positionDate);
        }

        // optional: branch_code kalau memang ada kolomnya
        if (!empty($filter['branch_code']) && \Schema::hasColumn('loan_accounts', 'branch_code')) {
            $q->where('branch_code', $filter['branch_code']);
        }

        // optional: ao_code manual filter
        if (!empty($filter['ao_code'])) {
            $code = trim((string) $filter['ao_code']);
            $q->where(function ($qq) use ($code) {
                $qq->where('ao_code', $code)->orWhere('collector_code', $code);
            });
        }

        // visibility AO
        if (is_array($visibleAoCodes)) {
            if (count($visibleAoCodes) === 0) {
                // NONE -> kosongkan hasil
                $q->whereRaw('1=0');
            } else {
                $codes = array_values(array_filter(array_map('trim', $visibleAoCodes)));
                $q->where(function ($qq) use ($codes) {
                    $qq->whereIn('ao_code', $codes)
                       ->orWhereIn('collector_code', $codes);
                });
            }
        }
        // kalau null => ALL, tidak difilter

        return $q;
    }

    /**
     * Meta warning (dipakai sidebar + card summary).
     */
    public function warnMeta(array $filter, ?array $visibleAoCodes): array
    {
        $q = $this->baseQuery($filter, $visibleAoCodes)
            ->whereIn('jenis_agunan', [6, 9]);

        $ageExpr = DB::raw('TIMESTAMPDIFF(MONTH, tgl_kolek, CURDATE())');

        $totalRek = (clone $q)->count();
        $totalOs  = (clone $q)->sum('outstanding');

        $ag6 = (clone $q)->where('jenis_agunan', 6)
            ->whereBetween($ageExpr, [20, 24]);

        $ag9 = (clone $q)->where('jenis_agunan', 9)
            ->whereBetween($ageExpr, [9, 12]);

        $warn6Rek = (clone $ag6)->count();
        $warn6Os  = (clone $ag6)->sum('outstanding');

        $warn9Rek = (clone $ag9)->count();
        $warn9Os  = (clone $ag9)->sum('outstanding');

        $warnRek = $warn6Rek + $warn9Rek;
        $warnOs  = $warn6Os + $warn9Os;

        $ratio = 0.0;
        if ((float)$totalOs > 0) {
            $ratio = ((float)$warnOs / (float)$totalOs) * 100.0;
        }

        return [
            'total_rek' => (int) $totalRek,
            'total_os'  => (float) $totalOs,

            'warn_rek'  => (int) $warnRek,
            'warn_os'   => (float) $warnOs,
            'ratio'     => (float) $ratio,

            'ag6' => [
                'rek' => (int) $warn6Rek,
                'os'  => (float) $warn6Os,
                'min' => 20,
                'max' => 24,
            ],
            'ag9' => [
                'rek' => (int) $warn9Rek,
                'os'  => (float) $warn9Os,
                'min' => 9,
                'max' => 12,
            ],
        ];
    }

    /**
     * List detail untuk halaman /ews/macet.
     * @param  'ag6'|'ag9'|'all'  $scope
     */
    public function list(array $filter, ?array $visibleAoCodes, string $scope = 'all', int $limit = 300)
    {
        $q = $this->baseQuery($filter, $visibleAoCodes);

        $ageExpr = DB::raw('TIMESTAMPDIFF(MONTH, tgl_kolek, CURDATE())');

        if ($scope === 'ag6') {
            $q->where('jenis_agunan', 6)->whereBetween($ageExpr, [20, 24]);
        } elseif ($scope === 'ag9') {
            $q->where('jenis_agunan', 9)->whereBetween($ageExpr, [9, 12]);
        } else {
            $q->whereIn('jenis_agunan', [6, 9]);
        }

        return $q->select([
                'id', 'account_no', 'customer_name', 'ao_code', 'collector_code',
                'outstanding',
                'jenis_agunan', 'tgl_kolek', 'keterangan_sandi', 'cadangan_ppap', 'nilai_agunan_yg_diperhitungkan',
                'position_date',
            ])
            ->selectRaw('TIMESTAMPDIFF(MONTH, tgl_kolek, CURDATE()) as usia_macet_bulan')
            ->orderByDesc('usia_macet_bulan')
            ->orderByDesc('outstanding')
            ->limit($limit)
            ->get();
    }

        /**
     * Summary untuk dipakai EWS Summary.
     * Ini wrapper dari warnMeta() supaya EwsSummaryController bisa tetap panggil summary().
     *
     * Return format:
     * - position_date
     * - total: rek, os
     * - ag6: rek, os
     * - ag9: rek, os
     * - warn_count
     */
    public function summary(array $filter, ?array $visibleAoCodes = null): array
    {
        $meta = $this->warnMeta($filter, $visibleAoCodes);

        return [
            'position_date' => $filter['position_date'] ?? LoanAccount::max('position_date'),

            'total' => [
                'rek' => (int) ($meta['total_rek'] ?? 0),
                'os'  => (float) ($meta['total_os'] ?? 0),
            ],

            'ag6' => [
                'rek' => (int) ($meta['ag6']['rek'] ?? 0),
                'os'  => (float) ($meta['ag6']['os'] ?? 0),
            ],

            'ag9' => [
                'rek' => (int) ($meta['ag9']['rek'] ?? 0),
                'os'  => (float) ($meta['ag9']['os'] ?? 0),
            ],

            'warn_count' => (int) ($meta['warn_rek'] ?? 0),
        ];
    }

    /**
     * Build payload view.
     */
    public function build(array $filter, ?array $visibleAoCodes, string $scope = 'all'): array
    {
        return [
            'filter'         => $filter,
            'scope'          => $scope, // ✅ ini yang hilang
            'visibleAoCodes' => $visibleAoCodes, // ✅ biar header scope ALL/SUBSET/PERSONAL aman
            'meta'           => $this->warnMeta($filter, $visibleAoCodes),
            'rows'           => $this->list($filter, $visibleAoCodes, $scope, 300),
        ];
    }

}
