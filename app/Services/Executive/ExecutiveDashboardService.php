<?php

namespace App\Services\Executive;

use App\Models\NplCase;
use App\Models\LoanAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ExecutiveDashboardService
{
    /**
     * Build payload dashboard executive (Kabag/Direksi/Komisaris) dalam 1 pintu.
     */
    public function build(User $user, array $filters = []): array
    {
        // 0) Capabilities + normalize filter
        $cap     = $this->capabilities($user);
        $filters = $this->normalizeFilters($filters);

        // 1) Governance: Direksi/KOM tanpa filter granular
        if (!($cap['can_filter_granular'] ?? false)) {
            $filters['ao_code'] = null;
            $filters['unit']    = null;
        }

        // 2) Resolve date range SELALU (biar UI "Periode" tidak kosong)
        [$startDate, $endDate] = $this->resolveDateRange($filters);

        // 3) Visibility AO
        $visibleAoCodes = $this->visibleAoCodesForUser($user, $cap);

        // Debug disiapkan sejak awal, supaya tetap tampil walau empty payload
        $debug = [
            'visibleAoCodes_count'  => count($visibleAoCodes),
            'visibleAoCodes_sample' => array_slice($visibleAoCodes, 0, 10),
            'startDate'             => $startDate,
            'endDate'               => $endDate,
        ];

        // Guard: kalau kosong, return payload kosong + debug + range tetap ada
        if (empty($visibleAoCodes)) {
            $empty = $this->emptyPayload($cap, $filters);
            $empty['range'] = ['start' => $startDate, 'end' => $endDate];
            $empty['debug'] = $debug;
            return $empty;
        }

        // 4) Cache
        $cacheKey   = $this->cacheKey($user, $cap, $filters, $visibleAoCodes, $startDate, $endDate);
        $ttlSeconds = ($cap['is_kabag'] ?? false) ? 180 : 600;

        return Cache::remember($cacheKey, $ttlSeconds, function () use (
            $cap, $filters, $visibleAoCodes, $startDate, $endDate, $debug
        ) {

            // =========================
            // A) Base Query (Case + Loan)
            // =========================
            $base = NplCase::query()
                ->join('loan_accounts as la', 'npl_cases.loan_account_id', '=', 'la.id')
                ->whereIn('la.ao_code', $visibleAoCodes);

            // Filter AO (khusus role yg boleh granular)
            if (!empty($filters['ao_code'])) {
                $base->where('la.ao_code', $filters['ao_code']);
            }

            // Filter unit/cabang (jika kolom tersedia)
            if (!empty($filters['unit']) && $this->hasColumn('loan_accounts', 'unit_code')) {
                $base->where('la.unit_code', $filters['unit']);
            }

            // Filter status case (jika kolom tersedia)
            if (!empty($filters['status']) && $this->hasColumn('npl_cases', 'status')) {
                $base->where('npl_cases.status', $filters['status']);
            }

            // Range by position_date (jika kolom tersedia)
            if ($this->hasColumn('loan_accounts', 'position_date')) {
                $base->whereBetween('la.position_date', [$startDate, $endDate]);
            }

            // =========================
            // B) Snapshot / Trend / Distribution
            // =========================
            $snapshot     = $this->snapshotCards(clone $base, $visibleAoCodes);
            $trend        = $this->trendNplLastMonths($visibleAoCodes, 6);
            $distribution = $this->distributionByStatus(clone $base);

            // =========================
            // C) Attention / Exceptions (case_actions)
            // =========================
            $attention = [
                'overdue_followups' => $this->overdueFollowUps($visibleAoCodes, $cap),
                'stagnant_cases'    => $this->stagnantCases($visibleAoCodes, $cap, 30),
                'legal_cases'       => $this->legalCases($visibleAoCodes, $cap, $startDate, $endDate),
            ];

            // =========================
            // D) Leaderboard AO
            // =========================
            $leaderboard = $this->leaderboardPic($visibleAoCodes, $cap);

            // =========================
            // E) Payload
            // =========================
            return [
                'cap'          => $cap,
                'filters'      => $filters,
                'range'        => ['start' => $startDate, 'end' => $endDate],

                'snapshot'     => $snapshot,
                'trend'        => $trend,
                'distribution' => $distribution,
                'attention'    => $attention,
                'leaderboard'  => $leaderboard,

                'debug'        => $debug,
            ];
        });
    }

    // =========================================================
    // Capabilities / Role
    // =========================================================

    protected function capabilities(User $user): array
    {
        // pakai helper yg sudah ada di User.php
        $isDireksi = method_exists($user, 'hasAnyRole')
            ? $user->hasAnyRole(['DIREKSI','DIR'])
            : (method_exists($user, 'hasRole') ? $user->hasRole('DIREKSI') : false);

        $isKom = method_exists($user, 'hasAnyRole')
            ? $user->hasAnyRole(['KOM'])
            : (method_exists($user, 'hasRole') ? $user->hasRole('KOM') : false);

        $isKabag = method_exists($user, 'hasAnyRole')
            ? $user->hasAnyRole(['KABAG','KBL','KBO','KTI','KBF','PE'])
            : false;

        return [
            'is_kabag'            => $isKabag && !$isDireksi && !$isKom,
            'is_direksi'          => (bool) $isDireksi,
            'is_komisaris'        => (bool) $isKom,

            'can_filter_granular' => $isKabag && !$isDireksi && !$isKom,
            'can_drill_detail'    => $isKabag && !$isDireksi && !$isKom,
            'can_export'          => true,
        ];
    }

    protected function leaderboardPic(array $visibleAoCodes, array $cap): array
    {
        if (empty($visibleAoCodes)) {
            return ['top' => [], 'bottom' => []];
        }

        $q = \App\Models\NplCase::query()
            ->join('loan_accounts as la', 'npl_cases.loan_account_id', '=', 'la.id')
            ->leftJoin('users as u', 'u.id', '=', 'npl_cases.pic_user_id')
            ->whereIn('la.ao_code', $visibleAoCodes);

        // Direksi/KOM boleh full, Kabag boleh filter by unit/ao_code sudah di-handle di base (kalau kamu pakai clone base, ini tetap aman)
        // Kita fokus leaderboard berdasarkan PIC penanganan.

        $rows = $q->selectRaw("
                npl_cases.pic_user_id,
                COALESCE(u.name, 'Belum ditugaskan') as pic_name,
                COUNT(*) as total_cases
            ")
            ->groupBy('npl_cases.pic_user_id', 'u.name')
            ->get();

        if ($rows->isEmpty()) {
            return ['top' => [], 'bottom' => []];
        }

        $list = $rows->map(function ($r) {
            return [
                'pic_user_id' => $r->pic_user_id,
                'pic_name'    => $r->pic_name,
                'label'       => $r->pic_name,
                'total_cases' => (int) $r->total_cases,
            ];
        })->values();

        // top = paling banyak, bottom = paling sedikit (exclude "Belum ditugaskan" dari bottom biar tidak menyesatkan)
        $top = $list->sortByDesc('total_cases')->take(5)->values()->all();

        $bottomPool = $list->filter(fn($x) => $x['pic_user_id'] !== null)->values();
        $bottom = $bottomPool->sortBy('total_cases')->take(5)->values()->all();

        return [
            'top'    => $top,
            'bottom' => $bottom,
        ];
    }

    protected function visibleAoCodesForUser(User $user, array $cap): array
    {
        $codes = [];

        // 1) Ambil dari OrgVisibilityService kalau ada
        if (app()->bound(\App\Services\Org\OrgVisibilityService::class)) {
            $codes = app(\App\Services\Org\OrgVisibilityService::class)->visibleAoCodes($user);
            $codes = $this->normalizeAoCodes((array) $codes);

            if (!empty($codes)) return $codes;
        }

        // Normalisasi kalau ada hasil
        $codes = $this->normalizeAoCodes((array) $codes);

        // 2) Jika masih kosong:
        //    - Kabag: fallback lihat semua AO (sementara)
        //    - Direksi/KOM: lihat semua AO
        //    - Lainnya: kosong (aman)
        if (empty($codes)) {
            if (($cap['is_kabag'] ?? false) || ($cap['is_direksi'] ?? false) || ($cap['is_komisaris'] ?? false)) {
                $codes = LoanAccount::query()
                    ->select('ao_code')
                    ->whereNotNull('ao_code')
                    ->distinct()
                    ->pluck('ao_code')
                    ->filter()
                    ->map(fn($c) => trim((string) $c))
                    ->values()
                    ->all();

                $codes = $this->normalizeAoCodes($codes);
            }
        }

        return $codes;
    }

    // =========================================================
    // Filters / Range
    // =========================================================

    protected function normalizeFilters(array $filters): array
    {
        return [
            'range'   => $filters['range'] ?? 'mtd',
            'start'   => $filters['start'] ?? null,
            'end'     => $filters['end'] ?? null,
            'ao_code' => $filters['ao_code'] ?? null,
            'unit'    => $filters['unit'] ?? null,
            'bucket'  => $filters['bucket'] ?? null, // reserved
            'status'  => $filters['status'] ?? null,
        ];
    }

    protected function resolveDateRange(array $filters): array
    {
        $today = now()->toDateString();

        return match ($filters['range'] ?? 'mtd') {
            'today' => [$today, $today],
            'ytd'   => [now()->startOfYear()->toDateString(), $today],
            'custom'=> [
                Carbon::parse($filters['start'] ?? now()->startOfMonth())->toDateString(),
                Carbon::parse($filters['end'] ?? $today)->toDateString(),
            ],
            default => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
        };
    }

    protected function cacheKey(User $user, array $cap, array $filters, array $visibleAoCodes, string $startDate, string $endDate): string
    {
        $roleKey = $cap['is_direksi'] ? 'DIR' : ($cap['is_komisaris'] ? 'KOM' : 'KABAG');
        $aoHash  = md5(implode('|', $visibleAoCodes));
        $fHash   = md5(json_encode([$filters, $startDate, $endDate]));

        return "exec_dash:v2:{$roleKey}:u{$user->id}:ao{$aoHash}:f{$fHash}";
    }

    protected function emptyPayload(array $cap, array $filters): array
    {
        return [
            'cap'          => $cap,
            'filters'      => $filters,
            'range'        => ['start' => null, 'end' => null],
            'snapshot'     => [
                'total_cases'        => 0,
                'active_cases'       => 0,
                'stagnant_30d'       => 0,
                'overdue_followups'  => 0,
                'npl_nominal'        => null,
                'npl_ratio'          => null,
            ],
            'trend'        => [],
            'distribution' => [],
            'attention'    => [
                'overdue_followups' => [],
                'stagnant_cases'    => [],
                'legal_cases'       => [],
            ],
            'leaderboard'  => [
                'top'    => [],
                'bottom' => [],
            ],
        ];
    }

    // =========================================================
    // Aggregations
    // =========================================================

    protected function snapshotCards($baseQuery, array $visibleAoCodes): array
    {
        $totalCases = (clone $baseQuery)->distinct('npl_cases.id')->count('npl_cases.id');

        $activeCases = $this->hasColumn('npl_cases', 'status')
            ? (clone $baseQuery)->whereIn('npl_cases.status', ['open','in_progress','legal'])
                ->distinct('npl_cases.id')->count('npl_cases.id')
            : $totalCases;

        // nominal outstanding (kalau ada)
        $nplNominal = $this->hasColumn('loan_accounts', 'outstanding')
            ? (clone $baseQuery)->sum('la.outstanding')
            : null;

        // count stagnant 30d (pakai case_actions.action_at)
        $stagnantCount = $this->countStagnantCases($visibleAoCodes, 30);

        // count overdue followups (pakai next_action_due)
        $overdueFollowupsCount = $this->countOverdueFollowUps($visibleAoCodes);

        // ratio butuh total kredit (di luar scope) -> null dulu
        $nplRatio = null;

        return [
            'total_cases'        => (int) $totalCases,
            'active_cases'       => (int) $activeCases,
            'stagnant_30d'       => (int) $stagnantCount,
            'overdue_followups'  => (int) $overdueFollowupsCount,
            'npl_nominal'        => $nplNominal,
            'npl_ratio'          => $nplRatio,
        ];
    }

    protected function countStagnantCases(array $visibleAoCodes, int $days): int
    {
        if (!$this->hasTable('case_actions')) return 0;

        $threshold = now()->subDays($days);

        // last_action per case
        $sub = DB::table('case_actions')
            ->selectRaw('npl_case_id, MAX(action_at) as last_action_at')
            ->groupBy('npl_case_id');

        $rows = DB::table('npl_cases as c')
            ->join('loan_accounts as la', 'la.id', '=', 'c.loan_account_id')
            ->leftJoinSub($sub, 'ca', fn($j) => $j->on('ca.npl_case_id', '=', 'c.id'))
            ->whereIn('la.ao_code', $visibleAoCodes)
            ->where(function ($q) use ($threshold) {
                $q->whereNull('ca.last_action_at')
                  ->orWhere('ca.last_action_at', '<', $threshold);
            })
            ->count('c.id');

        return (int) $rows;
    }

    protected function countOverdueFollowUps(array $visibleAoCodes): int
    {
        if (!$this->hasTable('case_actions')) return 0;

        // Ambil latest row per case untuk next_action_due (biar tidak double)
        $latestPerCase = DB::table('case_actions')
            ->selectRaw('npl_case_id, MAX(action_at) as last_action_at')
            ->groupBy('npl_case_id');

        $rows = DB::table('npl_cases as c')
            ->join('loan_accounts as la', 'la.id', '=', 'c.loan_account_id')
            ->leftJoinSub($latestPerCase, 'last', fn($j) => $j->on('last.npl_case_id', '=', 'c.id'))
            ->leftJoin('case_actions as a', function ($j) {
                $j->on('a.npl_case_id', '=', 'c.id')
                  ->on('a.action_at', '=', 'last.last_action_at');
            })
            ->whereIn('la.ao_code', $visibleAoCodes)
            ->whereNotNull('a.next_action_due')
            ->whereDate('a.next_action_due', '<', now()->toDateString())
            ->count('c.id');

        return (int) $rows;
    }

    protected function trendNplLastMonths(array $visibleAoCodes, int $months = 6): array
    {
        if (!$this->hasColumn('loan_accounts', 'position_date')) return [];

        $start = now()->copy()->startOfMonth()->subMonths($months - 1)->toDateString();
        $end   = now()->copy()->endOfMonth()->toDateString();

        $rows = LoanAccount::query()
            ->selectRaw("DATE_FORMAT(position_date, '%Y-%m') as ym")
            ->selectRaw("COUNT(*) as accounts")
            ->whereIn('ao_code', $visibleAoCodes)
            ->whereBetween('position_date', [$start, $end])
            ->groupBy('ym')
            ->orderBy('ym')
            ->get();

        return $rows->map(fn($r) => [
            'ym'       => $r->ym,
            'accounts' => (int) $r->accounts,
        ])->all();
    }

    protected function distributionByStatus($baseQuery): array
    {
        if (!$this->hasColumn('npl_cases', 'status')) return [];

        $rows = (clone $baseQuery)
            ->selectRaw('npl_cases.status as status')
            ->selectRaw('COUNT(DISTINCT npl_cases.id) as total')
            ->groupBy('npl_cases.status')
            ->orderBy('total', 'desc')
            ->get();

        return $rows->map(fn($r) => [
            'status' => (string) $r->status,
            'total'  => (int) $r->total,
        ])->all();
    }

    // =========================================================
    // Attention: Overdue / Stagnant / Legal
    // =========================================================

    /**
     * Overdue follow up = next_action_due dari LAST action per case sudah lewat hari ini.
     * Ini tidak dobel karena kita ambil only latest action per case.
     */
    protected function overdueFollowUps(array $visibleAoCodes, array $cap, int $limit = 10): array
    {
        if (!$this->hasTable('case_actions')) return [];

        $latestPerCase = DB::table('case_actions')
            ->selectRaw('npl_case_id, MAX(action_at) as last_action_at')
            ->groupBy('npl_case_id');

        $rows = DB::table('npl_cases as c')
            ->join('loan_accounts as la', 'la.id', '=', 'c.loan_account_id')
            ->leftJoinSub($latestPerCase, 'last', fn($j) => $j->on('last.npl_case_id', '=', 'c.id'))
            ->leftJoin('case_actions as a', function ($j) {
                $j->on('a.npl_case_id', '=', 'c.id')
                  ->on('a.action_at', '=', 'last.last_action_at');
            })
            ->whereIn('la.ao_code', $visibleAoCodes)
            ->whereNotNull('a.next_action_due')
            ->whereDate('a.next_action_due', '<', now()->toDateString())
            ->orderBy('a.next_action_due', 'asc')
            ->limit($limit)
            ->get([
                'c.id as case_id',
                'la.ao_code',
                'a.action_type',
                'a.next_action_due',
                'a.action_at as last_action_at',
                'a.user_id',
            ]);

        return $rows->map(function ($r) use ($cap) {
            $item = [
                'case_id'        => (int) $r->case_id,
                'ao_code'        => (string) $r->ao_code,
                'next_due'       => (string) $r->next_action_due,
                'action_type'    => (string) ($r->action_type ?? ''),
                'last_action_at' => $r->last_action_at ? (string) $r->last_action_at : null,
            ];

            // Direksi/KOM: tetap ringkas (tanpa identitas debitur).
            // Kabag: kalau mau, nanti kita tambah debtor_name/account_no dari loan_accounts (kalau kolomnya ada).
            if ($cap['can_drill_detail']) {
                $item['actor_user_id'] = $r->user_id ? (int) $r->user_id : null;
            }

            return $item;
        })->all();
    }

    /**
     * Stagnant = last action_at < N hari ATAU belum pernah ada action.
     */
    protected function stagnantCases(array $visibleAoCodes, array $cap, int $days = 30, int $limit = 10): array
    {
        if (!$this->hasTable('case_actions')) return [];

        $threshold = now()->subDays($days);

        $lastActionSub = DB::table('case_actions')
            ->selectRaw('npl_case_id, MAX(action_at) as last_action_at')
            ->groupBy('npl_case_id');

        $rows = DB::table('npl_cases as c')
            ->join('loan_accounts as la', 'la.id', '=', 'c.loan_account_id')
            ->leftJoinSub($lastActionSub, 'ca', fn($j) => $j->on('ca.npl_case_id', '=', 'c.id'))
            ->whereIn('la.ao_code', $visibleAoCodes)
            ->where(function ($q) use ($threshold) {
                $q->whereNull('ca.last_action_at')
                  ->orWhere('ca.last_action_at', '<', $threshold);
            })
            ->orderByRaw('COALESCE(ca.last_action_at, "1900-01-01") ASC')
            ->limit($limit)
            ->get([
                'c.id as case_id',
                'la.ao_code',
                'ca.last_action_at',
            ]);

        return $rows->map(fn($r) => [
            'case_id'        => (int) $r->case_id,
            'ao_code'        => (string) $r->ao_code,
            'last_action_at' => $r->last_action_at ? (string) $r->last_action_at : null,
        ])->all();
    }

    protected function legalCases(array $visibleAoCodes, array $cap, string $startDate, string $endDate, int $limit = 10): array
    {
        if (!$this->hasColumn('npl_cases', 'status')) return [];

        $rows = NplCase::query()
            ->join('loan_accounts as la', 'la.id', '=', 'npl_cases.loan_account_id')
            ->whereIn('la.ao_code', $visibleAoCodes)
            ->where('npl_cases.status', 'legal')
            ->orderByDesc('npl_cases.updated_at')
            ->limit($limit)
            ->get([
                'npl_cases.id as case_id',
                'la.ao_code',
                'npl_cases.updated_at',
            ]);

        return $rows->map(fn($r) => [
            'case_id'    => (int) $r->case_id,
            'ao_code'    => (string) $r->ao_code,
            'updated_at' => $r->updated_at ? (string) $r->updated_at : null,
        ])->all();
    }

    // =========================================================
    // Leaderboard
    // =========================================================

    protected function leaderboardAo(array $visibleAoCodes): array
    {
        if (empty($visibleAoCodes)) {
            return ['top' => [], 'bottom' => []];
        }

        // 1) Hitung jumlah case per AO
        $rows = \App\Models\NplCase::query()
            ->join('loan_accounts as la', 'npl_cases.loan_account_id', '=', 'la.id')
            ->whereIn('la.ao_code', $visibleAoCodes)
            ->selectRaw('la.ao_code, COUNT(*) as total_cases')
            ->groupBy('la.ao_code')
            ->get();

        if ($rows->isEmpty()) {
            return ['top' => [], 'bottom' => []];
        }

        // 2) Ambil mapping ao_code -> nama user
        $names = \DB::table('ao_mappings as am')
            ->join('users as u', 'u.employee_code', '=', 'am.employee_code')
            ->whereIn('am.ao_code', $rows->pluck('ao_code')->all())
            ->where('am.is_active', 1)
            ->select('am.ao_code', 'u.name')
            ->get()
            ->groupBy('ao_code')
            ->map(fn ($g) => $g->first()->name)
            ->all();

        // 3) Bentuk data leaderboard
        $list = $rows->map(function ($r) use ($names) {
            $code = (string) $r->ao_code;
            $name = $names[$code] ?? null;

            return [
                'ao_code'     => $code,
                'ao_name'     => $name,
                'label'       => $name ? "{$name} ({$code})" : "AO {$code}",
                'total_cases' => (int) $r->total_cases,
            ];
        })->sortByDesc('total_cases')->values();

        return [
            'top'    => $list->take(5)->values()->all(),
            'bottom' => $list->sortBy('total_cases')->take(5)->values()->all(),
        ];
    }

    // =========================================================
    // Helpers: schema checks
    // =========================================================

    protected function hasTable(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    protected function hasColumn(string $table, string $column): bool
    {
        return DB::getSchemaBuilder()->hasColumn($table, $column);
    }

    protected function normalizeAoCodes(array $codes): array
    {
        return collect($codes)
            ->filter(fn($c) => $c !== null && $c !== '')
            ->map(fn($c) => trim((string)$c))
            ->map(function ($c) {
                // Kalau numeric dan pendek, jadikan 6 digit (sesuaikan kalau AO kamu panjang lain)
                if (ctype_digit($c) && strlen($c) < 6) {
                    return str_pad($c, 6, '0', STR_PAD_LEFT);
                }
                return $c;
            })
            ->unique()
            ->values()
            ->all();
    }

    protected function leaderboardPicPerformance(array $visibleAoCodes, array $cap, string $startDate, string $endDate): array
    {
        if (empty($visibleAoCodes)) {
            return ['top' => [], 'bottom' => []];
        }

        // -------------------------
        // 1) Base PIC list dari npl_cases
        // -------------------------
        $pics = \App\Models\NplCase::query()
            ->join('loan_accounts as la', 'npl_cases.loan_account_id', '=', 'la.id')
            ->leftJoin('users as u', 'u.id', '=', 'npl_cases.pic_user_id')
            ->whereIn('la.ao_code', $visibleAoCodes)
            ->selectRaw("
                npl_cases.pic_user_id,
                COALESCE(u.name, 'Belum ditugaskan') as pic_name,
                COUNT(*) as total_cases
            ")
            ->groupBy('npl_cases.pic_user_id', 'u.name')
            ->get()
            ->keyBy('pic_user_id');

        if ($pics->isEmpty()) {
            return ['top' => [], 'bottom' => []];
        }

        $picIds = $pics->keys()->filter(fn($id) => !is_null($id))->values()->all();

        // -------------------------
        // 2) Closed count (kalau ada kolom status)
        // -------------------------
        $closedMap = collect();
        if ($this->hasColumn('npl_cases', 'status')) {
            // sesuaikan daftar "closed" sesuai enum kamu
            $closedStatuses = ['closed', 'settled', 'resolved', 'lunas', 'lancar'];

            $closedMap = \App\Models\NplCase::query()
                ->join('loan_accounts as la', 'npl_cases.loan_account_id', '=', 'la.id')
                ->whereIn('la.ao_code', $visibleAoCodes)
                ->whereIn('npl_cases.pic_user_id', $picIds)
                ->whereIn('npl_cases.status', $closedStatuses)
                ->when($this->hasColumn('npl_cases', 'updated_at'), function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('npl_cases.updated_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
                })
                ->selectRaw('npl_cases.pic_user_id, COUNT(*) as closed_count')
                ->groupBy('npl_cases.pic_user_id')
                ->pluck('closed_count', 'pic_user_id');
        }

        // -------------------------
        // 3) Follow-up done & overdue (ActionSchedule)
        // -------------------------
        $doneMap = collect();
        $overdueMap = collect();

        if (\Schema::hasTable('action_schedules')) {
            // done
            $doneMap = \App\Models\ActionSchedule::query()
                ->whereIn('pic_user_id', $picIds) // asumsi ada kolom pic_user_id di schedules
                ->where('status', 'done')
                ->whereBetween('scheduled_at', [$startDate, $endDate]) // sesuaikan kolom tanggal schedule kamu
                ->selectRaw('pic_user_id, COUNT(*) as done_count')
                ->groupBy('pic_user_id')
                ->pluck('done_count', 'pic_user_id');

            // overdue
            $overdueMap = \App\Models\ActionSchedule::query()
                ->whereIn('pic_user_id', $picIds)
                ->where('status', 'pending')
                ->whereDate('due_date', '<', now()->toDateString()) // sesuaikan kolom due kamu
                ->selectRaw('pic_user_id, COUNT(*) as overdue_count')
                ->groupBy('pic_user_id')
                ->pluck('overdue_count', 'pic_user_id');
        }

        // -------------------------
        // 4) Activity (CaseAction)
        // -------------------------
        $actionMap = collect();
        if (\Schema::hasTable('case_actions')) {
            $actionMap = \App\Models\CaseAction::query()
                ->whereIn('user_id', $picIds) // asumsi user_id adalah PIC yang input tindakan
                ->whereBetween('action_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
                ->selectRaw('user_id as pic_user_id, COUNT(*) as actions_count')
                ->groupBy('user_id')
                ->pluck('actions_count', 'pic_user_id');
        }

        // -------------------------
        // 5) Build list + score
        // -------------------------
        $list = $pics->map(function ($row, $picId) use ($closedMap, $doneMap, $overdueMap, $actionMap) {

            $total   = (int) ($row->total_cases ?? 0);
            $closed  = (int) ($closedMap[$picId] ?? 0);
            $done    = (int) ($doneMap[$picId] ?? 0);
            $overdue = (int) ($overdueMap[$picId] ?? 0);
            $actions = (int) ($actionMap[$picId] ?? 0);

            $completionRate = $total > 0 ? round(($closed / $total) * 100, 1) : 0;

            // ✅ Score gabungan (simple, mudah dijelaskan)
            $score = ($closed * 5) + ($done * 2) - ($overdue * 3);

            return [
                'pic_user_id'     => $picId,
                'pic_name'        => $row->pic_name,
                'total_cases'     => $total,
                'closed_count'    => $closed,
                'done_followups'  => $done,
                'overdue_followups'=> $overdue,
                'actions_count'   => $actions,
                'completion_rate' => $completionRate,
                'score'           => $score,
            ];
        })->values();

        $sorted = $list->sortByDesc('score')->values();

        return [
            'top'    => $sorted->take(5)->values()->all(),
            'bottom' => $sorted->sortBy('score')->take(5)->values()->all(),
        ];
    }

}
