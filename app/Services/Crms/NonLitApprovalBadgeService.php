<?php

namespace App\Services\Crms;

use App\Models\NonLitigationAction;
use App\Models\OrgAssignment;
use Carbon\Carbon;

class NonLitApprovalBadgeService
{
    /**
     * Inbox TL: Non-Lit yang butuh approval TL
     */
    public function tlInboxCount(int $tlUserId): int
    {
        $today = Carbon::now()->toDateString();

        $subordinateIds = OrgAssignment::query()
            ->where('leader_id', $tlUserId)
            ->where('is_active', 1)
            ->whereDate('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')
                  ->orWhereDate('effective_to', '>=', $today);
            })
            ->pluck('user_id')
            ->all();

        if (empty($subordinateIds)) {
            return 0;
        }

        return NonLitigationAction::query()
            ->where('status', NonLitigationAction::STATUS_PENDING_TL)
            ->where('needs_tl_approval', 1)
            ->whereIn('proposed_by', $subordinateIds)
            ->count();
    }

    /**
     * Inbox KASI: Non-Lit yang butuh approval KASI
     */
    public function kasiInboxCount(): int
    {
        return NonLitigationAction::query()
            ->where(function ($q) {
                $q->where('status', NonLitigationAction::STATUS_PENDING_KASI)
                  ->orWhere(function ($x) {
                      $x->where('status', NonLitigationAction::STATUS_PENDING_TL)
                        ->where('needs_tl_approval', 0);
                  });
            })
            ->count();
    }
}
