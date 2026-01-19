<?php

namespace App\Policies;

use App\Models\User;
use App\Models\CaseResolutionTarget;
use App\Enums\UserRole;
use App\Policies\Concerns\AuthorizesByRole;
use App\Models\NplCase;


class CaseResolutionTargetPolicy
{
    use AuthorizesByRole;

    public function view(User $user, CaseResolutionTarget $target): bool
    {
        if ($this->atLeast($user, UserRole::KSL)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // AO hanya yang dia propose
        return $this->isSelf($user, $target->proposed_by ?? null);
    }

    public function create(User $user): bool
    {
        return $this->allowRoles($user, [
            ...$this->rolesProposer(),
            ...$this->rolesTl(),
            ...$this->rolesKasi(),
        ]);
    }


    public function update(User $user, CaseResolutionTarget $target): bool
    {
        // kalau sudah approved final, jangan bisa update
        if (in_array(strtolower((string)$target->status), ['active', 'superseded'], true)) {
            return false;
        }

        if ($this->atLeast($user, UserRole::KSL)) return true;

        // TL boleh update selama belum approve
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // AO hanya yang dia propose
        return $this->isSelf($user, $target->proposed_by ?? null);
    }

    public function approveTl(User $user, CaseResolutionTarget $target): bool
    {
        return $this->allowRoles($user, $this->rolesTl());
    }

    public function approveKasi(User $user, CaseResolutionTarget $target): bool
    {
        return $this->allowRoles($user, $this->rolesKasi());
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

}
