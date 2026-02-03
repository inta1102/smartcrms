<?php

namespace App\Services\Supervision;

use App\Models\CaseResolutionTarget;
use App\Services\Org\OrgVisibilityService;
use App\Enums\UserRole;

class ApprovalBadgeService
{
    public function kasiTargetInboxCount(int $kasiId): int
    {
        $org = app(OrgVisibilityService::class);

        // under KASI: direct staff + staff via TL
        $underIds = method_exists($org, 'subordinateUserIdsForKasi')
            ? (array) $org->subordinateUserIdsForKasi($kasiId)
            : [];

        if (empty($underIds)) return 0;

        return (int) CaseResolutionTarget::query()
            ->whereIn('proposed_by', $underIds)
            ->where(function ($w) {
                $w->where('status', CaseResolutionTarget::STATUS_PENDING_KASI)
                  ->orWhere(function ($x) {
                      $x->where('status', CaseResolutionTarget::STATUS_PENDING_TL)
                        ->where('needs_tl_approval', 0);
                  });
            })
            ->count();
    }
}
