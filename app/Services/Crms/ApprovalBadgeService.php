<?php

namespace App\Services\Crms;

use App\Models\CaseResolutionTarget;
use App\Models\NonLitigationAction;

class ApprovalBadgeService
{
    public function tlTargetInboxCount(int $tlUserId): int
    {
        return CaseResolutionTarget::query()
            ->where('status', CaseResolutionTarget::STATUS_PENDING_TL)
            ->where('needs_tl_approval', 1)
            ->count();
    }

    public function kasiTargetInboxCount(int $kasiUserId): int
    {
        return CaseResolutionTarget::query()
            ->where(function ($q) {
                $q->where('status', CaseResolutionTarget::STATUS_PENDING_KASI)
                  ->orWhere(function ($x) {
                      $x->where('status', CaseResolutionTarget::STATUS_PENDING_TL)
                        ->where('needs_tl_approval', 0); // skip TL
                  });
            })
            ->count();
    }

    public function tlNonLitInboxCount(int $tlUserId): int
    {
        return NonLitigationAction::query()
            ->whereIn('status', [NonLitigationAction::STATUS_PENDING_TL, NonLitigationAction::STATUS_SUBMITTED])
            ->where('needs_tl_approval', 1)
            ->count();
    }

    public function kasiNonLitInboxCount(int $kasiUserId): int
    {
        return NonLitigationAction::query()
            ->where(function ($q) {
                $q->where('status', NonLitigationAction::STATUS_PENDING_KASI)
                ->orWhere(function ($x) {
                    $x->whereIn('status', [NonLitigationAction::STATUS_PENDING_TL, NonLitigationAction::STATUS_SUBMITTED])
                        ->where('needs_tl_approval', 0);
                });
            })
            ->count();
    }

}
