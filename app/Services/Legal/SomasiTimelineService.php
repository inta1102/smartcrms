<?php

namespace App\Services\Legal;

use App\Models\CaseAction;
use App\Models\LegalAction;
use Carbon\Carbon;

class SomasiTimelineService
{
    public function upsert(
        LegalAction $action,
        string $milestone,
        string $result,
        string $description,
        ?string $proofUrl = null,
        ?Carbon $at = null,
    ): void {
        $at ??= now();

        $nplCaseId = $action->legalCase?->npl_case_id;
        if (!$nplCaseId) return;

        CaseAction::updateOrCreate(
            [
                'npl_case_id'   => $nplCaseId,
                'source_system' => 'legal_somasi',
                'source_ref_id' => "{$action->id}:{$milestone}",
            ],
            [
                'user_id'     => auth()->id(),
                'action_type' => 'LEGAL',
                'action_at'   => $at,
                'description' => $description,
                'result'      => $result,
                'proof_url'   => $proofUrl,
            ]
        );
    }
}
