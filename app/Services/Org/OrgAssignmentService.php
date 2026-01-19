<?php

namespace App\Services\Org;

use App\Models\OrgAssignment;
use Illuminate\Support\Facades\DB;

class OrgAssignmentService
{
    public function assign(
        int $userId,
        int $leaderId,
        string $leaderRole,
        ?string $unitCode,
        string $effectiveFrom,
        ?int $createdBy = null
    ): OrgAssignment {
        return DB::transaction(function () use ($userId,$leaderId,$leaderRole,$unitCode,$effectiveFrom,$createdBy) {

            // 1) tutup assignment aktif sebelumnya untuk user+unit yang sama
            OrgAssignment::active()
                ->where('user_id', $userId)
                ->when($unitCode !== null, fn($q) => $q->where('unit_code', $unitCode),
                                  fn($q) => $q->whereNull('unit_code'))
                ->update([
                    'is_active' => 0,
                    'effective_to' => now()->toDateString(),
                    'active_key' => null,
                ]);

            // 2) buat record baru
            $row = OrgAssignment::create([
                'user_id' => $userId,
                'leader_id' => $leaderId,
                'leader_role' => $leaderRole,
                'unit_code' => $unitCode,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'is_active' => 1,
                'created_by' => $createdBy,
            ]);

            // 3) set active_key untuk yang aktif
            $row->active_key = $this->makeActiveKey($row);
            $row->save();

            return $row;
        });
    }

    public function makeActiveKey(OrgAssignment $a): string
    {
        return $a->user_id.'|'.$a->leader_id.'|'.($a->unit_code ?? '').'|'.($a->leader_role ?? '');
    }

    public static function leaderOf(int $userId, ?string $unit = null)
    {
        return OrgAssignment::query()
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->when($unit, fn($q) => $q->where('unit_code', $unit))
            ->first();
    }

    public static function subordinatesOf(int $leaderId, ?string $unit = null)
    {
        return OrgAssignment::query()
            ->where('leader_id', $leaderId)
            ->where('is_active', 1)
            ->when($unit, fn($q) => $q->where('unit_code', $unit))
            ->pluck('user_id');
    }
}
