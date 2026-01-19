<?php

namespace App\Http\Controllers\Ews;

use App\Http\Controllers\Controller;
use App\Models\LoanAccount;
use App\Services\Ews\EwsMacetService;
use App\Services\Org\OrgVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\EwsDetailExport;

class EwsMacetController extends Controller
{
    public function __construct(
        protected EwsMacetService $svc,
        protected OrgVisibilityService $orgVis,
    ) {}

    /**
     * Ambil visible AO codes via OrgVisibilityService (SAMAKAN dengan SummaryController).
     * - return null => ALL
     * - return array => SUBSET / PERSONAL
     */
    protected function visibleAoCodes(string $vis): ?array
    {
        $user = auth()->user();
        if (!$user) return null;

        $res = $this->orgVis->visibleAoCodes($user, $vis);

        if ($res instanceof Collection) {
            $res = $res->values()->all();
        }

        if (is_string($res) && $res !== '') {
            $res = [$res];
        }

        return is_array($res) ? $res : null;
    }

    /**
     * Apply visibility filter ke query:
     * cover ao_code / collector_code.
     */
    protected function applyVisibleAoFilter($q, ?array $visibleAoCodes)
    {
        if ($visibleAoCodes === null) {
            return $q; // ALL
        }

        if (count($visibleAoCodes) === 0) {
            return $q->whereRaw('1=0'); // tidak boleh lihat apa pun
        }

        return $q->where(function ($w) use ($visibleAoCodes) {
            $w->whereIn('ao_code', $visibleAoCodes)
              ->orWhereIn('collector_code', $visibleAoCodes);
        });
    }

    public function index(Request $request)
    {
        // -------------------------
        // PARAM
        // -------------------------
        $vis = (string) $request->query('vis', 'subset'); // samakan dengan summary: subset/all/personal (sesuai implementasi kamu)
        $scope = strtolower(trim((string) $request->get('scope', 'ag6'))); // ag6/ag9/all
        if (!in_array($scope, ['ag6', 'ag9', 'all'], true)) {
            $scope = 'ag6';
        }

        $agunanParam = strtoupper(trim((string) $request->get('agunan', 'ALL'))); // ALL|6|9

        $latestDate = LoanAccount::max('position_date');
        $positionDate = $request->get('position_date') ?: ($latestDate ?: now()->toDateString());

        $filter = [
            'position_date' => $positionDate,
        ];

        // -------------------------
        // VISIBILITY
        // -------------------------
        $visibleAoCodes = $this->visibleAoCodes($vis);

        // -------------------------
        // EFFECTIVE FILTER (agunan)
        // -------------------------
        // null = ALL (gabungan rule agunan 6 & 9)
        $effectiveAgunan = null;
        if (in_array($agunanParam, ['6', '9'], true)) {
            $effectiveAgunan = (int) $agunanParam;
        } else {
            if ($scope === 'ag6') $effectiveAgunan = 6;
            if ($scope === 'ag9') $effectiveAgunan = 9;
            if ($scope === 'all') $effectiveAgunan = null;
        }

        // helper expr usia (bulan) terhadap positionDate
        $ageExpr = "TIMESTAMPDIFF(MONTH, DATE(tgl_kolek), DATE(COALESCE(?, CURDATE())))";

        // =========================================================
        // META (KPI CARDS)
        // - total_all: KOLEK 5 SEMUA USIA
        // - ag6/ag9: WARNING WINDOW
        // =========================================================
        $baseAll = LoanAccount::query()
            ->where('kolek', 5)
            ->whereNotNull('tgl_kolek')
            ->when($positionDate, fn($q) => $q->whereDate('position_date', $positionDate));

        $this->applyVisibleAoFilter($baseAll, $visibleAoCodes);

        $totalAllRek = (int) (clone $baseAll)->count();
        $totalAllOs  = (int) (clone $baseAll)->sum('outstanding');

        $ag6 = (clone $baseAll)
            ->where('jenis_agunan', 6)
            ->whereRaw("{$ageExpr} BETWEEN 20 AND 24", [$positionDate]);

        $ag6Rek = (int) (clone $ag6)->count();
        $ag6Os  = (int) (clone $ag6)->sum('outstanding');

        $ag9 = (clone $baseAll)
            ->where('jenis_agunan', 9)
            ->whereRaw("{$ageExpr} BETWEEN 9 AND 12", [$positionDate]);

        $ag9Rek = (int) (clone $ag9)->count();
        $ag9Os  = (int) (clone $ag9)->sum('outstanding');

        $meta = [
            // ✅ total semua usia (kolek 5)
            'total_all_rek' => $totalAllRek,
            'total_all_os'  => $totalAllOs,

            // ✅ total warning gabungan (ag6+ag9)
            'total_warn_rek' => $ag6Rek + $ag9Rek,
            'total_warn_os'  => $ag6Os + $ag9Os,

            'ag6' => ['rek' => $ag6Rek, 'os' => $ag6Os],
            'ag9' => ['rek' => $ag9Rek, 'os' => $ag9Os],
        ];

        // =========================================================
        // ROWS (DETAIL TABLE) -> sesuai tombol filter
        // =========================================================
        $rowsQ = LoanAccount::query()
            ->select([
                'account_no',
                'customer_name',
                'ao_code',
                'collector_code',
                'jenis_agunan',
                'keterangan_sandi',
                'tgl_kolek',
                'outstanding',
                'cadangan_ppap',
                'nilai_agunan_yg_diperhitungkan',
                'position_date',
            ])
            ->selectRaw("
                CASE
                    WHEN tgl_kolek IS NULL THEN NULL
                    ELSE {$ageExpr}
                END AS usia_macet_bulan
            ", [$positionDate])

            ->where('kolek', 5)
            ->when($positionDate, fn($q) => $q->whereDate('position_date', $positionDate))
            ->whereNotNull('tgl_kolek');

        $this->applyVisibleAoFilter($rowsQ, $visibleAoCodes);

        // ✅ filter window sesuai definisi
        $rowsQ->where(function ($w) use ($effectiveAgunan, $ageExpr, $positionDate) {
            if ($effectiveAgunan === 6) {
                $w->where('jenis_agunan', 6)
                  ->whereRaw("{$ageExpr} BETWEEN 20 AND 24", [$positionDate]);
                return;
            }

            if ($effectiveAgunan === 9) {
                $w->where('jenis_agunan', 9)
                  ->whereRaw("{$ageExpr} BETWEEN 9 AND 12", [$positionDate]);
                return;
            }

            // ALL: gabungan
            $w->where(function ($x) use ($ageExpr, $positionDate) {
                    $x->where('jenis_agunan', 6)
                      ->whereRaw("{$ageExpr} BETWEEN 20 AND 24", [$positionDate]);
                })
              ->orWhere(function ($x) use ($ageExpr, $positionDate) {
                    $x->where('jenis_agunan', 9)
                      ->whereRaw("{$ageExpr} BETWEEN 9 AND 12", [$positionDate]);
                });
        });

        $rowsQ->orderByRaw('CASE WHEN usia_macet_bulan IS NULL THEN 1 ELSE 0 END ASC')
              ->orderBy('usia_macet_bulan', 'desc')
              ->orderBy('outstanding', 'desc');

        $rows = $rowsQ->limit(300)->get();

        return view('ews.macet.index', [
            'rows'           => $rows,
            'scope'          => $scope,
            'vis'            => $vis,
            'filter'         => $filter,
            'meta'           => $meta,
            'visibleAoCodes' => $visibleAoCodes,
        ]);
    }

    public function exportDetail(Request $request)
    {
        $scope   = $request->get('scope', 'ag6');
        $agunan  = $request->get('agunan', 'ALL'); // ALL|6|9
        $limit   = (int) $request->get('limit', 300);
        $posDate = $request->get('position_date');

        $stamp = now()->format('Ymd_His');
        $file  = "DETAIL_{$scope}_AGUNAN-{$agunan}_{$stamp}.xlsx";

        return Excel::download(new EwsDetailExport(
            scope: $scope,
            agunan: $agunan,
            limit: $limit,
            positionDate: $posDate
        ), $file);
    }
}
