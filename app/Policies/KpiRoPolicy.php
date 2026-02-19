<?php

namespace App\Policies;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class KpiRoPolicy
{
    public function view(User $viewer, User $target): bool
    {
        // 0) Guard: hanya untuk target RO (anti-bocor akses lewat URL /kpi/ro/{id})
        $targetLevel = strtoupper(trim((string)($target->level instanceof \BackedEnum ? $target->level->value : $target->level)));
        if ($targetLevel !== 'RO') return false;

        // 1) self selalu boleh
        if ((int)$viewer->id === (int)$target->id) return true;

        // 2) role viewer (aman untuk enum/string)
        $role = $this->resolveViewerRole($viewer);

        // 3) management/admin roles: boleh lihat semua RO
        if (in_array($role, ['DIR','DIREKSI','KOM','PE','KABAG','KBL','KSLU','KSLR','KSFE','KSBE','KTI'], true)) {
            return true;
        }

        // 4) TL: hanya bawahan sesuai org_assignments + leader_role + effective range
        if (str_starts_with($role, 'TL')) {
            $periodYmd = $this->resolvePeriodYmdFromRequest(); // startOfMonth Y-m-d

            // role alias: "TLRO", "TL RO", "tlro ", dll
            $aliases = $this->roleAliases($role);

            return DB::table('org_assignments as oa')
                ->where('oa.leader_id', (int)$viewer->id)
                ->where('oa.user_id', (int)$target->id)
                ->where('oa.is_active', 1)
                ->whereIn(DB::raw('LOWER(TRIM(oa.leader_role))'), $aliases)
                ->whereDate('oa.effective_from', '<=', $periodYmd)
                ->where(function ($q) use ($periodYmd) {
                    $q->whereNull('oa.effective_to')
                      ->orWhereDate('oa.effective_to', '>=', $periodYmd);
                })
                ->exists();
        }

        // default deny
        return false;
    }

    private function resolveViewerRole(User $viewer): string
    {
        // prioritas roleValue() bila ada
        $raw = $viewer->roleValue() ?? $viewer->level ?? '';
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
}
