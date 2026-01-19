<?php

namespace App\Policies;

use App\Models\User;
use App\Models\LegalAdminChecklist;
use App\Enums\UserRole;
use App\Policies\Concerns\AuthorizesByRole;
use App\Services\Org\OrgScopeService;


class LegalAdminChecklistPolicy
{
    use AuthorizesByRole;

    public function viewAny(User $user): bool
    {
        return $this->allowRoles($user, [
            ...$this->rolesKasi(),
            ...$this->rolesKabagPe(),
        ]);
    }

    public function create(User $user): bool
    {
        return $this->allowRoles($user, [
            UserRole::KSA, UserRole::KSO,
            ...$this->rolesKabagPe(),
        ]);
    }

    public function update(User $user, LegalAdminChecklist $item): bool
    {
        return $this->allowRoles($user, [
            UserRole::KSA, UserRole::KSO,
            ...$this->rolesKabagPe(),
        ]);
    }

    public function delete(User $user, LegalAdminChecklist $item): bool
    {
        return $this->allowRoles($user, [...$this->rolesKabagPe()]);
    }

    public function toggle(User $user, LegalAdminChecklist $item): bool
    {
        // harus supervisor
        if (!$user->isLegalSupervisor()) return false;

        // Ambil siapa PIC/Proposer yang harus disupervisi.
        // Pilih salah satu sumber yang paling valid di sistemmu:
        // 1) dari legal_action->proposed_by
        // 2) atau dari legal_action->legalCase->nplCase->pic_user_id
        $action = $item->legalAction; // pastikan relasi ada
        if (!$action) return false;

        $subordinateId = (int)($action->proposed_by ?? 0);
        if ($subordinateId < 1 && $action->legalCase?->nplCase?->pic_user_id) {
            $subordinateId = (int)$action->legalCase->nplCase->pic_user_id;
        }
        if ($subordinateId < 1) return false;

        // jangan bisa checklist untuk diri sendiri
        if ((int)$user->id === $subordinateId) return false;

        // TL boleh hanya untuk scope dia,
        // KASI/KABAG/DIR juga hanya untuk scope (biar aman audit)
        return app(OrgScopeService::class)->isInSupervisorScope((int)$user->id, $subordinateId);
    }
}
