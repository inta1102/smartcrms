<?php

namespace App\Services\Org;

use App\Enums\UserRole;
use App\Models\AoMapping;
use App\Models\LoanAccount;
use App\Models\OrgAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


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
        $tlRoleStrings = array_map(fn($e) => $e->value, UserRole::tlAll());

        if (method_exists($me, 'hasAnyRole') && $me->hasAnyRole($tlRoleStrings)) {
            $staffIds = $this->activeAssignments()
                ->where('leader_id', $selfId)
                ->pluck('user_id')
                ->map(fn($v) => (int) $v);

            return collect([$selfId])->merge($staffIds)->unique()->values()->all();
        }


        if (method_exists($me, 'hasAnyRole') && $me->hasAnyRole(['KSLU','KSLR','KSFE','KSBE', 'KSO', 'KSA', 'KSF', 'KSD', 'KSR'])) {
            $ids = $this->subordinateUserIdsForKasi($selfId);

            return collect([$selfId])
                ->merge($ids)
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
        if ($kasiId <= 0 || $userId <= 0) return false;
        if ($kasiId === $userId) return true; // opsional

        $ids = $this->subordinateUserIdsForKasi($kasiId);
        return in_array($userId, $ids, true);
    }

    public function subordinateUserIdsForKasi(int $kasiId): array
    {
        // TL langsung di bawah KASI
        $tlIds = $this->activeAssignments()
            ->where('leader_id', $kasiId)
            ->pluck('user_id')
            ->map(fn($v) => (int) $v)
            ->values();

        if ($tlIds->isEmpty()) {
            return [];
        }

        // Staff/AO di bawah TL-TL tsb
        $staffIds = $this->activeAssignments()
            ->whereIn('leader_id', $tlIds->all())
            ->pluck('user_id')
            ->map(fn($v) => (int) $v)
            ->values();

        // Gabung: TL + staff (opsional tambahkan diri sendiri kalau perlu)
        return $tlIds->merge($staffIds)->unique()->values()->all();
    }

    public function directSubordinateUserIds(int $leaderId): array
    {
        return $this->activeAssignments()
            ->where('leader_id', $leaderId)
            ->pluck('user_id')
            ->map(fn($v) => (int) $v)
            ->values()
            ->all();
    }

    public function subordinateBeUserIdsForLeader(int $leaderId, string $periodDate, ?string $unitCode = 'remedial'): array
    {
        return DB::table('org_assignments as oa')
            ->join('users as u', 'u.id', '=', 'oa.user_id')
            ->where('oa.leader_id', $leaderId)
            ->where('oa.is_active', 1)
            ->whereDate('oa.effective_from', '<=', $periodDate)
            ->where(function ($q) use ($periodDate) {
                $q->whereNull('oa.effective_to')
                ->orWhereDate('oa.effective_to', '>=', $periodDate);
            })
            ->when($unitCode, fn($q) => $q->where('oa.unit_code', $unitCode))
            ->whereRaw("UPPER(TRIM(u.level)) = 'BE'")
            ->pluck('u.id')
            ->map(fn($x)=>(int)$x)
            ->values()
            ->all();
    }

    public function subordinateUserIds(int $leaderId, string $periodDate, ?string $unitCode = null): array
    {
        $q = DB::table('org_assignments as oa')
            ->where('oa.leader_id', $leaderId)
            ->where('oa.is_active', 1)
            ->whereDate('oa.effective_from', '<=', $periodDate)
            ->where(function ($qq) use ($periodDate) {
                $qq->whereNull('oa.effective_to')
                   ->orWhereDate('oa.effective_to', '>=', $periodDate);
            });

        if ($unitCode) {
            $q->whereRaw('LOWER(TRIM(oa.unit_code)) = ?', [strtolower(trim($unitCode))]);
        }

        return $q->pluck('oa.user_id')->map(fn($x)=>(int)$x)->values()->all();
    }
}
