<?php

namespace App\Services\Executive;

use App\Models\ActionSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PicPerformanceKpiService
{
    /**
     * KPI Penanganan PIC berbasis action_schedules (fokus CRMS).
     *
     * Output:
     * - summary cards (total, done, pending, overdue, escalated, rates)
     * - leaderboard (top/bottom by completion_rate atau risk)
     * - debug optional
     */
    public function build(User $user, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        [$startDate, $endDate] = $this->resolveDateRange($filters);

        // Visibility PIC: pakai OrgVisibilityService kalau ada, fallback aman = diri sendiri
        $visibleUserIds = $this->visibleUserIdsForUser($user);

        // Optional: filter PIC tertentu
        if (!empty($filters['pic_user_id'])) {
            $visibleUserIds = array_values(array_intersect($visibleUserIds, [(int)$filters['pic_user_id']]));
        }

        if (empty($visibleUserIds)) {
            return $this->emptyPayload($filters, $startDate, $endDate);
        }

        $cacheKey = $this->cacheKey($user, $filters, $startDate, $endDate, $visibleUserIds);

        return Cache::remember($cacheKey, 180, function () use ($filters, $startDate, $endDate, $visibleUserIds) {

            // =========================
            // Base query: schedules assigned ke PIC
            // =========================
            $base = ActionSchedule::query()
                ->whereNotNull('assigned_to')
                ->whereIn('assigned_to', $visibleUserIds);

            // Filter rentang berdasarkan scheduled_at (konsisten KPI agenda)
            // pakai full datetime boundary biar aman
            $base->whereBetween('scheduled_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ]);

            // Kalau kamu punya filter unit di schedule (misal ada unit_code) bisa ditambah di sini
            // if (!empty($filters['unit']) && $this->hasColumn('action_schedules', 'unit_code')) {
            //     $base->where('unit_code', $filters['unit']);
            // }

            // =========================
            // 1) Summary cards
            // =========================
            $summary = $this->summaryCards(clone $base);

            // =========================
            // 2) Leaderboard PIC
            // =========================
            $leaderboard = $this->leaderboardPic(clone $base, $filters);

            // =========================
            // 3) Attention lists (optional tapi berguna)
            // =========================
            $attention = [
                'overdue_schedules'   => $this->topOverdueSchedules(clone $base, 10),
                'escalated_schedules' => $this->topEscalatedSchedules(clone $base, 10),
            ];

            return [
                'filters' => $filters,
                'range'   => ['start' => $startDate, 'end' => $endDate],
                'summary' => $summary,
                'leaderboard' => $leaderboard,
                'attention'   => $attention,

                // debug ringan (boleh kamu matikan di blade)
                'debug' => [
                    'visible_user_ids_count' => count($visibleUserIds),
                    'visible_user_ids_sample' => array_slice($visibleUserIds, 0, 10),
                ],
            ];
        });
    }

    // =========================================================
    // Summary
    // =========================================================
    protected function summaryCards($base): array
    {
        // total by status
        $counts = (clone $base)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) as escalated_count
            ")
            ->first();

        $total      = (int)($counts->total ?? 0);
        $done       = (int)($counts->done_count ?? 0);
        $pending    = (int)($counts->pending_count ?? 0);
        $cancelled  = (int)($counts->cancelled_count ?? 0);
        $escalated  = (int)($counts->escalated_count ?? 0);

        // Overdue = pending & scheduled_at < now
        $overdue = (clone $base)
            ->where('status', 'pending')
            ->where('scheduled_at', '<', now())
            ->count();

        // Ontime = done dan completed_at <= scheduled_at
        $ontime = (clone $base)
            ->where('status', 'done')
            ->whereNotNull('completed_at')
            ->whereColumn('completed_at', '<=', 'scheduled_at')
            ->count();

        // avg_lateness_days = rata2 keterlambatan (khusus done yang terlambat)
        $avgLateDays = (clone $base)
            ->where('status', 'done')
            ->whereNotNull('completed_at')
            ->whereColumn('completed_at', '>', 'scheduled_at')
            ->selectRaw("AVG(TIMESTAMPDIFF(HOUR, scheduled_at, completed_at)/24) as avg_late_days")
            ->value('avg_late_days');

        $denomRate = max($total - $cancelled, 0); // rate jangan dihitung cancelled
        $completionRate = $denomRate > 0 ? round(($done / $denomRate) * 100, 1) : 0.0;
        $ontimeRate     = $done > 0 ? round(($ontime / $done) * 100, 1) : 0.0;
        $overdueRate    = $pending > 0 ? round(($overdue / $pending) * 100, 1) : 0.0;
        $escalationRate = $denomRate > 0 ? round(($escalated / $denomRate) * 100, 1) : 0.0;

        return [
            'total'      => $total,
            'done'       => $done,
            'pending'    => $pending,
            'overdue'    => $overdue,
            'escalated'  => $escalated,
            'cancelled'  => $cancelled,

            'completion_rate' => $completionRate, // % done dari total (exclude cancelled)
            'ontime_rate'     => $ontimeRate,     // % ontime dari done
            'overdue_rate'    => $overdueRate,    // % overdue dari pending
            'escalation_rate' => $escalationRate, // % escalated dari total (exclude cancelled)

            'avg_late_days'   => $avgLateDays !== null ? round((float)$avgLateDays, 2) : null,
        ];
    }

    // =========================================================
    // Leaderboard PIC
    // =========================================================
    protected function leaderboardPic($base, array $filters): array
    {
        // default: ranking by completion_rate (desc)
        // alternatif: ranking risk (escalation_rate + overdue_rate tinggi)
        $mode = $filters['leaderboard_mode'] ?? 'completion'; // completion|risk|ontime

        $rows = (clone $base)
            ->selectRaw("
                assigned_to as pic_user_id,
                COUNT(*) as total,
                SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) as done_count,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN status='escalated' THEN 1 ELSE 0 END) as escalated_count,
                SUM(CASE WHEN status='pending' AND scheduled_at < NOW() THEN 1 ELSE 0 END) as overdue_count,
                SUM(CASE WHEN status='done' AND completed_at IS NOT NULL AND completed_at <= scheduled_at THEN 1 ELSE 0 END) as ontime_count
            ")
            ->groupBy('assigned_to')
            ->get();

        if ($rows->isEmpty()) {
            return ['top' => [], 'bottom' => []];
        }

        // hydrate nama PIC
        $userMap = User::query()
            ->whereIn('id', $rows->pluck('pic_user_id')->all())
            ->pluck('name', 'id')
            ->all();

        $calc = $rows->map(function ($r) use ($userMap) {
            $total     = (int)$r->total;
            $done      = (int)$r->done_count;
            $pending   = (int)$r->pending_count;
            $cancelled = (int)$r->cancelled_count;
            $escalated = (int)$r->escalated_count;
            $overdue   = (int)$r->overdue_count;
            $ontime    = (int)$r->ontime_count;

            $denom = max($total - $cancelled, 0);

            $completion = $denom > 0 ? ($done / $denom) : 0.0;
            $ontimeRate = $done > 0 ? ($ontime / $done) : 0.0;
            $escalRate  = $denom > 0 ? ($escalated / $denom) : 0.0;
            $overdueRate= $pending > 0 ? ($overdue / $pending) : 0.0;

            // skor risk sederhana (bisa kamu tweak)
            $riskScore  = ($escalRate * 0.6) + ($overdueRate * 0.4);

            return [
                'pic_user_id' => (int)$r->pic_user_id,
                'pic_name'    => $userMap[$r->pic_user_id] ?? ('User#'.$r->pic_user_id),

                'total'       => $total,
                'done'        => $done,
                'pending'     => $pending,
                'overdue'     => $overdue,
                'escalated'   => $escalated,
                'cancelled'   => $cancelled,

                'completion_rate' => round($completion * 100, 1),
                'ontime_rate'     => round($ontimeRate * 100, 1),
                'escalation_rate' => round($escalRate * 100, 1),
                'overdue_rate'    => round($overdueRate * 100, 1),

                'risk_score'      => round($riskScore * 100, 1),
            ];
        });

        // Sorting mode
        $sorted = match ($mode) {
            'ontime'     => $calc->sortByDesc('ontime_rate')->values(),
            'risk'       => $calc->sortByDesc('risk_score')->values(), // risk tinggi = buruk
            default      => $calc->sortByDesc('completion_rate')->values(),
        };

        // TOP dan BOTTOM
        $top = $sorted->take(5)->values()->all();

        // bottom untuk completion/ontime = paling rendah
        // bottom untuk risk = paling tinggi (yang buruk)
        if ($mode === 'risk') {
            $bottom = $sorted->take(5)->values()->all(); // risk: tampilkan 5 paling risk di top juga cukup
            // kalau kamu mau bottom risk = paling rendah risk:
            // $bottom = $sorted->reverse()->take(5)->values()->all();
        } else {
            $bottom = $sorted->reverse()->take(5)->values()->all();
        }

        return [
            'mode' => $mode,
            'top' => $top,
            'bottom' => $bottom,
        ];
    }

    // =========================================================
    // Attention lists
    // =========================================================
    protected function topOverdueSchedules($base, int $limit = 10): array
    {
        return (clone $base)
            ->where('status', 'pending')
            ->where('scheduled_at', '<', now())
            ->join('users as u', 'u.id', '=', 'action_schedules.assigned_to')
            ->select([
                'action_schedules.id',
                'action_schedules.npl_case_id',
                'action_schedules.title',
                'action_schedules.scheduled_at',
                'u.id as pic_user_id',
                'u.name as pic_name',
            ])
            ->orderBy('action_schedules.scheduled_at', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn($r) => [
                'id' => (int)$r->id,
                'npl_case_id' => (int)$r->npl_case_id,
                'title' => $r->title,
                'scheduled_at' => $r->scheduled_at,
                'pic_user_id' => (int)$r->pic_user_id,
                'pic_name' => $r->pic_name,
            ])
            ->all();
    }

    protected function topEscalatedSchedules($base, int $limit = 10): array
    {
        return (clone $base)
            ->where('status', 'escalated')
            ->join('users as u', 'u.id', '=', 'action_schedules.assigned_to')
            ->select([
                'action_schedules.id',
                'action_schedules.npl_case_id',
                'action_schedules.title',
                'action_schedules.scheduled_at',
                'action_schedules.escalated_at',
                'u.id as pic_user_id',
                'u.name as pic_name',
            ])
            ->orderBy('action_schedules.escalated_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn($r) => [
                'id' => (int)$r->id,
                'npl_case_id' => (int)$r->npl_case_id,
                'title' => $r->title,
                'scheduled_at' => $r->scheduled_at,
                'escalated_at' => $r->escalated_at,
                'pic_user_id' => (int)$r->pic_user_id,
                'pic_name' => $r->pic_name,
            ])
            ->all();
    }

    // =========================================================
    // Visibility (PIC)
    // =========================================================
    protected function visibleUserIdsForUser(User $me): array
    {
        try {
            if (app()->bound(\App\Services\Org\OrgVisibilityService::class)) {
                /** @var \App\Services\Org\OrgVisibilityService $svc */
                $svc = app(\App\Services\Org\OrgVisibilityService::class);
                // visibleUserIds() harus sudah beres role-check di User model
                $ids = $svc->visibleUserIds($me);
                return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [(int)$me->id];
            }
        } catch (\Throwable $e) {
            // fallback aman
        }
        return [(int)$me->id];
    }

    // =========================================================
    // Filters + Date Range
    // =========================================================
    protected function normalizeFilters(array $filters): array
    {
        return [
            // range: mtd | month | custom
            'range' => $filters['range'] ?? 'mtd',

            // custom date
            'start' => $filters['start'] ?? null,
            'end'   => $filters['end'] ?? null,

            // optional filter PIC
            'pic_user_id' => $filters['pic_user_id'] ?? null,

            // leaderboard mode: completion | ontime | risk
            'leaderboard_mode' => $filters['leaderboard_mode'] ?? 'completion',
        ];
    }

    protected function resolveDateRange(array $filters): array
    {
        $range = $filters['range'] ?? 'mtd';

        if ($range === 'custom' && !empty($filters['start']) && !empty($filters['end'])) {
            $start = Carbon::parse($filters['start'])->format('Y-m-d');
            $end   = Carbon::parse($filters['end'])->format('Y-m-d');
            return [$start, $end];
        }

        // month (pakai bulan berjalan)
        if ($range === 'month') {
            $start = now()->startOfMonth()->format('Y-m-d');
            $end   = now()->endOfMonth()->format('Y-m-d');
            return [$start, $end];
        }

        // default mtd
        $start = now()->startOfMonth()->format('Y-m-d');
        $end   = now()->endOfMonth()->format('Y-m-d');
        return [$start, $end];
    }

    protected function cacheKey(User $user, array $filters, string $start, string $end, array $visibleUserIds): string
    {
        $v = implode(',', array_slice($visibleUserIds, 0, 200)); // batasi
        return 'exec.pic_kpi:' . md5(json_encode([
            'u' => $user->id,
            'f' => $filters,
            's' => $start,
            'e' => $end,
            'v' => $v,
        ]));
    }

    protected function emptyPayload(array $filters, string $start, string $end): array
    {
        return [
            'filters' => $filters,
            'range' => ['start' => $start, 'end' => $end],
            'summary' => [
                'total' => 0, 'done' => 0, 'pending' => 0, 'overdue' => 0, 'escalated' => 0, 'cancelled' => 0,
                'completion_rate' => 0.0, 'ontime_rate' => 0.0, 'overdue_rate' => 0.0, 'escalation_rate' => 0.0,
                'avg_late_days' => null,
            ],
            'leaderboard' => ['mode' => $filters['leaderboard_mode'] ?? 'completion', 'top' => [], 'bottom' => []],
            'attention' => ['overdue_schedules' => [], 'escalated_schedules' => []],
            'debug' => ['visible_user_ids_count' => 0, 'visible_user_ids_sample' => []],
        ];
    }

    // Kalau butuh cek kolom, nanti kita tambah helper hasColumn (opsional)

    public function leaderboardCompletion(Carbon $start, Carbon $end, int $limit = 5): array
    {
        $rows = DB::table('action_schedules as s')
            ->join('users as u', 'u.id', '=', 's.assigned_to')
            ->whereNotNull('s.assigned_to')
            ->whereBetween('s.scheduled_at', [$start, $end])
            ->selectRaw('
                s.assigned_to as user_id,
                u.name as name,
                SUM(CASE WHEN s.status = "done" THEN 1 ELSE 0 END) as done_cnt,
                COUNT(*) as total_cnt
            ')
            ->groupBy('s.assigned_to', 'u.name')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'mode' => 'completion',
                'top' => [],
                'bottom' => [],
            ];
        }

        $scored = $rows->map(function ($r) {
            $total = (int) $r->total_cnt;
            $done  = (int) $r->done_cnt;
            $rate  = $total > 0 ? round(($done / $total) * 100, 2) : 0.0;

            return [
                'user_id' => (int) $r->user_id,
                'name'    => (string) $r->name,
                'done'    => $done,
                'total'   => $total,
                'rate'    => $rate,
            ];
        });

        // =========================
        // FALLBACK B: kalau semua rate sama (contoh: semua 0%)
        // TOP = total terbesar (beban kerja)
        // BOTTOM = total terkecil (beban kerja)
        // + anti-overlap
        // =========================
        $uniqueRates = $scored->pluck('rate')->unique()->values();
        $allSameRate = $uniqueRates->count() === 1;

        if ($allSameRate) {
            $top = $scored->sort(function ($a, $b) {
                // total desc, tie-breaker name asc
                return [$b['total'], $a['name']] <=> [$a['total'], $b['name']];
            })->take($limit)->values();

            $topIds = $top->pluck('user_id')->all();

            $bottom = $scored
                ->reject(fn ($r) => in_array($r['user_id'], $topIds, true))
                ->sort(function ($a, $b) {
                    // total asc, tie-breaker name asc
                    return [$a['total'], $a['name']] <=> [$b['total'], $b['name']];
                })
                ->take($limit)
                ->values();

            // kalau PIC sedikit & bottom kurang, isi dari sisa (tetap no-overlap)
            if ($bottom->count() < $limit) {
                $need = $limit - $bottom->count();
                $fill = $scored
                    ->reject(fn ($r) => in_array($r['user_id'], array_merge($topIds, $bottom->pluck('user_id')->all()), true))
                    ->sortBy('name')
                    ->take($need)
                    ->values();

                $bottom = $bottom->concat($fill)->values();
            }

            return [
                'mode' => 'completion',
                'top' => $top->all(),
                'bottom' => $bottom->all(),
            ];
        }

        // =========================
        // MODE NORMAL (A): rate beda-beda
        // TOP = rate tertinggi, tie-breaker done, total, name
        // BOTTOM = rate terendah, tie-breaker total besar (biar “kerja banyak tapi jelek” kebaca)
        // + anti-overlap
        // =========================
        $top = $scored->sort(function ($a, $b) {
            return [$b['rate'], $b['done'], $b['total'], $a['name']]
                <=> [$a['rate'], $a['done'], $a['total'], $b['name']];
        })->take($limit)->values();

        $topIds = $top->pluck('user_id')->all();

        $bottomPool = $scored->reject(fn ($r) => in_array($r['user_id'], $topIds, true));

        $bottom = $bottomPool->sort(function ($a, $b) {
            return [$a['rate'], -$a['total'], $a['name']]
                <=> [$b['rate'], -$b['total'], $b['name']];
        })->take($limit)->values();

        if ($bottom->count() < $limit) {
            $need = $limit - $bottom->count();
            $fill = $scored
                ->reject(fn ($r) => in_array($r['user_id'], array_merge($topIds, $bottom->pluck('user_id')->all()), true))
                ->sortBy('name')
                ->take($need)
                ->values();

            $bottom = $bottom->concat($fill)->values();
        }

        return [
            'mode' => 'completion',
            'top' => $top->all(),
            'bottom' => $bottom->all(),
        ];
    }


}
