<?php

namespace App\Policies;

use App\Models\User;

class KpiBePolicy
{
    public function viewAny(User $user): bool
    {
        // pada pattern kamu: hampir semua user boleh lihat ranking
        return true;
    }

    public function view(User $user, User $target): bool
    {
        // target harus BE
        $targetLvl = strtoupper(trim((string)($target->roleValue() ?? $target->level ?? '')));
        if ($targetLvl !== 'BE') return false;

        $meLvl = strtoupper(trim((string)($user->roleValue() ?? $user->level ?? '')));

        // ✅ staff BE boleh lihat dirinya sendiri
        if (in_array($meLvl, ['BE'], true)) {
            return (int)$user->id === (int)$target->id;
        }

        // ✅ TL boleh lihat bawahan (sementara: allow semua, atau pakai org_assignments)
        if (str_starts_with($meLvl, 'TL')) return true;

        // ✅ management boleh lihat semua
        if (in_array($meLvl, ['KSL','KBL','KABAG','DIR','DIREKSI','KOM','PE','KTI'], true)) return true;

        // fallback aman
        return (int)$user->id === (int)$target->id;
    }
}
