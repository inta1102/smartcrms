<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Collateral;
use App\Enums\UserRole;
use App\Policies\Concerns\AuthorizesByRole;

class CollateralPolicy
{
    use AuthorizesByRole;

    public function viewAny(User $user): bool
    {
        // Legal chain boleh akses
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

    public function view(User $user, Collateral $col): bool
    {
        if ($this->allowRoles($user, $this->rolesManagementNplLike())) return true;
        if ($this->allowRoles($user, [UserRole::BE])) return true;

        // AO/field staff: hanya kalau terkait case miliknya (fallback via PIC / ao_code)
        // Kalau collateral punya nplCase relation:
        $case = $col->nplCase ?? null;
        if ($case) {
            return (int)($case->pic_user_id ?? 0) === (int)$user->id;
        }

        // Kalau tidak ada relation case, minimal deny untuk keamanan
        return false;
    }

    public function create(User $user): bool
    {
        // biasanya collateral diinput lewat import / admin; kalau mau BE bisa tambah dok, pakai updateLegalFields saja
        return $this->allowRoles($user, [...$this->rolesKasi(), ...$this->rolesKabagPe(), UserRole::KTI]);
    }

    public function update(User $user, Collateral $col): bool
    {
        // update umum collateral (kalau ada) -> ketat
        return $this->allowRoles($user, [...$this->rolesKasi(), ...$this->rolesKabagPe(), UserRole::KTI]);
    }

    /**
     * Ability khusus: update field legal pada agunan (status pengikatan, nomor akta, fidusia, ht, dll)
     */
    public function updateLegalFields(User $user, Collateral $col): bool
    {
        if ($this->allowRoles($user, $this->rolesManagementNplLike())) return true;
        if ($this->allowRoles($user, ...$this->rolesTl())) return true; // kalau TL boleh lihat & koreksi legal status
        return $this->allowRoles($user, [UserRole::BE, ...$this->rolesKasi()]);
    }

    public function delete(User $user, Collateral $col): bool
    {
        return false;
    }

    protected function rolesManagementNplLike(): array
    {
        return [
            UserRole::DIREKSI,
            UserRole::KOM,
            UserRole::KBL,
            UserRole::KTI,
                UserRole::KSLR,
                UserRole::KSO,
                UserRole::KSA,
                UserRole::KSF,
                UserRole::KSD,
                UserRole::KSBE,
                UserRole::KSFE,
                UserRole::KSLU,
        ];
    }
}
