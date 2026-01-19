<?php

namespace App\Policies;

use App\Models\User;
use App\Models\LegalDocument;
use App\Enums\UserRole;
use App\Policies\Concerns\AuthorizesByRole;

class LegalDocumentPolicy
{
    use AuthorizesByRole;

    public function viewAny(User $user): bool
    {
        // BE & manajemen/legal chain boleh akses menu dokumen legal
        return $this->allowRoles($user, [
            UserRole::BE,
            ...$this->rolesTl(),
            ...$this->rolesKasi(),
            ...$this->rolesKabagPe(),
            UserRole::DIREKSI,
            UserRole::KOM,
            UserRole::KTI,
        ]);
    }

    public function view(User $user, LegalDocument $doc): bool
    {
        if ($this->allowRoles($user, $this->rolesManagementNplLike())) return true;
        if ($this->allowRoles($user, [UserRole::BE])) return true;

        // AO/field staff: hanya kalau dia owner case
        $case = $doc->nplCase ?? null;
        if (!$case) return false;

        $role = strtoupper(trim((string) $user->roleValue()));
        if (!in_array($role, ['AO','SO','FE','RO','SA'], true)) return false;

        return (int)($case->pic_user_id ?? 0) === (int)$user->id;
    }

    public function create(User $user): bool
    {
        // upload dokumen legal: BE + TL/Kasi/Kabag/PE
        return $this->allowRoles($user, [
            UserRole::BE,
            ...$this->rolesTl(),
            ...$this->rolesKasi(),
            ...$this->rolesKabagPe(),
        ]);
    }

    public function update(User $user, LegalDocument $doc): bool
    {
        // edit metadata dokumen: BE + TL/Kasi
        if ($this->allowRoles($user, $this->rolesManagementNplLike())) return true;
        return $this->allowRoles($user, [UserRole::BE, ...$this->rolesTl(), ...$this->rolesKasi()]);
    }

    public function delete(User $user, LegalDocument $doc): bool
    {
        // delete dokumen = sensitif: hanya Kabag/PE/Direksi/KTI
        return $this->allowRoles($user, [
            ...$this->rolesKabagPe(),
            UserRole::DIREKSI,
            UserRole::KOM,
            UserRole::KTI,
        ]);
    }

    protected function rolesManagementNplLike(): array
    {
        return [
            UserRole::DIREKSI,
            UserRole::KOM,
            UserRole::KBL,
            UserRole::KSR,
            UserRole::KTI,
            UserRole::KSL,
        ];
    }
}
