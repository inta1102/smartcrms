<?php

namespace App\Services\Legal;

use App\Models\LegalAction;
use App\Models\LegalAdminChecklist;
use DomainException;

class LegalActionReadinessService
{
    /**
     * Validasi kesiapan administratif TL
     * Digunakan sebelum Mark Prepared / Submit HT Execution
     */
    public function ensureChecklistComplete(LegalAction $action): void
    {
        $requiredChecklistTotal = LegalAdminChecklist::where('legal_action_id', $action->id)
            ->where('is_required', true)
            ->count();

        $requiredChecklistChecked = LegalAdminChecklist::where('legal_action_id', $action->id)
            ->where('is_required', true)
            ->where('is_checked', true)
            ->count();

        if ($requiredChecklistChecked < $requiredChecklistTotal) {
            throw new DomainException(
                "Checklist administrasi TL belum lengkap."
            );
        }
    }
}
