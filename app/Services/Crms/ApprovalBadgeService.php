<?php

namespace App\Services\Crms;

use App\Models\CaseResolutionTarget;
use App\Models\NonLitigationAction;
use App\Models\OrgAssignment;

class ApprovalBadgeService
{
    // =========================================================
    // TL
    // =========================================================
    public function tlTargetInboxCount(int $tlUserId): int
    {
        return (int) CaseResolutionTarget::query()
            ->where('status', CaseResolutionTarget::STATUS_PENDING_TL)
            ->where('needs_tl_approval', 1)
            ->where(function ($w) use ($tlUserId) {
                $w->where('proposed_by', $tlUserId) // optional: TL sendiri
                  ->orWhereHas('proposer.orgAssignmentsAsStaff', function ($x) use ($tlUserId) {
                      $x->active()
                        ->where('leader_id', $tlUserId)
                        ->whereIn('leader_role', ['TL','TLL','TLF','TLR','TLRO','TLSO','TLFE','TLBE','TLUM']); // mapping aman
                  });
            })
            ->count();
    }

    public function tlTargetOverSlaCount(int $tlUserId): int
    {
        $tlSlaDays = (int) config('crms.sla.tl_days', 1);

        return (int) CaseResolutionTarget::query()
            ->where('status', CaseResolutionTarget::STATUS_PENDING_TL)
            ->where('needs_tl_approval', 1)
            ->where('created_at', '<', now()->subDays($tlSlaDays))
            ->where(function ($w) use ($tlUserId) {
                $w->where('proposed_by', $tlUserId)
                  ->orWhereHas('proposer.orgAssignmentsAsStaff', function ($x) use ($tlUserId) {
                      $x->active()
                        ->where('leader_id', $tlUserId)
                        ->whereIn('leader_role', ['TL','TLL','TLF','TLR','TLRO','TLSO','TLFE','TLBE','TLUM']);
                  });
            })
            ->count();
    }

    public function tlNonLitInboxCount(int $tlUserId): int
    {
        return (int) NonLitigationAction::query()
            ->whereIn('status', [
                NonLitigationAction::STATUS_PENDING_TL,
                NonLitigationAction::STATUS_SUBMITTED,
            ])
            ->where('needs_tl_approval', 1)
            ->where(function ($w) use ($tlUserId) {
                $w->where('proposed_by', $tlUserId)
                  ->orWhereHas('proposer.orgAssignmentsAsStaff', function ($x) use ($tlUserId) {
                      $x->active()
                        ->where('leader_id', $tlUserId)
                        ->whereIn('leader_role', ['TL','TLL','TLF','TLR','TLRO','TLSO','TLFE','TLBE','TLUM']);
                  });
            })
            ->count();
    }

    // =========================================================
    // KASI
    // =========================================================
    public function kasiTargetInboxCount(int $kasiUserId): int
    {
        // Inbox KASI:
        // - pending_kasi
        // - pending_tl + needs_tl_approval=0 (skip TL)
        return (int) CaseResolutionTarget::query()
            ->where(function ($q) {
                $q->where('status', CaseResolutionTarget::STATUS_PENDING_KASI)
                  ->orWhere(function ($x) {
                      $x->where('status', CaseResolutionTarget::STATUS_PENDING_TL)
                        ->where('needs_tl_approval', 0);
                  });
            })
            ->where(function ($w) use ($kasiUserId) {
                $w->where('proposed_by', $kasiUserId)
                  ->orWhereHas('proposer.orgAssignmentsAsStaff', function ($x) use ($kasiUserId) {
                      // ✅ KASI lihat staff direct + TL dibawahnya, jadi cukup leader_id = kasi
                      // leader_role bisa KASI / KSR / KSL tergantung data kamu
                      $x->active()
                        ->where('leader_id', $kasiUserId)
                        ->whereIn('leader_role', ['KASI','KSR','KSL']);
                  })
                  ->orWhereHas('proposer.orgAssignmentsAsStaff.leader', function ($x) use ($kasiUserId) {
                      // ✅ level-2: staff dibawah TL yang dibawah KASI
                      // ini aman walau org kamu 2 level (KASI -> TL -> AO)
                      // caranya: proposer punya assignment leader_id = TL,
                      // dan TL itu punya assignment leader_id = KASI.
                      // (lebih efisien kalau nanti dibuat join khusus)
                      $x->whereExists(function ($sq) use ($kasiUserId) {
                          $sq->selectRaw(1)
                             ->from((new OrgAssignment)->getTable().' as oa')
                             ->whereColumn('oa.user_id', 'users.id')
                             ->where('oa.leader_id', $kasiUserId)
                             ->where('oa.is_active', 1);
                      });
                  });
            })
            ->count();
    }

    public function kasiNonLitInboxCount(int $kasiUserId): int
    {
        return (int) NonLitigationAction::query()
            ->where(function ($q) {
                $q->where('status', NonLitigationAction::STATUS_PENDING_KASI)
                  ->orWhere(function ($x) {
                      $x->where('status', NonLitigationAction::STATUS_PENDING_TL)
                        ->where('needs_tl_approval', 0);
                  });
            })
            ->where(function ($w) use ($kasiUserId) {
                $w->where('proposed_by', $kasiUserId)
                  ->orWhereHas('proposer.orgAssignmentsAsStaff', function ($x) use ($kasiUserId) {
                      $x->active()
                        ->where('leader_id', $kasiUserId)
                        ->whereIn('leader_role', ['KASI','KSR','KSL']);
                  });
            })
            ->count();
    }
}
