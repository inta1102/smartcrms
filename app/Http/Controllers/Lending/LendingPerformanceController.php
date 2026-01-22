<?php

namespace App\Http\Controllers\Lending;

use App\Http\Controllers\Controller;
use App\Services\Lending\LendingPerformanceService;
use Illuminate\Http\Request;

class LendingPerformanceController extends Controller
{
    public function __construct(
        protected LendingPerformanceService $svc
    ) {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        // default tanggal snapshot: latest
        $positionDate = $request->input('position_date');
        if (!$positionDate) {
            $positionDate = $this->svc->latestPositionDate();
        }

        $filter = [
            'position_date' => $positionDate,
            'branch_code'   => $request->input('branch_code'),
            'ao_code'       => $request->input('ao_code'),
        ];

        // visibility AO sesuai role (null=all, []=none, [ao_code...]=subset)
        $visibleAoCodes = $this->svc->visibleAoCodesForUser(auth()->user());

        // kalau visibility subset kosong → tampilkan kosong (aman)
        if (is_array($visibleAoCodes) && empty($visibleAoCodes)) {
            return view('lending.performance.index', [
                'filter'         => $filter,
                'summary'        => $this->svc->emptySummary(),
                'rows'           => collect(),
                'latestDate'     => $positionDate,
                'branches'       => $this->svc->branchOptions($positionDate),
                'aoOptions'      => $this->svc->aoOptions($positionDate, $filter['branch_code'], $visibleAoCodes),
            ]);
        }

        $summary = $this->svc->summary($filter, $visibleAoCodes);
        $rows    = $this->svc->rankingAo($filter, $visibleAoCodes);

        return view('lending.performance.index', [
            'filter'     => $filter,
            'summary'    => $summary,
            'rows'       => $rows,
            'latestDate' => $positionDate,
            'branches'   => $this->svc->branchOptions($positionDate),
            'aoOptions'  => $this->svc->aoOptions($positionDate, $filter['branch_code'], $visibleAoCodes),
        ]);
    }

    public function showAo(Request $request, string $ao_code)
    {
        $positionDate = $request->input('position_date') ?: $this->svc->latestPositionDate();

        $filter = [
            'position_date' => $positionDate,
            'branch_code'   => $request->input('branch_code'),
            'ao_code'       => $ao_code,
        ];

        $visibleAoCodes = $this->svc->visibleAoCodesForUser(auth()->user());
        if (is_array($visibleAoCodes) && !in_array($ao_code, $visibleAoCodes, true)) {
            abort(403);
        }

        $data = $this->svc->rootCauseAo($filter, $visibleAoCodes);

        return view('lending.performance.ao', [
            'filter' => $filter,
            'aoCode' => $ao_code,
            'data'   => $data,
        ]);
    }

}
