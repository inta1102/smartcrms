<?php

namespace App\Policies;

use App\Models\User;
use App\Models\LegalAction;
use App\Enums\UserRole;
use App\Policies\Concerns\AuthorizesByRole;

class LegalActionPolicy
{
    use AuthorizesByRole;

    /**
     * Akses menu daftar / index legal actions.
     * ✅ BE boleh akses menu legal.
     */
    public function viewAny(User $user): bool
    {
        return $this->allowRoles($user, [
            UserRole::BE,               // ✅ Legal staff
            UserRole::AO,
            ...$this->rolesTl(),
            ...$this->rolesKasi(),
            ...$this->rolesKabagPe(),
            UserRole::DIREKSI,
            UserRole::KOM,
        ]);
    }

    /**
     * Lihat detail legal action.
     * ✅ BE boleh melihat action yang terkait "legal case" (is_legal=1)
     * atau action yang terkait case yang dia pegang (pic_user_id).
     */
    public function view(User $user, LegalAction $action): bool
    {
        // management / approver lihat semua
        if ($this->atLeast($user, UserRole::KSL)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // ✅ BE: boleh lihat legal actions untuk legal cases
        if ($this->allowRoles($user, [UserRole::BE])) {
            $case = $action->nplCase;

            // aturan paling aman: case legal ATAU case miliknya
            if (($case->is_legal ?? false) === true) return true;
            if ((int)($case->pic_user_id ?? 0) === (int)$user->id) return true;

            return false;
        }

        // AO: hanya yang terkait case miliknya (kalau relasi ao_user_id ada)
        if ($this->allowRoles($user, [UserRole::AO])) {
            $aoId = $action->nplCase?->ao_user_id ?? $action->npl_case_ao_id ?? null;
            return $this->isSelf($user, $aoId);
        }

        return false;
    }

    /**
     * Create legal action.
     * Saran: biasanya legal action dibuat oleh TL/KASI atau BE.
     * Kalau AO boleh create, boleh tetap dibuka.
     */
    public function create(User $user): bool
    {
        return $this->allowRoles($user, [
            UserRole::BE,               // ✅ legal staff boleh create
            UserRole::AO,               // optional (kalau AO boleh usul legal)
            ...$this->rolesTl(),
            ...$this->rolesKasi(),
            ...$this->rolesKabagPe(),
        ]);
    }

    /**
     * Update data legal action (catatan, tanggal, dokumen, event).
     * ❗Status "closed" hanya management/approver.
     * ✅ BE boleh update untuk action yg legal / case miliknya.
     */
    public function update(User $user, LegalAction $action): bool
    {
        $status = (string)($action->status ?? '');

        // kalau sudah closed/locked, hanya management
        if ($status === 'closed') {
            return $this->atLeast($user, UserRole::KSL);
        }

        // management/approver
        if ($this->atLeast($user, UserRole::KSL)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // ✅ BE: boleh update operasional untuk legal case / case miliknya
        if ($this->allowRoles($user, [UserRole::BE])) {
            $case = $action->nplCase;

            if (($case->is_legal ?? false) === true) return true;
            if ((int)($case->pic_user_id ?? 0) === (int)$user->id) return true;

            return false;
        }

        // AO update hanya draft/prepared
        if ($this->allowRoles($user, [UserRole::AO])) {
            $isDraft = in_array($status, ['draft', 'prepared'], true);
            if (!$isDraft) return false;

            $aoId = $action->nplCase?->ao_user_id ?? null;
            return $this->isSelf($user, $aoId);
        }

        return false;
    }

    /**
     * Update status / transition status legal action.
     * ⛔ BE tidak boleh.
     * ✅ TL/KASI/management saja.
     */
    public function updateStatus(User $user, LegalAction $action, string $toStatus): bool
    {
        return $this->allowRoles($user, [
            ...$this->rolesTl(),
            ...$this->rolesKasi(),
            ...$this->rolesKabagPe(),
            UserRole::DIREKSI,
        ]);
    }

    public function auditHt(User $user, LegalAction $action): bool
    {
        // hanya management/approver
        return $this->allowRoles($user, [
            ...$this->rolesKabagPe(),
            UserRole::DIREKSI,
            UserRole::KOM,
            UserRole::KTI,
            UserRole::KSL,
            UserRole::KSR,
        ]);
    }

    public function updateHt(User $user, LegalAction $action): bool
    {
        if ($action->action_type !== LegalAction::TYPE_HT_EXECUTION) {
            return false;
        }

        if ($this->atLeast($user, UserRole::KSL)) return true;
        if ($this->allowRoles($user, $this->rolesTl())) return true;

        // BE boleh operasional HT
        if ($this->allowRoles($user, [UserRole::BE])) return true;

        return false;
    }

    public function verifyDocument(User $user, LegalAction $action): bool
    {
        // khusus HT execution saja (biar aman)
        if ($action->action_type !== LegalAction::TYPE_HT_EXECUTION) {
            return false;
        }

        // management/approver lihat semua (kalau kamu memang ingin begitu)
        // (opsional) kalau cukup KSL ke atas:
        if ($this->atLeast($user, UserRole::KSL)) {
            return true;
        }

        // TL boleh
        if ($this->allowRoles($user, $this->rolesTl())) {
            return true;
        }

        /**
         * ✅ Kasi boleh VERIFIKASI dokumen HT
         * tapi wajib dalam scope struktur dia.
         * Ini untuk kasus seperti Helmi (AO tanpa TL).
         */
        if ($this->allowRoles($user, $this->rolesKasi())) {
            return app(\App\Services\Org\OrgVisibilityService::class)
                ->isWithinKasiScope((int) $user->id, (int) $action->proposed_by);
        }

        /**
         * ✅ BE (legal staff) kamu tadi bilang boleh operasional HT.
         * Kalau kamu ingin BE juga boleh verify dokumen, buka ini.
         * Kalau tidak, biarkan false.
         */
        // if ($this->allowRoles($user, [UserRole::BE])) {
        //     // kalau mau dibatasi hanya untuk legal case / case miliknya seperti update():
        //     $case = $action->nplCase;
        //     if (($case->is_legal ?? false) === true) return true;
        //     if ((int)($case->pic_user_id ?? 0) === (int)$user->id) return true;
        //     return false;
        // }

        return false;
    }

}
