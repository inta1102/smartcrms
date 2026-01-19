<?php

namespace App\Http\Controllers;

use App\Models\LoanAccount;
use App\Services\Ews\EwsMacetService;
use App\Services\Ews\EwsSummaryService;
use App\Services\Org\OrgVisibilityService;
use Illuminate\Http\Request;

class EwsSummaryController extends Controller
{
    public function __construct(
        protected EwsSummaryService $service,
        protected EwsMacetService $macetSvc,
        protected OrgVisibilityService $orgVis,
    ) {}

    public function index(Request $request)
    {
        $data = $this->service->build($request, auth()->user());

        $latestDate = LoanAccount::max('position_date');
        $filter = [
            'position_date' => $latestDate,
            'branch_code'   => null,
            'ao_code'       => null,
        ];

        $vis = (string) $request->query('vis', 'subset');
        $visibleAoCodes = $this->orgVis->visibleAoCodes(auth()->user(), $vis);

        $macetMeta = $this->macetSvc->summary($filter, $visibleAoCodes);

        return view('ews.summary', array_merge($data, [
            'macetMeta' => $macetMeta,
            'vis' => $vis,
            'visibleAoCodes' => $visibleAoCodes,
        ]));
    }
}
