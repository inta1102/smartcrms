<?php

namespace App\Policies;

use App\Models\NplCase;
use App\Models\User;
use App\Enums\UserRole;
use App\Policies\Concerns\AuthorizesByRole;

class NplCasePolicy
{
    use AuthorizesByRole;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, NplCase $case): bool
    {
        // management ok
        if ($this->allowRoles($user, $this->rolesManagementNpl())) return true;

        // TL boleh lihat (refine pakai org_assignment nanti)
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // ✅ BE (Legal): boleh lihat case legal (is_legal=1)
        if ($this->allowRoles($user, [UserRole::BE])) {
            if ((bool)($case->is_legal ?? false) === true) return true;

            // fallback: kalau dia PIC, tetap boleh
            return (int)($case->pic_user_id ?? 0) === (int)$user->id;
        }

        // AO/SO/FE/RO/SA hanya case miliknya
        return $this->isOwnerFieldStaff($user, $case);
    }

    /**
     * Update data inti case: jangan beri BE akses.
     */
    public function update(User $user, NplCase $case): bool
    {
        if ($this->allowRoles($user, $this->rolesManagementNpl())) return true;

        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // hanya AO owner
        return $this->isOwnerAo($user, $case);
    }

    public function updateAssessment(User $user, NplCase $case): bool
    {
        // management ok
        if ($this->allowRoles($user, $this->rolesManagementNpl())) return true;

        // TL/Kasi/Kabag/PE boleh isi assessment
        if ($this->allowRoles($user, $this->rolesTl())) return true;
        if ($this->allowRoles($user, $this->rolesKasi())) return true;
        if ($this->allowRoles($user, $this->rolesKabagPe())) return true;

        // BE (Legal) tidak boleh isi assessment (sesuai rule "inti case")
        if ($this->allowRoles($user, [UserRole::BE])) return false;

        // AO/SO/FE/RO/SA: boleh kalau dia owner/scope
        return $this->isOwnerFieldStaff($user, $case);
    }


    /**
     * Ability khusus: update aspek legal (is_legal, legal_started_at, legal_note)
     */
    public function updateLegal(User $user, NplCase $case): bool
    {
        if ($this->allowRoles($user, $this->rolesManagementNpl())) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;
        if ($this->allowRoles($user, $this->rolesKasi())) return true;

        // BE boleh update legal untuk case legal,
        // dan boleh “menandai legal” kalau dia PIC (opsi aman)
        if ($this->allowRoles($user, [UserRole::BE])) {
            if ((bool)($case->is_legal ?? false) === true) return true;

            return (int)($case->pic_user_id ?? 0) === (int)$user->id;
        }

        return false;
    }

    public function startLegalAction(User $user, NplCase $case): bool
    {
        // Diganti: include BE + TL/Kasi/Kabag/PE
        return $this->allowRoles($user, [
            UserRole::BE,
            ...$this->rolesTl(),
            ...$this->rolesKasi(),
            ...$this->rolesKabagPe(),
            UserRole::KTI,
            UserRole::DIREKSI,
            UserRole::KOM,
        ]);
    }

    public function create(User $user): bool
    {
        return $this->allowRoles($user, [
            UserRole::AO, UserRole::CS, UserRole::BO, UserRole::ACC,
            ...$this->rolesTl(),
            ...$this->rolesKasi(),
            ...$this->rolesKabagPe(),
        ]);
    }

    public function delete(User $user, NplCase $nplCase): bool { return false; }
    public function restore(User $user, NplCase $nplCase): bool { return false; }
    public function forceDelete(User $user, NplCase $nplCase): bool { return false; }

    private function isOwnerAo(User $user, NplCase $case): bool
    {
        $aoId = $case->ao_user_id ?? $case->assigned_to ?? null;
        return $this->isSelf($user, $aoId);
    }

    private function isOwnerFieldStaff(User $user, NplCase $case): bool
    {
        $role = strtoupper(trim((string) $user->roleValue()));

        if (!in_array($role, ['AO','SO','FE','RO','SA'], true)) {
            return false;
        }

        // if (!in_array($role, ['AO','SO','FE','BE','RO','SA'], true)) {
        //     return false;
        // }

        if ((int) ($case->pic_user_id ?? 0) === (int) $user->id) {
            return true;
        }

        $uAoCode = trim((string) ($user->ao_code ?? ''));
        $cAoCode = trim((string) ($case->loanAccount?->ao_code ?? ''));

        if ($uAoCode !== '' && $cAoCode !== '' && $uAoCode === $cAoCode) {
            return true;
        }

        return false;
    }

    protected function rolesManagementNpl(): array
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
