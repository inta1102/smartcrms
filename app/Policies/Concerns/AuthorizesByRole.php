<?php

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\User;

trait AuthorizesByRole
{
    protected function atLeast(User $user, UserRole $min): bool
    {
        return $user->atLeast($min);
    }

    protected function allowRoles(User $user, array $roles): bool
    {
        return $user->hasAnyRole($roles);
    }

    protected function deny(string $message = 'Unauthorized'): bool
    {
        // kalau kamu mau return Response::deny($message) juga boleh,
        // tapi untuk simple policy boolean cukup false.
        return false;
    }

    protected function isSelf(User $user, ?int $userId): bool
    {
        return $userId !== null && $user->id === (int) $userId;
    }

    /**
     * Helper group role biar policy nggak panjang.
     */
    protected function rolesDireksi(): array
    {
        return [UserRole::DIREKSI, UserRole::KOM];
    }

    protected function rolesKabagPe(): array
    {
        return [UserRole::KABAG, UserRole::KBL, UserRole::KBO, UserRole::KTI, UserRole::KBF, UserRole::PE];
    }

    protected function rolesKasi(): array
    {
        return [UserRole::KSL, UserRole::KSO, UserRole::KSA, UserRole::KSF, UserRole::KSD, UserRole::KSR];
    }

    protected function rolesTl(): array
    {
        return [UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR, UserRole::TLRO, UserRole::TLSO, UserRole::TLFE, UserRole::TLBE, UserRole::TLUM];
    }

    protected function rolesStaffOps(): array
    {
        return [UserRole::AO, UserRole::CS, UserRole::TEL, UserRole::BO, UserRole::ACC, UserRole::BE, UserRole::SO, UserRole::TI, UserRole::SAD, UserRole::SPE, UserRole::SSD, UserRole::FE, UserRole::FO, UserRole::STAFF];
    }
}
