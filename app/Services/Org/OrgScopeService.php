<?php

namespace App\Services\Org;

use App\Models\User;
use App\Models\OrgAssignment;

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
}
