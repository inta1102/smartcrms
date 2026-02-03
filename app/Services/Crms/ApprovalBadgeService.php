<?php

namespace App\Services\Crms;

use App\Models\CaseResolutionTarget;
use App\Models\NonLitigationAction;
use App\Services\Org\OrgVisibilityService;

class ApprovalBadgeService
{
    // =========================================================
    // TL
    // =========================================================
    public function tlTargetInboxCount(int $tlUserId): int
    {
        $org = app(OrgVisibilityService::class);

        // bawahan TL langsung (staff)
        $underIds = method_exists($org, 'directSubordinateUserIds')
            ? (array) $org->directSubordinateUserIds($tlUserId)
            : $this->fallbackDirectUnderIds($tlUserId);

        if (empty($underIds)) return 0;

        return (int) CaseResolutionTarget::query()
            ->whereIn('proposed_by', $underIds)
            ->where('status', CaseResolutionTarget::STATUS_PENDING_TL)
            ->where('needs_tl_approval', 1)
            ->count();
    }

    public function tlNonLitInboxCount(int $tlUserId): int
    {
        $org = app(OrgVisibilityService::class);

        $underIds = method_exists($org, 'directSubordinateUserIds')
            ? (array) $org->directSubordinateUserIds($tlUserId)
            : $this->fallbackDirectUnderIds($tlUserId);

        if (empty($underIds)) return 0;

        return (int) NonLitigationAction::query()
            ->whereIn('proposed_by', $underIds) // pastikan kolomnya proposed_by (sesuaikan jika beda)
            ->whereIn('status', [NonLitigationAction::STATUS_PENDING_TL, NonLitigationAction::STATUS_SUBMITTED])
            ->where('needs_tl_approval', 1)
            ->count();
    }

    // =========================================================
    // KASI
    // =========================================================
    public function kasiTargetInboxCount(int $kasiUserId): int
    {
        $org = app(OrgVisibilityService::class);

        // under KASI: staff direct + TL + staff under TL
        $underIds = method_exists($org, 'subordinateUserIdsForKasi')
            ? (array) $org->subordinateUserIdsForKasi($kasiUserId)
            : $this->fallbackKasiUnderIds($kasiUserId);

        if (empty($underIds)) return 0;

        return (int) CaseResolutionTarget::query()
            ->whereIn('proposed_by', $underIds)
            ->where(function ($q) {
                $q->where('status', CaseResolutionTarget::STATUS_PENDING_KASI)
                  ->orWhere(function ($x) {
                      $x->where('status', CaseResolutionTarget::STATUS_PENDING_TL)
                        ->where('needs_tl_approval', 0); // skip TL
                  });
            })
            ->count();
    }

    public function kasiNonLitInboxCount(int $kasiUserId): int
    {
        $org = app(OrgVisibilityService::class);

        $underIds = method_exists($org, 'subordinateUserIdsForKasi')
            ? (array) $org->subordinateUserIdsForKasi($kasiUserId)
            : [];

        if (empty($underIds)) return 0;

        return (int) NonLitigationAction::query()
            // ✅ scope under KASI
            // 🔥 pastikan kolom pengusulnya sesuai (umumnya proposed_by)
            ->whereIn('proposed_by', $underIds)

            // ✅ inbox filter harus sama seperti index nonlit-kasi kamu
            ->where(function ($x) {
                $x->where('status', NonLitigationAction::STATUS_PENDING_KASI)
                ->orWhere(function ($y) {
                    $y->where('status', NonLitigationAction::STATUS_PENDING_TL)
                        ->where('needs_tl_approval', 0);
                });
            })
            ->count();
    }

    // =========================================================
    // Fallbacks (kalau kamu belum sempat bikin helper di OrgVisibilityService)
    // =========================================================

    /**
     * Fallback bawahan langsung: leader_id = TL
     */
    protected function fallbackDirectUnderIds(int $leaderId): array
    {
        return \App\Models\OrgAssignment::query()
            ->where('is_active', 1)
            ->where('leader_id', $leaderId)
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('effective_to')
                  ->orWhereDate('effective_to', '>=', now()->toDateString());
            })
            ->pluck('user_id')
            ->map(fn($v) => (int) $v)
            ->values()
            ->all();
    }

    /**
     * Fallback under KASI: level1 (direct) + level2 (bawahan dari level1)
     */
    protected function fallbackKasiUnderIds(int $kasiId): array
    {
        $level1 = \App\Models\OrgAssignment::query()
            ->where('is_active', 1)
            ->where('leader_id', $kasiId)
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('effective_to')
                  ->orWhereDate('effective_to', '>=', now()->toDateString());
            })
            ->pluck('user_id')
            ->map(fn($v) => (int) $v)
            ->values();

        if ($level1->isEmpty()) return [];

        $level2 = \App\Models\OrgAssignment::query()
            ->where('is_active', 1)
            ->whereIn('leader_id', $level1->all())
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('effective_to')
                  ->orWhereDate('effective_to', '>=', now()->toDateString());
            })
            ->pluck('user_id')
            ->map(fn($v) => (int) $v)
            ->values();

        return $level1->merge($level2)->unique()->values()->all();
    }
}
