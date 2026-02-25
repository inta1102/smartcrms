<?php

namespace App\Policies;

use App\Models\User;

class KpiKblPolicy
{
    public function view(User $viewer): bool
    {
        $role = $this->resolveRole($viewer);

        // yang boleh buka sheet KSLR:
        // - KSLR sendiri
        // - management/admin
        return in_array($role, [
            'KBL','DIR','DIREKSI','KOM','PE','KTI','ADMIN','SUPERADMIN'
        ], true);
    }

    private function resolveRole(User $u): string
    {
        $raw = method_exists($u, 'roleValue') ? ($u->roleValue() ?? null) : null;
        if ($raw === null) $raw = $u->level ?? '';
        if ($raw instanceof \BackedEnum) $raw = $raw->value;
        return strtoupper(trim((string)$raw));
    }
}