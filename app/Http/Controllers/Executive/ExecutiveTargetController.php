<?php

namespace App\Http\Controllers\Executive;

use App\Http\Controllers\Controller;
use App\Models\NplCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExecutiveTargetController extends Controller
{
    public function index(Request $request)
    {
        $today = now()->toDateString();

        $issue = strtoupper(trim((string) $request->get('issue', 'all')));
        $q     = trim((string) $request->get('q', ''));

        /**
         * ============================
         * SAFE COLUMN MAP (hindari unknown column)
         * ============================
         * loan_accounts yang PASTI ada (dari screenshot):
         * - customer_name, cif, account_no
         * kolom lain (no_rek / norek) mungkin ada/mungkin tidak -> cek dulu
         */
        $hasNoRek  = Schema::hasColumn('loan_accounts', 'no_rek');
        $hasNorek  = Schema::hasColumn('loan_accounts', 'norek');

        // no_rek expression yang aman
        $noRekExpr = "la.account_no";
        if ($hasNoRek && $hasNorek) {
            $noRekExpr = "COALESCE(la.account_no, la.no_rek, la.norek)";
        } elseif ($hasNoRek) {
            $noRekExpr = "COALESCE(la.account_no, la.no_rek)";
        } elseif ($hasNorek) {
            $noRekExpr = "COALESCE(la.account_no, la.norek)";
        }

        // debtor expression: loan_accounts.customer_name (karena debtor_name tidak ada)
        $debtorExpr = "COALESCE(la.customer_name, '-')";

        // cif expression: loan_accounts.cif
        $cifExpr = "COALESCE(la.cif, '-')";

        /**
         * ============================
         * SUBQUERY: active target (per npl_case_id)
         * - ambil 1 target is_active=1 (paling baru)
         * ============================
         */
        $activeTargetSub = DB::table('case_resolution_targets as t')
            ->select(
                't.npl_case_id',
                't.id as target_id',
                't.target_date',
                't.status as target_status',
                DB::raw('COALESCE(t.activated_at, t.created_at) as target_anchor_at')
            )
            ->where('t.is_active', 1);

        // kalau somehow ada >1 active, pick yang terbaru
        $activeTargetPick = DB::table(DB::raw("({$activeTargetSub->toSql()}) as at0"))
            ->mergeBindings($activeTargetSub)
            ->select('at0.npl_case_id', DB::raw('MAX(at0.target_id) as target_id'))
            ->groupBy('at0.npl_case_id');

        $activeTargetFinal = DB::table('case_resolution_targets as t')
            ->joinSub($activeTargetPick, 'pick', function ($j) {
                $j->on('pick.target_id', '=', 't.id');
            })
            ->select(
                't.npl_case_id',
                't.id as target_id',
                't.target_date',
                't.status as target_status',
                DB::raw('COALESCE(t.activated_at, t.created_at) as target_anchor_at')
            );

        /**
         * ============================
         * SUBQUERY: last action after target active
         * - MAX(case_actions.action_at) where action_at >= target_anchor_at
         * ============================
         */
        $lastActionAfterTarget = DB::table('case_actions as a')
            ->joinSub($activeTargetFinal, 'at', function ($j) {
                $j->on('at.npl_case_id', '=', 'a.npl_case_id');
            })
            ->whereColumn('a.action_at', '>=', 'at.target_anchor_at')
            ->groupBy('a.npl_case_id')
            ->select(
                'a.npl_case_id',
                DB::raw('MAX(a.action_at) as last_action_at')
            );

        /**
         * ============================
         * BASE QUERY rows
         * ============================
         */
        $rowsQ = DB::table('npl_cases as c')
            ->leftJoin('loan_accounts as la', 'la.id', '=', 'c.loan_account_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.pic_user_id') // PIC dari npl_cases.pic_user_id
            ->leftJoinSub($activeTargetFinal, 'at', function ($j) {
                $j->on('at.npl_case_id', '=', 'c.id');
            })
            ->leftJoinSub($lastActionAfterTarget, 'lx', function ($j) {
                $j->on('lx.npl_case_id', '=', 'c.id');
            })
            ->select(
                'c.id',

                // Identitas debitur aman (ambil dari loan_accounts)
                DB::raw("{$debtorExpr} as debtor_name"),
                DB::raw("{$cifExpr} as cif"),
                DB::raw("{$noRekExpr} as no_rek"),

                // PIC
                DB::raw('COALESCE(u.name, "-") as pic_name'),
                'c.pic_user_id',

                // target
                'at.target_id',
                'at.target_date',
                'at.target_status',
                'at.target_anchor_at',

                // last action
                'lx.last_action_at',

                // issue
                DB::raw("
                    CASE
                        WHEN at.target_id IS NULL THEN 'NO_TARGET'
                        WHEN at.target_date < '{$today}'
                             AND LOWER(COALESCE(at.target_status,'')) NOT IN ('done','closed','completed','executed','settled')
                             THEN 'OVERDUE'
                        WHEN lx.last_action_at IS NULL THEN 'NO_ACTION'
                        ELSE 'OK'
                    END AS issue
                "),

                // overdue_days
                DB::raw("
                    CASE
                        WHEN at.target_id IS NOT NULL
                         AND at.target_date < '{$today}'
                         AND LOWER(COALESCE(at.target_status,'')) NOT IN ('done','closed','completed','executed','settled')
                        THEN DATEDIFF('{$today}', at.target_date)
                        ELSE NULL
                    END AS overdue_days
                ")
            );

        /**
         * ============================
         * FILTER: q (Debitur/CIF/No Rek/PIC)
         * ============================
         */
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rowsQ->where(function ($w) use ($needle, $debtorExpr, $cifExpr, $noRekExpr) {
                $w->whereRaw("LOWER({$debtorExpr}) LIKE ?", ["%{$needle}%"])
                  ->orWhereRaw("LOWER({$cifExpr}) LIKE ?", ["%{$needle}%"])
                  ->orWhereRaw("LOWER({$noRekExpr}) LIKE ?", ["%{$needle}%"])
                  ->orWhereRaw("LOWER(COALESCE(u.name,'')) LIKE ?", ["%{$needle}%"]);
            });
        }

        /**
         * ============================
         * FILTER: issue
         * ============================
         */
        if ($issue !== '' && $issue !== 'ALL') {
            $rowsQ->whereRaw("(
                CASE
                    WHEN at.target_id IS NULL THEN 'NO_TARGET'
                    WHEN at.target_date < '{$today}'
                         AND LOWER(COALESCE(at.target_status,'')) NOT IN ('done','closed','completed','executed','settled')
                         THEN 'OVERDUE'
                    WHEN lx.last_action_at IS NULL THEN 'NO_ACTION'
                    ELSE 'OK'
                END
            ) = ?", [$issue]);
        }

        /**
         * ============================
         * KPI COUNTS (ikut filter q)
         * ============================
         * Cara paling aman: bungkus rowsQ jadi subquery (x), lalu group by x.issue
         */
        $kpi = DB::query()
            ->fromSub(clone $rowsQ, 'x')
            ->selectRaw("x.issue, COUNT(*) as cnt")
            ->groupBy('x.issue')
            ->pluck('cnt', 'issue');

        $noTarget = (int) ($kpi['NO_TARGET'] ?? 0);
        $noAction = (int) ($kpi['NO_ACTION'] ?? 0);
        $overdue  = (int) ($kpi['OVERDUE'] ?? 0);
        $ok       = (int) ($kpi['OK'] ?? 0);

        /**
         * ============================
         * ORDER + PAGINATION
         * ============================
         */
        $rowsQ->orderByRaw("
            CASE
                WHEN issue = 'OVERDUE' THEN 1
                WHEN issue = 'NO_ACTION' THEN 2
                WHEN issue = 'NO_TARGET' THEN 3
                ELSE 9
            END ASC
        ")
        ->orderByDesc('overdue_days')
        ->orderBy('at.target_date', 'asc')
        ->orderByDesc('c.id');

        $rows = $rowsQ->paginate(15)->withQueryString();

        return view('executive.targets.index', compact(
            'rows',
            'noTarget', 'noAction', 'overdue', 'ok',
            'issue', 'q'
        ));
    }

    public function show(NplCase $case)
    {
        $case->loadMissing(['loanAccount', 'pic']);

        $targets = DB::table('case_resolution_targets')
            ->where('npl_case_id', $case->id)
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get();

        $activeTarget = $targets->firstWhere('is_active', 1);

        $actions = DB::table('case_actions')
            ->where('npl_case_id', $case->id)
            ->orderByDesc('action_at')
            ->limit(50)
            ->get();

        return view('executive.targets.show', compact(
            'case', 'targets', 'activeTarget', 'actions'
        ));
    }
}
