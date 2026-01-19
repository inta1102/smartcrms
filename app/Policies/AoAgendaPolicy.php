<?php

namespace App\Policies;

use App\Models\AoAgenda;
use App\Models\User;
use App\Enums\UserRole;
use App\Policies\Concerns\AuthorizesByRole;

class AoAgendaPolicy
{
    use AuthorizesByRole;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AoAgenda $agenda): bool
    {
        if ($this->atLeast($user, UserRole::KSL)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // AO/Staff: hanya agenda untuk case yang dia pegang
        return (int)($agenda->nplCase?->pic_user_id) === (int)$user->id;
    }

    public function create(User $user): bool
    {
        return $this->allowRoles($user, [UserRole::AO, ...$this->rolesTl(), ...$this->rolesKasi()]);
    }

    /**
     * UPDATE = edit master agenda (judul, planned_at, owner, dsb).
     * Tetap ketat.
     */
    public function update(User $user, AoAgenda $agenda): bool
    {
        if ($this->atLeast($user, UserRole::KSL)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        return $this->isSelf($user, $agenda->user_id ?? $agenda->ao_user_id ?? null);
    }

    /**
     * PROGRESS = aksi operasional (start/in_progress/done) untuk eksekusi agenda,
     * khususnya dari VisitController.
     *
     * Di sini kita izinkan AO/BE/FE/RO/SO/SA tapi tetap dibatasi:
     * - agenda harus untuk case yang dia pegang (PIC case), ATAU
     * - agenda owner = dirinya (self), ATAU
     * - supervisi (TL/KASI) selalu boleh.
     */
    public function progress(User $user, AoAgenda $agenda): bool
    {
        // Supervisi bebas
        if ($this->atLeast($user, UserRole::KSL)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // Staff operasional yang boleh menjalankan agenda visit
        if (! $this->allowRoles($user, [
            UserRole::AO,
            UserRole::BE,
            UserRole::FE,
            UserRole::RO,
            UserRole::SO,
            UserRole::SA,
        ])) {
            return false;
        }

        // Batasi: hanya untuk case yg dia pegang (PIC case)
        if ((int)($agenda->nplCase?->pic_user_id) === (int)$user->id) {
            return true;
        }

        // Atau kalau agenda memang di-assign ke user tsb
        if ($this->isSelf($user, $agenda->user_id ?? $agenda->ao_user_id ?? null)) {
            return true;
        }

        return false;
    }

    public function delete(User $user, AoAgenda $agenda): bool
    {
        // aman: hanya TL/KASI
        if ($this->atLeast($user, UserRole::KSL)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        return false;
    }
}
