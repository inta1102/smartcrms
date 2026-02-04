<?php

namespace App\Policies;

use App\Models\User;
use App\Models\CaseResolutionTarget;
use App\Enums\UserRole;
use App\Policies\Concerns\AuthorizesByRole;
use App\Models\NplCase;
use App\Models\OrgAssignment;


class CaseResolutionTargetPolicy
{
    use AuthorizesByRole;

    public function view(User $user, CaseResolutionTarget $target): bool
    {
        if ($this->atLeast($user, UserRole::KSL)) return true;

        // TL hanya boleh lihat jika dalam scope timnya
        if ($this->allowRoles($user, $this->rolesTl())) {
            return $this->withinLeaderScope($user, $target->proposed_by ?? null, $this->rolesTl());
        }

        // AO/BE/FE/SO/RO hanya yg dia propose
        return $this->isSelf($user, $target->proposed_by ?? null);
    }

    public function update(User $user, CaseResolutionTarget $target): bool
    {
        if (in_array(strtolower((string)$target->status), ['active', 'superseded'], true)) {
            return false;
        }

        if ($this->atLeast($user, UserRole::KSL)) return true;

        // TL boleh update jika target dalam scope timnya
        if ($this->allowRoles($user, $this->rolesTl())) {
            return $this->withinLeaderScope($user, $target->proposed_by ?? null, $this->rolesTl());
        }

        return $this->isSelf($user, $target->proposed_by ?? null);
    }

    public function approveTl(User $user, CaseResolutionTarget $target): bool
    {
        if (!$this->allowRoles($user, $this->rolesTl())) return false;

        // ✅ Guard status (optional tapi bagus)
        if ((string)$target->status !== CaseResolutionTarget::STATUS_PENDING_TL) return false;

        // ✅ Scope TL: hanya target bawahan (atau milik sendiri)
        return $this->withinLeaderScope($user, $target->proposed_by ?? null, $this->rolesTl());
    }


    public function create(User $user): bool
    {
        return $this->allowRoles($user, [
            ...$this->rolesProposer(),
            ...$this->rolesTl(),
            ...$this->rolesKasi(),
        ]);
    }

    protected function rolesProposer(): array
    {
        return [
            UserRole::AO,
            UserRole::BE,
            UserRole::FE,
            UserRole::SO,
            UserRole::RO,
        ];
    }

    public function propose(User $user, NplCase $case): bool
    {
        // Atasan boleh
        if ($this->atLeast($user, UserRole::KSL)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;
        if ($this->allowRoles($user, $this->rolesKasi())) return true;

        // Pelaksana (AO/BE/FE/SO/RO) boleh kalau match case
        if (!$this->allowRoles($user, $this->rolesProposer())) return false;

        $case->loadMissing('loanAccount');

        // 1) PIC case boleh (paling stabil)
        if (!empty($case->pic_user_id) && (int)$case->pic_user_id === (int)$user->id) {
            return true;
        }

        // 2) Cocokkan ao_code loanAccount dengan user (handle leading zero)
        $aoCode = $case->loanAccount?->ao_code;
        if (!$aoCode) return false;

        $norm = function ($v) {
            $v = trim((string)$v);
            // normalisasi: buang spasi, uppercase, buang leading zero
            $v = strtoupper($v);
            $v = ltrim($v, '0');
            return $v;
        };

        return $norm($user->username) === $norm($aoCode)
            || $norm($user->employee_code ?? '') === $norm($aoCode);
    }

    public function forceCreateByKti(User $user, NplCase $case): bool
    {
        // ✅ mode awal saja: bisa dimatikan via .env
        // .env: CRMS_KTI_FORCE_TARGET=1  (default: false)
        if (!config('crms.kti_force_target', false)) {
            return false;
        }

        // ✅ hanya KTI (dan opsional Kabag TI kalau ada di enum)
        // Kalau enum kamu belum punya KABAG_TI, hapus saja baris itu.
        return $this->allowRoles($user, [
            UserRole::KTI,
            // UserRole::KABAG_TI, // uncomment kalau enum ada
        ]);
    }

    /**
     * Leader scope check berbasis org_assignments.
     * - staff_user_id = proposed_by (kolom: user_id)
     * - leader_id     = user->id
     * - leader_role   = role atasan (kolom: leader_role)
     * - active range  = scopeActive()
     */
    protected function withinLeaderScope(User $leader, $staffUserId, array $leaderRoles): bool
    {
        $leaderId = (int) $leader->id;
        $staffId  = (int) ($staffUserId ?? 0);
        if ($leaderId <= 0 || $staffId <= 0) return false;

        // boleh untuk data milik sendiri
        if ($leaderId === $staffId) return true;

        // roleValue login (TL/TLL/TLF/TLR)
        $roleVal = method_exists($leader, 'roleValue') ? strtoupper((string) $leader->roleValue()) : '';

        // ✅ FIX: mapping varian TL -> tetap izinkan leader_role='TL'
        // jadi kalau roleVal = TLL, query tetap match row yang leader_role = TL
        $roleVals = array_values(array_unique(array_filter([
            'TL',        // <-- selalu izinkan TL sebagai leader_role dasar
            $roleVal,    // <-- dan izinkan value aktual (TLL/TLF/TLR) kalau memang ada
        ])));

        return \App\Models\OrgAssignment::query()
            ->active()
            ->where('user_id', $staffId)
            ->where('leader_id', $leaderId)
            ->whereIn('leader_role', $roleVals)
            ->exists();
    }

}
