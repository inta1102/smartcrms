<?php

namespace App\Http\Controllers\Supervision;

use App\Http\Controllers\Controller;
use App\Models\AoAgenda;
use App\Models\CaseAction;
use App\Models\NplCase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TlDashboardController extends Controller
{
    public function __construct()
    {
        // ✅ TL group (TL/TLL/TLF/TLR)
        $this->middleware('requireRole:TL,TLL,TLF,TLR,TLRO,TLSO,TLFE,TLBE,TLUM');
    }

    /**
     * Ambil staff ids bawahan TL via relasi User::staffAssignments().
     * (memanfaatkan yang sudah ada di User model)
     */
    protected function staffIdsForCurrentTl(?string $unitCode = null): array
    {
        $u = auth()->user();
        if (!$u) return [];

        $q = $u->staffAssignments()
            ->where('is_active', 1)
            ->whereNull('effective_to');

        if (!empty($unitCode)) {
            $q->where('unit_code', $unitCode);
        }

        return $q->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function index(Request $request)
    {
        $unitCode = trim((string) $request->input('unit_code', ''));

        $staffIds = $this->staffIdsForCurrentTl($unitCode);

        // ✅ kalau belum ada assignment, tampilkan kosong (bukan 403)
        if (empty($staffIds)) {
            $empty = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
            $kpi = [
                'active_cases' => 0,
                'active_agendas' => 0,
                'overdue' => 0,
                'in_progress_no_action' => 0,
                'visits_week' => 0,
                'stagnant_7d' => 0,
            ];
            $attention = [
                'overdue_heavy' => [],
                'in_progress_empty' => [],
                'stagnant' => [],
            ];
            $filters = [
                'unit_code' => $unitCode,
            ];

            return view('supervision.tl.index', [
                'kpi' => $kpi,
                'attention' => $attention,
                'filters' => $filters,
                'rows' => $empty,
            ]);
        }

        // ==========================================
        // 1) Base query agenda (SCOPE: staff TL)
        // ==========================================
        // Kita scope agenda lewat actions.user_id (paling aman),
        // supaya tidak tergantung ada/tidaknya created_by.
        $q = AoAgenda::query()
            ->select('ao_agendas.*')
            ->with(['case.loanAccount'])
            ->whereHas('actions', fn ($x) => $x->whereIn('user_id', $staffIds));

        // FILTERS
        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->filled('type')) {
            $q->where('agenda_type', $request->string('type')->toString());
        }
        if ($request->filled('due_from')) {
            $q->whereDate('due_at', '>=', $request->input('due_from'));
        }
        if ($request->filled('due_to')) {
            $q->whereDate('due_at', '<=', $request->input('due_to'));
        }
        if ($request->boolean('only_overdue')) {
            $q->whereNotIn('status', ['done', 'cancelled'])
              ->where('due_at', '<', now());
        }
        if ($request->boolean('empty_inprogress')) {
            // agenda in progress tapi belum ada tindakan
            $q->where('status', 'in_progress')
              ->whereDoesntHave('actions');
        }

        // -----------------------------------------
        // 2) Last action per agenda (hindari N+1)
        // -----------------------------------------
        $lastActionSub = CaseAction::query()
            ->select('ao_agenda_id', DB::raw('MAX(action_at) as last_at'))
            ->whereNotNull('ao_agenda_id')
            ->groupBy('ao_agenda_id');

        $q->leftJoinSub($lastActionSub, 'la', function ($j) {
            $j->on('ao_agendas.id', '=', 'la.ao_agenda_id');
        });

        // Sorting: overdue dulu, lalu in_progress, lalu due_at
        $agendas = $q
            ->orderByRaw("CASE
                WHEN ao_agendas.due_at < NOW() AND ao_agendas.status NOT IN ('done','cancelled') THEN 0
                WHEN ao_agendas.status='in_progress' THEN 1
                ELSE 2 END")
            ->orderBy('ao_agendas.due_at')
            ->paginate(20)
            ->withQueryString();

        // Batch ambil last action detail
        $agendaIds = $agendas->getCollection()->pluck('id')->values()->all();

        $lastActions = CaseAction::query()
            ->whereIn('ao_agenda_id', $agendaIds)
            ->orderByDesc('action_at')
            ->get()
            ->groupBy('ao_agenda_id')
            ->map(fn ($g) => $g->first());

        $rows = $agendas->getCollection()->map(function ($a) use ($lastActions) {
            $case = $a->case;
            $loan = $case?->loanAccount;

            $debtor = $loan?->customer_name ?? $case?->debtor_name ?? '-';
            $aoName = $loan?->ao_name ?? '-';

            $overdueDays = null;
            if (!in_array($a->status, ['done', 'cancelled'], true) && $a->due_at && $a->due_at->lt(now())) {
                $overdueDays = $a->due_at->diffInDays(now());
            }

            $last = $lastActions->get($a->id);

            return [
                'agenda_id'         => $a->id,
                'agenda_title'      => $a->title,
                'agenda_type'       => $a->agenda_type,
                'status'            => $a->status,
                'planned_at'        => optional($a->planned_at)->format('Y-m-d H:i'),
                'due_at'            => optional($a->due_at)->format('Y-m-d H:i'),
                'overdue_days'      => $overdueDays,
                'evidence_required' => (int) $a->evidence_required === 1,

                'case_id'           => $a->npl_case_id,
                'debtor'            => $debtor,
                'ao_name'           => $aoName,

                'last_action_type'   => $last?->action_type,
                'last_action_at'     => $last?->action_at?->format('d M Y H:i'),
                'last_action_result' => $last?->result,
                'last_action_desc'   => $last?->description,

                'last_action' => $last
                    ? (strtoupper((string) $last->action_type).' • '.$last->action_at?->format('d M H:i').' • '.($last->result ?? '-'))
                    : null,
            ];
        })->values()->all();

        $agendas->setCollection(collect($rows));

        // ==========================================
        // 3) KPI (TERSCOPE TL)
        // ==========================================

        // Kasus aktif (scope: PIC under TL)
        $activeCases = NplCase::query()
            ->whereNull('closed_at')
            ->whereIn('pic_user_id', $staffIds)
            ->count();

        // Agenda aktif (scope via actions user staff)
        $activeAgendas = AoAgenda::query()
            ->whereNotIn('status', ['done', 'cancelled'])
            ->whereHas('actions', fn ($a) => $a->whereIn('user_id', $staffIds))
            ->count();

        $overdue = AoAgenda::query()
            ->whereNotIn('status', ['done', 'cancelled'])
            ->where('due_at', '<', now())
            ->whereHas('actions', fn ($a) => $a->whereIn('user_id', $staffIds))
            ->count();

        // In progress, tapi belum ada aksi (scope by case PIC)
        // Ini lebih akurat daripada created_by, karena TL memantau agenda staff via PIC casenya.
        $inProgressNoAction = AoAgenda::query()
            ->where('status', 'in_progress')
            ->whereDoesntHave('actions')
            ->whereHas('case', fn ($c) => $c->whereIn('pic_user_id', $staffIds))
            ->count();

        $visitsWeek = CaseAction::query()
            ->where('action_type', 'visit')
            ->where('action_at', '>=', now()->startOfWeek())
            ->whereIn('user_id', $staffIds)
            ->count();

        $kpi = [
            'active_cases' => $activeCases,
            'active_agendas' => $activeAgendas,
            'overdue' => $overdue,
            'in_progress_no_action' => $inProgressNoAction,
            'visits_week' => $visitsWeek,
            'stagnant_7d' => $this->countStagnantCasesForStaff(7, $staffIds),
        ];

        // ==========================================
        // 4) Attention list (TERSCOPE TL)
        // ==========================================
        $attention = [
            'overdue_heavy'     => $this->topOverdueHeavyForStaff(10, 3, $staffIds),
            'in_progress_empty' => $this->topInProgressEmptyForStaff(10, $staffIds),
            'stagnant'          => $this->topStagnantForStaff(10, 7, $staffIds),
        ];

        $filters = [
            'unit_code' => $unitCode,
        ];

        return view('supervision.tl.index', [
            'kpi' => $kpi,
            'attention' => $attention,
            'filters' => $filters,
            'rows' => $agendas,
        ]);
    }

    // =========================================================
    // Scoped helpers (berdasarkan staffIds)
    // =========================================================

    /**
     * Stagnant = case PIC under TL yang last action-nya < cutoff ATAU belum pernah ada action.
     * IMPORTANT: scope-nya pakai npl_cases.pic_user_id, bukan user_id action saja.
     */
    protected function countStagnantCasesForStaff(int $days, array $staffIds): int
    {
        $cutoff = now()->subDays($days);

        $latest = CaseAction::select('npl_case_id', DB::raw('MAX(action_at) as last_at'))
            ->groupBy('npl_case_id');

        return NplCase::query()
            ->whereNull('closed_at')
            ->whereIn('pic_user_id', $staffIds)
            ->leftJoinSub($latest, 'la', function ($j) {
                $j->on('npl_cases.id', '=', 'la.npl_case_id');
            })
            ->where(function ($w) use ($cutoff) {
                $w->whereNull('la.last_at')->orWhere('la.last_at', '<', $cutoff);
            })
            ->count();
    }

    protected function topOverdueHeavyForStaff(int $limit, int $minDays, array $staffIds): array
    {
        $agendas = AoAgenda::query()
            ->with(['case.loanAccount'])
            ->whereNotIn('status', ['done', 'cancelled'])
            ->where('due_at', '<', now()->subDays($minDays))
            ->whereHas('case', fn ($c) => $c->whereIn('pic_user_id', $staffIds)) // ✅ scope by PIC
            ->orderBy('due_at')
            ->limit($limit)
            ->get();

        return $agendas->map(function ($a) {
            $debtor = $a->case?->loanAccount?->customer_name ?? '-';

            return [
                'agenda_id' => $a->id,
                'case_id' => $a->npl_case_id,
                'debtor' => $debtor,
                'agenda_title' => $a->title,
                'due' => optional($a->due_at)->format('d M Y H:i'),
            ];
        })->values()->all();
    }

    protected function topInProgressEmptyForStaff(int $limit, array $staffIds): array
    {
        $agendas = AoAgenda::query()
            ->with(['case.loanAccount'])
            ->where('status', 'in_progress')
            ->whereDoesntHave('actions')
            ->whereHas('case', fn ($c) => $c->whereIn('pic_user_id', $staffIds)) // ✅ scope by PIC
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();

        return $agendas->map(function ($a) {
            $debtor = $a->case?->loanAccount?->customer_name ?? '-';

            return [
                'agenda_id' => $a->id,
                'case_id' => $a->npl_case_id,
                'debtor' => $debtor,
                'agenda_title' => $a->title,
                'started_at' => optional($a->started_at)->format('d M Y H:i'),
            ];
        })->values()->all();
    }

    protected function topStagnantForStaff(int $limit, int $days, array $staffIds): array
    {
        $cutoff = now()->subDays($days);

        $latest = CaseAction::select('npl_case_id', DB::raw('MAX(action_at) as last_at'))
            ->groupBy('npl_case_id');

        $cases = NplCase::query()
            ->with('loanAccount')
            ->whereNull('closed_at')
            ->whereIn('pic_user_id', $staffIds)
            ->leftJoinSub($latest, 'la', function ($j) {
                $j->on('npl_cases.id', '=', 'la.npl_case_id');
            })
            ->where(function ($w) use ($cutoff) {
                $w->whereNull('la.last_at')->orWhere('la.last_at', '<', $cutoff);
            })
            ->orderByRaw('COALESCE(la.last_at, "1900-01-01") ASC')
            ->limit($limit)
            ->get(['npl_cases.*', 'la.last_at']);

        return $cases->map(function ($c) {
            $debtor = $c->loanAccount?->customer_name ?? '-';

            return [
                'case_id' => $c->id,
                'debtor' => $debtor,
                'last_action_at' => $c->last_at ? Carbon::parse($c->last_at)->format('d M Y H:i') : '-',
            ];
        })->values()->all();
    }
}
