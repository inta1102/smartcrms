<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class KpiSoPolicy
{
    public function view(User $viewer, User $target): bool
    {
        // 1) self boleh
        if ((int)$viewer->id === (int)$target->id) return true;

        // role normalize
        $role = strtoupper(trim((string)($viewer->roleValue() ?? $viewer->level ?? '')));

        // 2) management boleh
        if (in_array($role, ['DIR','DIREKSI','KOM','PE','KABAG','KBL','KSL','KTI','KBO','KSA','KSF'], true)) {
            return true;
        }

        // 3) TL boleh lihat bawahan (org_assignments)
        if (str_starts_with($role, 'TL')) {
            return DB::table('org_assignments as oa')
                ->where('oa.leader_id', (int)$viewer->id)
                ->where('oa.user_id', (int)$target->id)
                ->where('oa.is_active', 1)
                ->exists();
        }

        return false;
    }
}
