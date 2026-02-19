<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Carbon\Carbon;
use App\Models\User; // ✅ ini
use Illuminate\Support\Facades\Schema;

class KpiRankingController extends Controller
{

    // helper sementara (nanti diganti query beneran)
    private function placeholder(Request $request, string $role)
    {
        return view('kpi.ranking.placeholder', [
            'role' => $role,
            'periodYmd' => $request->query('period', now()->startOfMonth()->toDateString()),
        ]);
    }

    /**
     * Helper: ambil period start-of-month (Y-m-d).
     * support query: ?period=2026-02-01 atau 2026-02
     */
    private function resolvePeriodYmd(Request $request): string
    {
        $raw = trim((string) $request->query('period', ''));

        if ($raw === '') {
            return now()->startOfMonth()->toDateString();
        }

        // support "YYYY-MM"
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m', $raw)->startOfMonth()->toDateString();
        }

        // support "YYYY-MM-DD"
        try {
            return Carbon::parse($raw)->startOfMonth()->toDateString();
        } catch (\Throwable $e) {
            return now()->startOfMonth()->toDateString();
        }
    }

    /**
     * Helper: role user (string upper).
     * Kamu sudah biasa pakai roleValue() / level enum/string, kita buat aman.
     */
    private function resolveRole(Request $request): string
    {
        $u = $request->user();
        $lvl = strtoupper(trim((string)($u?->roleValue() ?? '')));

        if ($lvl !== '') return $lvl;

        $raw = $u?->level;
        if ($raw instanceof \BackedEnum) $raw = $raw->value;

        return strtoupper(trim((string)$raw));
    }

    /**
     * Helper: scope userIds yang boleh dilihat.
     * - Kalau kamu sudah punya OrgVisibilityService, tinggal aktifkan bloknya.
     * - Untuk tahap awal: fallback = hanya diri sendiri (aman).
     */
    private function resolveScopeUserIds(Request $request, string $targetLevel): array
    {
        $u = $request->user();
        if (!$u) return [];

        $role = strtoupper(trim($this->resolveRole($request)));

        // STAFF lihat semua user level target
        if (in_array($role, ['AO','RO','SO','FE','BE'], true)) {

            $q = DB::table('users')
                ->whereRaw('TRIM(UPPER(level)) = ?', [strtoupper($targetLevel)]);

            // ❌ AO & RO saja yang butuh ao_code
            if (in_array($targetLevel, ['AO','RO'], true)) {
                $q->whereNotNull('ao_code')
                ->where('ao_code','!=','');
            }

            return $q->pluck('id')
                    ->map(fn($x)=>(int)$x)
                    ->toArray();
        }

        // TL scope
        if (str_starts_with($role, 'TL')) {
            return $this->subordinateUserIdsByOrg(
                $u->id,
                $role,
                $this->resolvePeriodYmd($request),
                $targetLevel
            );
        }

        // Management
        if (in_array($role, ['KSLU','KSLR','KBL','DIR','DIREKSI','KABAG','PE'], true)) {
            $q = DB::table('users')
                ->whereRaw('TRIM(UPPER(level)) = ?', [strtoupper($targetLevel)]);

            if (in_array(strtoupper($targetLevel), ['AO','RO'], true)) {
                $q->whereNotNull('ao_code')->where('ao_code','!=','');
            }

            return $q->pluck('id')->map(fn($x)=>(int)$x)->toArray();
        }


        return [(int)$u->id];
    }

    private function subordinateUserIdsByOrg(int $leaderId, string $leaderRole, string $periodYmd, string $targetLevel): array
    {
        // alias role TL supaya match "TLRO", "TL RO", "tlro", dll
        $aliases = [
            strtolower(trim($leaderRole)),
            strtolower(trim(str_replace(' ', '', $leaderRole))),
        ];

        // contoh: TLRO boleh melihat AO+RO? (nanti kita refine)
        // Tahap awal: ambil semua user_id bawahan dan filter level target
        $q = DB::table('org_assignments as oa')
            ->join('users as u', 'u.id', '=', 'oa.user_id')
            ->where('oa.leader_id', $leaderId)
            ->where('oa.is_active', 1)
            ->whereIn(DB::raw('LOWER(TRIM(oa.leader_role))'), $aliases)
            ->where('u.level', $targetLevel);

        // effective range kalau ada
        $q->whereDate('oa.effective_from', '<=', $periodYmd)
        ->where(function($qq) use ($periodYmd) {
            $qq->whereNull('oa.effective_to')
                ->orWhereDate('oa.effective_to', '>=', $periodYmd);
        });

        return $q->pluck('u.id')->map(fn($x)=>(int)$x)->toArray();
    }

    private function resolveDetailUserIds(Request $request, string $targetLevel): array
    {
        $u = $request->user();
        if (!$u) return [];

        $role = strtoupper(trim($this->resolveRole($request)));
        $periodYmd = $this->resolvePeriodYmd($request);

        // ✅ staff hanya boleh drilldown dirinya sendiri
        if (in_array($role, ['AO','RO','SO','FE','BE'], true)) {
            return [(int)$u->id];
        }

        // ✅ TL: drilldown ke bawahan (sesuai org)
        if (str_starts_with($role, 'TL')) {
            return $this->subordinateUserIdsByOrg((int)$u->id, $role, $periodYmd, $targetLevel);
        }

        // ✅ management: boleh drilldown semua (sementara)
        if (in_array($role, ['KSLR','KSLU','KBL','DIR','DIREKSI','KABAG','PE'], true)) {
            return DB::table('users')
                ->where('level', $targetLevel)
                ->whereNotNull('ao_code')
                ->where('ao_code', '!=', '')
                ->pluck('id')
                ->map(fn($x)=>(int)$x)
                ->toArray();
        }

        // fallback aman
        return [(int)$u->id];
    }

    /**
     * Helper: template respon view ranking.
     * $rows: hasil ranking
     */
    private function render(
        string $view,
        array $rows,
        string $periodYmd,
        Request $request,
        string $targetLevel,
        array $extra = []
    ) {
        return view($view, array_merge([
            'periodYmd'   => $periodYmd,
            'periodLabel' => Carbon::parse($periodYmd)->translatedFormat('F Y'),
            'targetLevel' => $targetLevel,
            'rows'        => $rows,
            'meRole'      => $this->resolveRole($request),
        ], $extra));
    }


    // ============================
    // AO
    // ============================
    public function ao(Request $request)
    {
        $periodYmd = $this->resolvePeriodYmd($request);

        $rankUserIds = $this->resolveScopeUserIds($request, 'AO');
        $rows = $this->queryRankingAo($periodYmd, $rankUserIds);

        $detailUserIds = $this->resolveDetailUserIds($request, 'AO');
        $allowedUserIds = array_fill_keys(array_map('intval', $detailUserIds), true);

        return $this->render('kpi.ranking.ao', $rows, $periodYmd, $request, 'AO', [
            'allowedUserIds' => $allowedUserIds,
        ]);
    }


    // ============================
    // RO
    // ============================
    
    public function ro(Request $request)
    {
        $periodYmd     = $this->resolvePeriodYmd($request);
        $scopeUserIds  = $this->resolveScopeUserIds($request, 'RO'); // <- pakai yg ini (lihat poin 3)
        $rows          = $this->queryRankingRo($periodYmd, $scopeUserIds);

        $ids = array_values(array_unique(array_map(fn($x) => (int)$x['user_id'], $rows)));
        $users = \App\Models\User::whereIn('id', $ids)->get()->keyBy('id');

        $allowedUserIds = [];
        foreach ($ids as $id) {
            $target = $users->get($id);
            if ($target && \Illuminate\Support\Facades\Gate::allows('kpi-ro-view', $target)) {
                $allowedUserIds[$id] = true;
            }
        }

        return $this->render('kpi.ranking.ro', $rows, $periodYmd, $request, 'RO', [
            'allowedUserIds' => $allowedUserIds,
        ]);
    }

    // ============================
    // SO
    // ============================
    public function so(Request $request)
    {
        $periodYmd     = $this->resolvePeriodYmd($request);
        $scopeUserIds  = $this->resolveScopeUserIds($request, 'SO'); // kamu sudah punya

        $rows = $this->queryRankingSo($periodYmd, $scopeUserIds);

        $ids = array_values(array_unique(array_map(fn($x) => (int)$x['user_id'], $rows)));
        $users = \App\Models\User::whereIn('id', $ids)->get()->keyBy('id');

        $allowedUserIds = [];
        foreach ($ids as $id) {
            $target = $users->get($id);
            if ($target && \Illuminate\Support\Facades\Gate::allows('kpi-so-view', $target)) {
                $allowedUserIds[$id] = true;
            }
        }

        return $this->render('kpi.ranking.so', $rows, $periodYmd, $request, 'SO', [
            'allowedUserIds' => $allowedUserIds,
        ]);
    }

    // ============================
    // FE
    // ============================
    public function fe(Request $request)
    {
        Gate::authorize('kpi-fe-viewAny');

        $periodYmd = $request->input('period');
        if (empty($periodYmd)) $periodYmd = now()->startOfMonth()->toDateString();
        $periodYmd = Carbon::parse($periodYmd)->startOfMonth()->toDateString();

        $rows = $this->queryRankingFe($periodYmd);

        // ✅ ids dari collection
        $ids = $rows->pluck('fe_user_id')
            ->filter()
            ->unique()
            ->values()
            ->map(fn($v) => (int)$v)
            ->all();

        $users = \App\Models\User::whereIn('id', $ids)->get()->keyBy('id');

        $allowedUserIds = [];
        foreach ($ids as $id) {
            $target = $users->get($id);
            if ($target && Gate::allows('kpi-fe-view', $target)) {
                $allowedUserIds[$id] = true;
            }
        }

        return view('kpi.ranking.fe', [
            'periodYmd' => $periodYmd,
            'periodLabel' => Carbon::parse($periodYmd)->translatedFormat('F Y'),
            'rows' => $rows,
            'allowedUserIds' => $allowedUserIds,
        ]);
    }

   // ============================
    // BE
    // ============================
    public function be(Request $request)
    {
        $periodYmd    = $this->resolvePeriodYmd($request);
        $scopeUserIds = $this->resolveScopeUserIds($request, 'BE');

        $rows = $this->queryRankingBe($periodYmd, $scopeUserIds);

        // allowedUserIds (mirip SO/RO)
        $ids = array_values(array_unique(array_map(fn($x) => (int)($x['user_id'] ?? 0), $rows)));
        $ids = array_values(array_filter($ids));

        $users = \App\Models\User::whereIn('id', $ids)->get()->keyBy('id');

        $allowedUserIds = [];
        foreach ($ids as $id) {
            $target = $users->get($id);
            if ($target && Gate::allows('kpi-be-view', $target)) {
                $allowedUserIds[$id] = true;
            }
        }

        return $this->render('kpi.ranking.be', $rows, $periodYmd, $request, 'BE', [
            'allowedUserIds' => $allowedUserIds,
        ]);
    }


    // ============================
    // TL
    // ============================
    public function tl(Request $request)
    {
        $periodYmd = $this->resolvePeriodYmd($request);

        // scope: kita targetkan level TL (tapi di resolveScopeUserIds kamu belum handle TLRO/TLFE/TLUM)
        // jadi untuk TL ranking: ambil semua TL langsung dari users, tapi tetap hormati scopeUserIds kalau ada.
        $scopeUserIds = DB::table('users')
            ->whereIn(DB::raw('TRIM(UPPER(level))'), ['TLRO','TLFE','TLUM'])
            ->pluck('id')
            ->map(fn($x)=>(int)$x)
            ->toArray();

        $rows = $this->queryRankingTl($periodYmd, $scopeUserIds);

        return $this->render('kpi.ranking.tl', $rows, $periodYmd, $request, 'TL');
    }

    // ============================
    // KASI
    // ============================
    public function kasi(Request $request)
    {
        $periodYmd = $this->resolvePeriodYmd($request);
        $scopeUserIds = $this->resolveScopeUserIds($request, 'KASI');

        $rows = $this->queryRankingStub('KASI', $periodYmd, $scopeUserIds);

        return $this->render('kpi.ranking.kasi', $rows, $periodYmd, $request, 'KASI');
    }

    // ============================
    // KBL
    // ============================
    public function kbl(Request $request)
    {
        $periodYmd = $this->resolvePeriodYmd($request);
        $scopeUserIds = $this->resolveScopeUserIds($request, 'KBL');

        $rows = $this->queryRankingStub('KBL', $periodYmd, $scopeUserIds);

        return $this->render('kpi.ranking.kbl', $rows, $periodYmd, $request, 'KBL');
    }

    /**
     * Stub query sementara.
     * Ini biar controller jalan dulu tanpa error & view bisa kita bikin bertahap.
     *
     * Step berikutnya: kita ganti khusus AO dulu jadi query beneran (pakai tabel kpi_ao_monthlies dkk).
     */
    private function queryRankingStub(string $level, string $periodYmd, array $scopeUserIds): array
    {
        if (empty($scopeUserIds)) return [];

        // Contoh dummy: ambil user dalam scope, urut nama (sementara)
        // Nanti diganti join ke tabel KPI masing2.
        $users = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereIn('id', $scopeUserIds)
            ->orderBy('name')
            ->limit(200)
            ->get();

        // format rows biar view gampang
        $rows = [];
        $rank = 0;
        foreach ($users as $u) {
            $rank++;
            $rows[] = [
                'rank' => $rank,
                'user_id' => (int)$u->id,
                'name' => (string)$u->name,
                'code' => (string)($u->ao_code ?? ''),
                'level' => (string)($u->level ?? ''),
                'score' => null, // nanti isi
                'meta'  => [],
            ];
        }

        return $rows;
    }

    private function detectColumn(string $table, array $candidates, ?string $connection = null): ?string
    {
        // cache per (connection + table)
        static $cache = [];

        $conn = $connection ?: DB::getDefaultConnection();
        $key = $conn . '|' . $table;

        // 1) ambil listing kolom dari cache atau schema
        if (!array_key_exists($key, $cache)) {
            $cols = [];

            // a) prefer Schema (lebih portable & permission-friendly)
            try {
                $cols = Schema::connection($conn)->getColumnListing($table);
            } catch (\Throwable $e) {
                $cols = [];
            }

            // b) fallback ke information_schema (opsional, kalau Schema gagal)
            if (empty($cols)) {
                try {
                    $db = DB::connection($conn)->getDatabaseName();
                    $cols = DB::connection($conn)->table('information_schema.columns')
                        ->where('table_schema', $db)
                        ->where('table_name', $table)
                        ->pluck('column_name')
                        ->toArray();
                } catch (\Throwable $e) {
                    $cols = [];
                }
            }

            // simpan cache: map lowercase => original
            $map = [];
            foreach ($cols as $col) {
                $map[strtolower((string)$col)] = (string)$col;
            }

            $cache[$key] = $map;
        }

        $map = $cache[$key];

        // 2) resolve candidate pertama yang match
        foreach ($candidates as $c) {
            $lc = strtolower((string)$c);
            if (isset($map[$lc])) {
                // return nama kolom yang benar (original)
                return $map[$lc];
            }
        }

        return null;
    }

    private function queryRankingAo(string $periodYmd, array $scopeUserIds): array
    {
        if (empty($scopeUserIds)) return [];

        $table = 'kpi_ao_monthlies';

        // auto-detect kolom
        $colUser   = $this->detectColumn($table, ['user_id', 'users_id', 'ao_user_id']) ?: 'user_id';
        $colPeriod = $this->detectColumn($table, ['period', 'period_date', 'period_ymd', 'periode', 'month']) ?: 'period';

        $ym = Carbon::parse($periodYmd)->format('Y-m');

        $q = DB::table("$table as k")
            ->join('users as u', 'u.id', '=', "k.$colUser")
            ->select([
                'u.id as user_id',
                'u.name as name',
                'u.ao_code as code',
                'u.level as level',

                DB::raw('COALESCE(k.score_total,0) as score_total'),
                DB::raw('COALESCE(k.rr_pct,0) as rr_pct'),

                DB::raw('COALESCE(k.score_rr,0) as score_rr'),
                DB::raw('COALESCE(k.score_kolek,0) as score_kolek'),
            ])
            ->whereIn("k.$colUser", $scopeUserIds)
            ->where(function ($qq) use ($colPeriod, $periodYmd, $ym) {
                // date/datetime
                $qq->orWhereDate("k.$colPeriod", '=', $periodYmd);
                // string YYYY-MM
                $qq->orWhere("k.$colPeriod", '=', $ym);
                // datetime string
                $qq->orWhere("k.$colPeriod", 'like', $periodYmd . '%');
                // YYYY-MM%
                $qq->orWhere("k.$colPeriod", 'like', $ym . '%');
            });

        $rowsRaw = $q
            ->orderByDesc('score_total')
            ->orderByDesc('rr_pct')
            ->orderByDesc('score_kolek')
            ->orderBy('name')
            ->limit(500)
            ->get();

        $rows = [];
        $rank = 0;

        foreach ($rowsRaw as $r) {
            $rank++;
            $rows[] = [
                'rank'    => $rank,
                'user_id' => (int)$r->user_id,
                'name'    => (string)$r->name,
                'code'    => (string)($r->code ?? ''),
                'level'   => (string)($r->level ?? ''),
                'score'   => (float)$r->score_total,
                'meta'    => [
                    'rr_pct'       => (float)$r->rr_pct,
                    'score_rr'     => (float)$r->score_rr,
                    'score_kolek'  => (float)$r->score_kolek,
                ],
            ];
        }

        return $rows;
    }

    private function queryRankingRo(string $periodYmd, array $scopeUserIds): array
    {
        if (empty($scopeUserIds)) return [];

        $scopeAoCodes = DB::table('users')
            ->whereIn('id', $scopeUserIds)
            ->pluck('ao_code')
            ->filter(fn($x) => trim((string)$x) !== '')
            ->map(fn($x) => str_pad(trim((string)$x), 6, '0', STR_PAD_LEFT))
            ->values()
            ->toArray();

        if (empty($scopeAoCodes)) return [];

        $rowsRaw = DB::table('kpi_ro_monthly as k')
            ->join('users as u', DB::raw("LPAD(TRIM(u.ao_code),6,'0')"), '=', DB::raw("TRIM(k.ao_code)"))
            ->select([
                'u.id as user_id',
                'u.name as name',
                'u.ao_code as code',
                'u.level as level',

                DB::raw('COALESCE(k.total_score_weighted,0) as score_total'),
                DB::raw('COALESCE(k.repayment_rate,0) as repayment_rate'),
                DB::raw('COALESCE(k.repayment_pct,0) as repayment_pct'),
                DB::raw('COALESCE(k.dpk_pct,0) as dpk_pct'),
                DB::raw('COALESCE(k.topup_pct,0) as topup_pct'),
                DB::raw('COALESCE(k.noa_pct,0) as noa_pct'),
                DB::raw('COALESCE(k.calc_mode,"") as calc_mode'),
                DB::raw('COALESCE(k.baseline_ok,1) as baseline_ok'),
            ])
            ->whereDate('k.period_month', '=', $periodYmd)
            ->whereIn(DB::raw("TRIM(k.ao_code)"), $scopeAoCodes)
            ->orderByDesc('score_total')
            ->orderByDesc('repayment_rate')
            ->orderBy('dpk_pct', 'asc')
            ->orderByDesc('topup_pct')
            ->orderBy('name')
            ->limit(500)
            ->get();

        $rows = [];
        $rank = 0;

        foreach ($rowsRaw as $r) {
            $rank++;
            $rows[] = [
                'rank'    => $rank,
                'user_id' => (int)$r->user_id,
                'name'    => (string)$r->name,
                'code'    => (string)($r->code ?? ''),
                'level'   => (string)($r->level ?? ''),
                'score'   => (float)($r->score_total ?? 0),
                'meta'    => [
                    'repayment_rate' => (float)($r->repayment_rate ?? 0),
                    'repayment_pct'  => (float)($r->repayment_pct ?? 0),
                    'dpk_pct'        => (float)($r->dpk_pct ?? 0),
                    'topup_pct'      => (float)($r->topup_pct ?? 0),
                    'noa_pct'        => (float)($r->noa_pct ?? 0),
                    'calc_mode'      => (string)($r->calc_mode ?? ''),
                    'baseline_ok'    => (int)($r->baseline_ok ?? 1),
                ],
            ];
        }

        return $rows;
    }

    private function queryRankingSo(string $periodYmd, array $scopeUserIds): array
    {
        if (empty($scopeUserIds)) return [];

        $rowsRaw = DB::table('kpi_so_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.user_id')
            ->select([
                'u.id as user_id',
                'u.name as name',
                'u.ao_code as code',
                'u.level as level',

                DB::raw('COALESCE(k.score_total,0) as score_total'),
                DB::raw('COALESCE(k.rr_pct,0) as rr_pct'),

                DB::raw('COALESCE(k.os_disbursement,0) as os_disbursement'),
                DB::raw('COALESCE(k.noa_disbursement,0) as noa_disbursement'),
                DB::raw('COALESCE(k.activity_pct,0) as activity_pct'),

                DB::raw('COALESCE(k.score_os,0) as score_os'),
                DB::raw('COALESCE(k.score_noa,0) as score_noa'),
                DB::raw('COALESCE(k.score_rr,0) as score_rr'),
                DB::raw('COALESCE(k.score_activity,0) as score_activity'),
            ])
            ->whereIn('u.id', $scopeUserIds)
            ->whereDate('k.period', '=', $periodYmd)
            ->orderByDesc('score_total')
            ->orderByDesc('rr_pct')
            ->orderByDesc('os_disbursement')
            ->orderByDesc('noa_disbursement')
            ->orderByDesc('activity_pct')
            ->orderBy('name')
            ->limit(500)
            ->get();

        $rows = [];
        $rank = 0;

        foreach ($rowsRaw as $r) {
            $rank++;
            $rows[] = [
                'rank'    => $rank,
                'user_id' => (int)$r->user_id,
                'name'    => (string)$r->name,
                'code'    => (string)($r->code ?? ''),
                'level'   => (string)($r->level ?? ''),
                'score'   => (float)($r->score_total ?? 0),
                'meta'    => [
                    'rr_pct'          => (float)($r->rr_pct ?? 0),
                    'os_disbursement' => (int)($r->os_disbursement ?? 0),
                    'noa_disbursement'=> (int)($r->noa_disbursement ?? 0),
                    'activity_pct'    => (float)($r->activity_pct ?? 0),

                    'score_os'        => (float)($r->score_os ?? 0),
                    'score_noa'       => (float)($r->score_noa ?? 0),
                    'score_rr'        => (float)($r->score_rr ?? 0),
                    'score_activity'  => (float)($r->score_activity ?? 0),
                ],
            ];
        }

        return $rows;
    }

    private function queryRankingBe(string $periodYmd, array $scopeUserIds): array
    {
        if (empty($scopeUserIds)) return [];

        $rowsRaw = DB::table('kpi_be_monthlies as m')
            ->join('users as u', 'u.id', '=', 'm.be_user_id')
            ->leftJoin('kpi_be_targets as t', function ($j) {
                $j->on('t.period', '=', 'm.period')
                ->on('t.be_user_id', '=', 'm.be_user_id');
            })
            ->whereDate('m.period', '=', $periodYmd)
            ->whereIn('m.be_user_id', $scopeUserIds)

            // ✅ FILTER: hanya BE yang punya portofolio/exposure (biar Winda yg non-account tidak tampil)
            ->where(function ($qq) {
                $qq->where('m.os_npl_prev', '>', 0)
                ->orWhere('m.os_npl_now', '>', 0)
                ->orWhere('m.actual_os_selesai', '>', 0)
                ->orWhere('m.actual_noa_selesai', '>', 0)
                ->orWhere('m.actual_bunga_masuk', '>', 0)
                ->orWhere('m.actual_denda_masuk', '>', 0);
            })

            ->select([
                'm.period',
                'm.be_user_id',
                'u.name',
                'u.level',

                // actuals
                DB::raw('COALESCE(m.actual_os_selesai,0) as actual_os_selesai'),
                DB::raw('COALESCE(m.actual_noa_selesai,0) as actual_noa_selesai'),
                DB::raw('COALESCE(m.actual_bunga_masuk,0) as actual_bunga_masuk'),
                DB::raw('COALESCE(m.actual_denda_masuk,0) as actual_denda_masuk'),

                // scores
                DB::raw('COALESCE(m.score_os,0) as score_os'),
                DB::raw('COALESCE(m.score_noa,0) as score_noa'),
                DB::raw('COALESCE(m.score_bunga,0) as score_bunga'),
                DB::raw('COALESCE(m.score_denda,0) as score_denda'),

                // PI
                DB::raw('COALESCE(m.pi_os,0) as pi_os'),
                DB::raw('COALESCE(m.pi_noa,0) as pi_noa'),
                DB::raw('COALESCE(m.pi_bunga,0) as pi_bunga'),
                DB::raw('COALESCE(m.pi_denda,0) as pi_denda'),
                DB::raw('COALESCE(m.total_pi,0) as total_pi'),

                // meta npl
                DB::raw('COALESCE(m.os_npl_prev,0) as os_npl_prev'),
                DB::raw('COALESCE(m.os_npl_now,0) as os_npl_now'),
                DB::raw('COALESCE(m.net_npl_drop,0) as net_npl_drop'),

                // targets
                DB::raw('COALESCE(t.target_os_selesai,0) as target_os_selesai'),
                DB::raw('COALESCE(t.target_noa_selesai,0) as target_noa_selesai'),
                DB::raw('COALESCE(t.target_bunga_masuk,0) as target_bunga_masuk'),
                DB::raw('COALESCE(t.target_denda_masuk,0) as target_denda_masuk'),

                // workflow
                DB::raw('COALESCE(m.status,"draft") as status'),
            ])
            ->orderByDesc('total_pi')
            ->orderByDesc('score_os')
            ->orderByDesc('score_noa')
            ->orderByDesc('score_bunga')
            ->orderByDesc('score_denda')
            ->orderBy('u.name')
            ->limit(500)
            ->get();

        $rows = [];
        $rank = 0;

        foreach ($rowsRaw as $r) {
            $rank++;
            $rows[] = [
                'rank'    => $rank,
                'user_id' => (int)$r->be_user_id,
                'name'    => (string)$r->name,
                'code'    => '',
                'level'   => (string)($r->level ?? 'BE'),
                'score'   => (float)($r->total_pi ?? 0),

                'meta' => [
                    'total_pi' => (float)$r->total_pi,
                    'status'   => (string)$r->status,

                    'target_os_selesai'   => (float)$r->target_os_selesai,
                    'target_noa_selesai'  => (int)$r->target_noa_selesai,
                    'target_bunga_masuk'  => (float)$r->target_bunga_masuk,
                    'target_denda_masuk'  => (float)$r->target_denda_masuk,

                    'actual_os_selesai'   => (float)$r->actual_os_selesai,
                    'actual_noa_selesai'  => (int)$r->actual_noa_selesai,
                    'actual_bunga_masuk'  => (float)$r->actual_bunga_masuk,
                    'actual_denda_masuk'  => (float)$r->actual_denda_masuk,

                    'score_os'    => (int)$r->score_os,
                    'score_noa'   => (int)$r->score_noa,
                    'score_bunga' => (int)$r->score_bunga,
                    'score_denda' => (int)$r->score_denda,

                    'pi_os'    => (float)$r->pi_os,
                    'pi_noa'   => (float)$r->pi_noa,
                    'pi_bunga' => (float)$r->pi_bunga,
                    'pi_denda' => (float)$r->pi_denda,

                    'os_npl_prev'  => (float)$r->os_npl_prev,
                    'os_npl_now'   => (float)$r->os_npl_now,
                    'net_npl_drop' => (float)$r->net_npl_drop,
                ],
            ];
        }

        return $rows;
    }

        /**
     * Ranking FE - sumber: kpi_fe_monthlies
     * Urutan: total_score_weighted desc lalu tiebreaker komponen.
     */
    private function queryRankingFe(string $periodYmd, ?array $scopeFeUserIds = null)
    {
        $q = DB::table('kpi_fe_monthlies as m')
            ->join('users as u', 'u.id', '=', 'm.fe_user_id')
            ->leftJoin('kpi_fe_targets as t', function ($j) {
                $j->on('t.period', '=', 'm.period')
                  ->on('t.fe_user_id', '=', 'm.fe_user_id');
            })
            ->whereDate('m.period', $periodYmd);

        if (is_array($scopeFeUserIds) && count($scopeFeUserIds) > 0) {
            $q->whereIn('m.fe_user_id', $scopeFeUserIds);
        }

        // pilih kolom yang dipakai blade
        $rows = $q->select([
                'm.period',
                'm.calc_mode',
                'm.fe_user_id',
                'm.ao_code',

                'u.name',

                // metrics utama (kalau mau tampil)
                'm.os_kol2_awal',
                'm.os_kol2_akhir',
                'm.os_kol2_turun_total',
                'm.os_kol2_turun_murni',
                'm.os_kol2_turun_migrasi',
                'm.os_kol2_turun_pct',

                'm.migrasi_npl_os',
                'm.migrasi_npl_pct',

                'm.penalty_paid_total',

                // target (opsional tampil di sheet)
                DB::raw('COALESCE(t.target_os_turun_kol2, m.target_os_turun_kol2) as target_os_turun_kol2'),
                DB::raw('COALESCE(t.target_os_turun_kol2_pct, m.target_os_turun_kol2_pct) as target_os_turun_kol2_pct'),
                DB::raw('COALESCE(t.target_migrasi_npl_pct, m.target_migrasi_npl_pct) as target_migrasi_npl_pct'),
                DB::raw('COALESCE(t.target_penalty_paid, m.target_penalty_paid) as target_penalty_paid'),

                // achievement & score
                'm.ach_os_turun_pct',
                'm.ach_migrasi_pct',
                'm.ach_penalty_pct',

                'm.score_os_turun',
                'm.score_migrasi',
                'm.score_penalty',

                'm.pi_os_turun',
                'm.pi_migrasi',
                'm.pi_penalty',

                'm.total_score_weighted',
            ])
            ->orderByDesc('m.total_score_weighted')
            ->orderByDesc('m.score_os_turun')
            ->orderByDesc('m.score_migrasi')
            ->orderByDesc('m.score_penalty')
            ->orderByDesc('m.os_kol2_turun_total')
            ->get();

        // kasih ranking number
        $rank = 0;
        return $rows->map(function ($r) use (&$rank) {
            $rank++;
            $r->rank = $rank;
            return $r;
        });
    }

    private function queryRankingTl(string $periodYmd, array $scopeUserIds): array
    {
        if (empty($scopeUserIds)) return [];

        // ✅ detect kolom total score TLUM
        $tlumTable = 'kpi_tlum_monthlies';
        $colTlumUser   = $this->detectColumn($tlumTable, ['tlum_user_id','user_id']) ?: 'tlum_user_id';
        $colTlumPeriod = $this->detectColumn($tlumTable, ['period','period_month','period_date']) ?: 'period';

        // ini yang kemarin bikin error: total_pi ternyata beda nama
        $colTlumTotal  = $this->detectColumn($tlumTable, [
            'total_pi','total_pi_tlum','total_score_weighted','score_total','total_score'
        ]);

        // kalau bener-bener gak ketemu, fallback aman: 0 terus
        $exprTlumTotal = $colTlumTotal ? "COALESCE(tm.$colTlumTotal,0)" : "0";

        $rowsRaw = DB::table('users as u')
            ->leftJoin("$tlumTable as tm", function ($j) use ($periodYmd, $colTlumUser, $colTlumPeriod) {
                $j->on("tm.$colTlumUser", '=', 'u.id')
                ->whereDate("tm.$colTlumPeriod", '=', $periodYmd);
            })
            ->whereIn('u.id', $scopeUserIds)
            ->whereIn(DB::raw('TRIM(UPPER(u.level))'), ['TLRO','TLFE','TLUM'])
            ->select([
                'u.id as user_id',
                'u.name',
                'u.level',

                DB::raw("CASE
                    WHEN TRIM(UPPER(u.level)) = 'TLUM' THEN $exprTlumTotal
                    ELSE 0
                END as score_total"),

                DB::raw("CASE
                    WHEN TRIM(UPPER(u.level)) = 'TLUM' AND tm.id IS NOT NULL THEN 'TLUM_MONTHLY'
                    WHEN TRIM(UPPER(u.level)) = 'TLUM' AND tm.id IS NULL THEN 'TLUM_NO_DATA'
                    ELSE 'NO_MONTHLY_YET'
                END as score_source"),
            ])
            ->orderByDesc('score_total')
            ->orderBy('u.level')
            ->orderBy('u.name')
            ->limit(500)
            ->get();

        $rows = [];
        $rank = 0;

        foreach ($rowsRaw as $r) {
            $rank++;
            $rows[] = [
                'rank'    => $rank,
                'user_id' => (int)$r->user_id,
                'name'    => (string)$r->name,
                'code'    => '',
                'level'   => (string)($r->level ?? 'TL'),
                'score'   => (float)($r->score_total ?? 0),
                'meta'    => [
                    'score_source' => (string)($r->score_source ?? ''),
                ],
            ];
        }

        return $rows;
    }

}
