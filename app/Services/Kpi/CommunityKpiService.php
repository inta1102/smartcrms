<?php

namespace App\Services\Kpi;

use Illuminate\Support\Facades\DB;

class CommunityKpiService
{
    /**
     * Hitung actual komunitas per user untuk periode (startOfMonth Y-m-d)
     * role: 'AO' atau 'SO'
     * return: [user_id => distinct_community_count]
     */
    public function actualByRoleForPeriod(string $periodYmd, string $role): array
    {
        $rows = DB::table('community_handlings')
            ->selectRaw('user_id, COUNT(DISTINCT community_id) as cnt')
            ->where('role', $role)
            ->whereDate('period_from', '<=', $periodYmd)
            ->where(function ($q) use ($periodYmd) {
                $q->whereNull('period_to')
                  ->orWhereDate('period_to', '>=', $periodYmd);
            })
            ->groupBy('user_id')
            ->get();

        $map = [];
        foreach ($rows as $r) $map[(int)$r->user_id] = (int)$r->cnt;
        return $map;
    }
}
