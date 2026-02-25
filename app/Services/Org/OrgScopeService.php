<?php

namespace App\Services\Org;

use App\Models\User;
use App\Models\OrgAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrgScopeService
{
    /**
     * True jika $supervisor adalah atasan langsung (atau chain) dari $subordinate.
     * - dipakai untuk checklist TL/KASI
     * - dipakai untuk approval legal, dll
     */
    public function isInSupervisorScope(int $supervisorId, int $subordinateId): bool
    {
        if ($supervisorId === $subordinateId) return false;

        // chain walk: subordinate -> leader -> leader -> ...
        $visited = [];
        $current = $subordinateId;

        for ($i = 0; $i < 10; $i++) { // safety stop
            if (in_array($current, $visited, true)) return false;
            $visited[] = $current;

            $leaderId = OrgAssignment::query()
                ->where('user_id', $current)
                ->value('leader_id');

            if (!$leaderId) return false;
            if ((int)$leaderId === (int)$supervisorId) return true;

            $current = (int)$leaderId;
        }

        return false;
    }

    /**
     * Cari atasan pertama dari user (buat fallback TL kosong -> KASI)
     */
    public function directLeaderId(int $userId): ?int
    {
        $leaderId = OrgAssignment::query()
            ->where('user_id', $userId)
            ->value('leader_id');

        return $leaderId ? (int)$leaderId : null;
    }

    /**
     * Ambil semua descendant user_id dari leader_id pada periode & unit tertentu.
     * BFS hingga depth tertentu (default 3 cukup untuk: KSLR -> TL -> RO, dan KSLR -> TLSO -> SO).
     */
    public function descendantUserIds(int $leaderId, string $periodYmd, ?string $unitCode = 'lending', int $maxDepth = 3): array
    {
        $periodYmd = Carbon::parse($periodYmd)->startOfMonth()->toDateString();

        $seen = [];
        $frontier = [$leaderId];

        for ($depth = 0; $depth < $maxDepth; $depth++) {
            if (empty($frontier)) break;

            $children = DB::table('org_assignments as oa')
                ->whereIn('oa.leader_id', $frontier)
                ->where('oa.is_active', 1)
                ->when($unitCode, fn($q) => $q->where('oa.unit_code', $unitCode))
                ->whereDate('oa.effective_from', '<=', $periodYmd)
                ->where(function ($q) use ($periodYmd) {
                    $q->whereNull('oa.effective_to')
                      ->orWhereDate('oa.effective_to', '>=', $periodYmd);
                })
                ->pluck('oa.user_id')
                ->map(fn($v) => (int)$v)
                ->all();

            $children = array_values(array_diff(array_unique($children), $seen, [$leaderId]));
            if (empty($children)) break;

            foreach ($children as $cid) $seen[] = $cid;
            $frontier = $children;
        }

        return array_values(array_unique($seen));
    }
}
