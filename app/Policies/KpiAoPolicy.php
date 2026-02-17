<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class KpiAoPolicy
{
    /**
     * View KPI AO Sheet.
     * - AO staff: self-only
     * - TL/KSL/KBL/DIR/dll: scope bawahan (org_assignments)
     * - Admin / management: allow
     */
    public function view(User $viewer, User $target): bool
    {
        // 0) target harus AO (biar policy spesifik AO)
        $targetRole = $this->roleOf($target);
        if ($targetRole !== 'AO') return false;

        // 1) self boleh
        if ((int)$viewer->id === (int)$target->id) return true;

        // 2) management/admin roles (sesuaikan daftar sesuai enum/levelmu)
        $viewerRole = $this->roleOf($viewer);
        if (in_array($viewerRole, ['ORGADMIN','ADMIN','DIR','DIREKSI','KOM','PE','KABAG','KBL','KSL','KTI'], true)) {
            return true;
        }

        // 3) TL roles -> hanya bawahan (via org_assignments)
        if (str_starts_with($viewerRole, 'TL')) {
            return DB::table('org_assignments as oa')
                ->where('oa.leader_id', (int)$viewer->id)
                ->where('oa.user_id', (int)$target->id)
                ->where('oa.is_active', 1)
                ->exists();
        }

        // default deny
        return false;
    }

    private function roleOf(User $u): string
    {
        // roleValue() kalau ada
        $lvl = strtoupper(trim((string)($u->roleValue() ?? '')));
        if ($lvl !== '') return $lvl;

        // fallback kolom level (enum/string)
        $raw = $u->level ?? '';
        if ($raw instanceof \BackedEnum) $raw = $raw->value;

        return strtoupper(trim((string)$raw));
    }
}
