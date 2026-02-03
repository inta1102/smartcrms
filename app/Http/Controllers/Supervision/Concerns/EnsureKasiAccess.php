<?php

namespace App\Http\Controllers\Supervision\Concerns;

use App\Enums\UserRole;

trait EnsureKasiAccess
{
    protected function ensureKasiLevel(): void
    {
        $u = auth()->user();
        abort_unless($u, 403);

        $role = method_exists($u, 'role') ? $u->role() : null;

        // fallback string kalau cast enum belum kepakai
        $val = method_exists($u, 'roleValue')
            ? strtoupper((string) $u->roleValue())
            : strtoupper((string) ($u->level ?? ''));

        $isKasi = false;

        if ($role instanceof UserRole) {
            $isKasi = in_array($role, [
                UserRole::KSL,
                UserRole::KSO,
                UserRole::KSA,
                UserRole::KSF,
                UserRole::KSD,
                UserRole::KSR,
            ], true);
        } else {
            $isKasi = in_array($val, ['KSL','KSO','KSA','KSF','KSD','KSR'], true);
        }

        abort_unless($isKasi, 403);
    }
}
