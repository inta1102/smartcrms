<?php

namespace App\Services\Kpi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\Executive\PicPerformanceKpiService;
use Carbon\Carbon;

class PicHandlingKpiService
{
    protected PicPerformanceKpiService $svc;

    public function __construct(PicPerformanceKpiService $svc)
    {
        $this->svc = $svc;
    }

    public function build(User $user, array $filters = []): array
    {
        return $this->svc->build($user, $filters);
    }
    /**
     * Leaderboard KPI Penanganan PIC berbasis action_schedules.
     *
     * $mode:
     * - completion : score = completion_rate (0..1)
     * - ontime     : score = on_time_rate (0..1) (berdasar done dalam range)
     * - backlog    : score = backlog_cnt (semakin kecil semakin bagus) -> nanti kita invert untuk ranking
     */
    public function leaderboard(
        array $visibleUserIds,
        string $startDate, // 'Y-m-d'
        string $endDate,   // 'Y-m-d'
        string $mode = 'completion',
        int $limit = 5
    ): array {
        $visibleUserIds = array_values(array_unique(array_map('intval', $visibleUserIds)));
        if (empty($visibleUserIds)) {
            return ['mode' => $mode, 'top' => [], 'bottom' => []];
        }

        $cacheKey = 'kpi:pic:' . md5(json_encode([$visibleUserIds, $startDate, $endDate, $mode, $limit]));
        return Cache::remember($cacheKey, 300, function () use ($visibleUserIds, $startDate, $endDate, $mode, $limit) {

            $startDT = $startDate . ' 00:00:00';
            $endDT   = $endDate   . ' 23:59:59';

            // Base: semua schedule yang "relevan periode".
            // Catatan:
            // - Untuk backlog, kita lihat scheduled_at <= endDT dan status pending/escalated.
            // - Untuk completion & ontime, kita hitung done yang completed_at dalam range (lebih adil).
            $rows = match ($mode) {
                'backlog' => $this->queryBacklog($visibleUserIds, $endDT),
                'ontime'  => $this->queryOnTime($visibleUserIds, $startDT, $endDT),
                default   => $this->queryCompletion($visibleUserIds, $startDT, $endDT),
            };

            // Normalisasi ranking:
            // - completion/ontime: semakin besar semakin bagus
            // - backlog: semakin kecil semakin bagus -> kita urutkan asc untuk TOP, desc untuk BOTTOM
            if ($mode === 'backlog') {
                $top    = collect($rows)->sortBy('score')->take($limit)->values()->all();
                $bottom = collect($rows)->sortByDesc('score')->take($limit)->values()->all();
            } else {
                $top    = collect($rows)->sortByDesc('score')->take($limit)->values()->all();
                $bottom = collect($rows)->sortBy('score')->take($limit)->values()->all();
            }

            return [
                'mode'   => $mode,
                'top'    => $top,
                'bottom' => $bottom,
                'debug'  => [
                    'range' => ['start' => $startDT, 'end' => $endDT],
                    'visible_user_ids_count' => count($visibleUserIds),
                ],
            ];
        });
    }

    private function queryCompletion(array $visibleUserIds, string $startDT, string $endDT): array
    {
        // completion: berbasis agenda yang selesai dalam periode, plus total workload yang jatuh dalam periode
        // Kita ambil dua komponen:
        // - done_cnt: done dengan completed_at dalam range
        // - workload_cnt: semua schedule dengan scheduled_at dalam range (pending/escalated/done) -> basis denominator
        $sql = DB::table('action_schedules as s')
            ->join('users as u', 'u.id', '=', 's.assigned_to')
            ->whereNotNull('s.assigned_to')
            ->whereIn('s.assigned_to', $visibleUserIds)
            ->selectRaw('
                s.assigned_to as user_id,
                u.name as user_name,
                SUM(CASE WHEN s.scheduled_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as workload_cnt,
                SUM(CASE WHEN s.status = "done" AND s.completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as done_cnt
            ', [$startDT, $endDT, $startDT, $endDT])
            ->groupBy('s.assigned_to', 'u.name')
            ->get();

        return $sql->map(function ($r) {
            $workload = (int) $r->workload_cnt;
            $done     = (int) $r->done_cnt;

            // Score 0..1 (kalau workload 0, score 0 supaya tetap muncul)
            $score = $workload > 0 ? ($done / $workload) : 0;

            return [
                'user_id'   => (int) $r->user_id,
                'user_name' => $r->user_name,
                'score'     => round($score, 4),
                'done_cnt'  => $done,
                'workload_cnt' => $workload,
            ];
        })->all();
    }

    private function queryOnTime(array $visibleUserIds, string $startDT, string $endDT): array
    {
        $sql = DB::table('action_schedules as s')
            ->join('users as u', 'u.id', '=', 's.assigned_to')
            ->whereNotNull('s.assigned_to')
            ->whereIn('s.assigned_to', $visibleUserIds)
            ->where('s.status', '=', 'done')
            ->whereBetween('s.completed_at', [$startDT, $endDT])
            ->selectRaw('
                s.assigned_to as user_id,
                u.name as user_name,
                COUNT(*) as done_cnt,
                SUM(CASE WHEN s.completed_at <= s.scheduled_at THEN 1 ELSE 0 END) as ontime_cnt
            ')
            ->groupBy('s.assigned_to', 'u.name')
            ->get();

        return $sql->map(function ($r) {
            $done   = (int) $r->done_cnt;
            $ontime = (int) $r->ontime_cnt;
            $score  = $done > 0 ? ($ontime / $done) : 0;

            return [
                'user_id'    => (int) $r->user_id,
                'user_name'  => $r->user_name,
                'score'      => round($score, 4),
                'done_cnt'   => $done,
                'ontime_cnt' => $ontime,
            ];
        })->all();
    }

    private function queryBacklog(array $visibleUserIds, string $endDT): array
    {
        $sql = DB::table('action_schedules as s')
            ->join('users as u', 'u.id', '=', 's.assigned_to')
            ->whereNotNull('s.assigned_to')
            ->whereIn('s.assigned_to', $visibleUserIds)
            ->whereIn('s.status', ['pending', 'escalated'])
            ->where('s.scheduled_at', '<=', $endDT)
            ->selectRaw('
                s.assigned_to as user_id,
                u.name as user_name,
                COUNT(*) as backlog_cnt
            ')
            ->groupBy('s.assigned_to', 'u.name')
            ->get();

        return $sql->map(fn ($r) => [
            'user_id'     => (int) $r->user_id,
            'user_name'   => $r->user_name,
            'score'       => (int) $r->backlog_cnt, // backlog kecil = bagus
            'backlog_cnt' => (int) $r->backlog_cnt,
        ])->all();
    }

    public function leaderboardCompletion(Carbon $start, Carbon $end, int $limit = 5): array
    {
        return $this->svc->leaderboardCompletion($start, $end, $limit);
    }
}
