<?php

namespace App\Policies;

use App\Models\NonLitigationAction;
use App\Models\NplCase;
use App\Models\User;
use App\Enums\UserRole;
use App\Policies\Concerns\AuthorizesByRole;

class NonLitigationActionPolicy
{
    use AuthorizesByRole;

    /**
     * Siapa saja yang boleh melihat data Non-Lit (show).
     */
    public function view(User $user, NonLitigationAction $nonLit): bool
    {
        // Management & Supervisi
        if ($this->atLeast($user, UserRole::KSLR, UserRole::KSLU, UserRole::KSFE, UserRole::KSBE)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;
        if ($this->allowRoles($user, $this->rolesKasi())) return true;
        if ($this->allowRoles($user, $this->rolesKabagPe())) return true;

        // Role operasional yang kamu minta
        if (! $this->allowRoles($user, [
            UserRole::AO,
            UserRole::BE,
            UserRole::FE,
            UserRole::SO,
            UserRole::RO,
            UserRole::SA,
            UserRole::TL,
            UserRole::TLL,
            UserRole::TLF,
            UserRole::TLR,
            UserRole::TLRO,
            UserRole::TLSO,
            UserRole::TLFE,
            UserRole::TLBE,
            UserRole::TLUM,
        ])) {
            return false;
        }


        // SA (support/admin) boleh lihat semua (opsional, tapi biasanya perlu)
        if ($this->allowRoles($user, [UserRole::SA])) return true;

        // Pembuat usulan boleh lihat
        if ((int)($nonLit->proposed_by ?? 0) === (int)$user->id) return true;

        // PIC case juga boleh lihat
        $case = $this->caseOf($nonLit);
        if ($case && (int)($case->pic_user_id ?? 0) === (int)$user->id) return true;

        return false;
    }

    /**
     * Edit/update hanya untuk draft dan pembuat (atau supervisor).
     */
    public function update(User $user, NonLitigationAction $nonLit): bool
    {
        // Supervisi bebas
        if ($this->atLeast($user, UserRole::KSLR, UserRole::KSLU, UserRole::KSFE, UserRole::KSBE)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // selain draft tidak bisa diedit (konsisten dengan ensureEditable)
        if ($nonLit->status !== NonLitigationAction::STATUS_DRAFT) {
            return false;
        }

        // pembuat draft boleh update
        return (int)($nonLit->proposed_by ?? 0) === (int)$user->id;
    }

    /**
     * Approve/reject hanya untuk kasi/atas (sesuai flow).
     * (Kamu di controller pakai authorize('approve', $nonLit))
     */
   public function approve(User $user, NonLitigationAction $nonLit): bool
    {
        // ketat: hanya SUBMITTED yang bisa approve/reject
        if ($nonLit->status !== NonLitigationAction::STATUS_SUBMITTED) {
            return false;
        }

        // ✅ TL boleh approve
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // ✅ Kasi/Kabag/PE/Management juga boleh
        if ($this->atLeast($user, UserRole::KSLR, UserRole::KSLU, UserRole::KSFE, UserRole::KSBE)) return true;
        if ($this->allowRoles($user, $this->rolesKasi())) return true;
        if ($this->allowRoles($user, $this->rolesKabagPe())) return true;

        return false;
    }


    /**
     * Helper ambil case (biar gak query berulang di banyak tempat).
     */
    private function caseOf(NonLitigationAction $nonLit): ?NplCase
    {
        // kalau model sudah ada relasi nplCase, pakai itu
        if (method_exists($nonLit, 'nplCase')) {
            return $nonLit->nplCase;
        }

        // fallback query
        return NplCase::query()->find($nonLit->npl_case_id);
    }

    /**
     * List/index NonLit dalam 1 case.
     * (Kalau controller authorize('viewAny', ...) tanpa case, boleh dihapus.
     * Tapi banyak controller index pakai viewAny.)
     */
    public function viewAny(User $user): bool
    {
        // Supervisi/management
        if ($this->atLeast($user, UserRole::KSLR, UserRole::KSLU, UserRole::KSFE, UserRole::KSBE)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;
        if ($this->allowRoles($user, $this->rolesKasi())) return true;
        if ($this->allowRoles($user, $this->rolesKabagPe())) return true;

        // Operasional (AO dkk) boleh buka list miliknya
        return $this->allowRoles($user, [
            UserRole::AO, UserRole::BE, UserRole::FE, UserRole::SO, UserRole::RO, UserRole::SA,
            UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR, UserRole::TLRO, UserRole::TLSO, UserRole::TLFE, UserRole::TLBE, UserRole::TLUM,
        ]);
    }

    /**
     * Create NonLit untuk 1 case (ini yang bikin AO 403 kalau gak ada).
     * Signature: (User $user, NplCase $case) -> penting.
     */
    public function create(User $user, NplCase $case): bool
    {
        // Supervisi/management bebas
        if ($this->atLeast($user, UserRole::KSLR, UserRole::KSLU, UserRole::KSFE, UserRole::KSBE)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // Operasional yang boleh mengusulkan
        if (! $this->allowRoles($user, [
            UserRole::AO, UserRole::BE, UserRole::FE, UserRole::SO, UserRole::RO, UserRole::SA,
        ])) {
            return false;
        }

        // SA boleh create untuk siapa saja (opsional)
        if ($this->allowRoles($user, [UserRole::SA])) return true;

        // AO/BE/FE/SO/RO hanya boleh create untuk case yang dia pegang
        return (int)($case->pic_user_id ?? 0) === (int)$user->id;
    }

    /**
     * Submit NonLit (kalau controller punya action submit).
     */
    public function submit(User $user, NonLitigationAction $nonLit): bool
    {
        // hanya draft yang bisa submit
        if ($nonLit->status !== NonLitigationAction::STATUS_DRAFT) return false;

        // supervisi boleh
        if ($this->atLeast($user, UserRole::KSLR, UserRole::KSLU, UserRole::KSFE, UserRole::KSBE)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // pembuat draft boleh submit
        return (int)($nonLit->proposed_by ?? 0) === (int)$user->id;
    }

    
}
