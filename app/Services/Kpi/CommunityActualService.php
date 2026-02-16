<?php

namespace App\Services\Kpi;

use Illuminate\Support\Facades\DB;

class CommunityActualService
{
    /**
     * Return map: [user_id => actual_count]
     * - role: 'AO' or 'SO'
     * - periodYmd: 'YYYY-MM-01'
     */
    public function actualByUser(string $role, string $periodYmd, ?array $userIds = null): array
    {
        $q = DB::table('community_handlings')
            ->select('user_id', DB::raw('COUNT(DISTINCT community_id) as cnt'))
            ->where('role', strtoupper($role))
            ->whereDate('period_from', '<=', $periodYmd)
            ->where(function ($qq) use ($periodYmd) {
                $qq->whereNull('period_to')
                   ->orWhereDate('period_to', '>=', $periodYmd);
            })
            ->groupBy('user_id');

        if (!empty($userIds)) {
            $q->whereIn('user_id', $userIds);
        }

        return $q->pluck('cnt', 'user_id')->map(fn($v) => (int)$v)->all();
    }

    public function actualForUser(string $role, string $periodYmd, int $userId): int
    {
        $map = $this->actualByUser($role, $periodYmd, [$userId]);
        return (int)($map[$userId] ?? 0);
    }
}
