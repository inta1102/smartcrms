<?php

namespace App\Services\Org;

use App\Enums\UserRole;
use App\Models\AoMapping;
use App\Models\LoanAccount;
use App\Models\OrgAssignment;
use App\Models\User;
use Carbon\Carbon;


class OrgVisibilityService
{
    /**
     * Backward compatible:
     * Mengembalikan daftar user_id yang visible untuk user $me.
     */
    public function visibleUserIds(User $me): array
    {
        $selfId = (int) $me->id;

        // =========================
        // 0) Enum role kalau tersedia
        // =========================
        $roleEnum = null;
        if (method_exists($me, 'role')) {
            $roleEnum = $me->role(); // UserRole|null
        }

        // =========================
        // 1) TOP MANAGEMENT (KABAG/DIR/KOM/PE) -> semua user
        // =========================
        // A) lewat enum (jika role() ada)
        if ($roleEnum instanceof UserRole) {
            if (in_array($roleEnum, [
                UserRole::KABAG, UserRole::KBL, UserRole::KBO, UserRole::KTI, UserRole::KBF, UserRole::PE,
                UserRole::DIR, UserRole::DIREKSI, UserRole::KOM,
            ], true)) {
                return User::query()->pluck('id')->map(fn($v) => (int) $v)->all();
            }
        }

        // B) fallback string role (hasAnyRole)
        if (method_exists($me, 'hasAnyRole') && $me->hasAnyRole([
            'KABAG', 'KBL', 'KBO', 'KTI', 'KBF', 'PE',
            'DIR', 'DIREKSI', 'KOM',
        ])) {
            return User::query()->pluck('id')->map(fn($v) => (int) $v)->all();
        }

        // =========================
        // 2) Pelaksana: AO/BE/FE/SO/RO/SA -> hanya diri sendiri
        // =========================
        if (method_exists($me, 'hasAnyRole') && $me->hasAnyRole(['AO', 'BE', 'FE', 'SO', 'RO', 'SA'])) {
            return [$selfId];
        }

        // =========================
        // 3) TL: staff langsung (aktif)
        // =========================
        if (method_exists($me, 'hasAnyRole') && $me->hasAnyRole(['TLL', 'TLR', 'TLF', 'TL'])) {
            $staffIds = $this->activeAssignments()
                ->where('leader_id', $selfId)
                ->pluck('user_id')
                ->map(fn($v) => (int) $v);

            return collect([$selfId])->merge($staffIds)->unique()->values()->all();
        }

        // =========================
        // 4) KASI: TL langsung + staff dari TL (2 level)
        // =========================
        if (method_exists($me, 'hasAnyRole') && $me->hasAnyRole(['KSL', 'KSO', 'KSA', 'KSF', 'KSD', 'KSR'])) {
            $tlIds = $this->activeAssignments()
                ->where('leader_id', $selfId)
                ->pluck('user_id')
                ->map(fn($v) => (int) $v);

            $staffIds = $this->activeAssignments()
                ->whereIn('leader_id', $tlIds->all())
                ->pluck('user_id')
                ->map(fn($v) => (int) $v);

            return collect([$selfId])
                ->merge($tlIds)
                ->merge($staffIds)
                ->unique()
                ->values()
                ->all();
        }

        // default aman: diri sendiri
        return [$selfId];
    }

    /**
     * Menghasilkan AO codes (loan_accounts.ao_code) yang visible untuk user $me.
     *
     * Rule:
     * - TOP MANAGEMENT (KABAG/DIR/KOM/PE) => semua AO aktif (A)
     * - selain itu => AO dari bawahan via employee_code -> AoMapping (B)
     */

    public function visibleAoCodes(User $me): array
    {
        $role = method_exists($me, 'role') ? $me->role() : null;

        // TOP MANAGEMENT: ALL AO dari loan_accounts
        if ($role && in_array($role, [
            UserRole::KABAG, UserRole::KBL, UserRole::KBO, UserRole::KTI, UserRole::KBF, UserRole::PE,
            UserRole::DIR, UserRole::DIREKSI, UserRole::KOM,
        ], true)) {
            return LoanAccount::query()
                ->whereNotNull('ao_code')
                ->distinct()
                ->pluck('ao_code')
                ->map(fn($v) => trim((string)$v))
                ->filter()
                ->values()
                ->all();
        }

        // selain top management: ambil ao_code langsung dari users bawahan
        $userIds = $this->visibleUserIds($me);

        return User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('ao_code')
            ->pluck('ao_code')
            ->map(fn($c) => trim((string)$c))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Query builder assignment yang valid (aktif & tidak expired).
     */
    protected function activeAssignments()
    {
        return OrgAssignment::query()
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', now()->toDateString());
            });
    }

    /**
     * Cek apakah AO/proposer berada dalam scope Kasi.
     *
     * @param int $kasiId
     * @param int $userId (AO / proposer)
     */
    public function isWithinKasiScope(int $kasiId, int $userId): bool
    {
        $today = Carbon::today()->toDateString();

        return OrgAssignment::query()
            ->where('leader_id', $kasiId)
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->whereDate('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')
                  ->orWhereDate('effective_to', '>=', $today);
            })
            ->exists();
    }
}
