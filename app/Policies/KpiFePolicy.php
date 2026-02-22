<?php

namespace App\Policies;

use App\Models\User;
use App\Enums\UserRole;
use App\Policies\Concerns\AuthorizesByRole;

class KpiFePolicy
{
    use AuthorizesByRole;

    public function viewAny(User $actor): bool
    {
        // yang boleh akses menu/ranking FE
        if ($this->allowRoles($actor, [
            UserRole::DIREKSI, UserRole::KOM, UserRole::DIR,
            UserRole::PE,
            UserRole::KABAG, UserRole::KBL,
            UserRole::KSLR, UserRole::KSLU, UserRole::KSFE, UserRole::KSBE,
            UserRole::TLFE,
        ])) return true;

        return $this->allowRoles($actor, [UserRole::FE]);
    }

    public function view(User $actor, User $target): bool
    {
        // management/atasan boleh lihat detail siapa pun
        if ($this->allowRoles($actor, [
            UserRole::DIREKSI, UserRole::KOM, UserRole::DIR,
            UserRole::PE,
            UserRole::KABAG, UserRole::KBL,
            UserRole::KSLR, UserRole::KSLU, UserRole::KSFE, UserRole::KSBE,
            UserRole::TLFE,
        ])) return true;

        // FE hanya boleh lihat dirinya
        if ($this->allowRoles($actor, [UserRole::FE])) {
            return (int)$actor->id === (int)$target->id;
        }

        return false;
    }
}
