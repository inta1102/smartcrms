<?php

namespace App\Policies;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KpiRoPolicy
{
    public function view(User $viewer, User $target): bool
    {
        // 0) Guard: hanya untuk target RO
        $targetLevel = strtoupper(trim((string)($target->level instanceof \BackedEnum ? $target->level->value : $target->level)));
        if ($targetLevel !== 'RO') return false;

        // 1) self selalu boleh
        if ((int)$viewer->id === (int)$target->id) return true;

        // ✅ 2) definisikan role viewer dulu (ini yg bikin error kemarin)
        $role = $this->resolveViewerRole($viewer);

        // 3) management/admin roles: boleh lihat semua RO
        if (in_array($role, ['DIR','DIREKSI','KOM','PE','KABAG','KBL','KSLU','KSLR','KSFE','KSBE','KTI'], true)) {
            return true;
        }

        // 4) TL layer (TLRO, TL*, dll): scope via org_assignments (leader_id -> user_id)
        if (str_starts_with($role, 'TL')) {
            $periodYmd = $this->resolvePeriodYmdFromRequest();

            return DB::table('org_assignments as oa')
                ->where('oa.leader_id', (int)$viewer->id)
                ->where('oa.user_id', (int)$target->id)
                ->where('oa.is_active', 1)
                ->whereDate('oa.effective_from', '<=', $periodYmd)
                ->where(function ($q) use ($periodYmd) {
                    $q->whereNull('oa.effective_to')
                    ->orWhereDate('oa.effective_to', '>=', $periodYmd);
                })
                ->exists();
        }

        return false;
    }

    private function resolveUserRole(User $u): string
    {
        // prioritas roleValue() bila ada
        $raw = null;
        if (method_exists($u, 'roleValue')) {
            $raw = $u->roleValue();
        }
        $raw = $raw ?? ($u->role ?? null) ?? ($u->level ?? '');

        if ($raw instanceof \BackedEnum) $raw = $raw->value;
        return strtoupper(trim((string)$raw));
    }

    private function resolvePeriodYmdFromRequest(): string
    {
        $raw = trim((string) request()->query('period', ''));
        try {
            if ($raw === '') return now()->startOfMonth()->toDateString();
            if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
                return Carbon::createFromFormat('Y-m', $raw)->startOfMonth()->toDateString();
            }
            return Carbon::parse($raw)->startOfMonth()->toDateString();
        } catch (\Throwable $e) {
            return now()->startOfMonth()->toDateString();
        }
    }

    private function roleAliases(string $role): array
    {
        $r1 = strtolower(trim($role));
        $r2 = strtolower(trim(str_replace(' ', '', $role)));
        return array_values(array_unique([$r1, $r2]));
    }

    private function resolveViewerRole(User $viewer): string
    {
        // kalau kamu punya method roleValue()
        $raw = method_exists($viewer, 'roleValue')
            ? $viewer->roleValue()
            : ($viewer->level ?? '');

        if ($raw instanceof \BackedEnum) {
            $raw = $raw->value;
        }

        return strtoupper(trim((string)$raw));
    }
}