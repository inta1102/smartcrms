<?php

namespace App\Http\Controllers;

use App\Models\LoanAccount;
use App\Services\Ews\EwsMacetService;
use App\Services\Ews\EwsSummaryService;
use App\Services\Org\OrgVisibilityService;
use Illuminate\Http\Request;
use App\Models\User;

class EwsSummaryController extends Controller
{
    public function __construct(
        protected EwsSummaryService $service,
        protected EwsMacetService $macetSvc,
        protected OrgVisibilityService $orgVis,
    ) {}

    public function index(Request $request)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        abort_unless($user->hasAnyRole(['DIR','KOM','PE','KBO','KSA','SAD','KSL','KSR','KSO','TL','TLL','TLR','TLRO','TLSO','TLFE','TLBE','TLUM','AO','RO','SO','BE','FE','SA']), 403);


        $data = $this->service->build($request, $user);

        $latestDate = LoanAccount::max('position_date');
        $filter = [
            'position_date' => $latestDate,
            'branch_code'   => null,
            'ao_code'       => null,
        ];

        $vis = (string) $request->query('vis', 'subset');
        $visibleAoCodes = $this->orgVis->visibleAoCodes($user, $vis);

        $macetMeta = $this->macetSvc->summary($filter, $visibleAoCodes);

        $scope = $this->scopeLabelForEws($user);

        // ✅ harden: pastikan keys yang dipakai view selalu ada
        $data['dpdDist']   = $data['dpdDist']   ?? [];
        $data['kolekDist'] = $data['kolekDist'] ?? [];
        $data['topAo']     = $data['topAo']     ?? [];

        return view('ews.summary', array_merge($data, [
            'macetMeta'      => $macetMeta,
            'vis'            => $vis,
            'visibleAoCodes' => $visibleAoCodes,
            'scope'          => $scope,
        ]));
    }

    /**
     * Label scope untuk badge UI (biar gampang dipahami user).
     * Silakan sesuaikan role-role kamu.
     */
    protected function scopeLabelForEws($user): string
    {
        // contoh aturan umum:
        // - AO/RO/SO/FE/BE: Personal (AO code)
        // - TL: Unit (bawahan)
        // - KSA/KSL/KSO/KSR: Seksi/Divisi
        // - KABAG/DIR/KOM: All / Cabang
        if ($user->hasAnyRole(['AO','RO','SO','FE','BE'])) {
            $ao = (string)($user->ao_code ?? '');
            return $ao !== '' ? "Personal ({$ao})" : "Personal";
        }

        if ($user->hasAnyRole(['TL','TLL','TLR','TLF','TLRO','TLSO','TLFE','TLBE','TLUM'])) {
            return "Unit (Bawahan)";
        }

        if ($user->hasAnyRole(['KSA','KSL','KSO','KSR','KSF','KSD'])) {
            return "Seksi";
        }

        if ($user->hasAnyRole(['KABAG','KBL','KBO','KBF','KTI','DIR','DIREKSI','KOM','PE'])) {
            return "All";
        }

        return "Unknown";
    }
}
