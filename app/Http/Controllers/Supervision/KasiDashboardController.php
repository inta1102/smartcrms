<?php

namespace App\Http\Controllers\Supervision;

use App\Http\Controllers\Controller;
use App\Models\AoAgenda;
use App\Models\CaseAction;
use App\Models\NplCase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\CaseResolutionTarget;
use App\Services\Supervision\KasiDecisionPanelService;

class KasiDashboardController extends Controller
{
    protected KasiDecisionPanelService $panelSvc;

    public function __construct(KasiDecisionPanelService $panelSvc)
    {
        $this->panelSvc = $panelSvc;
    }

    public function index(Request $request)
    {
        $decisionPanel = $this->panelSvc->getPanel();

        $q = AoAgenda::query()
            ->select('ao_agendas.*')
            ->with([
                'case.loanAccount',
                // ✅ wajib: bikin relasi latestAction() di model AoAgenda
                'latestAction',
            ]);

        // =========================
        // Filters
        // =========================
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
              ->whereNotNull('due_at')
              ->where('due_at', '<', now());
        }

        if ($request->boolean('empty_inprogress')) {
            $q->where('status', 'in_progress')
              ->whereDoesntHave('actions');
        }

        // =========================
        // Sorting (anti timezone drift)
        // =========================
        $q->orderByRaw("
            CASE
                WHEN due_at IS NULL THEN 9
                WHEN due_at < ? AND status NOT IN ('done','cancelled') THEN 0
                WHEN status = 'in_progress' THEN 1
                ELSE 2
            END
        ", [now()])
        ->orderByRaw("COALESCE(due_at, '2999-12-31 00:00:00') ASC");

        $agendas = $q->paginate(20)->withQueryString();

        // =========================
        // Rows for view (NO N+1)
        // =========================
        $rows = $agendas->getCollection()->map(function ($a) {
            $case = $a->case;
            $loan = $case?->loanAccount;

            $debtor = $loan?->customer_name ?? $case?->debtor_name ?? '-';
            $aoName  = $loan?->ao_name ?? '-';

            $overdueDays = null;
            if (!in_array($a->status, ['done','cancelled'], true) && $a->due_at && $a->due_at->lt(now())) {
                $overdueDays = $a->due_at->diffInDays(now());
            }

            $last = $a->latestAction; // ✅ dari eager load

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

                'last_action'       => $last
                    ? (strtoupper((string) $last->action_type).' • '.optional($last->action_at)->format('d M H:i').' • '.($last->result ?? '-'))
                    : null,
            ];
        })->values();

        $agendas->setCollection($rows);

        // =========================
        // KPI KASI
        // =========================
        $kpi = [
            'active_cases'          => NplCase::query()->count(),
            'active_agendas'        => AoAgenda::whereNotIn('status', ['done','cancelled'])->count(),
            'overdue'               => AoAgenda::whereNotIn('status', ['done','cancelled'])
                                        ->whereNotNull('due_at')
                                        ->where('due_at','<', now())
                                        ->count(),
            'in_progress_no_action' => AoAgenda::where('status','in_progress')->whereDoesntHave('actions')->count(),
            'stagnant_7d'           => $this->countStagnantCases(7),

            // ✅ fixed
            'pending_tl_approvals'   => $this->countPendingTargetApprovals('tl'),
            'pending_kasi_approvals' => $this->countPendingTargetApprovals('kasi'),
        ];

        // =========================
        // Attention Boxes
        // =========================
        $attention = [
            'overdue_heavy'     => $this->topOverdueHeavy(10, 3),
            'in_progress_empty' => $this->topInProgressEmpty(10),
            'stagnant'          => $this->topStagnant(10, 7),
            'pending_targets'   => $this->topPendingTargets(10),
        ];

        return view('supervision.kasi.index', [
            'decisionPanel' => $decisionPanel,
            'kpi'           => $kpi,
            'attention'     => $attention,
            'rows'          => $agendas,
        ]);
    }

    protected function countStagnantCases(int $days): int
    {
        $cutoff = now()->subDays($days);

        $latest = CaseAction::select('npl_case_id', DB::raw('MAX(action_at) as last_at'))
            ->groupBy('npl_case_id');

        return NplCase::query()
            ->leftJoinSub($latest, 'la', function ($j) {
                $j->on('npl_cases.id', '=', 'la.npl_case_id');
            })
            ->where(function ($w) use ($cutoff) {
                $w->whereNull('la.last_at')->orWhere('la.last_at', '<', $cutoff);
            })
            ->count();
    }

    protected function topOverdueHeavy(int $limit, int $minDays): array
    {
        $agendas = AoAgenda::query()
            ->with(['case.loanAccount'])
            ->whereNotIn('status', ['done','cancelled'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()->subDays($minDays))
            ->orderBy('due_at')
            ->limit($limit)
            ->get();

        return $agendas->map(function ($a) {
            $debtor = $a->case?->loanAccount?->customer_name ?? '-';
            return [
                'agenda_id'    => $a->id,
                'case_id'      => $a->npl_case_id,
                'debtor'       => $debtor,
                'agenda_title' => $a->title,
                'due'          => optional($a->due_at)->format('d M Y H:i'),
            ];
        })->values()->all();
    }

    protected function topInProgressEmpty(int $limit): array
    {
        $agendas = AoAgenda::query()
            ->with(['case.loanAccount'])
            ->where('status', 'in_progress')
            ->whereDoesntHave('actions')
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();

        return $agendas->map(function ($a) {
            $debtor = $a->case?->loanAccount?->customer_name ?? '-';
            return [
                'agenda_id'    => $a->id,
                'case_id'      => $a->npl_case_id,
                'debtor'       => $debtor,
                'agenda_title' => $a->title,
                'started_at'   => optional($a->started_at)->format('d M Y H:i'),
            ];
        })->values()->all();
    }

    protected function topStagnant(int $limit, int $days): array
    {
        $cutoff = now()->subDays($days);

        $latest = CaseAction::select('npl_case_id', DB::raw('MAX(action_at) as last_at'))
            ->groupBy('npl_case_id');

        $cases = NplCase::query()
            ->with('loanAccount')
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
                'case_id'         => $c->id,
                'debtor'          => $debtor,
                'last_action_at'  => $c->last_at ? Carbon::parse($c->last_at)->format('d M Y H:i') : '-',
            ];
        })->values()->all();
    }

    protected function countPendingTargetApprovals(string $who): int
    {
        return match ($who) {
            'tl'   => CaseResolutionTarget::pendingTl()->count(),
            'kasi' => CaseResolutionTarget::pendingKasi()->count(),
            default => 0,
        };
    }

    protected function topPendingTargets(int $limit): array
    {
        try {
            // contoh ide: tampilkan target terbaru yang pending
            // return CaseResolutionTarget::pendingAny()->latest()->limit($limit)->get()->toArray();
            return [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
