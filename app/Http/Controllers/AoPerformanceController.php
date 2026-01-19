<?php

namespace App\Http\Controllers;

use App\Exports\AoCasesExport;
use App\Models\NplCase;
use App\Models\User;
use App\Services\Org\OrgVisibilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class AoPerformanceController extends Controller
{
    /**
     * Jenis action yang dianggap "penanganan" (handled).
     * Legacy SP/Visit harus masuk sini agar otomatis dianggap sudah ditangani.
     */
    private array $handledTypes = ['sp1','sp2','sp3','spt','spjad','visit'];

    /**
     * ✅ 1 pintu role: controller ini hanya untuk supervisor/management.
     * Rekomendasi: pindah ke Policy/Gate, tapi minimal kita kunci di sini dulu.
     */
    protected function ensureCanView(): void
    {
        $user = auth()->user();

        if (!$user) abort(403);

        // ✅ REKOMENDASI: di User model ada method isSupervisor()
        if (method_exists($user, 'isSupervisor')) {
            if (!$user->isSupervisor()) abort(403);
            return;
        }

        // Fallback sementara (kalau helper belum ada)
        if (method_exists($user, 'hasAnyRole')) {
            if (!$user->hasAnyRole(['TL','KASI','KABAG','KBL','KBO','KTI','KBF','PE','DIREKSI','KOM'])) {
                abort(403);
            }
            return;
        }

        abort(403);
    }

    /**
     * ✅ Ambil daftar AO code yang boleh terlihat oleh user login (berdasarkan org_assignments).
     * - KASI/TL: hanya bawahannya
     * - Kabag/Direksi/PE: bisa semua (service bisa return semua user ids)
     *
     * Fail-safe:
     * - Utama: users.ao_code (kalau ada)
     * - Fallback: loan_accounts.ao_user_id (kalau ada)
     */
    protected function visibleAoCodes(): array
    {
        $me = auth()->user();
        if (!$me) return [];

        try {
            /** @var \App\Services\Org\OrgVisibilityService $svc */
            $svc = app(\App\Services\Org\OrgVisibilityService::class);

            $visibleUserIds = $svc->visibleUserIds($me);
            if (empty($visibleUserIds)) return [];

            // ✅ Ambil employee_code dari user yang visible -> map jadi ao_code
            $codes = \App\Models\User::query()
                ->whereIn('id', $visibleUserIds)
                ->whereNotNull('employee_code')
                ->pluck('employee_code')
                ->map(fn($v) => strtoupper(trim((string)$v)))
                ->filter(fn($v) => $v !== '')
                ->unique()
                ->values()
                ->all();

            return $codes;

        } catch (\Throwable $e) {
            return [];
        }
    }


    /**
     * Subquery: ambil next_action_due dari action TERAKHIR per kasus.
     * (anti false overdue dari record lama)
     */
    private function lastNextDueSub(): string
    {
        return '(SELECT ca2.next_action_due
                FROM case_actions ca2
                WHERE ca2.npl_case_id = npl_cases.id
                ORDER BY ca2.action_at DESC, ca2.id DESC
                LIMIT 1)';
    }

    /**
     * Subquery: ambil MAX(action_at) per kasus.
     * Untuk hitung stale.
     */
    private function lastActionAtSub(): string
    {
        return '(SELECT MAX(ca.action_at)
                FROM case_actions ca
                WHERE ca.npl_case_id = npl_cases.id)';
    }

    public function index(Request $request)
    {
        $this->ensureCanView();

        $today        = now()->toDateString();
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth   = now()->endOfMonth()->toDateString();

        // ✅ FILTER VISIBILITY AO
        $visibleAoCodes = $this->visibleAoCodes();

        // Jika kosong (dan bukan role "lihat semua"), maka dashboard kosong (aman)
        // (kalau kamu ingin kabag/direksi selalu lihat semua, pastikan OrgVisibilityService return semua ids)
        // Di sini kita pakai aturan aman: kalau kosong -> tampilkan kosong.
        if (empty($visibleAoCodes)) {
            $stats = collect();
            return view('dashboard.ao-index', compact('stats'));
        }

        // 🔹 Agregasi utama per AO
        $stats = NplCase::join('loan_accounts', 'npl_cases.loan_account_id', '=', 'loan_accounts.id')
            ->whereIn('loan_accounts.ao_code', $visibleAoCodes)
            ->select(
                'loan_accounts.ao_code',
                'loan_accounts.ao_name',
                DB::raw('COUNT(*) as total_cases'),
                DB::raw('SUM(CASE WHEN npl_cases.closed_at IS NULL THEN 1 ELSE 0 END) as open_cases'),
                DB::raw('SUM(CASE WHEN npl_cases.closed_at IS NOT NULL THEN 1 ELSE 0 END) as closed_cases'),
                DB::raw('SUM(CASE WHEN npl_cases.closed_at IS NULL THEN loan_accounts.outstanding ELSE 0 END) as os_open'),
                DB::raw('SUM(CASE WHEN npl_cases.closed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as closed_this_month')
            )
            ->addBinding([$startOfMonth, $endOfMonth], 'select')
            ->groupBy('loan_accounts.ao_code', 'loan_accounts.ao_name')
            ->orderByDesc('total_cases')
            ->get();

        /**
         * 🔹 Jumlah kasus dengan next action overdue per AO
         * UPDATE: hitung overdue berdasarkan next_action_due dari ACTION TERAKHIR per case.
         */
        $lastNextDueSub = $this->lastNextDueSub();

        $overduePerAo = NplCase::query()
            ->whereNull('npl_cases.closed_at')
            ->join('loan_accounts', 'npl_cases.loan_account_id', '=', 'loan_accounts.id')
            ->whereIn('loan_accounts.ao_code', $visibleAoCodes)
            ->whereRaw("{$lastNextDueSub} IS NOT NULL")
            ->whereRaw("{$lastNextDueSub} < ?", [$today])
            ->select(
                'loan_accounts.ao_code',
                DB::raw('COUNT(*) as overdue_cases')
            )
            ->groupBy('loan_accounts.ao_code')
            ->pluck('overdue_cases', 'ao_code');

        /**
         * 🔹 Jumlah kasus yang BELUM PERNAH ditangani (berdasarkan handledTypes) per AO
         */
        $handledTypes = $this->handledTypes;

        $noActionPerAo = NplCase::whereNull('closed_at')
            ->whereDoesntHave('actions', function ($a) use ($handledTypes) {
                $a->whereIn('action_type', $handledTypes);
            })
            ->join('loan_accounts', 'npl_cases.loan_account_id', '=', 'loan_accounts.id')
            ->whereIn('loan_accounts.ao_code', $visibleAoCodes)
            ->select(
                'loan_accounts.ao_code',
                DB::raw('COUNT(*) as no_action_cases')
            )
            ->groupBy('loan_accounts.ao_code')
            ->pluck('no_action_cases', 'ao_code');

        // 🔹 Tempel overdue + no_action ke collection stats
        $stats = $stats->map(function ($row) use ($overduePerAo, $noActionPerAo) {
            $row->overdue_cases   = $overduePerAo[$row->ao_code] ?? 0;
            $row->no_action_cases = $noActionPerAo[$row->ao_code] ?? 0;
            $risk = $this->classifyAoRisk($row);
            $row->risk_level = $risk['level'];
            $row->risk_score = $risk['score'];

            $open = (int) $row->open_cases;
            $row->no_action_pct = $open > 0
                ? round(($row->no_action_cases / $open) * 100, 1)
                : 0;

            return $row;
        });

        $stats = $stats->sortByDesc(fn($r) => $r->risk_score)->values();

        return view('dashboard.ao-index', compact('stats'));
    }

    // ==== HELPER QUERY UNTUK LIST KASUS AO ====
    private function aoCasesQuery(string $aoCode, ?string $filter, Carbon $today, int $staleDays, ?string $q = null)
    {
        $staleLimit = $today->copy()->subDays($staleDays);

        $handledTypes    = $this->handledTypes;
        $lastActionAtSub = $this->lastActionAtSub();
        $lastNextDueSub  = $this->lastNextDueSub();

        $base = NplCase::with([
                'loanAccount',
                'actions' => function ($q) {
                    $q->orderBy('action_at', 'desc');
                }
            ])
            ->join('loan_accounts', 'npl_cases.loan_account_id', '=', 'loan_accounts.id')
            ->where('loan_accounts.ao_code', $aoCode)
            ->select('npl_cases.*');

        // ==== SEARCH (nama debitur / rekening / CIF) ====
        $q = trim((string) $q);
        if ($q !== '') {
            $base->where(function ($w) use ($q) {
                $w->where('loan_accounts.customer_name', 'like', "%{$q}%")
                  ->orWhere('loan_accounts.account_no', 'like', "%{$q}%")
                  ->orWhere('loan_accounts.cif', 'like', "%{$q}%");
            });
        }

        // ==== FILTER ====
        if ($filter === 'no-action') {
            $base->whereNull('npl_cases.closed_at')
                 ->whereDoesntHave('actions', function ($a) use ($handledTypes) {
                     $a->whereIn('action_type', $handledTypes);
                 });

        } elseif ($filter === 'stale') {
            $base->whereNull('npl_cases.closed_at')
                 ->whereHas('actions', function ($a) use ($handledTypes) {
                     $a->whereIn('action_type', $handledTypes);
                 })
                 ->whereRaw("{$lastActionAtSub} < ?", [$staleLimit]);

        } elseif ($filter === 'overdue') {
            $base->whereNull('npl_cases.closed_at')
                 ->whereRaw("{$lastNextDueSub} IS NOT NULL")
                 ->whereRaw("{$lastNextDueSub} < ?", [$today->toDateString()]);

        } elseif ($filter === 'open') {
            $base->whereNull('npl_cases.closed_at');
        }

        // ==== ORDERING (TO-DO PRIORITY) ====
        $base->orderByRaw("
            CASE
                WHEN npl_cases.closed_at IS NULL
                     AND NOT EXISTS (
                         SELECT 1 FROM case_actions ca
                         WHERE ca.npl_case_id = npl_cases.id
                           AND ca.action_type IN ('sp1','sp2','sp3','spt','spjad','visit')
                     )
                    THEN 1
                WHEN npl_cases.closed_at IS NULL
                     AND EXISTS (
                         SELECT 1 FROM case_actions ca
                         WHERE ca.npl_case_id = npl_cases.id
                           AND ca.action_type IN ('sp1','sp2','sp3','spt','spjad','visit')
                     )
                     AND ({$lastActionAtSub}) < ?
                    THEN 2
                WHEN npl_cases.closed_at IS NULL
                     AND ({$lastNextDueSub}) IS NOT NULL
                     AND ({$lastNextDueSub}) < ?
                    THEN 3
                WHEN npl_cases.closed_at IS NULL THEN 4
                ELSE 5
            END
        ", [$staleLimit, $today->toDateString()])
        ->orderByDesc('loan_accounts.dpd')
        ->orderBy('npl_cases.opened_at', 'asc');

        return $base;
    }

    // ==== DETAIL AO (VIEW) ====
    public function show(Request $request, string $aoCode)
    {
        $this->ensureCanView();

        // ✅ GUARD: pastikan aoCode yang dibuka ada dalam scope user
        $visibleAoCodes = $this->visibleAoCodes();
        $aoCodeNorm = strtoupper(trim((string)$aoCode));
        if (empty($visibleAoCodes) || !in_array($aoCodeNorm, $visibleAoCodes, true)) {
            abort(403);
        }

        $today      = now();
        $staleDays  = 7;
        $filter     = $request->query('filter', 'all');
        $staleLimit = $today->copy()->subDays($staleDays);
        $q          = $request->query('q');

        $handledTypes    = $this->handledTypes;
        $lastActionAtSub = $this->lastActionAtSub();

        // Badge: belum pernah ditangani
        $noActionCount = NplCase::whereNull('closed_at')
            ->whereDoesntHave('actions', function ($a) use ($handledTypes) {
                $a->whereIn('action_type', $handledTypes);
            })
            ->whereHas('loanAccount', function ($q) use ($aoCodeNorm) {
                $q->where('ao_code', $aoCodeNorm);
            })
            ->count();

        // Badge: stale ≥ 7 hari (hanya yang sudah pernah ada action handledTypes)
        $staleCount = NplCase::whereNull('closed_at')
            ->whereHas('actions', function ($a) use ($handledTypes) {
                $a->whereIn('action_type', $handledTypes);
            })
            ->whereHas('loanAccount', function ($q) use ($aoCodeNorm) {
                $q->where('ao_code', $aoCodeNorm);
            })
            ->whereRaw("{$lastActionAtSub} < ?", [$staleLimit])
            ->count();

        $cases = $this->aoCasesQuery($aoCodeNorm, $filter, $today, $staleDays, $q)
            ->paginate(20)
            ->withQueryString();

        $aoName = optional($cases->first()?->loanAccount)->ao_name;

        return view('dashboard.ao-show', compact(
            'cases',
            'aoCode',
            'aoName',
            'noActionCount',
            'staleCount',
            'staleDays',
            'filter',
            'q'
        ));
    }

    // ==== EXPORT EXCEL ====
    public function export(Request $request, string $aoCode)
    {
        $this->ensureCanView();

        // ✅ GUARD: export juga harus sesuai scope
        $visibleAoCodes = $this->visibleAoCodes();
        $aoCodeNorm = strtoupper(trim((string)$aoCode));
        if (empty($visibleAoCodes) || !in_array($aoCodeNorm, $visibleAoCodes, true)) {
            abort(403);
        }

        $today     = now();
        $staleDays = 7;
        $filter    = $request->query('filter', 'all');
        $q         = $request->query('q');

        $rows = $this->aoCasesQuery($aoCodeNorm, $filter, $today, $staleDays, $q)->get();

        $aoName = optional($rows->first()?->loanAccount)->ao_name ?? $aoCodeNorm;
        $safeAoName = str_replace(' ', '_', strtoupper($aoName));
        $suffix = $filter !== 'all' ? '_' . str_replace('-', '_', $filter) : '';
        $filename = "AO_{$safeAoName}_cases{$suffix}.xlsx";

        return Excel::download(new AoCasesExport($rows, $staleDays), $filename);
    }

    private function classifyAoRisk($row): array
    {
        $open    = max(0, (int) $row->open_cases);
        $overdue = max(0, (int) ($row->overdue_cases ?? 0));
        $noAct   = max(0, (int) ($row->no_action_cases ?? 0));

        $overdueRatio = $open > 0 ? ($overdue / $open) : 0;
        $noActionPct  = $open > 0 ? (($noAct / $open) * 100) : 0;

        // ===== CRITICAL =====
        if ($noActionPct >= 60 || $overdueRatio >= 0.50 || $overdue >= 20) {
            return ['level' => 'CRITICAL', 'score' => 100];
        }

        // ===== HIGH =====
        if ($noActionPct >= 40 || $overdueRatio >= 0.30 || $overdue >= 10) {
            return ['level' => 'HIGH', 'score' => 80];
        }

        // ===== MEDIUM =====
        if ($noActionPct >= 20 || $overdueRatio >= 0.10 || $overdue >= 5) {
            return ['level' => 'MEDIUM', 'score' => 50];
        }

        return ['level' => 'LOW', 'score' => 20];
    }

}
