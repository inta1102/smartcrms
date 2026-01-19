<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use App\Models\OrgAssignment;


class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'level',
        'wa_number',
        'remember_token',
        'ao_code',
        'employee_code',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'level'             => UserRole::class, // ✅ single source of truth
    ];

    // =========================
    // Role core (safe)
    // =========================

    /**
     * Dapatkan role sebagai Enum (aman untuk data lama & null).
     */
    public function role(): ?UserRole
    {
        $lvl = $this->level ?? null;

        // Normal: sudah enum karena cast
        if ($lvl instanceof UserRole) {
            return $lvl;
        }

        // Fallback: kalau ternyata masih string (data lama / cast tidak terpakai)
        if (is_string($lvl) && trim($lvl) !== '') {
            return UserRole::tryFrom(strtoupper(trim($lvl)));
        }

        return null;
    }

    /**
     * Role string untuk kebutuhan UI/query.
     */
    public function roleValue(): string
    {
        return $this->role()?->value ?? UserRole::STAFF->value;
    }

    /**
     * Accessor: $user->role_value
     */
    public function getRoleValueAttribute(): string
    {
        return $this->roleValue();
    }

    // =========================
    // Role checks
    // =========================

    public function hasRole(UserRole|string $role): bool
    {
        $mine = $this->role();
        if (!$mine) return false;

        $target = $role instanceof UserRole
            ? $role
            : UserRole::tryFrom(strtoupper(trim((string) $role)));

        return $target ? $mine === $target : false;
    }

    public function hasAnyRole(array $roles): bool
    {
        $mine = $this->role();
        if (!$mine) return false;

        foreach ($roles as $r) {
            $target = $r instanceof UserRole
                ? $r
                : (is_string($r) ? UserRole::tryFrom(strtoupper(trim($r))) : null);

            if ($target && $mine === $target) return true;
        }

        return false;
    }

    // =========================
    // Convenience helpers (recommended)
    // =========================

    public function isTl(): bool
    {
        return $this->hasAnyRole([UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR]);
    }

    public function isKasi(): bool
    {
        return $this->hasAnyRole([UserRole::KSL, UserRole::KSO, UserRole::KSA, UserRole::KSF, UserRole::KSD, UserRole::KSR]);
    }

    public function isKabagOrPe(): bool
    {
        return $this->hasAnyRole([UserRole::KABAG, UserRole::KBL, UserRole::KBO, UserRole::KTI, UserRole::KBF, UserRole::PE]);
    }

    public function isTopBoard(): bool
    {
        return $this->hasAnyRole([UserRole::DIREKSI, UserRole::KOM, UserRole::DIR]);
    }

    public function isSupervisor(): bool
    {
        // ✅ pakai rule dari enum (lebih rapi daripada list string manual)
        return $this->role()?->isSupervisor() ?? false;
    }

    public function isManagement(): bool
    {
        return $this->role()?->isManagement() ?? false;
    }

    public function isTop(): bool
    {
        return $this->role()?->isTop() ?? false;
    }

    public function rank(): int
    {
        return $this->role()?->rank() ?? UserRole::STAFF->rank();
    }

    public function atLeast(UserRole $min): bool
    {
        return $this->rank() >= $min->rank();
    }

    public function isCollectorStaff(): bool
    {
        // AO / SO / FE / BE masuk kategori lapangan/collector
        return $this->hasAnyRole([UserRole::AO, UserRole::SO, UserRole::FE, UserRole::BE]);
    }

    public function inRoles(array $roles): bool
    {
        // kompatibel: terima array berisi string role ATAU UserRole enum
        return $this->hasAnyRole($roles);
    }

    // User.php
    public function staffAssignments()
    {
        // siapa saja yang leader_id = saya
        return $this->hasMany(\App\Models\OrgAssignment::class, 'leader_id');
    }

    public function leaderAssignments()
    {
        // saya ini bawahan siapa
        return $this->hasMany(\App\Models\OrgAssignment::class, 'user_id');
    }

    public function staffUserIds(): array
    {
        // list user_id bawahan langsung (leader_id = saya)
        return $this->staffAssignments()
            ->pluck('user_id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();
    }

    public function staffUsers()
    {
        // kalau butuh object user nya (buat tabel breakdown)
        return User::query()->whereIn('id', $this->staffUserIds());
    }

    public function isBe(): bool
    {
        return $this->hasAnyRole(['BE']) || ($this->level === 'BE');
    }

    public function isLegal(): bool
    {
        return $this->isBe(); // nanti kalau ada role LEGAL khusus, tinggal extend
    }

    public function canLegal(): bool
    {
        return $this->isLegal() || $this->hasAnyRole(['KBL','KABAG','PE','DIR','DIREKSI']);
    }

    public function isLegalSupervisor(): bool
    {
        return $this->hasAnyRole([
            'TLL',
            'TLR',      // team leader remedial/loan
            'KSL',     // kasi legal (atau role kamu)
            'KSR',
            'KBL',     // kabag lending (opsional)
            'DIREKSI',
            'DIR',
            'KOM',
        ]);
    }


    public function activeOrgAssignment(): ?OrgAssignment
    {
        return OrgAssignment::query()
            ->active()
            ->where('user_id', $this->id)
            ->orderByDesc('effective_from')
            ->first();
    }

    public function directLeaderRole(): ?string
    {
        return $this->activeOrgAssignment()?->leader_role;
    }

    public function hasLeaderTl(): bool
    {
        $role = strtolower((string) $this->directLeaderRole());
        return in_array($role, ['tl','tll','tlr'], true);
    }

    public function directLeaderId(): ?int
    {
        return $this->activeOrgAssignment()?->leader_id;
    }

}
